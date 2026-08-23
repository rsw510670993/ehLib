import asyncio
import json
import shutil
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
from ehlib.utils.image_compression import ImageCompressor
from ehlib.utils.logger import get_logger
from ehlib.utils.progress import write_progress, remove_progress

logger = get_logger(__name__)

DOWNLOAD_CANCEL_FILE = Path("data/download_cancel.flag")


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
        self._image_compressor = ImageCompressor(
            enabled=bool(config.download.get("convert_to_avif", True)),
            quality=config.download.get("avif_quality", 65),
            speed=config.download.get("avif_speed", 5),
            min_savings_percent=config.download.get("avif_min_savings_percent", 5),
        )
        self._semaphore = asyncio.Semaphore(self._max_concurrent)

    async def download(self, source: str, identifier: str, force: bool = False, skip_existing: bool = False) -> Gallery:
        self._check_cancelled()
        identifier = str(identifier)
        site = self._sites.get(source)
        if not site:
            raise ValueError(f"Unknown source: {source}. Use 'nhentai' or 'exhentai'.")

        if source == "exhentai" and "/" not in identifier:
            raise ValueError("exhentai identifier must be in format 'gid/token'")

        gallery = await self._fetch_metadata(site, identifier)
        self._check_cancelled()

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
                await self._db.register_completed_refresh_target(source, gallery.source_id)
                return gallery
            else:
                skip_existing = True
                logger.info(
                    "Resuming incomplete gallery %s/%s and skipping existing pages.", source, gallery.source_id
                )

        gallery_dir = self._file_manager.create_gallery_dir(source, gallery.source_id, gallery.title)
        gallery.local_path = str(gallery_dir)
        gallery.is_complete = False
        gallery.updated_at = datetime.now().isoformat()
        gallery.id = await self._db.save_gallery(gallery)

        logger.info("Downloading [%s] %s (%d pages)", source, gallery.source_id, gallery.total_pages)
        write_progress(source, gallery.source_id, gallery.title, gallery.total_pages, 0, "downloading")

        try:
            self._check_cancelled()
            if skip_existing:
                existing = self._file_manager.list_downloaded_pages(gallery_dir)
                if existing:
                    started = max(existing)
                    write_progress(source, gallery.source_id, gallery.title, gallery.total_pages, started, 'downloading', str(started) + '/' + str(gallery.total_pages) + ' (skip)')

            self._check_cancelled()
            await self._download_pages(gallery, gallery_dir, skip_existing=skip_existing)
            self._check_cancelled()
            await self._create_cover_from_first_page(gallery, gallery_dir)
        except KeyboardInterrupt:
            logger.warning("Download cancelled by user, cleaning up %s", gallery_dir)
            self._file_manager.delete_gallery_dir(gallery_dir)
            await self._db.delete_gallery(source, gallery.source_id)
            remove_progress(source, gallery.source_id)
            raise
        except Exception:
            gallery.file_size = self._file_manager.get_dir_size(gallery_dir)
            gallery.is_complete = False
            gallery.updated_at = datetime.now().isoformat()
            await self._db.save_gallery(gallery)
            remove_progress(source, gallery.source_id)
            raise

        self._log_request_summary(gallery)

        gallery.file_size = self._file_manager.get_dir_size(gallery_dir)
        gallery.is_complete = True
        gallery.downloaded_at = datetime.now().isoformat()
        gallery.updated_at = datetime.now().isoformat()

        gallery.id = await self._db.save_gallery(gallery)
        await self._persist_download_compression(gallery, gallery_dir)
        await self._db.register_completed_refresh_target(source, gallery.source_id)
        self._save_metadata_file(gallery, gallery_dir)
        remove_progress(source, gallery.source_id)

        logger.info(
            "Download complete: [%s] %s (%d pages)",
            source, gallery.source_id, gallery.total_pages,
        )
        return gallery

    async def download_batch(self, urls: list[str], force: bool = False) -> list[Gallery]:
        self._check_cancelled()
        jobs = []
        for raw_url in urls:
            url = str(raw_url).strip()
            if not url:
                continue
            source, identifier = self._resolve_url(url)
            if source and identifier:
                jobs.append((url, self.download(source, identifier, force=force)))
        if not jobs:
            return []
        results = await asyncio.gather(*(task for _, task in jobs), return_exceptions=True)
        galleries = []
        for (url, _task), result in zip(jobs, results):
            if isinstance(result, KeyboardInterrupt):
                raise
            if isinstance(result, Exception):
                logger.error("Batch download error for %s", url, exc_info=(type(result), result, result.__traceback__))
            else:
                galleries.append(result)
        return galleries

    async def count_retry_pages(self) -> int:
        """Count total pages that need retry for timeout estimation."""
        incomplete = await self._db.get_incomplete_downloads()
        orphaned = await self._find_orphan_downloads(incomplete)
        total = sum(g.total_pages or 0 for g in incomplete)
        for gallery in orphaned:
            files = self._file_manager.list_downloaded_pages(Path(gallery.local_path)) if gallery.local_path else []
            total += max(len(files), 1)
        return total

    async def retry_incomplete(self, skip_existing: bool = False) -> list[Gallery]:
        incomplete = await self._db.get_incomplete_downloads()
        orphaned = await self._find_orphan_downloads(incomplete)
        jobs = incomplete + orphaned
        total_jobs = len(jobs)
        print(f"找到 {len(incomplete)} 个未完成的下载和 {len(orphaned)} 个孤儿目录，共 {total_jobs} 个任务")
        logger.info(
            "Found %d incomplete downloads and %d orphan directories to retry",
            len(incomplete), len(orphaned),
        )
        results = []
        failures: list[str] = []
        for idx, gallery in enumerate(jobs, 1):
            label = f"{gallery.source}/{gallery.source_id}"
            title_display = (gallery.title or gallery.source_id)[:60]
            print(f"[{idx}/{total_jobs}] 正在重试: [{gallery.source}] {title_display} ...")
            try:
                result = await self.download(gallery.source, gallery.source_id, force=False, skip_existing=skip_existing)
                results.append(result)
                print(f"[{idx}/{total_jobs}] 完成: [{gallery.source}] {title_display}")
            except Exception as e:
                failures.append(label)
                write_progress(
                    gallery.source,
                    gallery.source_id,
                    gallery.title,
                    gallery.total_pages,
                    len(self._file_manager.list_downloaded_pages(Path(gallery.local_path))) if gallery.local_path else 0,
                    "failed",
                    str(e),
                )
                print(f"[{idx}/{total_jobs}] 失败: [{gallery.source}] {title_display} - {e}")
                logger.error("Retry failed for %s: %s", label, e, exc_info=True)
        if failures:
            err_msg = "重试失败: " + ", ".join(failures)
            print(err_msg)
            raise RuntimeError(err_msg)
        return results

    async def _find_orphan_downloads(self, known: list[Gallery]) -> list[Gallery]:
        known_keys = {(gallery.source, gallery.source_id) for gallery in known}
        orphaned: list[Gallery] = []
        for source in ("nhentai", "exhentai"):
            source_dir = self._file_manager.base_path / source
            if not source_dir.exists():
                continue
            for directory in sorted(source_dir.iterdir()):
                if not directory.is_dir():
                    continue
                source_id = self._source_id_from_dir_name(source, directory.name)
                if not source_id or (source, source_id) in known_keys:
                    continue
                if await self._db.get_gallery(source, source_id):
                    continue
                if not self._file_manager.list_downloaded_pages(directory):
                    continue
                orphaned.append(Gallery(
                    source=source,
                    source_id=source_id,
                    title=directory.name,
                    local_path=str(directory),
                    is_complete=False,
                ))
                known_keys.add((source, source_id))
        return orphaned

    @staticmethod
    def _source_id_from_dir_name(source: str, dir_name: str) -> str | None:
        if source == "nhentai" and dir_name.isdigit():
            return dir_name
        if source == "exhentai" and "_" in dir_name:
            gid, token = dir_name.split("_", 1)
            if gid.isdigit() and token:
                return f"{gid}/{token}"
        return None

    @staticmethod
    def _check_cancelled() -> None:
        if DOWNLOAD_CANCEL_FILE.exists():
            logger.warning("Cancel signal detected, aborting download")
            raise KeyboardInterrupt()

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
                headless=self._config.browser.get("headless", False),
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

    async def _create_cover_from_first_page(self, gallery: Gallery, gallery_dir: Path) -> None:
        first_page = self._file_manager.first_page_path(gallery_dir)
        if not first_page:
            logger.warning("No first page found to use as cover for %s", gallery.source_id)
            return
        cover_path = gallery_dir / f"cover{first_page.suffix}"
        shutil.copy2(first_page, cover_path)
        gallery.cover_path = str(cover_path)
        logger.info("Cover created from first page for %s", gallery.source_id)

    async def _download_pages(self, gallery: Gallery, gallery_dir: Path, skip_existing: bool = False) -> None:
        urls = gallery.page_urls
        if not urls:
            logger.error("No page URLs for gallery %s", gallery.source_id)
            return

        if gallery.source == "exhentai":
            await self._download_exhentai_display_pages(gallery, gallery_dir, urls, skip_existing)
            return

        failed_pages = await self._download_pages_httpx(gallery, gallery_dir, urls, skip_existing)
        if not failed_pages:
            return

        failed_list = ", ".join(str(page_num) for page_num, _ in failed_pages[:10])
        logger.warning(
            "httpx page download incomplete for %s, failed pages: %s. Trying browser fallback...",
            gallery.source_id,
            failed_list,
        )
        await self._download_pages_browser(gallery, gallery_dir, failed_pages)

    async def _download_exhentai_display_pages(self, gallery: Gallery, gallery_dir: Path, urls: list[str], skip_existing: bool = False) -> None:
        site = self._sites.get("exhentai")
        if not isinstance(site, ExhentaiSite):
            raise RuntimeError("Exhentai site handler is unavailable")

        stats = self._ensure_request_stats(gallery)
        failed_pages: list[int] = []
        existing_pages: set[int] = set()
        if skip_existing:
            existing_pages = set(self._file_manager.list_downloaded_pages(gallery_dir))

        for i, image_page_url in enumerate(urls):
            self._check_cancelled()
            page_num = i + 1
            if skip_existing and page_num in existing_pages:
                continue
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

        # Retry pass: retry failed pages one by one (transient errors like SSL handshake)
        if failed_pages:
            logger.info("Retrying %d failed pages for %s...", len(failed_pages), gallery.source_id)
            still_failed: list[int] = []
            for page_num in failed_pages:
                i = page_num - 1
                image_page_url = urls[i]
                try:
                    display_url = await site.resolve_display_image_url(image_page_url)
                    ext = self._extract_ext(display_url)
                    page_path = self._file_manager.page_path(gallery_dir, page_num, ext)
                    if page_path.exists() and page_path.stat().st_size > 0:
                        continue
                    await self._download_file(
                        "exhentai",
                        display_url,
                        page_path,
                        str(page_num),
                        stats=stats,
                        stats_key="image_file_requests",
                    )
                except Exception as e:
                    still_failed.append(page_num)
                    logger.warning("Exhentai display page %d retry failed: %s", page_num, e)

            if still_failed:
                raise RuntimeError(
                    f"Exhentai download incomplete, failed pages: {', '.join(map(str, still_failed[:10]))}"
                )

    async def _download_pages_httpx(
        self,
        gallery: Gallery,
        gallery_dir: Path,
        urls: list[str],
        skip_existing: bool = False,
    ) -> list[tuple[int, str]]:
        stats = self._ensure_request_stats(gallery)
        self._check_cancelled()
        tasks = []
        existing_pages: set[int] = set()
        if skip_existing:
            existing_pages = set(self._file_manager.list_downloaded_pages(gallery_dir))
        for i, page_url in enumerate(urls):
            page_num = i + 1
            if skip_existing and page_num in existing_pages:
                continue
            ext = self._extract_ext(page_url)
            page_path = self._file_manager.page_path(gallery_dir, page_num, ext)
            tasks.append(
                self._download_page_with_progress(
                    gallery, page_url, page_path, str(page_num),
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
                _, page_stats = await asyncio.to_thread(
                    self._image_compressor.save_page_bytes_with_stats, data, page_path
                )
                self._record_compression_stats(stats, page_stats)

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

    async def _download_page_with_progress(
        self,
        gallery: Gallery,
        url: str,
        path: Path,
        label: str,
    ) -> None:
        stats = self._ensure_request_stats(gallery)
        await self._download_with_semaphore(
            gallery.source, url, path, label,
            stats=stats, stats_key="image_file_requests",
        )
        write_progress(
            gallery.source,
            gallery.source_id,
            gallery.title,
            gallery.total_pages,
            int(label),
            "downloading",
            f"{label}/{gallery.total_pages}",
        )

    async def _download_with_semaphore(
        self,
        source: str,
        url: str,
        path: Path,
        label: str,
        *,
        stats: dict | None = None,
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
        stats: dict | None = None,
        stats_key: str | None = None,
    ) -> None:
        for attempt in range(self._retry_times):
            try:
                if self._image_compressor.find_existing_page_file(path) is not None:
                    return
                if stats is not None and stats_key is not None:
                    stats[stats_key] = stats.get(stats_key, 0) + 1
                response = await self._session.fetch(source, url)
                response.raise_for_status()
                _, page_stats = await asyncio.to_thread(
                    self._image_compressor.save_page_bytes_with_stats,
                    response.content,
                    path,
                )
                if stats is not None:
                    self._record_compression_stats(stats, page_stats)
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
    def _record_compression_stats(request_stats: dict, page_stats: dict) -> None:
        bucket = request_stats.setdefault("compression", {
            "processed_pages": 0,
            "used_candidate_count": 0,
            "orig_bytes_total": 0,
            "result_bytes_total": 0,
            "failed_pages_count": 0,
        })
        orig_bytes = max(0, int(page_stats.get("orig_bytes", 0) or 0))
        used_candidate = bool(page_stats.get("used_candidate", False))
        candidate_bytes = max(0, int(page_stats.get("candidate_bytes", 0) or 0))
        result_bytes = candidate_bytes if used_candidate and candidate_bytes > 0 else orig_bytes
        bucket["processed_pages"] += 1
        bucket["used_candidate_count"] += int(used_candidate)
        bucket["orig_bytes_total"] += orig_bytes
        bucket["result_bytes_total"] += result_bytes
        bucket["failed_pages_count"] += int(bool(page_stats.get("exception")))

    async def _persist_download_compression(self, gallery: Gallery, gallery_dir: Path) -> None:
        avif_page_count = sum(
            1
            for path in gallery_dir.glob("*.avif")
            if path.is_file() and path.stem.isdigit()
        )
        if avif_page_count <= 0:
            return

        request_stats = self._ensure_request_stats(gallery)
        stats = request_stats.get("compression", {})
        processed_pages = int(stats.get("processed_pages", 0) or 0)
        orig_total = int(stats.get("orig_bytes_total", 0) or 0)
        result_total = int(stats.get("result_bytes_total", 0) or 0)
        savings_pct = None
        if processed_pages == int(gallery.total_pages) and orig_total > 0:
            savings_pct = round((1.0 - result_total / orig_total) * 100.0, 2)

        info = {
            "schema_version": 2,
            "gallery_id": int(gallery.id or 0),
            "tool": "pillow-avif-download",
            "target_format": "AVIF",
            "target_extension": ".avif",
            "quality": int(self._image_compressor.quality),
            "speed": int(self._image_compressor.speed),
            "min_savings_percent": float(self._image_compressor.min_savings_percent),
            "total_pages": int(gallery.total_pages),
            "processed_pages": processed_pages,
            "used_candidate_count": avif_page_count,
            "used_avif_count": avif_page_count,
            "skipped_pages_count": max(0, int(gallery.total_pages) - avif_page_count),
            "failed_pages_count": int(stats.get("failed_pages_count", 0) or 0),
            "orig_bytes_total": orig_total,
            "candidate_bytes_total": result_total,
            "avif_bytes_total": result_total,
            "savings_pct_overall": savings_pct,
            "applied_during_download": True,
        }
        await self._db.update_gallery_compression(
            gallery.source,
            gallery.source_id,
            "applied",
            json.dumps(info, ensure_ascii=False),
        )

    @staticmethod
    def _ensure_request_stats(gallery: Gallery) -> dict:
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
        total = sum(int(v) for v in stats.values() if isinstance(v, (int, float)))
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
        if ext.lower() in (".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif"):
            return ext.lower()
        return ".jpg"

    async def close(self) -> None:
        await self._session.close()
