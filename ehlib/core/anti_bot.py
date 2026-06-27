import asyncio

from ehlib.config import Config
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)


class AntiBotBrowser:
    def __init__(self, config: Config):
        self._config = config
        self._headless = config.browser.get("headless", False)
        self._browser = None

    async def fetch_via_browser(self, url: str) -> str:
        try:
            import nodriver as uc
        except ImportError:
            logger.error("nodriver not installed. Run: pip install nodriver")
            raise

        browser = await uc.start(
            headless=self._headless,
            browser_args=[
                "--no-sandbox",
                "--disable-gpu",
                "--disable-dev-shm-usage",
                "--disable-blink-features=AutomationControlled",
            ],
        )
        self._browser = browser

        try:
            page = await browser.get(url)
            await asyncio.sleep(3)

            content = await page.get_content()
            page_url = await page.evaluate("document.location.href")

            if self._contains_cf_challenge(content):
                logger.info("Cloudflare challenge detected, waiting for resolution...")
                await asyncio.sleep(5)
                content = await page.get_content()

            cookies = await browser.cookies.get_all()
            await self._save_cf_clearance(cookies)

            return content
        finally:
            browser.stop()

    async def close(self) -> None:
        if self._browser:
            try:
                self._browser.stop()
            except Exception:
                pass

    def _contains_cf_challenge(self, html: str) -> bool:
        markers = [
            "cf-browser-verification",
            "_cf_chl_opt",
            "challenge-platform",
            "turnstile",
        ]
        return any(m in html.lower() for m in markers)

    async def _save_cf_clearance(self, cookies: list) -> None:
        for cookie in cookies:
            if cookie.name == "cf_clearance":
                self._config.set("cookies", "nhentai", "cf_clearance", value=cookie.value)
                self._config.set("cookies", "exhentai", "cf_clearance", value=cookie.value)
                self._config.save()
                logger.info("Saved new cf_clearance cookie")
