import json
import tempfile
import unittest
import sqlite3
from io import BytesIO
from pathlib import Path

from ehlib.storage.file_manager import FileManager
from ehlib.core.downloader import Downloader
from ehlib.models.database import ensure_gallery_compression_columns_v1
from ehlib.utils.image_compression import ImageCompressor


class ImageCompressionTests(unittest.TestCase):
    def test_download_compression_stats_use_kept_bytes(self):
        request_stats = {}
        Downloader._record_compression_stats(request_stats, {
            "used_candidate": True,
            "orig_bytes": 1000,
            "candidate_bytes": 600,
        })
        Downloader._record_compression_stats(request_stats, {
            "used_candidate": False,
            "orig_bytes": 500,
            "candidate_bytes": 550,
        })
        stats = request_stats["compression"]
        self.assertEqual(stats["processed_pages"], 2)
        self.assertEqual(stats["used_candidate_count"], 1)
        self.assertEqual(stats["orig_bytes_total"], 1500)
        self.assertEqual(stats["result_bytes_total"], 1100)

    def test_conservative_defaults_and_invalid_value_bounds(self):
        compressor = ImageCompressor()
        self.assertTrue(compressor.enabled)
        self.assertEqual(compressor.quality, 65)
        self.assertEqual(compressor.speed, 5)
        self.assertEqual(compressor.min_savings_percent, 5.0)

        bounded = ImageCompressor(quality="bad", speed=99, min_savings_percent=-1)
        self.assertEqual(bounded.quality, 65)
        self.assertEqual(bounded.speed, 10)
        self.assertEqual(bounded.min_savings_percent, 0.0)

    def test_disabled_compression_preserves_original_bytes(self):
        compressor = ImageCompressor(enabled=False)
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "001.jpg"
            data = b"original-image-bytes"
            saved_path = compressor.save_page_bytes(data, path)
            self.assertEqual(saved_path, path)
            self.assertEqual(path.read_bytes(), data)
            self.assertFalse(path.with_name(path.name + ".part").exists())

    def test_invalid_image_falls_back_to_original_format(self):
        compressor = ImageCompressor(enabled=True)
        with tempfile.TemporaryDirectory() as temp_dir:
            path = Path(temp_dir) / "001.jpg"
            data = b"not-a-decodable-image"
            saved_path = compressor.save_page_bytes(data, path)
            self.assertEqual(saved_path, path)
            self.assertEqual(path.read_bytes(), data)
            self.assertFalse(path.with_suffix(".avif").exists())

    def test_existing_avif_satisfies_original_page_request(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            requested_path = Path(temp_dir) / "001.jpg"
            avif_path = requested_path.with_suffix(".avif")
            avif_path.write_bytes(b"existing-avif")
            self.assertEqual(ImageCompressor.find_existing_page_file(requested_path), avif_path)

    def test_first_page_prefers_avif_without_deleting_original(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            gallery_dir = Path(temp_dir) / "gallery"
            gallery_dir.mkdir()
            jpg_path = gallery_dir / "001.jpg"
            avif_path = gallery_dir / "001.avif"
            jpg_path.write_bytes(b"original-jpg")
            avif_path.write_bytes(b"compressed-avif")
            manager = FileManager(temp_dir)
            self.assertEqual(manager.first_page_path(gallery_dir), avif_path)
            self.assertTrue(jpg_path.exists())
    def test_lossy_avif_keeps_dimensions_when_encoder_is_available(self):
        try:
            from PIL import Image, features
        except ImportError:
            self.skipTest("Pillow is not installed in this development environment")
        if not features.check("avif"):
            self.skipTest("Pillow AVIF encoder is unavailable")

        image = Image.new("RGB", (640, 960), "white")
        source = BytesIO()
        image.save(source, format="BMP")
        compressor = ImageCompressor(quality=65, speed=8, min_savings_percent=5)
        with tempfile.TemporaryDirectory() as temp_dir:
            requested_path = Path(temp_dir) / "001.png"
            requested_path.write_bytes(b"existing-original")
            saved_path, stats = compressor.save_page_bytes_with_stats(source.getvalue(), requested_path)
            self.assertEqual(saved_path.suffix, ".avif")
            self.assertTrue(stats["used_candidate"])
            self.assertEqual(stats["orig_bytes"], len(source.getvalue()))
            self.assertEqual(stats["candidate_bytes"], saved_path.stat().st_size)
            self.assertLess(saved_path.stat().st_size, len(source.getvalue()) * 0.95)
            self.assertEqual(requested_path.read_bytes(), b"existing-original")
            with Image.open(saved_path) as result:
                self.assertEqual(result.size, (640, 960))
                self.assertEqual(result.format, "AVIF")

    def test_existing_avif_is_never_reencoded(self):
        try:
            from PIL import Image, features
        except ImportError:
            self.skipTest("Pillow is not installed in this development environment")
        if not features.check("avif"):
            self.skipTest("Pillow AVIF encoder is unavailable")

        source = BytesIO()
        Image.new("RGB", (80, 120), "navy").save(source, format="AVIF", quality=65)
        compressor = ImageCompressor(quality=65, speed=8, min_savings_percent=100)

        used, candidate, normal_stats = compressor._encode_one_page(source.getvalue())
        self.assertFalse(used)
        self.assertIsNone(candidate)
        self.assertEqual(normal_stats["src_format"], "AVIF")

        used, candidate, forced_stats = compressor._encode_one_page(
            source.getvalue(), force_candidate=True
        )
        self.assertFalse(used)
        self.assertIsNone(candidate)
        self.assertFalse(forced_stats["forced_candidate"])
        self.assertFalse(forced_stats["no_savings"])

    def test_force_candidates_workdir_scans_existing_webp_pages(self):
        try:
            from PIL import Image, features
        except ImportError:
            self.skipTest("Pillow is not installed in this development environment")
        if not features.check("avif"):
            self.skipTest("Pillow AVIF encoder is unavailable")

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            gallery_dir = root / "gallery"
            gallery_dir.mkdir()
            Image.effect_noise((600, 900), 100).convert("RGB").save(
                gallery_dir / "001.webp", format="WEBP", quality=95
            )
            Image.new("RGB", (60, 90), "blue").save(gallery_dir / "002.webp", format="WEBP")
            Image.new("RGB", (600, 900), "green").save(gallery_dir / "003.jpg", format="JPEG", quality=100)
            Image.new("RGB", (60, 90), "black").save(gallery_dir / "cover.webp", format="WEBP")
            conn = sqlite3.connect(root / "test.db")
            progress_events = []
            try:
                conn.execute(
                    "CREATE TABLE galleries (id INTEGER PRIMARY KEY, compression_status TEXT NOT NULL DEFAULT '', compression_info TEXT NOT NULL DEFAULT '', updated_at TEXT DEFAULT '')"
                )
                conn.execute("INSERT INTO galleries(id) VALUES (54)")
                conn.commit()
                status, info = ImageCompressor().compress_gallery_to_workdir(
                    54,
                    gallery_dir,
                    root / "compress_work",
                    force=True,
                    force_candidates=True,
                    progress_callback=progress_events.append,
                    db_conn=conn,
                )
            finally:
                conn.close()

            self.assertEqual(status, "user_review_required")
            self.assertEqual(info["total_pages"], 3)
            self.assertGreaterEqual(info["used_candidate_count"], 1)
            self.assertEqual(info["target_format"], "AVIF")
            self.assertTrue(
                any(
                    page["src_format"] == "WEBP" and page["used_candidate"]
                    for page in info["pages"]
                )
            )
            self.assertEqual(
                info["discarded_pages_count"],
                info["total_pages"] - info["used_candidate_count"],
            )
            self.assertGreater(info["savings_pct_overall"], 0)
            self.assertTrue(info["force_candidates"])
            self.assertEqual(
                len(list((root / "compress_work" / "54").glob("*.avif"))),
                info["used_candidate_count"],
            )
            self.assertEqual([event["current"] for event in progress_events], [0, 1, 2, 3])
            self.assertTrue(all(event["total"] == 3 for event in progress_events))

    def test_result_database_write_failure_is_not_reported_as_success(self):
        try:
            from PIL import Image, features
        except ImportError:
            self.skipTest("Pillow is not installed in this development environment")
        if not features.check("avif"):
            self.skipTest("Pillow AVIF encoder is unavailable")

        class FailingResultConnection:
            def __init__(self, connection):
                self.connection = connection

            def execute(self, sql, params=()):
                if "compression_info = ?" in sql:
                    raise sqlite3.OperationalError("simulated result write failure")
                return self.connection.execute(sql, params)

            def commit(self):
                return self.connection.commit()

            def rollback(self):
                return self.connection.rollback()

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            gallery_dir = root / "gallery"
            gallery_dir.mkdir()
            Image.new("RGB", (60, 90), "red").save(gallery_dir / "001.jpg", format="JPEG")
            conn = sqlite3.connect(root / "test.db")
            try:
                conn.execute(
                    "CREATE TABLE galleries (id INTEGER PRIMARY KEY, compression_status TEXT NOT NULL DEFAULT '', compression_info TEXT NOT NULL DEFAULT '', updated_at TEXT DEFAULT '')"
                )
                conn.execute("INSERT INTO galleries(id) VALUES (54)")
                conn.commit()
                with self.assertRaisesRegex(RuntimeError, "failed to persist compression result"):
                    ImageCompressor().compress_gallery_to_workdir(
                        54,
                        gallery_dir,
                        root / "compress_work",
                        force=True,
                        db_conn=FailingResultConnection(conn),
                    )
            finally:
                conn.close()


    def test_skipped_result_removes_workdir_and_keeps_only_savings(self):
        try:
            from PIL import Image, features
        except ImportError:
            self.skipTest("Pillow is not installed in this development environment")
        if not features.check("avif"):
            self.skipTest("Pillow AVIF encoder is unavailable")

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            gallery_dir = root / "gallery"
            gallery_dir.mkdir()
            Image.new("RGB", (80, 120), "navy").save(
                gallery_dir / "001.webp", format="WEBP", quality=88
            )
            conn = sqlite3.connect(root / "test.db")
            try:
                conn.execute(
                    "CREATE TABLE galleries (id INTEGER PRIMARY KEY, compression_status TEXT NOT NULL DEFAULT '', compression_info TEXT NOT NULL DEFAULT '', updated_at TEXT DEFAULT '')"
                )
                conn.execute(
                    "INSERT INTO galleries(id,compression_status) VALUES (24,'queued')"
                )
                conn.commit()
                status, info = ImageCompressor(
                    quality=65, speed=8, min_savings_percent=100
                ).compress_gallery_to_workdir(
                    24,
                    gallery_dir,
                    root / "compress_work",
                    force=True,
                    db_conn=conn,
                )
                self.assertEqual(status, "skipped")
                self.assertEqual(info["work_dir"], "")
                self.assertTrue(info["work_dir_cleaned"])
                self.assertFalse((root / "compress_work" / "24").exists())
                row = conn.execute(
                    "SELECT compression_status,compression_info,compression_savings_pct FROM galleries WHERE id=24"
                ).fetchone()
                self.assertEqual(row[0], "skipped")
                self.assertEqual(row[1], "")
                self.assertAlmostEqual(row[2], 0.0, places=2)
            finally:
                conn.close()

    def test_terminal_compression_info_migrates_to_savings_only(self):
        conn = sqlite3.connect(":memory:")
        try:
            conn.execute(
                "CREATE TABLE galleries (id INTEGER PRIMARY KEY, compression_status TEXT, compression_info TEXT)"
            )
            conn.execute(
                "INSERT INTO galleries VALUES (1,'applied',?)",
                (json.dumps({"savings_pct_overall": 37.25, "quality": 65, "pages": [{"name": "001.jpg"}]}),),
            )
            conn.execute(
                "INSERT INTO galleries VALUES (2,'user_review_required',?)",
                (json.dumps({"savings_pct_overall": 28.5, "pages": [{"name": "002.jpg"}]}),),
            )
            conn.execute("INSERT INTO galleries VALUES (3,'compressed','')")
            ensure_gallery_compression_columns_v1(conn)

            applied = conn.execute(
                "SELECT compression_info,compression_savings_pct FROM galleries WHERE id=1"
            ).fetchone()
            pending = conn.execute(
                "SELECT compression_info,compression_savings_pct FROM galleries WHERE id=2"
            ).fetchone()
            legacy_status = conn.execute(
                "SELECT compression_status FROM galleries WHERE id=3"
            ).fetchone()[0]
            self.assertEqual(applied, ("", 37.25))
            self.assertIn('"pages"', pending[0])
            self.assertIsNone(pending[1])
            self.assertEqual(legacy_status, "applied")
        finally:
            conn.close()

    def test_missing_avif_encoder_fails_before_clearing_existing_candidates(self):
        class MissingEncoderCompressor(ImageCompressor):
            def _ensure_pillow_avif(self):
                return False

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            gallery_dir = root / "gallery"
            gallery_dir.mkdir()
            (gallery_dir / "001.jpg").write_bytes(b"original")
            work_dir = root / "compress_work" / "54"
            work_dir.mkdir(parents=True)
            old_candidate = work_dir / "001.avif"
            old_summary = work_dir / "summary.json"
            old_candidate.write_bytes(b"old-candidate")
            old_summary.write_text('{"old": true}', encoding="utf-8")
            conn = sqlite3.connect(root / "test.db")
            try:
                conn.execute(
                    "CREATE TABLE galleries (id INTEGER PRIMARY KEY, compression_status TEXT NOT NULL DEFAULT '', compression_info TEXT NOT NULL DEFAULT '', updated_at TEXT DEFAULT '')"
                )
                conn.execute(
                    "INSERT INTO galleries(id,compression_status,compression_info) VALUES (54,'queued','old-info')"
                )
                conn.commit()
                with self.assertRaisesRegex(RuntimeError, "Pillow/AVIF"):
                    MissingEncoderCompressor().compress_gallery_to_workdir(
                        54,
                        gallery_dir,
                        root / "compress_work",
                        force=True,
                        db_conn=conn,
                    )
                self.assertEqual(old_candidate.read_bytes(), b"old-candidate")
                self.assertEqual(old_summary.read_text(encoding="utf-8"), '{"old": true}')
                row = conn.execute(
                    "SELECT compression_status,compression_info FROM galleries WHERE id=54"
                ).fetchone()
                self.assertEqual(row, ("queued", "old-info"))
            finally:
                conn.close()


if __name__ == "__main__":
    unittest.main()
