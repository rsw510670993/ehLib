import sqlite3
import tempfile
import unittest
from pathlib import Path

from PIL import Image, features

from ehlib.cmd_compress_thumbs import compress_thumbnails


class CompressThumbsTests(unittest.TestCase):
    @unittest.skipUnless(features.check("avif"), "Pillow AVIF encoder unavailable")
    def test_converts_smaller_thumb_and_updates_database_path(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            thumbs = root / "data" / "thumbs" / "exhentai"
            thumbs.mkdir(parents=True)
            source_path = thumbs / "123_token.webp"
            Image.effect_noise((250, 350), 70).convert("RGB").save(
                source_path,
                format="WEBP",
                quality=95,
            )
            original_size = source_path.stat().st_size

            db_path = root / "data" / "ehlib.db"
            conn = sqlite3.connect(db_path)
            conn.execute(
                "CREATE TABLE search_cache ("
                "source TEXT, source_id TEXT, thumb_path TEXT, "
                "PRIMARY KEY(source,source_id))"
            )
            conn.execute(
                "INSERT INTO search_cache(source,source_id,thumb_path) VALUES (?,?,?)",
                ("exhentai", "123/token", str(source_path.resolve())),
            )
            conn.commit()
            conn.close()

            summary = compress_thumbnails(
                root / "data" / "thumbs",
                db_path,
                quality=65,
                speed=5,
                min_savings_percent=5,
            )

            target_path = source_path.with_suffix(".avif")
            self.assertEqual(summary["converted"], 1)
            self.assertEqual(summary["updated_rows"], 1)
            self.assertFalse(source_path.exists())
            self.assertTrue(target_path.is_file())
            self.assertLess(target_path.stat().st_size, original_size)

            conn = sqlite3.connect(db_path)
            stored_path = conn.execute(
                "SELECT thumb_path FROM search_cache WHERE source=? AND source_id=?",
                ("exhentai", "123/token"),
            ).fetchone()[0]
            conn.close()
            self.assertEqual(stored_path, str(target_path.resolve()))

    @unittest.skipUnless(features.check("avif"), "Pillow AVIF encoder unavailable")
    def test_dry_run_does_not_change_file_or_database(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            thumbs = root / "data" / "thumbs" / "exhentai"
            thumbs.mkdir(parents=True)
            source_path = thumbs / "456_token.webp"
            Image.effect_noise((250, 350), 60).convert("RGB").save(
                source_path,
                format="WEBP",
                quality=95,
            )

            db_path = root / "data" / "ehlib.db"
            conn = sqlite3.connect(db_path)
            conn.execute(
                "CREATE TABLE search_cache (source TEXT, source_id TEXT, thumb_path TEXT)"
            )
            conn.execute(
                "INSERT INTO search_cache(source,source_id,thumb_path) VALUES (?,?,?)",
                ("exhentai", "456/token", str(source_path.resolve())),
            )
            conn.commit()
            conn.close()

            summary = compress_thumbnails(
                root / "data" / "thumbs",
                db_path,
                dry_run=True,
            )

            self.assertEqual(summary["converted"], 1)
            self.assertTrue(source_path.is_file())
            self.assertFalse(source_path.with_suffix(".avif").exists())
            conn = sqlite3.connect(db_path)
            stored_path = conn.execute("SELECT thumb_path FROM search_cache").fetchone()[0]
            conn.close()
            self.assertEqual(stored_path, str(source_path.resolve()))


if __name__ == "__main__":
    unittest.main()
