import argparse
import unittest
from unittest.mock import AsyncMock, patch

from ehlib.main import cmd_crawl_worker


class _EmptyCrawlQueue:
    def __init__(self):
        self.claim_calls = 0

    async def recover_interrupted_crawl_jobs(self):
        return None

    async def claim_next_crawl_job(self):
        self.claim_calls += 1
        return None


class CrawlWorkerTests(unittest.IsolatedAsyncioTestCase):
    async def test_once_worker_exits_immediately_when_queue_is_empty(self):
        db = _EmptyCrawlQueue()
        with patch("ehlib.main.sleep", new_callable=AsyncMock) as mocked_sleep:
            await cmd_crawl_worker(argparse.Namespace(once=True), None, db)

        self.assertEqual(db.claim_calls, 1)
        mocked_sleep.assert_not_awaited()

    async def test_web_worker_exits_after_queue_stays_empty(self):
        db = _EmptyCrawlQueue()
        with (
            patch("ehlib.main.time.monotonic", side_effect=[10.0, 12.0, 16.0]),
            patch("ehlib.main.sleep", new_callable=AsyncMock) as mocked_sleep,
        ):
            await cmd_crawl_worker(
                argparse.Namespace(once=False, idle_seconds=6.0),
                None,
                db,
            )

        self.assertEqual(db.claim_calls, 3)
        self.assertEqual(mocked_sleep.await_count, 2)


if __name__ == "__main__":
    unittest.main()
