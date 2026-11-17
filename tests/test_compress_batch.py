import json
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from ehlib.cmd_compress_batch import _parse_ids, _reset_queued, main


class CompressBatchTests(unittest.TestCase):
    def test_parse_ids_deduplicates_and_rejects_empty(self):
        self.assertEqual(_parse_ids("3, 5,3,9"), [3, 5, 9])
        with self.assertRaises(ValueError):
            _parse_ids(" , ")
        with self.assertRaises(ValueError):
            _parse_ids("1,0")

    def test_reset_queued_only_changes_queued_rows(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            db_path = Path(temp_dir) / "test.db"
            conn = sqlite3.connect(db_path)
            conn.execute(
                "CREATE TABLE galleries (id INTEGER PRIMARY KEY, compression_status TEXT, updated_at TEXT)"
            )
            conn.executemany(
                "INSERT INTO galleries(id,compression_status,updated_at) VALUES (?,?, '')",
                [(1, "queued"), (2, "compressing"), (3, "queued")],
            )
            conn.commit()
            self.assertEqual(_reset_queued(conn, [1, 2]), 1)
            statuses = dict(conn.execute("SELECT id,compression_status FROM galleries"))
            self.assertEqual(statuses, {1: "", 2: "compressing", 3: "queued"})
            conn.close()

    def test_main_processes_queue_sequentially_and_writes_summary(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            db_path = root / "test.db"
            progress_path = root / "progress" / "compress_batch.json"
            conn = sqlite3.connect(db_path)
            conn.execute(
                """CREATE TABLE galleries (
                    id INTEGER PRIMARY KEY,
                    title TEXT,
                    title_jp TEXT,
                    total_pages INTEGER,
                    compression_status TEXT NOT NULL DEFAULT '',
                    compression_info TEXT NOT NULL DEFAULT '',
                    updated_at TEXT DEFAULT ''
                )"""
            )
            conn.executemany(
                "INSERT INTO galleries(id,title,total_pages,compression_status) VALUES (?,?,?,?)",
                [(1, "one", 3, "queued"), (2, "two", 4, "queued")],
            )
            conn.commit()
            conn.close()

            seen = []

            def fake_compress(args):
                gallery_id = int(args[args.index("--gallery-id") + 1])
                seen.append(gallery_id)
                status = "user_review_required" if gallery_id == 1 else "skipped"
                fake_conn = sqlite3.connect(db_path)
                fake_conn.execute(
                    "UPDATE galleries SET compression_status=? WHERE id=?",
                    (status, gallery_id),
                )
                fake_conn.commit()
                fake_conn.close()
                return 0

            with patch("ehlib.cmd_compress_batch.compress_one", side_effect=fake_compress):
                rc = main([
                    "--gallery-ids", "1,2",
                    "--db-path", str(db_path),
                    "--work-root", str(root / "work"),
                    "--progress-file", str(progress_path),
                ])

            self.assertEqual(rc, 0)
            self.assertEqual(seen, [1, 2])
            summary = json.loads(progress_path.read_text(encoding="utf-8"))
            self.assertEqual(summary["status"], "completed")
            self.assertEqual(summary["finished_count"], 2)
            self.assertEqual(summary["review_count"], 1)
            self.assertEqual(summary["skipped_count"], 1)
            self.assertEqual(summary["failed_count"], 0)

    def test_main_releases_remaining_queue_after_exception(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            db_path = root / "test.db"
            progress_path = root / "progress.json"
            conn = sqlite3.connect(db_path)
            conn.execute(
                """CREATE TABLE galleries (
                    id INTEGER PRIMARY KEY, title TEXT, title_jp TEXT, total_pages INTEGER,
                    compression_status TEXT NOT NULL DEFAULT '',
                    compression_info TEXT NOT NULL DEFAULT '', updated_at TEXT DEFAULT ''
                )"""
            )
            conn.executemany(
                "INSERT INTO galleries(id,title,total_pages,compression_status) VALUES (?,?,?,?)",
                [(1, "one", 1, "queued"), (2, "two", 1, "queued")],
            )
            conn.commit()
            conn.close()

            with patch("ehlib.cmd_compress_batch.compress_one", side_effect=RuntimeError("boom")):
                rc = main([
                    "--gallery-ids", "1,2",
                    "--db-path", str(db_path),
                    "--work-root", str(root / "work"),
                    "--progress-file", str(progress_path),
                ])

            self.assertEqual(rc, 3)
            conn = sqlite3.connect(db_path)
            statuses = dict(conn.execute("SELECT id,compression_status FROM galleries"))
            conn.close()
            self.assertEqual(statuses, {1: "", 2: ""})
            summary = json.loads(progress_path.read_text(encoding="utf-8"))
            self.assertEqual(summary["status"], "failed")
            self.assertEqual(summary["reset_queued_count"], 2)


if __name__ == "__main__":
    unittest.main()
