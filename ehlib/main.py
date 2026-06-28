import argparse
import asyncio
import sys
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


async def cmd_search(args: argparse.Namespace, config: Config, db: Database) -> None:
    session = SessionManager(config)
    source = args.source
    if source == "nhentai":
        site = NhentaiSite(config, session)
    elif source == "exhentai":
        site = ExhentaiSite(config, session)
    else:
        print(f"Error: Unknown source '{source}'")
        await session.close()
        return

    try:
        results = await site.search(args.query)
        for g in results:
            print(f"[{g.source_id}] {g.title}")
    finally:
        await session.close()


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
    downloader = Downloader(config, db)
    try:
        results = await downloader.retry_incomplete()
        print(f"Retry complete: {len(results)} galleries re-downloaded")
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

    search = subparsers.add_parser("search", help="Search galleries online")
    search.add_argument("source", choices=["nhentai", "exhentai"], help="Source site")
    search.add_argument("--query", required=True, help="Search query")

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

    subparsers.add_parser("retry", help="Retry incomplete downloads")

    exp = subparsers.add_parser("export", help="Export metadata to JSON")
    exp.add_argument("--output", default="metadata.json", help="Output file path")
    exp.add_argument("--format", choices=["json"], default="json", help="Export format")

    subparsers.add_parser("migrate-dirs", help="Migrate gallery directories to ID-only names")

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
            "search": cmd_search,
            "list": cmd_list,
            "config": cmd_config,
            "retry": cmd_retry,
            "export": cmd_export,
            "migrate-dirs": cmd_migrate_dirs,
        }
        handler = commands.get(args.command)
        if handler:
            await handler(args, config, db)
            return

    asyncio.run(run())


if __name__ == "__main__":
    main()
