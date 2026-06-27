import asyncio
import base64
import json

from ehlib.config import Config
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

_CF_MARKERS = [
    "cf-browser-verification", "_cf_chl_opt",
    "challenge-platform", "turnstile",
    "just a moment", "checking your browser",
]


class AntiBotBrowser:
    def __init__(self, config: Config):
        self._config = config
        self._headless = config.browser.get("headless", False)
        self._browser = None

    async def _start_browser(self):
        import nodriver as uc
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
        return browser

    async def _stop_browser(self):
        if self._browser:
            try:
                self._browser.stop()
            except Exception:
                pass
            self._browser = None

    async def fetch_api_via_browser(self, url: str) -> dict:
        browser = await self._start_browser()
        try:
            page = await browser.get(url)
            await asyncio.sleep(2)

            data = None
            for i in range(30):
                text = await page.get_content()
                try:
                    data = json.loads(text)
                    logger.info("API data fetched via browser (attempt %d)", i + 1)
                    break
                except json.JSONDecodeError:
                    if any(m in text.lower() for m in _CF_MARKERS):
                        logger.info("Cloudflare challenge ongoing, waiting...")
                        await asyncio.sleep(3)
                    else:
                        await asyncio.sleep(1)

            if data is None:
                text = await page.get_content()
                try:
                    data = json.loads(text)
                except json.JSONDecodeError:
                    raise RuntimeError(
                        f"Browser could not fetch API JSON from {url}. "
                        f"Got HTML (length={len(text)}): {text[:150]}"
                    )

            cookies = await browser.cookies.get_all()
            await self._save_cf_clearance(cookies)
            return data
        finally:
            await self._stop_browser()

    async def fetch_images_via_browser(self, urls: list[str]) -> dict[int, bytes]:
        if not urls:
            return {}

        browser = await self._start_browser()
        page = await browser.get("about:blank")
        results: dict[int, bytes] = {}

        for i, img_url in enumerate(urls):
            label = f"image {i + 1}/{len(urls)}"
            retries = 3
            success = False

            for attempt in range(retries):
                try:
                    b64_data = await page.evaluate(
                        f"""
                        (async () => {{
                            try {{
                                const resp = await fetch('{img_url}', {{
                                    credentials: 'include',
                                    headers: {{ 'Accept': 'image/webp,image/apng,image/*,*/*;q=0.8' }}
                                }});
                                if (!resp.ok) return 'ERROR:' + resp.status;
                                const blob = await resp.blob();
                                return new Promise((resolve) => {{
                                    const reader = new FileReader();
                                    reader.onload = () => resolve(reader.result);
                                    reader.onerror = () => resolve('ERROR:reader');
                                    reader.readAsDataURL(blob);
                                }});
                            }} catch(e) {{
                                return 'ERROR:' + e.message;
                            }}
                        }})()
                        """
                    )

                    if b64_data and isinstance(b64_data, str) and b64_data.startswith("data:"):
                        _, encoded = b64_data.split(",", 1)
                        results[i] = base64.b64decode(encoded)
                        logger.info("Downloaded %s via browser (%d bytes)", label, len(results[i]))
                        success = True
                        break
                    elif b64_data and b64_data.startswith("ERROR:403"):
                        logger.info("Got 403 for %s (attempt %d/%d), retrying...", label, attempt + 1, retries)
                        await asyncio.sleep(2)
                    elif b64_data and b64_data.startswith("ERROR:"):
                        logger.warning("%s error for %s (attempt %d/%d)", b64_data, label, attempt + 1, retries)
                        await asyncio.sleep(2)
                    else:
                        logger.warning("No data for %s (attempt %d/%d), retrying...", label, attempt + 1, retries)
                        await asyncio.sleep(2)

                except Exception as e:
                    logger.warning("Exception for %s (attempt %d/%d): %s", label, attempt + 1, retries, e)
                    await asyncio.sleep(2)

            if not success:
                logger.error("Failed to download %s after %d attempts", label, retries)

        await self._stop_browser()
        return results

    async def close(self) -> None:
        await self._stop_browser()

    async def _save_cf_clearance(self, cookies: list) -> None:
        for cookie in cookies:
            if cookie.name == "cf_clearance":
                self._config.set("cookies", "nhentai", "cf_clearance", value=cookie.value)
                self._config.set("cookies", "exhentai", "cf_clearance", value=cookie.value)
                self._config.save()
                logger.info("Saved new cf_clearance cookie")
