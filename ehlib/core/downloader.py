import asyncio
from datetime import datetime
from pathlib import Path

import httpx
from tqdm import tqdm

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

    async def download(self, source: str, identifier: str) -> Gallery:
        site = self._sites.get(source)
        if not site:
            raise ValueError(f"Unknown source: {source}. Use 'nhentai' or 'exhentai'.")

        if source == "exhentai" and "/" not in identifier:
            raise ValueError("exhentai identifier must be in format 'gid/token'")

        gallery = await self._fetch_metadata(site, identifier)

        existing = await self._db.get_gallery(source, gallery.source_id)
        if existing and existing.is_complete:
            logger.info("Gallery %s/%s already downloaded completely, skipping.", source, gallery.source_id)
            return existing

        gallery_dir = self._file_manager.create_gallery_dir(source, gallery.source_id, gallery.title)
        gallery.local_path = str(gallery_dir)

        logger.info("Downloading [%s] %s (%d pages)", source, gallery.source_id, gallery.total_pages)

        await self._download_cover(gallery, gallery_dir)
        await self._download_pages(gallery, gallery_dir)

        gallery.file_size = self._file_manager.get_dir_size(gallery_dir)
        gallery.is_complete = True
        gallery.downloaded_at = datetime.now().isoformat()
        gallery.updated_at = datetime.now().isoformat()

        await self._db.save_gallery(gallery)
        self._save_metadata_file(gallery, gallery_dir)

        logger.info(
            "Download complete: [%s] %s (%d pages)",
            source, gallery.source_id, gallery.total_pages,
        )
        return gallery

    async def download_batch(self, urls: list[str]) -> list[Gallery]:
        tasks = []
        for url in urls:
            url = url.strip()
            if not url:
                continue
            source, identifier = self._resolve_url(url)
            if source and identifier:
                tasks.append(self.download(source, identifier))
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
                result = await self.download(gallery.source, gallery.source_id)
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
            return await site.fetch_gallery(identifier)
        except Exception as e:
            if isinstance(e, RuntimeError):
                logger.warning("Cookie mode failed, trying browser mode...")
                return await self._fetch_metadata_browser(site, identifier)
            raise

    async def _fetch_metadata_browser(self, site: SiteBase, identifier: str) -> Gallery:
        browser = AntiBotBrowser(self._config)
        try:
            if site.name == "nhentai":
                url = f"https://nhentai.net/api/gallery/{identifier}"
            else:
                gid, token = identifier.split("/", 1)
                url = f"https://exhentai.org/g/{gid}/{token}/"

            html = await browser.fetch_via_browser(url)
            if site.name == "nhentai":
                import json
                data = json.loads(html)
                return site._parse_gallery_data(data)
            else:
                return site._parse_html(html, identifier)
        finally:
            await browser.close()

    async def _download_cover(self, gallery: Gallery, gallery_dir: Path) -> None:
        if not gallery.cover_url:
            return
        cover_path = self._file_manager.cover_path(gallery_dir, gallery.cover_url)
        gallery.cover_path = str(cover_path)
        if cover_path.exists() and cover_path.stat().st_size > 0:
            return
        await self._download_file(gallery.cover_url, cover_path, "cover")

    async def _download_pages(self, gallery: Gallery, gallery_dir: Path) -> None:
        if gallery.source == "exhentai" and gallery.page_urls:
            urls = gallery.page_urls
        elif gallery.page_urls:
            urls = gallery.page_urls
        else:
            logger.error("No page URLs for gallery %s", gallery.source_id)
            return

        tasks = []
        for i, page_url in enumerate(urls):
            page_num = i + 1
            ext = self._extract_ext(page_url)
            page_path = self._file_manager.page_path(gallery_dir, page_num, ext)
            tasks.append(self._download_with_semaphore(page_url, page_path, str(page_num)))

        results = await asyncio.gather(*tasks, return_exceptions=True)
        failed = sum(1 for r in results if isinstance(r, Exception))
        if failed:
            logger.warning("%d pages failed to download for gallery %s", failed, gallery.source_id)

    async def _download_with_semaphore(self, url: str, path: Path, label: str) -> None:
        async with self._semaphore:
            await self._download_file(url, path, label)

    async def _download_file(self, url: str, path: Path, label: str) -> None:
        for attempt in range(self._retry_times):
            try:
                if path.exists() and path.stat().st_size > 0:
                    return
                client = await self._session.get_client("nhentai")
                response = await client.get(url)
                response.raise_for_status()
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(response.content)
                return
            except Exception as e:
                if attempt < self._retry_times - 1:
                    logger.debug("Retry %d/%d for %s: %s", attempt + 1, self._retry_times, label, e)
                    await asyncio.sleep(self._retry_delay)
                else:
                    raise

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
