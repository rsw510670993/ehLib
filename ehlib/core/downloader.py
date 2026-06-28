import asyncio
from datetime import datetime
from pathlib import Path

import httpx

from ehlib.config import Config
from ehlib.core.anti_bot import AntiBotBrowser
from ehlib.core.session_manager import SessionManager
from ehlib.models.database import Database
from ehlib.models.schemas import Gallery
from ehlib.sites.nhentai import NhentaiSite
from ehlib.sites.exhentai import ExhentaiSite
from ehlib.sites.base import SiteBase
from ehlib.storage.file_manager import FileManager
from ehlib.utils.logger import get_logger
from ehlib.utils.progress import write_progress, remove_progress

logger = get_logger(__name__)


class Downloader:
    def __init__(self, config: Config, db: Database):
        self._config = config
        self._db = db
        self._session = SessionManager(config)
        self._file_manager = FileManager(str(config.download_path))
        self._sites: dict[str, SiteBase] = {
            "nhentai": NhentaiSite(config, self._session),
            "exhentai": ExhentaiSite(config, self._session),
        }
        self._max_concurrent = config.download.get("max_concurrent", 3)
        self._retry_times = config.download.get("retry_times", 3)
        self._retry_delay = config.download.get("retry_delay", 5)
        self._semaphore = asyncio.Semaphore(self._max_concurrent)

    async def download(self, source: str, identifier: str, force: bool = False) -> Gallery:
        site = self._sites.get(source)
        if not site:
            raise ValueError(f"Unknown source: {source}. Use 'nhentai' or 'exhentai'.")

        if source == "exhentai" and "/" not in identifier:
            raise ValueError("exhentai identifier must be in format 'gid/token'")

        gallery = await self._fetch_metadata(site, identifier)

        existing = await self._db.get_gallery(source, gallery.source_id)
        if existing:
            if force:
                logger.warning("Force re-download: deleting local data for %s/%s", source, gallery.source_id)
                if existing.local_path:
                    self._file_manager.delete_gallery_dir(Path(existing.local_path))
                await self._db.delete_gallery(source, gallery.source_id)
            elif existing.is_complete:
                logger.info("Gallery %s/%s already downloaded completely, updating metadata.", source, gallery.source_id)
                gallery.id = existing.id
                gallery.local_path = existing.local_path
                gallery.file_size = existing.file_size
                gallery.is_complete = True
                gallery.downloaded_at = existing.downloaded_at
                await self._db.save_gallery(gallery)
                return gallery

        gallery_dir = self._file_manager.create_gallery_dir(source, gallery.source_id, gallery.title)
        gallery.local_path = str(gallery_dir)

        logger.info("Downloading [%s] %s (%d pages)", source, gallery.source_id, gallery.total_pages)
        write_progress(source, gallery.source_id, gallery.title, gallery.total_pages, 0, "downloading")

        try:
            await self._download_cover(gallery, gallery_dir)
            await self._download_pages(gallery, gallery_dir)
        except Exception:
            remove_progress(source, gallery.source_id)
            raise

        self._log_request_summary(gallery)

        gallery.file_size = self._file_manager.get_dir_size(gallery_dir)
        gallery.is_complete = True
        gallery.downloaded_at = datetime.now().isoformat()
        gallery.updated_at = datetime.now().isoformat()

        await self._db.save_gallery(gallery)
        self._save_metadata_file(gallery, gallery_dir)
        remove_progress(source, gallery.source_id)

        logger.info(
            "Download complete: [%s] %s (%d pages)",
            source, gallery.source_id, gallery.total_pages,
        )
        return gallery

    async def download_batch(self, urls: list[str], force: bool = False) -> list[Gallery]:
        tasks = []
        for url in urls:
            url = url.strip()
            if not url:
                continue
            source, identifier = self._resolve_url(url)
            if source and identifier:
                tasks.append(self.download(source, identifier, force=force))
        if not tasks:
            return []
        results = await asyncio.gather(*tasks, return_exceptions=True)
        galleries = []
        for i, result in enumerate(results):
            if isinstance(result, Exception):
                logger.error("Batch download error for %s: %s", urls[i], result)
            else:
                galleries.append(result)
        return galleries

    async def retry_incomplete(self) -> list[Gallery]:
        incomplete = await self._db.get_incomplete_downloads()
        logger.info("Found %d incomplete downloads to retry", len(incomplete))
        results = []
        for gallery in incomplete:
            try:
                result = await self.download(gallery.source, gallery.source_id, force=True)
                results.append(result)
            except Exception as e:
                logger.error("Retry failed for %s/%s: %s", gallery.source, gallery.source_id, e)
        return results

    def _resolve_url(self, url: str) -> tuple[str | None, str | None]:
        from ehlib.utils.helpers import parse_nhentai_url, parse_exhentai_url
        nh_id = parse_nhentai_url(url)
        if nh_id:
            return "nhentai", nh_id
        eh_result = parse_exhentai_url(url)
        if eh_result:
            return "exhentai", f"{eh_result[0]}/{eh_result[1]}"
        logger.warning("Cannot parse URL: %s", url)
        return None, None

    async def _fetch_metadata(self, site: SiteBase, identifier: str) -> Gallery:
        try:
            gallery = await site.fetch_gallery(identifier)
            return gallery
        except (httpx.HTTPStatusError, RuntimeError) as e:
            logger.warning("Cookie/fetch mode failed: %s. Trying browser fallback...", e)
            return await self._fetch_metadata_browser(site, identifier)

    async def _fetch_metadata_browser(self, site: SiteBase, identifier: str) -> Gallery:
        if site.name == "nhentai":
            url = f"https://nhentai.net/api/v2/galleries/{identifier}"
            browser = AntiBotBrowser(self._config)
            try:
                data = await browser.fetch_api_via_browser(url)
                gallery = site._parse_gallery_detail(data)
                gallery.request_stats = {
                    "metadata_requests": 0,
                    "cover_requests": 0,
                    "image_file_requests": 0,
                    "browser_metadata_requests": 1,
                    "browser_image_requests": 0,
                }
                return gallery
            finally:
                await browser.close()
        else:
            gid, token = identifier.split("/", 1)
            url = f"https://exhentai.org/g/{gid}/{token}/"
            import nodriver as uc
            ex_browser = await uc.start(
                headless=not self._config.browser.get("headless", False),
                browser_args=["--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage"],
            )
            try:
                page = await ex_browser.get(url)
                await asyncio.sleep(3)
                content = await page.get_content()
                return site._parse_html(content, identifier)
            finally:
                try:
                    ex_browser.stop()
                except Exception:
                    pass

    async def _download_cover(self, gallery: Gallery, gallery_dir: Path) -> None:
        if not gallery.cover_url:
            return
        cover_path = self._file_manager.cover_path(gallery_dir, gallery.cover_url)
        gallery.cover_path = str(cover_path)
        if cover_path.exists() and cover_path.stat().st_size > 0:
            return
        stats = self._ensure_request_stats(gallery)
        await self._download_file(
            gallery.source,
            gallery.cover_url,
            cover_path,
            "cover",
            stats=stats,
            stats_key="cover_requests",
        )

    async def _download_pages(self, gallery: Gallery, gallery_dir: Path) -> None:
        urls = gallery.page_urls
        if not urls:
            logger.error("No page URLs for gallery %s", gallery.source_id)
            return

        if gallery.source == "exhentai":
            await self._download_exhentai_display_pages(gallery, gallery_dir, urls)
            return

        failed_pages = await self._download_pages_httpx(gallery, gallery_dir, urls)
        if not failed_pages:
            return

        failed_list = ", ".join(str(page_num) for page_num, _ in failed_pages[:10])
        logger.warning(
            "httpx page download incomplete for %s, failed pages: %s. Trying browser fallback...",
            gallery.source_id,
            failed_list,
        )
        await self._download_pages_browser(gallery, gallery_dir, failed_pages)

    async def _download_exhentai_display_pages(self, gallery: Gallery, gallery_dir: Path, urls: list[str]) -> None:
        site = self._sites.get("exhentai")
        if not isinstance(site, ExhentaiSite):
            raise RuntimeError("Exhentai site handler is unavailable")

        stats = self._ensure_request_stats(gallery)
        failed_pages: list[int] = []

        for i, image_page_url in enumerate(urls):
            page_num = i + 1
            try:
                stats["image_page_requests"] += 1
                display_url = await site.resolve_display_image_url(image_page_url)
                ext = self._extract_ext(display_url)
                page_path = self._file_manager.page_path(gallery_dir, page_num, ext)
                await self._download_file(
                    "exhentai",
                    display_url,
                    page_path,
                    str(page_num),
                    stats=stats,
                    stats_key="image_file_requests",
                )
                write_progress(
                    gallery.source,
                    gallery.source_id,
                    gallery.title,
                    gallery.total_pages,
                    page_num,
                    "downloading",
                    f"{page_num}/{gallery.total_pages}",
                )
            except Exception as e:
                failed_pages.append(page_num)
                if self._should_abort_exhentai_download(e):
                    raise RuntimeError(
                        f"Exhentai request aborted at page {page_num}: {e}"
                    ) from e
                logger.warning("Exhentai display page %d failed: %s", page_num, e)

        if failed_pages:
            raise RuntimeError(
                f"Exhentai download incomplete, failed pages: {', '.join(map(str, failed_pages[:10]))}"
            )

    async def _download_pages_httpx(
        self,
        gallery: Gallery,
        gallery_dir: Path,
        urls: list[str],
    ) -> list[tuple[int, str]]:
        stats = self._ensure_request_stats(gallery)
        tasks = []
        for i, page_url in enumerate(urls):
            page_num = i + 1
            ext = self._extract_ext(page_url)
            page_path = self._file_manager.page_path(gallery_dir, page_num, ext)
            tasks.append(
                self._download_with_semaphore(
                    gallery.source,
                    page_url,
                    page_path,
                    str(page_num),
                    stats=stats,
                    stats_key="image_file_requests",
                )
            )

        results = await asyncio.gather(*tasks, return_exceptions=True)
        failed_pages: list[tuple[int, str]] = []
        blocking_errors = 0
        for i, result in enumerate(results):
            if not isinstance(result, Exception):
                continue
            page_num = i + 1
            failed_pages.append((page_num, urls[i]))
            if self._should_abort_nhentai_httpx(result):
                blocking_errors += 1

        if failed_pages and blocking_errors == len(failed_pages):
            logger.warning(
                "nhentai httpx requests look blocked for %s, switching failed pages to browser fallback",
                gallery.source_id,
            )

        return failed_pages

    async def _download_pages_browser(
        self,
        gallery: Gallery,
        gallery_dir: Path,
        page_items: list[tuple[int, str]],
    ) -> None:
        logger.info("Starting browser-based download for %d pages", len(page_items))
        browser = AntiBotBrowser(self._config)
        stats = self._ensure_request_stats(gallery)
        try:
            urls = [url for _, url in page_items]
            stats["browser_image_requests"] = stats.get("browser_image_requests", 0) + len(urls)
            results = await browser.fetch_images_via_browser(urls)
            for idx, data in results.items():
                page_num, page_url = page_items[idx]
                ext = self._extract_ext(page_url)
                page_path = self._file_manager.page_path(gallery_dir, page_num, ext)
                page_path.parent.mkdir(parents=True, exist_ok=True)
                page_path.write_bytes(data)

            logger.info("Browser downloaded %d/%d pages", len(results), len(urls))
            if len(results) != len(urls):
                failed_pages = [
                    str(page_items[idx][0])
                    for idx in range(len(urls))
                    if idx not in results
                ]
                raise RuntimeError(
                    f"Browser fallback incomplete, failed pages: {', '.join(failed_pages[:10])}"
                )
        finally:
            await browser.close()

    async def _download_with_semaphore(
        self,
        source: str,
        url: str,
        path: Path,
        label: str,
        *,
        stats: dict[str, int] | None = None,
        stats_key: str | None = None,
    ) -> None:
        async with self._semaphore:
            await self._download_file(
                source,
                url,
                path,
                label,
                stats=stats,
                stats_key=stats_key,
            )

    async def _download_file(
        self,
        source: str,
        url: str,
        path: Path,
        label: str,
        *,
        stats: dict[str, int] | None = None,
        stats_key: str | None = None,
    ) -> None:
        for attempt in range(self._retry_times):
            try:
                if path.exists() and path.stat().st_size > 0:
                    return
                if stats is not None and stats_key is not None:
                    stats[stats_key] = stats.get(stats_key, 0) + 1
                response = await self._session.fetch(source, url)
                response.raise_for_status()
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(response.content)
                return
            except httpx.HTTPStatusError as e:
                if e.response.status_code == 403:
                    raise
                if attempt < self._retry_times - 1:
                    logger.debug("Retry %d/%d for %s: %s", attempt + 1, self._retry_times, label, e)
                    await asyncio.sleep(self._retry_delay)
                else:
                    raise
            except Exception as e:
                if attempt < self._retry_times - 1:
                    logger.debug("Retry %d/%d for %s: %s", attempt + 1, self._retry_times, label, e)
                    await asyncio.sleep(self._retry_delay)
                else:
                    raise

    @staticmethod
    def _ensure_request_stats(gallery: Gallery) -> dict[str, int]:
        stats = getattr(gallery, "request_stats", None)
        if not isinstance(stats, dict):
            stats = {
                "metadata_requests": 0,
                "cover_requests": 0,
                "image_file_requests": 0,
                "browser_metadata_requests": 0,
                "browser_image_requests": 0,
            }
            gallery.request_stats = stats
        return stats

    @staticmethod
    def _should_abort_exhentai_download(error: Exception) -> bool:
        if isinstance(error, RuntimeError):
            text = str(error).lower()
            return "cloudflare" in text or "blocked" in text
        if isinstance(error, httpx.HTTPStatusError):
            return error.response.status_code in (302, 403, 429, 509)
        return False

    @staticmethod
    def _should_abort_nhentai_httpx(error: Exception) -> bool:
        if isinstance(error, RuntimeError):
            text = str(error).lower()
            return "cloudflare" in text or "blocked" in text
        if isinstance(error, httpx.HTTPStatusError):
            return error.response.status_code in (403, 429, 503)
        return False

    def _log_request_summary(self, gallery: Gallery) -> None:
        stats = getattr(gallery, "request_stats", None)
        if not isinstance(stats, dict):
            return
        total = sum(int(v) for v in stats.values())
        if gallery.source == "exhentai":
            logger.info(
                "Exhentai request summary for %s: gallery pages=%d, cover=%d, image pages=%d, image files=%d, total=%d",
                gallery.source_id,
                stats.get("gallery_page_requests", 0),
                stats.get("cover_requests", 0),
                stats.get("image_page_requests", 0),
                stats.get("image_file_requests", 0),
                total,
            )
            return
        if gallery.source == "nhentai":
            logger.info(
                "nhentai request summary for %s: metadata=%d, cover=%d, image files=%d, browser metadata=%d, browser images=%d, total=%d",
                gallery.source_id,
                stats.get("metadata_requests", 0),
                stats.get("cover_requests", 0),
                stats.get("image_file_requests", 0),
                stats.get("browser_metadata_requests", 0),
                stats.get("browser_image_requests", 0),
                total,
            )

    def _save_metadata_file(self, gallery: Gallery, gallery_dir: Path) -> None:
        metadata = {
            "source": gallery.source,
            "source_id": gallery.source_id,
            "title": gallery.title,
            "title_jp": gallery.title_jp,
            "artist": gallery.artist,
            "group": gallery.group_name,
            "language": gallery.language,
            "category": gallery.category,
            "total_pages": gallery.total_pages,
            "uploaded_at": gallery.uploaded_at,
            "downloaded_at": gallery.downloaded_at,
            "file_size": gallery.file_size,
            "tags": [
                {"type": t.type, "name": t.name} for t in gallery.tags
            ],
        }
        self._file_manager.save_metadata(gallery_dir, metadata)

    @staticmethod
    def _extract_ext(url: str) -> str:
        ext = Path(url.split("?")[0]).suffix
        if ext.lower() in (".jpg", ".jpeg", ".png", ".gif", ".webp"):
            return ext.lower()
        return ".jpg"

    async def close(self) -> None:
        await self._session.close()
