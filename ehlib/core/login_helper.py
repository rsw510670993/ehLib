import asyncio
import json
import os
import sys
import time
from pathlib import Path

from ehlib.config import Config
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

REQUIRED_COOKIES = {
    "nhentai": ["cf_clearance"],
    "exhentai": ["ipb_member_id", "ipb_pass_hash", "cf_clearance"],
}

SITES = {
    "nhentai": "https://nhentai.net",
    "exhentai": "https://exhentai.org",
}


def _status_file_path(source: str) -> str:
    root = Path(__file__).resolve().parent.parent.parent
    return str(root / "data" / f"login_{source}.json")


def write_status(source: str, status: str, message: str = "", cookies: dict | None = None) -> None:
    path = _status_file_path(source)
    Path(path).parent.mkdir(parents=True, exist_ok=True)
    data = {
        "source": source,
        "status": status,
        "message": message,
        "cookies": cookies or {},
        "updated_at": time.time(),
    }
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False)


class LoginHelper:
    def __init__(self, source: str, config: Config):
        if source not in SITES:
            raise ValueError(f"Unknown source: {source}. Choose from: {', '.join(SITES.keys())}")
        self._source = source
        self._config = config
        self._url = SITES[source]
        self._required = REQUIRED_COOKIES[source]
        self._browser = None

    async def run(self) -> dict:
        write_status(self._source, "starting", "Starting browser...")

        try:
            import nodriver as uc
        except ImportError:
            write_status(self._source, "error", "nodriver not installed. Run: pip install nodriver")
            raise

        browser = await uc.start(
            headless=False,
            browser_args=[
                "--no-sandbox",
                "--disable-gpu",
                "--disable-dev-shm-usage",
                "--disable-blink-features=AutomationControlled",
                "--window-size=1280,800",
            ],
        )
        self._browser = browser

        try:
            page = await browser.get(self._url)
            write_status(self._source, "navigating", f"Navigated to {self._url}")

            found_cookies = {}
            poll_start = time.time()
            timeout = 300

            while True:
                elapsed = time.time() - poll_start
                if elapsed > timeout:
                    write_status(
                        self._source, "timeout",
                        f"Login timed out after {timeout}s. You may need to refresh the page and try again.",
                        found_cookies,
                    )
                    return {"status": "timeout", "cookies": found_cookies}

                cookies = await browser.cookies.get_all()
                cookie_dict = {}
                for c in cookies:
                    cookie_dict[c.name] = c.value

                found_cookies = {
                    k: v for k, v in cookie_dict.items() if k in self._required
                }

                status = "waiting"
                message = f"Waiting for login... Found {len(found_cookies)}/{len(self._required)} cookies"
                write_status(self._source, status, message, found_cookies)

                if all(k in cookie_dict and cookie_dict[k] for k in self._required):
                    write_status(
                        self._source, "saving",
                        "All cookies acquired, saving to config...",
                        found_cookies,
                    )

                    target_section = {}
                    for key, val in cookie_dict.items():
                        if key in self._required:
                            target_section[key] = val
                    self._config.set("cookies", self._source, **target_section)
                    self._config.save()

                    write_status(
                        self._source, "completed",
                        f"Login successful! {len(self._required)} cookies saved to config.yaml",
                        found_cookies,
                    )
                    return {"status": "completed", "cookies": found_cookies}

                await asyncio.sleep(2)

        except Exception as e:
            write_status(self._source, "error", str(e))
            logger.error("Login helper error: %s", e)
            raise
        finally:
            try:
                browser.stop()
            except Exception:
                pass

    async def sync_now(self) -> dict:
        if not self._browser:
            return {"status": "error", "message": "Browser not running. Start login helper first."}

        cookies = await self._browser.cookies.get_all()
        cookie_dict = {}
        for c in cookies:
            cookie_dict[c.name] = c.value

        found = {k: v for k, v in cookie_dict.items() if k in self._required}

        if all(k in cookie_dict and cookie_dict[k] for k in self._required):
            target_section = {}
            for key, val in cookie_dict.items():
                if key in self._required:
                    target_section[key] = val
            self._config.set("cookies", self._source, **target_section)
            self._config.save()
            write_status(self._source, "completed", "Manually synced cookies!", found)
            return {"status": "completed", "cookies": found}
        else:
            missing = [k for k in self._required if k not in cookie_dict or not cookie_dict[k]]
            write_status(self._source, "waiting", f"Missing: {', '.join(missing)}", found)
            return {"status": "waiting", "cookies": found, "missing": missing}


async def cmd_login_helper(source: str) -> None:
    config = Config()
    helper = LoginHelper(source, config)
    try:
        result = await helper.run()
        print(json.dumps(result, ensure_ascii=False))
    except Exception as e:
        print(json.dumps({"status": "error", "error": str(e)}, ensure_ascii=False))
        sys.exit(1)


async def cmd_sync_now(source: str) -> None:
    config = Config()
    try:
        import nodriver as uc
        browser = await uc.start(headless=False)
        try:
            page = await browser.get(SITES[source])
            await asyncio.sleep(3)
            cookies = await browser.cookies.get_all()
            cookie_dict = {}
            for c in cookies:
                cookie_dict[c.name] = c.value

            required = REQUIRED_COOKIES[source]
            found = {k: v for k, v in cookie_dict.items() if k in required}

            if all(k in cookie_dict and cookie_dict[k] for k in required):
                target_section = {}
                for key, val in cookie_dict.items():
                    if key in required:
                        target_section[key] = val
                for key, val in target_section.items():
                    config.set("cookies", source, key, value=val)
                config.save()
                print(json.dumps({"status": "completed", "cookies": found}, ensure_ascii=False))
            else:
                missing = [k for k in required if k not in cookie_dict or not cookie_dict[k]]
                print(json.dumps({"status": "waiting", "cookies": found, "missing": missing}, ensure_ascii=False))
        finally:
            browser.stop()
    except ImportError:
        print(json.dumps({"status": "error", "error": "nodriver not installed"}, ensure_ascii=False))
        sys.exit(1)


def read_login_status(source: str) -> dict:
    path = _status_file_path(source)
    if not os.path.exists(path):
        return {"source": source, "status": "idle", "message": "Not started", "cookies": {}}
    with open(path, "r", encoding="utf-8") as f:
        return json.load(f)


def clear_login_status(source: str) -> None:
    path = _status_file_path(source)
    if os.path.exists(path):
        os.remove(path)
