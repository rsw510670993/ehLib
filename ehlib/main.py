import argparse
import asyncio
import json
import sys
from asyncio import sleep
from pathlib import Path

from ehlib.config import get_config, Config
from ehlib.core.downloader import Downloader
from ehlib.core.session_manager import SessionManager
from ehlib.models.database import Database
from ehlib.sites.nhentai import NhentaiSite
from ehlib.sites.exhentai import ExhentaiSite
from ehlib.storage.file_manager import FileManager
from ehlib.utils.helpers import parse_nhentai_url, parse_exhentai_url
from ehlib.utils.logger import setup_logger, get_logger
from ehlib.utils.progress import write_progress, remove_progress


async def cmd_download(args: argparse.Namespace, config: Config, db: Database) -> None:
    downloader = Downloader(config, db)
    try:
        if args.url:
            source, identifier = _resolve_url(args.url)
            if not source:
                print(f"Error: Cannot parse URL: {args.url}")
                return
        elif args.id:
            source = args.source
            identifier = args.id
        elif args.gid and args.token:
            source = "exhentai"
            identifier = f"{args.gid}/{args.token}"
        else:
            print("Error: Provide --id, --url, or --gid/--token")
            return

        gallery = await downloader.download(source, identifier, force=args.force)
        print(f"Downloaded: [{gallery.source}] {gallery.title} ({gallery.total_pages} pages)")
    finally:
        await downloader.close()


async def cmd_batch(args: argparse.Namespace, config: Config, db: Database) -> None:
    filepath = Path(args.file)
    if not filepath.exists():
        print(f"Error: File not found: {args.file}")
        return

    urls = filepath.read_text(encoding="utf-8").strip().splitlines()
    downloader = Downloader(config, db)
    try:
        results = await downloader.download_batch(urls)
        print(f"Batch complete: {len(results)} galleries downloaded")
    finally:
        await downloader.close()


async def cmd_list(args: argparse.Namespace, _config: Config, db: Database) -> None:
    tag_names = None
    if args.tags:
        tag_names = [t.strip() for t in args.tags.split(",") if t.strip()]
    galleries = await db.search_galleries(
        source=args.source,
        artist=args.artist,
        tag_name=args.tag,
        tag_names=tag_names,
        tag_mode=args.tag_mode or "any",
        language=args.language,
        limit=args.limit or 50,
    )
    if not galleries:
        print("No galleries found.")
        return
    for g in galleries:
        title_jp_part = g.title_jp if g.title_jp else ""
        print(f"[{g.source}/{g.source_id}] {g.title} | {title_jp_part} ({g.total_pages}p) - {g.downloaded_at}")


async def cmd_crawl(args: argparse.Namespace, config: Config, db: Database) -> None:
    """后台爬取任务：搜索并缓存所有结果页"""
    progress_file = Path(f"data/crawl_progress_{args.source}.json")
    cancel_file = Path(f"data/crawl_cancel_{args.source}.flag")
    CRAWL_TASK_ID = f"crawl_{args.source}"

    # 如果已有取消标记则清除
    if cancel_file.exists():
        cancel_file.unlink()

    # 恢复进度
    resume_cursor = ""
    resume_page = 1
    reserved_ids = set()
    metadata_done = set()
    if progress_file.exists() and not args.force:
        try:
            data = json.loads(progress_file.read_text())
            resume_cursor = data.get("next_cursor", "")
            resume_page = data.get("page", 1)
            reserved_ids = set(data.get("saved_ids", []))
            metadata_done = set(data.get("metadata_ids", []))
            print(f"Resuming from page {resume_page}, cursor={resume_cursor}, {len(reserved_ids)} cached, {len(metadata_done)} metadata done")
        except Exception:
            pass

    thumbs_dir = f"data/thumbs/{args.source}"
    session = SessionManager(config)
    try:
        if args.source == "exhentai":
            site = ExhentaiSite(config, session)
        else:
            site = NhentaiSite(config, session)

        print(f"Starting crawl: {args.source}, query='{args.query}'")
        write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", 0, 0, "running", f"Page {resume_page}")

        async def on_page(page: int, items: list[dict], next_cursor: str):
            # 检查取消标记
            if cancel_file.exists():
                print("Cancel signal received, stopping crawl.")
                raise KeyboardInterrupt()

            saved_ids = []
            for item in items:
                sid = item.get("source_id", "")
                if sid and sid not in reserved_ids:
                    reserved_ids.add(sid)
                    saved_ids.append(sid)
            if saved_ids:
                batch = [it for it in items if it.get("source_id", "") in saved_ids]
                await db.save_search_results(batch)

            # 获取元数据和封面（1分钟间隔）
            for idx, item in enumerate(items, 1):
                if cancel_file.exists():
                    raise KeyboardInterrupt()
                sid = item.get("source_id", "")
                if not sid or sid in metadata_done:
                    continue
                try:
                    artist, thumb_path, uploaded_at, category, cover_url = await site.fetch_metadata_and_thumb(sid, thumbs_dir, item.get("thumbnail", ""))
                    await db.update_search_cache_metadata(args.source, sid, artist, thumb_path, uploaded_at, category, cover_url)
                    metadata_done.add(sid)
                    print(f"    Metadata {idx}/{len(items)}: {sid} artist={artist} uploaded={uploaded_at}")
                except Exception as e:
                    print(f"    Metadata failed for {sid}: {e}")
                # save progress after each item
                progress_file.write_text(json.dumps({
                    "page": page,
                    "next_cursor": next_cursor,
                    "saved_ids": list(reserved_ids),
                    "metadata_ids": list(metadata_done),
                    "query": args.query,
                }))
                write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", len(items), idx, "running", f"Page {page}, metadata {idx}/{len(items)}")
                if idx < len(items):
                    await sleep(60)

            # 保存进度文件（全页完成后）
            progress_file.write_text(json.dumps({
                "page": page,
                "next_cursor": next_cursor,
                "saved_ids": list(reserved_ids),
                "metadata_ids": list(metadata_done),
                "query": args.query,
            }))
            write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", 0, page, "running", f"Page {page}, cached {len(reserved_ids)}")
            print(f"  Page {page}: {len(items)} items, total cached: {len(reserved_ids)}, metadata done: {len(metadata_done)}")

        cats = None
        if args.categories:
            cats = list(args.categories)
        try:
            await site.crawl_all_pages(
                query=args.query,
                categories=cats,
                resume_cursor=resume_cursor,
                resume_page=resume_page,
                on_page=on_page,
            )
        except KeyboardInterrupt:
            print("Crawl cancelled by user.")
            remove_progress("crawl", CRAWL_TASK_ID)
            if cancel_file.exists():
                cancel_file.unlink()
            return

        print(f"Crawl complete. Total items cached: {len(reserved_ids)}")
        remove_progress("crawl", CRAWL_TASK_ID)
        if progress_file.exists():
            progress_file.unlink()
        if cancel_file.exists():
            cancel_file.unlink()
    finally:
        await session.close()


async def cmd_config(args: argparse.Namespace, config: Config, _db: Database) -> None:
    if args.show_cookies:
        cookies = config.cookies
        for site_name, site_cookies in cookies.items():
            print(f"\n[{site_name}]")
            for k, v in site_cookies.items():
                masked = v[:4] + "****" if len(v) > 4 else "****"
                print(f"  {k}: {masked}")
        return

    if args.set_cookie:
        parts = args.set_cookie.split(":")
        if len(parts) != 3:
            print("Error: set-cookie format: source:cookie_name:value")
            return
        source, cookie_name, value = parts
        config.set("cookies", source, cookie_name, value=value)
        config.save()
        print(f"Cookie set: [{source}] {cookie_name} = {value[:4]}****")


async def cmd_retry(args: argparse.Namespace, config: Config, db: Database) -> None:
    import sys
    downloader = Downloader(config, db)
    try:
        results = await downloader.retry_incomplete(skip_existing=args.skip_existing)
        print(f"重试完成: {len(results)} 个画廊已重新下载")
    except Exception as e:
        print(f"重试失败: {e}", file=sys.stderr)
        raise
    finally:
        await downloader.close()


async def cmd_count_retry_pages(args: argparse.Namespace, config: Config, db: Database) -> None:
    downloader = Downloader(config, db)
    try:
        total = await downloader.count_retry_pages()
        print(total)
    finally:
        await downloader.close()


async def cmd_export(args: argparse.Namespace, _config: Config, db: Database) -> None:
    await db.export_json(args.output)
    print(f"Exported to {args.output}")


async def cmd_migrate_dirs(_args: argparse.Namespace, config: Config, db: Database) -> None:
    file_manager = FileManager(config.download.get("path", "./downloads"))
    galleries = await db.get_all_galleries()
    if not galleries:
        print("No galleries found.")
        return

    migrated = 0
    skipped = 0
    conflicts = 0
    missing = 0

    for gallery in galleries:
        if not gallery.local_path:
            print(f"SKIP [{gallery.source}/{gallery.source_id}] local_path is empty")
            skipped += 1
            continue

        current_path = Path(gallery.local_path).resolve()
        target_path = file_manager.gallery_dir(gallery.source, gallery.source_id, gallery.title)

        ok, status = file_manager.migrate_gallery_dir(current_path, target_path)
        if status == "already-current":
            skipped += 1
            continue
        if not ok and status == "source-missing":
            print(f"MISSING [{gallery.source}/{gallery.source_id}] {current_path}")
            missing += 1
            continue
        if not ok and status == "target-exists":
            print(f"CONFLICT [{gallery.source}/{gallery.source_id}] {target_path}")
            conflicts += 1
            continue
        if not ok:
            print(f"SKIP [{gallery.source}/{gallery.source_id}] {status}")
            skipped += 1
            continue

        await db.update_gallery_local_path(gallery.source, gallery.source_id, str(target_path))
        print(f"MIGRATED [{gallery.source}/{gallery.source_id}] -> {target_path.name}")
        migrated += 1

    print(
        f"Migration complete: migrated={migrated}, skipped={skipped}, "
        f"missing={missing}, conflicts={conflicts}"
    )


async def cmd_refresh_metadata(args: argparse.Namespace, config: Config, db: Database) -> None:
    downloader = Downloader(config, db)
    try:
        gallery = await downloader.download(args.source, args.source_id)
        print(f"Metadata refreshed: [{args.source}/{args.source_id}] {gallery.title}")
        print(f"Tags: {len(gallery.tags)}, Pages: {gallery.total_pages}")
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
    finally:
        await downloader.close()


async def cmd_recover_orphans(args: argparse.Namespace, config: Config, db: Database) -> None:
    file_manager = FileManager(config.download.get("path", "./downloads"))
    sources = [args.source] if args.source else ["nhentai", "exhentai"]
    count = 0

    for source in sources:
        source_dir = file_manager.base_path / source
        if not source_dir.is_dir():
            continue

        existing_ids = set()
        galleries = await db.get_all_galleries()
        for g in galleries:
            if g.source == source:
                existing_ids.add(g.source_id)

        for dir_entry in sorted(source_dir.iterdir()):
            if not dir_entry.is_dir():
                continue
            dir_name = dir_entry.name
            source_id = dir_name.replace("_", "/", 1) if source == "exhentai" else dir_name
            if source_id in existing_ids:
                continue

            local_path = str(dir_entry.resolve())
            image_count = len(file_manager.list_downloaded_pages(dir_entry))

            if args.dry_run:
                print(f"[{source}] Orphaned: {dir_name} (source_id: {source_id}, images: {image_count})")
                count += 1
                continue

            try:
                print(f"[{source}] Recovering: {dir_name} ({image_count} images)...", end=" ", flush=True)
                session_mgr = SessionManager(config)
                if source == "nhentai":
                    site = NhentaiSite(config, session_mgr)
                else:
                    site = ExhentaiSite(config, session_mgr)
                gallery = await site.fetch_gallery(source_id)
                gallery.local_path = local_path
                gallery.is_complete = True
                gallery.file_size = file_manager.get_dir_size(dir_entry)
                gallery.downloaded_at = _find_oldest_file_time(dir_entry)
                await db.save_gallery(gallery)
                from dataclasses import asdict
                meta = asdict(gallery)
                meta["tags"] = [asdict(t) for t in gallery.tags]
                file_manager.save_metadata(dir_entry, meta)
                await session_mgr.close()
                print(f"Saved. Tags: {len(gallery.tags)}, Pages: {gallery.total_pages}")
                count += 1
            except Exception as e:
                print(f"Failed: {e}")

    print(f"\nRecovery complete: {count} galleries recovered.")


def _find_oldest_file_time(directory: Path) -> str:
    oldest = None
    for f in directory.iterdir():
        if f.is_file() and f.suffix.lower() in (".jpg", ".jpeg", ".png", ".gif", ".webp"):
            mtime = f.stat().st_mtime
            if oldest is None or mtime < oldest:
                oldest = mtime
    if oldest:
        from datetime import datetime
        return datetime.fromtimestamp(oldest).isoformat()
    return ""


def _resolve_url(url: str) -> tuple[str | None, str | None]:
    nh_id = parse_nhentai_url(url)
    if nh_id:
        return "nhentai", nh_id
    eh_result = parse_exhentai_url(url)
    if eh_result:
        return "exhentai", f"{eh_result[0]}/{eh_result[1]}"
    return None, None


def main() -> None:
    parser = argparse.ArgumentParser(
        prog="ehlib",
        description="Download manga galleries from nhentai and exhentai with tag metadata",
    )
    subparsers = parser.add_subparsers(dest="command", help="Available commands")

    dl = subparsers.add_parser("download", help="Download a gallery")
    dl.add_argument("source", nargs="?", choices=["nhentai", "exhentai"], help="Source site")
    dl.add_argument("--id", help="Gallery ID")
    dl.add_argument("--url", help="Gallery URL")
    dl.add_argument("--gid", help="exhentai gallery ID (gid)")
    dl.add_argument("--token", help="exhentai gallery token")
    dl.add_argument("--force", action="store_true", help="Force re-download even if already complete (clears local data)")

    batch = subparsers.add_parser("batch", help="Batch download from file")
    batch.add_argument("--file", required=True, help="File containing URLs (one per line)")
    batch.add_argument("--force", action="store_true", help="Force re-download even if already complete (clears local data)")

    crawl = subparsers.add_parser("crawl", help="Crawl and cache search results")
    crawl.add_argument("source", choices=["exhentai", "nhentai"], help="Source site")
    crawl.add_argument("--query", required=True, help="Search query (artist name, tag, etc.)")
    crawl.add_argument("--force", action="store_true", help="Restart crawl from beginning")
    crawl.add_argument("--categories", type=int, nargs="*", help="Category bitmask values (e.g. 2 4 8)")

    lst = subparsers.add_parser("list", help="List local galleries")
    lst.add_argument("--source", choices=["nhentai", "exhentai"], help="Filter by source")
    lst.add_argument("--artist", help="Filter by artist")
    lst.add_argument("--tag", help="Filter by single tag name")
    lst.add_argument("--tags", help="Filter by multiple tags (comma-separated, e.g. english,shindol)")
    lst.add_argument("--tag-mode", choices=["any", "all"], default="any", help="Tag matching mode: any (OR) or all (AND)")
    lst.add_argument("--language", help="Filter by language")
    lst.add_argument("--limit", type=int, default=50, help="Max results")

    cfg = subparsers.add_parser("config", help="Manage configuration")
    cfg.add_argument("--show-cookies", action="store_true", help="Show configured cookies (masked)")
    cfg.add_argument("--set-cookie", help="Set a cookie: source:cookie_name:value")

    retry_parser = subparsers.add_parser("retry", help="Retry incomplete downloads")
    retry_parser.add_argument("--skip-existing", action="store_true", help="Skip already downloaded pages")

    count_retry_parser = subparsers.add_parser("count-retry-pages", help="Count total pages across incomplete galleries")

    exp = subparsers.add_parser("export", help="Export metadata to JSON")
    exp.add_argument("--output", default="metadata.json", help="Output file path")
    exp.add_argument("--format", choices=["json"], default="json", help="Export format")

    subparsers.add_parser("migrate-dirs", help="Migrate gallery directories to ID-only names")

    refresh = subparsers.add_parser("refresh-metadata", help="Re-fetch metadata for an existing gallery (preserves local images)")
    refresh.add_argument("source", choices=["nhentai", "exhentai"], help="Source site")
    refresh.add_argument("source_id", help="Gallery source ID or gid/token for exhentai")

    recover = subparsers.add_parser("recover-orphans", help="Scan download dirs and recover galleries with no DB record")
    recover.add_argument("--source", choices=["nhentai", "exhentai"], help="Limit scan to a specific source")
    recover.add_argument("--dry-run", action="store_true", help="List orphaned dirs without recovering")

    args = parser.parse_args()

    if not args.command:
        parser.print_help()
        return

    setup_logger("ehlib")
    config = get_config()
    db = Database()

    async def run() -> None:
        await db.init()
        commands = {
            "download": cmd_download,
            "batch": cmd_batch,
            "crawl": cmd_crawl,
            "list": cmd_list,
            "config": cmd_config,
            "retry": cmd_retry,
            "count-retry-pages": cmd_count_retry_pages,
            "export": cmd_export,
            "migrate-dirs": cmd_migrate_dirs,
            "refresh-metadata": cmd_refresh_metadata,
            "recover-orphans": cmd_recover_orphans,
        }
        handler = commands.get(args.command)
        if handler:
            await handler(args, config, db)
            return

    asyncio.run(run())


if __name__ == "__main__":
    main()
