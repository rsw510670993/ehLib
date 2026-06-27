import time
import httpx

from ehlib.config import Config
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)


class SessionManager:
    def __init__(self, config: Config):
        self._config = config
        self._client: httpx.AsyncClient | None = None

    async def get_client(self, source: str) -> httpx.AsyncClient:
        if self._client is None or self._client.is_closed:
            cookies = self._load_cookies(source)
            headers = {
                "User-Agent": self._config.request.get("user_agent", ""),
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "en-US,en;q=0.9,ja;q=0.8",
                "Accept-Encoding": "gzip, deflate, br",
            }
            self._client = httpx.AsyncClient(
                cookies=cookies,
                headers=headers,
                timeout=httpx.Timeout(30.0),
                follow_redirects=True,
            )
        return self._client

    async def close(self) -> None:
        if self._client and not self._client.is_closed:
            await self._client.aclose()

    async def fetch(self, source: str, url: str, method: str = "GET", **kwargs) -> httpx.Response:
        client = await self.get_client(source)
        delay = self._config.request.get("delay_between_requests", 1.5)
        await self._rate_limit(source, delay)
        if method.upper() == "POST":
            response = await client.post(url, **kwargs)
        else:
            response = await client.get(url, **kwargs)
        return response

    def is_cloudflare_blocked(self, response: httpx.Response) -> bool:
        if response.status_code in (403, 503):
            text = response.text.lower()
            cf_markers = [
                "cloudflare",
                "cf-browser-verification",
                "cf-chl",
                "cf_captcha",
                "_cf_chl",
                "jschl",
                "attention required",
                "just a moment",
                "checking your browser",
            ]
            if any(marker in text for marker in cf_markers):
                return True
        return False

    def _load_cookies(self, source: str) -> dict[str, str]:
        cookies = {}
        source_cookies = self._config.cookies.get(source, {})
        for key, value in source_cookies.items():
            if value:
                cookies[key] = value
        return cookies

    async def _rate_limit(self, source: str, delay: float) -> None:
        now = time.monotonic()
        key = f"_last_request_{source}"
        if not hasattr(self, "_last_requests"):
            self._last_requests: dict[str, float] = {}
        if key in self._last_requests:
            elapsed = now - self._last_requests[key]
            if elapsed < delay:
                await self._async_sleep(delay - elapsed)
        self._last_requests[key] = time.monotonic()

    @staticmethod
    async def _async_sleep(seconds: float) -> None:
        import asyncio
        await asyncio.sleep(seconds)
