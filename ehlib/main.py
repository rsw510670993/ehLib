import argparse
import asyncio
import json
import os
import random
import re as _re
import sys
import time
from asyncio import sleep
from contextlib import contextmanager
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


class CrawlLockBusy(RuntimeError):
    """Raised when another crawl worker/process already holds a lock."""


class CrawlLockError(RuntimeError):
    """Raised when a crawl lock file cannot be opened or locked."""


def _is_updated_on_site(existing, item: dict) -> bool:
    """站点上的上传日期与本地已下载作品不一致时视为已被更新（不能作为中断点）。
    任一侧日期缺失时回退为旧行为（仅按 is_complete 判断）。"""
    site_date = " ".join(str(item.get("uploaded_at", "") or "").split())
    local_date = " ".join((existing.uploaded_at or "").split())
    return bool(site_date and local_date and site_date != local_date)


def _cached_item_is_updated(cached_uploaded_at: str, item: dict) -> bool:
    """缓存日期与站点日期均存在且不一致时，不能把该记录作为停止点。"""
    site_date = " ".join(str(item.get("uploaded_at", "") or "").split())
    cached_date = " ".join(str(cached_uploaded_at or "").split())
    return bool(site_date and cached_date and site_date != cached_date)


def _parse_languages(languages) -> set:
    """解析逗号分隔/列表形式的语种筛选条件，返回小写集合。空集合表示不过滤。
    注意：E-Hentai 的 language: 标签搜索不可靠（japanese 不过滤、~ 语义异常），
    语种筛选在元数据抓取后按画廊 Language 属性执行。"""
    if isinstance(languages, str):
        parts = languages.split(",")
    else:
        parts = list(languages or [])
    return {str(l).strip().lower() for l in parts if str(l).strip()}


@contextmanager
def _exclusive_crawl_lock(blocking: bool = False, lock_name: str = "crawl.lock"):
    """Allow only one crawl/update process across all sources and entry points."""
    lock_path = Path("data") / lock_name
    lock_path.parent.mkdir(parents=True, exist_ok=True)
    try:
        lock_file = lock_path.open("a+b")
        if os.name != "nt":
            try:
                os.chmod(lock_path, 0o666)
            except OSError:
                pass
    except PermissionError:
        if os.name == "nt":
            raise CrawlLockError(f"Cannot open crawl lock: {lock_path}")
        try:
            # POSIX flock does not require a writable descriptor. Existing lock
            # files may belong to DSM, http, or an interactive NAS user.
            lock_file = lock_path.open("rb")
        except OSError as exc:
            raise CrawlLockError(f"Cannot open crawl lock {lock_path}: {exc}") from exc
    except OSError as exc:
        raise CrawlLockError(f"Cannot open crawl lock {lock_path}: {exc}") from exc

    try:
        if os.name == "nt":
            import msvcrt

            if lock_path.stat().st_size == 0:
                lock_file.write(b"\0")
                lock_file.flush()
            lock_file.seek(0)
            msvcrt.locking(lock_file.fileno(), msvcrt.LK_LOCK if blocking else msvcrt.LK_NBLCK, 1)
        else:
            import fcntl

            fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB))
    except BlockingIOError as exc:
        lock_file.close()
        raise CrawlLockBusy("Another crawl task is already running; concurrent crawls are not allowed.") from exc
    except OSError as exc:
        lock_file.close()
        raise CrawlLockError(f"Cannot lock {lock_path}: {exc}") from exc

    try:
        yield
    finally:
        try:
            if os.name == "nt":
                lock_file.seek(0)
                msvcrt.locking(lock_file.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
        finally:
            lock_file.close()


async def cmd_download(args: argparse.Namespace, config: Config, db: Database) -> None:
    download_cancel = Path("data/download_cancel.flag")
    if download_cancel.exists():
        download_cancel.unlink()
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
    except KeyboardInterrupt:
        print("Download cancelled by user.")
    finally:
        await downloader.close()


async def cmd_batch(args: argparse.Namespace, config: Config, db: Database) -> None:
    download_cancel = Path("data/download_cancel.flag")
    if download_cancel.exists():
        download_cancel.unlink()
    filepath = Path(args.file)
    if not filepath.exists():
        print(f"Error: File not found: {args.file}")
        return

    urls = filepath.read_text(encoding="utf-8").strip().splitlines()
    downloader = Downloader(config, db)
    try:
        results = await downloader.download_batch(urls)
        print(f"Batch complete: {len(results)} galleries downloaded")
    except KeyboardInterrupt:
        print("Batch download cancelled by user.")
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
    if cancel_file.exists() and not getattr(args, "preserve_cancel", False):
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
            # 无 next_cursor 且 page>1 说明上一轮已爬完，忽略续传文件从头开始
            if not resume_cursor and resume_page > 1:
                print("No next_cursor found, ignoring stale progress, fresh start.")
                progress_file.unlink()
                raise Exception("fresh start")
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

        lang_filter = _parse_languages(getattr(args, "languages", "") or "")
        print(f"Starting crawl: {args.source}, query='{args.query}'" + (f", languages={sorted(lang_filter)}" if lang_filter else ""))
        write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", 0, 0, "running", f"Page {resume_page}")

        async def on_page(page: int, items: list[dict], next_cursor: str):
            # 检查取消标记
            if cancel_file.exists():
                print("Cancel signal received, stopping crawl.")
                raise KeyboardInterrupt()

            page_ids = list(dict.fromkeys(
                item.get("source_id", "") for item in items if item.get("source_id", "")
            ))
            queue_job_id = getattr(args, "queue_job_id", 0)
            cached_states = {}
            if args.update and page_ids:
                cached_states = await db.get_search_cache_states(args.source, page_ids)
                # 断点续传时，本任务此前写入的记录不属于历史重复边界。
                if queue_job_id:
                    cached_states = {
                        sid: state for sid, state in cached_states.items()
                        if state[1] != queue_job_id
                    }

            saved_ids = []
            for item in items:
                sid = item.get("source_id", "")
                if sid and sid not in reserved_ids:
                    reserved_ids.add(sid)
                    saved_ids.append(sid)
            if saved_ids:
                batch = [it for it in items if it.get("source_id", "") in saved_ids]
                await db.save_search_results(batch)
            if queue_job_id:
                await db.set_search_cache_origin(args.source, [it.get("source_id", "") for it in items if it.get("source_id")], queue_job_id)

            # 获取元数据和封面
            eligible_ids = set()
            for idx, item in enumerate(items, 1):
                if cancel_file.exists():
                    raise KeyboardInterrupt()
                sid = item.get("source_id", "")
                if not sid:
                    continue
                if sid in metadata_done:
                    eligible_ids.add(sid)
                    continue
                try:
                    artist, thumb_path, uploaded_at, category, cover_url, language, title_jp, group_name, tags_json, tags_cn_json = await site.fetch_metadata_and_thumb(sid, thumbs_dir, item.get("thumbnail", ""))
                    # 语种后置过滤：Language 属性不在选中集合内则从缓存剔除
                    # （保留在 reserved_ids 中，避免断点续传时重新入库）
                    if lang_filter and language and language not in lang_filter:
                        await db.delete_search_cache(args.source, sid)
                        if thumb_path:
                            try:
                                Path(thumb_path).unlink(missing_ok=True)
                            except Exception:
                                pass
                        metadata_done.add(sid)
                        print(f"    Filtered out (language={language}): {sid}")
                        continue
                    await db.update_search_cache_metadata(args.source, sid, artist, thumb_path, uploaded_at, category, cover_url, language, title_jp, group_name, tags_json, tags_cn_json)
                    metadata_done.add(sid)
                    eligible_ids.add(sid)
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

            # 更新模式：遇到历史缓存且上传日期一致的作品则停止翻页（但仍更新当前页元数据）
            if args.update:
                for item in items:
                    sid = item.get("source_id", "")
                    if not sid or sid not in eligible_ids:
                        continue
                    cached_state = cached_states.get(sid)
                    if cached_state is not None:
                        cached_uploaded_at, _origin_job_id = cached_state
                        if _cached_item_is_updated(cached_uploaded_at, item):
                            print(f"  Cached gallery updated on site, not a stop point: {sid} (cached={cached_uploaded_at}, site={item.get('uploaded_at', '')})")
                            continue
                        print(f"  Existing cache entry found: {sid}, stopping further pages")
                        progress_file.write_text(json.dumps({
                            "page": page,
                            "next_cursor": next_cursor,
                            "saved_ids": list(reserved_ids),
                            "metadata_ids": list(metadata_done),
                            "query": args.query,
                        }))
                        write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", 0, page, "completed", f"已是最新，Page {page}")
                        remove_progress("crawl", CRAWL_TASK_ID)
                        return False
                    try:
                        existing = await db.get_gallery(args.source, sid)
                        if existing and existing.is_complete:
                            if _is_updated_on_site(existing, item):
                                print(f"  Gallery updated on site, not a stop point: {sid} (local={existing.uploaded_at}, site={item.get('uploaded_at', '')})")
                                continue
                            print(f"  Already-downloaded gallery found: {sid}, stopping further pages")
                            progress_file.write_text(json.dumps({
                                "page": page,
                                "next_cursor": next_cursor,
                                "saved_ids": list(reserved_ids),
                                "metadata_ids": list(metadata_done),
                                "query": args.query,
                            }))
                            write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", 0, page, "completed", f"已是最新，Page {page}")
                            remove_progress("crawl", CRAWL_TASK_ID)
                            return False
                    except Exception as e:
                        print(f"  Check download status failed for {sid}: {e}")

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
            return True

        async def on_wait(next_run: str, delay: int):
            write_progress("crawl", CRAWL_TASK_ID, f"爬取: {args.query}", 0, 0, "waiting", f"等待至 {next_run} ({delay}s)")

        cats = None
        if args.categories:
            cats = list(args.categories)
        t_start = time.time()
        try:
            await site.crawl_all_pages(
                query=args.query,
                categories=cats,
                resume_cursor=resume_cursor,
                resume_page=resume_page,
                on_page=on_page,
                on_wait=on_wait,
            )
        except KeyboardInterrupt:
            print("Crawl cancelled by user.")
            remove_progress("crawl", CRAWL_TASK_ID)
            if cancel_file.exists():
                cancel_file.unlink()
            return

        elapsed = time.time() - t_start
        elapsed_str = f"{int(elapsed//60)}m{int(elapsed%60)}s"
        print(f"Crawl complete. Total items cached: {len(reserved_ids)}, time: {elapsed_str}")
        remove_progress("crawl", CRAWL_TASK_ID)
        if progress_file.exists():
            progress_file.unlink()
        if cancel_file.exists():
            cancel_file.unlink()
    finally:
        await session.close()


async def cmd_crawl_worker(_args: argparse.Namespace, config: Config, db: Database) -> None:
    """Run queued crawl jobs sequentially in strict FIFO order."""
    print("Crawl queue worker started.")
    await db.recover_interrupted_crawl_jobs()
    while True:
        job = await db.claim_next_crawl_job()
        if job is None:
            if getattr(_args, "once", False):
                return
            await sleep(2)
            continue

        job_id = int(job["id"])
        try:
            categories = [int(value) for value in (job.get("categories") or "").split(",") if value]
        except ValueError:
            await db.finish_crawl_job(job_id, "failed", "Invalid category values")
            continue

        crawl_args = argparse.Namespace(
            source=job.get("source") or "exhentai",
            query=job.get("query") or "",
            force=bool(job.get("force_crawl")),
            categories=categories,
            languages=job.get("languages") or "",
            update=(
                job.get("refresh_target_id") is not None
                and not bool(job.get("force_crawl"))
            ),
            preserve_cancel=True,
            queue_job_id=job_id,
        )
        Path(f"data/crawl_progress_{crawl_args.source}.json").unlink(missing_ok=True)
        print(f"Queue job #{job_id} started: {crawl_args.query}")
        try:
            with _exclusive_crawl_lock(blocking=True):
                await cmd_crawl(crawl_args, config, db)
            status = await db.get_crawl_job_status(job_id)
            final_status = "cancelled" if status == "cancel_requested" else "completed"
            await db.finish_crawl_job(job_id, final_status)
            if final_status == "completed" and job.get("refresh_target_id") is None:
                created = await db.register_completed_crawl_job(job_id)
                if created:
                    print(f"Queue job #{job_id} registered as refresh target.")
            print(f"Queue job #{job_id} {final_status}.")
        except Exception as exc:
            await db.finish_crawl_job(job_id, "failed", str(exc))
            print(f"Queue job #{job_id} failed: {exc}", file=sys.stderr)

async def cmd_update_artists(_args: argparse.Namespace, config: Config, db: Database) -> None:
    """Enqueue enabled completed-task refresh targets and ensure the queue is drained."""
    queued = await db.enqueue_enabled_refresh_targets()
    print(f"Queued {queued} enabled refresh target(s).")
    try:
        with _exclusive_crawl_lock(lock_name="crawl-worker.lock"):
            await cmd_crawl_worker(argparse.Namespace(once=True), config, db)
    except CrawlLockBusy:
        print("Crawl queue worker is already running; queued jobs will be processed there.")

async def cmd_verify(args: argparse.Namespace, config: Config, db: Database) -> None:
    """校对缓存的画廊：对比元数据、重下封面"""
    source = args.source
    CHECKPOINT = Path(f"data/verify_checkpoint_{source}.json")
    CANCEL = Path(f"data/verify_cancel_{source}.flag")
    PROGRESS_ID = f"verify_{source}"
    BATCH_SIZE = 100

    if CANCEL.exists():
        CANCEL.unlink()

    if CHECKPOINT.exists():
        cp = json.loads(CHECKPOINT.read_text())
        source_ids = cp["source_ids"]
        start_idx = cp["current_index"]
        total = cp["total"]
        mismatches = cp.get("mismatches", 0)
        errors = cp.get("errors", 0)
        verified = cp.get("verified", 0)
        print(f"Resuming verify: {verified}/{total} done, {mismatches} mismatches, from index {start_idx}")
    else:
        source_ids = await db.get_cached_source_ids(source)
        total = len(source_ids)
        start_idx = 0
        mismatches = 0
        errors = 0
        verified = 0
        print(f"Starting verify: {total} galleries")
        CHECKPOINT.write_text(json.dumps({
            "source_ids": source_ids, "current_index": 0,
            "total": total, "verified": 0, "mismatches": 0, "errors": 0,
        }))

    if total == 0:
        print("No galleries to verify.")
        return

    thumbs_dir = f"data/thumbs/{source}"
    session = SessionManager(config)
    try:
        if source == "exhentai":
            site = ExhentaiSite(config, session)
        else:
            site = NhentaiSite(config, session)

        write_progress("verify", PROGRESS_ID, f"校对: {source}", total, verified + errors, "running", f"{verified}/{total}")

        while start_idx < total:
            if CANCEL.exists():
                print("Verify cancelled.")
                break

            batch = source_ids[start_idx:start_idx + BATCH_SIZE]
            batch_errors = 0

            for sid in batch:
                if CANCEL.exists():
                    break
                try:
                    row = await db.get_search_cache_row(source, sid)
                    old_cover = (row or {}).get("thumbnail", "") or ""
                    new = await site.verify_gallery(sid, thumbs_dir, old_cover, (row or {}).get("thumb_path", "") or "")
                    if "error" in new:
                        print(f"  SKIP {sid}: {new['error']}")
                        errors += 1
                        continue

                    if row:
                        diff = {}
                        field_map = {"artist":"artist","uploaded_at":"uploaded_at","category":"category",
                                     "language":"language","title_jp":"title_jp","group_name":"group_name","tags":"tags_json"}
                        for field, rfield in field_map.items():
                            old_val = row.get(field, "") or ""
                            new_val = new.get(rfield, "") or ""
                            if field == "language":
                                if str(old_val).lower() != str(new_val).lower():
                                    diff[field] = (old_val, new_val)
                            elif str(old_val) != str(new_val):
                                diff[field] = (old_val, new_val)
                        old_pages = row.get("total_pages", 0) or 0
                        if int(old_pages) != int(new.get("total_pages", 0)):
                            diff["total_pages"] = (old_pages, new["total_pages"])

                        # 封面 URL 变化也计为差异
                        old_cover = row.get("thumbnail", "") or ""
                        new_cover = new.get("cover_url", "") or ""
                        if old_cover != new_cover:
                            diff["cover_url"] = (old_cover, new_cover)

                        if diff:
                            mismatches += 1
                            print(f"  MISMATCH {sid}: {diff}")

                        # don't overwrite tags with empty
                        new_tags = new["tags_json"]
                        new_tags_cn = new.get("tags_cn_json", "")
                        if row and (not new_tags or new_tags == '""' or new_tags == "[]"):
                            new_tags = row.get("tags", "") or ""
                            new_tags_cn = row.get("tags_cn", "") or ""
                        await db.update_search_cache_metadata(
                            source, sid,
                            new["artist"], new["thumb_path"],
                            new["uploaded_at"], new["category"],
                            new["cover_url"], new["language"],
                            new["title_jp"], new["group_name"],
                            new_tags, new_tags_cn,
                        )
                    verified += 1
                    print(f"  OK {sid}")
                except Exception as e:
                    errors += 1
                    print(f"  ERROR {sid}: {e}")

                details = f"{verified}/{total}"
                write_progress("verify", PROGRESS_ID, f"校对: {source}", total, verified + errors, "running", details)

            start_idx += BATCH_SIZE
            CHECKPOINT.write_text(json.dumps({
                "source_ids": source_ids, "current_index": start_idx,
                "total": total, "verified": verified, "mismatches": mismatches,
                "errors": errors, "updated_at": time.time(),
            }))

            if start_idx < total and not CANCEL.exists():
                delay = random.randint(60, 300)
                next_run = time.strftime("%H:%M:%S", time.localtime(time.time() + delay))
                details = f"{verified}/{total}"
                if mismatches or errors:
                    details += f"  △{mismatches} ✗{errors}"
                print(f"Batch done ({details}), next at {next_run}, waiting {delay}s...")
                write_progress("verify", PROGRESS_ID, f"校对: {source}", total, verified + errors, "waiting", f"等待至 {next_run}  {details}")
                await sleep(delay)

        print(f"Verify finished: {verified}/{total}  △{mismatches} ✗{errors}")
        remove_progress("verify", PROGRESS_ID)
        if CHECKPOINT.exists():
            CHECKPOINT.unlink()
        if CANCEL.exists():
            CANCEL.unlink()
    finally:
        await session.close()


async def cmd_verify_single(args: argparse.Namespace, config: Config, db: Database) -> None:
    source = args.source
    source_id = args.source_id
    thumbs_dir = f"data/thumbs/{source}"

    # suppress logger output - write output to temp file instead of stdout
    import logging
    logging.getLogger("ehlib").handlers.clear()

    out_file = Path(f"data/verify_single_result.json")
    session = SessionManager(config)
    try:
        if source == "exhentai":
            site = ExhentaiSite(config, session)
        else:
            site = NhentaiSite(config, session)

        row = await db.get_search_cache_row(source, source_id)
        old = dict(row) if row else None
        old_cover = (old or {}).get("thumbnail", "") or ""

        result = await site.verify_gallery(source_id, thumbs_dir, old_cover, (old or {}).get("thumb_path", "") or "")

        if "error" in result:
            out_file.write_text(json.dumps({"error": result["error"], "old": old}, ensure_ascii=False))
            return

        diff = {}
        if old:
            for field, rfield in (("artist","artist"), ("uploaded_at","uploaded_at"), ("category","category"),
                                  ("language","language"), ("title_jp","title_jp"), ("group_name","group_name"),
                                  ("tags","tags_json")):
                ov = (old.get(field, "") or "").strip()
                nv = (result.get(rfield, "") or "").strip()
                if field == "language":
                    if ov.lower() != nv.lower():
                        diff[field] = (ov, nv)
                elif ov != nv:
                    diff[field] = (ov, nv)
            op = old.get("total_pages", 0) or 0
            np = result.get("total_pages", 0) or 0
            if int(op) != int(np):
                diff["total_pages"] = (op, np)
            oc = (old.get("thumbnail", "") or "").strip()
            nc = (result.get("cover_url", "") or "").strip()
            if oc != nc:
                diff["cover_url"] = (oc, nc)

        # update DB with new data so the thumb is immediately reflected
        # but don't overwrite tags with empty
        new_tags = result["tags_json"]
        new_tags_cn = result.get("tags_cn_json", "")
        if row and (not new_tags or new_tags == '""' or new_tags == "[]"):
            new_tags = row.get("tags", "") or ""
            new_tags_cn = row.get("tags_cn", "") or ""
        await db.update_search_cache_metadata(
            source, source_id,
            result["artist"], result["thumb_path"],
            result["uploaded_at"], result["category"],
            result["cover_url"], result["language"],
            result["title_jp"], result["group_name"],
            new_tags, new_tags_cn,
        )

        output = {"old": old, "new": result, "diff": diff}
        out_file.write_text(json.dumps(output, ensure_ascii=False))
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


async def cmd_export_package(args: argparse.Namespace, _config: Config, db: Database) -> None:
    import zipfile
    output = getattr(args, "output", None)
    zip_path = getattr(args, "zip", None)
    if not output or not zip_path:
        print("--output and --zip are required")
        return
    # export metadata json
    await db.export_json(output)
    # create zip
    data_dir = Path("data")
    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED) as zf:
        zf.write(output, arcname="metadata.json")
        zf.write(str(data_dir / "ehlib.db"), arcname="ehlib.db")
        thumbs_dir = data_dir / "thumbs"
        if thumbs_dir.is_dir():
            for f in sorted(thumbs_dir.rglob("*")):
                if f.is_file():
                    zf.write(f, arcname=str(f.relative_to(data_dir)))
        Path(output).unlink(missing_ok=True)
    print(f"Exported package to {zip_path}")


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


async def cmd_update_translations(_args: argparse.Namespace, _config: Config, _db: Database) -> None:
    from ehlib.translate.tag_translator import download_latest
    try:
        path = download_latest()
        print(f"Translation database updated: {path}")
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)


async def cmd_translate_tags(args: argparse.Namespace, _config: Config, db: Database) -> None:
    from ehlib.translate.tag_translator import TagTranslator
    source = args.source or "exhentai"
    translator = TagTranslator()
    if not translator.load():
        print("Translation database not found. Run 'update-translations' first.", file=sys.stderr)
        return

    rows = await db.get_all_cache_tags(source)
    total = len(rows)
    if total == 0:
        print("No cached tags to translate.")
        return

    print(f"Translating {total} cache entries...")
    done = 0
    for row in rows:
        tags_json = row["tags"]
        tags_cn = translator.translate_tags(tags_json)
        if tags_cn and tags_cn != tags_json:
            await db.update_cache_tags_cn(source, row["source_id"], tags_cn)
        done += 1
        if done % 50 == 0:
            print(f"  {done}/{total}")
    print(f"Done. {done} entries processed.")


async def cmd_translate_query_label(args: argparse.Namespace, _config: Config, _db: Database) -> None:
    from ehlib.translate.tag_translator import TagTranslator

    translator = TagTranslator()
    if not translator.load():
        print(args.query)
        return
    print(translator.translate_query_label(args.query) or args.query)


async def cmd_backfill_search_names(_args: argparse.Namespace, _config: Config, db: Database) -> None:
    stats = await db.backfill_search_and_refresh_names()
    print(
        "Backfill complete: "
        f"search_presets={stats['search_presets']}, "
        f"refresh_targets={stats['refresh_targets']}"
    )


async def cmd_sync_exhentai_favorite_authors(args: argparse.Namespace, config: Config, db: Database) -> None:
    """扫 exhentai 收藏夹 favcat (默认 0/1/9)，按作者维度生成 refresh_targets。"""
    import random as random_module
    from datetime import datetime as datetime_module
    import aiosqlite as _aiosqlite

    t_start = time.time()
    summary: dict = {
        "cookies_ok": False,
        "favcats_requested": [],
        "favcats_visited": [],
        "favorites_list_pages_fetched": 0,
        "favorite_items_seen": 0,
        "favorite_items_detail_succeeded": 0,
        "favorite_items_detail_skipped": 0,
        "favorite_items_detail_failed": 0,
        "unique_artists": 0,
        "refresh_targets_created": 0,
        "refresh_targets_updated": 0,
        "refresh_targets_name_skipped_user_custom": 0,
        "refresh_targets_skipped_unchanged": 0,
        "refresh_targets_skipped_duplicate_existing": 0,
        "refresh_targets_origin_backfilled": 0,
        "removed_items_marked": 0,
        "errors": [],
        "request_timings": {
            "total_elapsed_s": 0.0,
            "list_avg_interval_s": 0.0,
            "detail_avg_interval_s": 0.0,
            "total_detail_requests": 0,
        },
    }

    # 1. 校验 cookies
    cookies_map = (config.cookies or {}).get("exhentai") or {}
    ipb_member_id = str(cookies_map.get("ipb_member_id") or "").strip()
    ipb_pass_hash = str(cookies_map.get("ipb_pass_hash") or "").strip()
    cookies_ok = bool(ipb_member_id and ipb_pass_hash)
    if not cookies_ok:
        summary["errors"].append("cookies_missing")
        summary["cookies_ok"] = False
        summary["request_timings"]["total_elapsed_s"] = round(time.time() - t_start, 3)
        print(json.dumps(summary, ensure_ascii=False, indent=2))
        print("Error: exhentai ipb_member_id / ipb_pass_hash not configured. Cannot access favorites.php.", file=sys.stderr)
        return
    summary["cookies_ok"] = True

    # 2. 解析参数
    favcats_raw = [s for s in str(getattr(args, "favcats", "0,1,9") or "0,1,9").split(",") if s.strip()]
    favcats: list[int] = []
    for raw in favcats_raw:
        try:
            c = int(raw.strip())
            if 0 <= c <= 9:
                favcats.append(c)
        except Exception:
            pass
    favcats = list(dict.fromkeys(favcats)) or [0, 1, 9]
    summary["favcats_requested"] = list(favcats)

    pages_per_cat = getattr(args, "pages_per_cat", None)
    try:
        pages_per_cat_int = int(pages_per_cat) if pages_per_cat not in (None, "", "0") else None
        if pages_per_cat_int is not None and pages_per_cat_int <= 0:
            pages_per_cat_int = None
    except Exception:
        pages_per_cat_int = None

    max_detail = getattr(args, "max_detail", None)
    try:
        max_detail_int = int(max_detail) if max_detail not in (None, "", "0") else None
        if max_detail_int is not None and max_detail_int <= 0:
            max_detail_int = None
    except Exception:
        max_detail_int = None

    dry_run = bool(getattr(args, "dry_run", False))
    no_upsert_name = bool(getattr(args, "no_upsert_name", False))
    force_metadata = bool(getattr(args, "force_metadata", False))

    delay_sec_raw = (config.request or {}).get("delay_between_requests")
    try:
        delay_sec = int(float(delay_sec_raw)) if delay_sec_raw is not None and str(delay_sec_raw).strip() != "" else 3
    except (TypeError, ValueError):
        delay_sec = 3
    LIST_DELAY_MIN = max(1, delay_sec * 2) + 1.0
    LIST_DELAY_MAX = max(1, delay_sec * 2) + 2.0
    DETAIL_DELAY_MIN = max(1, delay_sec * 3) + 2.0
    DETAIL_DELAY_MAX = max(1, delay_sec * 3) + 5.0
    BATCH_INTERVAL_MIN = 30.0
    BATCH_INTERVAL_MAX = 60.0
    BATCH_SIZE = 20

    synced_at = datetime_module.now().isoformat()
    list_intervals: list[float] = []
    detail_intervals: list[float] = []
    artist_favcats: dict[str, set[int]] = {}

    import io as _io_io
    import os as _pyos
    import traceback as _tb_io

    def _stdout_safe_write(text_line: str) -> None:
        line_bytes = (str(text_line or "") + "\n").encode("utf-8", errors="replace")
        try:
            buf = getattr(sys.stdout, "buffer", None)
            if buf is not None:
                buf.write(line_bytes)
                try: sys.stdout.flush()
                except Exception: pass
                try: buf.flush()
                except Exception: pass
                return
        except Exception:
            pass
        try:
            sys.stdout.write(str(text_line) + "\n")
            sys.stdout.flush()
        except Exception:
            pass

    _unhandled_log_path = None
    try:
        _eh_data_dir = _pyos.environ.get("EHLIB_DATA_DIR") or str(getattr(db, "_db_path", "") or "")
        if _eh_data_dir:
            _eh_data_dir = _pyos.path.dirname(_eh_data_dir) or "."
        if not _eh_data_dir or not _pyos.path.isdir(_eh_data_dir):
            _eh_data_dir = "."
        _unhandled_log_path = _pyos.path.join(_eh_data_dir, "sync_fav_last_crash.log")
    except Exception:
        _unhandled_log_path = None

    def _dump_unhandled(exc: BaseException) -> None:
        if not _unhandled_log_path:
            return
        try:
            import datetime as _dt_internal
            chunks = []
            chunks.append("===== sync_exhentai_favorite_authors unhandled " + _dt_internal.datetime.now().isoformat(timespec="seconds") + " =====")
            chunks.append("".join(_tb_io.format_exception(type(exc), exc, exc.__traceback__)))
            chunks.append("summary_so_far=" + json.dumps(summary, ensure_ascii=True, default=str))
            chunks.append("")
            with _io_io.open(_unhandled_log_path, "a", encoding="utf-8") as fp:
                fp.write("\n".join(chunks))
        except Exception:
            pass

    def _emit_event(payload: dict) -> None:
        import copy as _copy
        d = _copy.deepcopy(dict(payload or {}))
        if "__kind" not in d or not isinstance(d["__kind"], str):
            d["__kind"] = d.pop("kind", "event") if isinstance(d.get("kind"), str) else "event"
        if "ts" not in d:
            d["ts"] = time.time()
        def _sanitize(node):
            if isinstance(node, dict):
                return {str(k): _sanitize(v) for k, v in node.items()}
            if isinstance(node, (list, tuple)):
                return [_sanitize(v) for v in node]
            if isinstance(node, (set, frozenset)):
                return [_sanitize(v) for v in node]
            if isinstance(node, (str, int, float, bool)) or node is None:
                return node
            try:
                s = json.dumps(node, ensure_ascii=False, default=str)
            except Exception:
                s = repr(node)
            return s
        try:
            d = _sanitize(d)
        except Exception:
            d = {"__kind": d.get("__kind", "event"), "ts": time.time(), "sanitize_failed": True}
        # 策略：先尝试 UTF-8 明文（ensure_ascii=False + 直接 buffer 写 utf-8 bytes），如果写失败或 encoder 不支持，
        # 再兜底用 ensure_ascii=True（ASCII \uXXXX 兼容模式，保证不抛 UnicodeEncodeError）。
        line = None
        for use_ascii in (False, True):
            try:
                line = json.dumps(d, ensure_ascii=use_ascii, separators=(",", ":"))
                break
            except Exception:
                line = None
        if line is None:
            line = '{"__kind":"event","ts":%s,"ok":false}' % str(int(time.time()))
        _stdout_safe_write("__EVT__" + line)

    _emit_event({
        "__kind": "stage",
        "stage": "initializing",
        "message": f"cookies_ok={cookies_ok} favcats={favcats} dry_run={dry_run}",
        "dry_run": dry_run,
        "cookies_ok": cookies_ok,
        "favcats_requested": list(favcats),
    })

    session = SessionManager(config)
    try:
        site = ExhentaiSite(config, session)

        all_items: list[dict] = []
        seen_ids_by_favcat: dict[int, set[str]] = {}

        _emit_event({
            "__kind": "stage",
            "stage": "list",
            "message": "开始抓取列表页",
        })
        list_plan_total_pages_by_favcat: dict[int, int] = {int(c): 1 for c in favcats}

        for favcat in favcats:
            page = 0
            visited_max = -1
            visited_any = False
            cat_seen: set[str] = set()
            while True:
                if pages_per_cat_int is not None and page >= pages_per_cat_int:
                    break
                t_before = time.time()
                try:
                    page_items, max_page_idx = await site.fetch_favorites_page(favcat, page=page)
                except Exception as exc:
                    summary["errors"].append(f"list_favcat_{favcat}_page_{page}: {type(exc).__name__}: {exc}")
                    print(f"[favcat={favcat}] page {page} FAILED: {exc}", file=sys.stderr)
                    _emit_event({
                        "__kind": "error",
                        "stage": "list",
                        "scope": f"favcat_{favcat}",
                        "sub": f"page_{page}",
                        "error": f"{type(exc).__name__}: {exc}",
                    })
                    break
                finally:
                    t_after = time.time()
                    list_intervals.append(t_after - t_before)
                summary["favorites_list_pages_fetched"] += 1
                visited_any = True
                if visited_max < 0:
                    visited_max = max_page_idx
                visited_max = max(visited_max, max_page_idx)
                list_plan_total_pages_by_favcat[int(favcat)] = max(1, int(max_page_idx or 0) + 1)
                if pages_per_cat_int is not None:
                    list_plan_total_pages_by_favcat[int(favcat)] = min(
                        list_plan_total_pages_by_favcat[int(favcat)], int(pages_per_cat_int)
                    )

                total_list_pages_planned = sum(list_plan_total_pages_by_favcat.values())
                _emit_event({
                    "__kind": "progress",
                    "stage": "list",
                    "scope": f"favcat_{favcat}",
                    "sub": f"page_{page}",
                    "current": int(summary["favorites_list_pages_fetched"]),
                    "total": int(total_list_pages_planned),
                    "items_in_this_page": int(len(page_items)),
                    "items_seen": int(summary["favorite_items_seen"] + len(page_items)),
                    "favcat": int(favcat),
                    "page": int(page),
                    "max_page": int(max_page_idx or 0),
                })

                for it in page_items:
                    it["favcat"] = favcat
                    sid = it.get("source_id") or ""
                    if sid:
                        cat_seen.add(sid)
                        all_items.append(dict(it))
                summary["favorite_items_seen"] += len(page_items)

                if not dry_run:
                    _seen, updated = await db.upsert_remote_favorites(page_items, favcat, synced_at)
                    for s in _seen:
                        cat_seen.add(s)
                    _ = updated

                if len(page_items) < 25 or page >= max_page_idx:
                    break
                page += 1
                wait = random_module.uniform(LIST_DELAY_MIN, LIST_DELAY_MAX)
                print(f"[favcat={favcat}] page {page-1}/{max_page_idx} done -> wait {wait:.1f}s")
                await sleep(wait)

            if visited_any:
                summary["favcats_visited"].append(int(favcat))
            seen_ids_by_favcat[favcat] = cat_seen

            if not dry_run:
                removed = await db.mark_removed_remote_favorites("exhentai", favcat, cat_seen, synced_at)
                summary["removed_items_marked"] += int(removed or 0)

            if favcat != favcats[-1]:
                wait = random_module.uniform(DETAIL_DELAY_MIN, DETAIL_DELAY_MAX)
                print(f"[switch] moving to next favcat after {wait:.1f}s")
                _emit_event({
                    "__kind": "wait",
                    "stage": "list",
                    "message": f"切换下一个收藏夹前等待 {wait:.1f}s",
                    "wait_s": float(wait),
                })
                await sleep(wait)

        # 4. 详情页：逐本进 gid/token 拿全部作者（带节流和批次间隔）
        # 【顺序必须先构造 detail_queue，再 emit（否则 len=0 会导致后面空循环+结果为0）】
        detail_queue: list[dict] = []
        seen_detail: set[str] = set()
        in_memory_artists: dict[str, list[str]] = {}
        for item in all_items:
            sid = item.get("source_id") or ""
            if not sid or sid in seen_detail:
                continue
            seen_detail.add(sid)
            detail_queue.append(dict(item))

        if max_detail_int is not None:
            detail_queue = detail_queue[:max_detail_int]

        _emit_event({
            "__kind": "stage",
            "stage": "detail",
            "message": f"进入详情页阶段，候选 {len(detail_queue)} 本",
            "detail_queue_size": int(len(detail_queue)),
            "max_detail_limit": int(max_detail_int or 0),
        })
        _emit_event({
            "__kind": "progress",
            "stage": "detail",
            "current": 0,
            "total": int(len(detail_queue)),
            "detail_queue_size": int(len(detail_queue)),
            "max_detail_limit": int(max_detail_int or 0),
        })

        for i, item in enumerate(detail_queue, 1):
            sid = item.get("source_id") or ""
            # 如果 DB 里已经有 artists_json 且不强制重抓，就跳过
            if not dry_run and not force_metadata:
                async with _aiosqlite.connect(db._db_path) as _conn:
                    _conn.row_factory = _aiosqlite.Row
                    _cur = await _conn.execute(
                        "SELECT artists_json FROM remote_favorites "
                        "WHERE source=? AND source_id=? LIMIT 1",
                        ("exhentai", sid),
                    )
                    _row = await _cur.fetchone()
                    if _row and _row["artists_json"]:
                        try:
                            existing_list = json.loads(_row["artists_json"]) if _row["artists_json"] else []
                        except Exception:
                            existing_list = []
                        if isinstance(existing_list, list) and existing_list:
                            summary["favorite_items_detail_skipped"] += 1
                            _emit_event({
                                "__kind": "progress",
                                "stage": "detail",
                                "sub": "skip",
                                "source_id": sid,
                                "current": int(
                                    summary["favorite_items_detail_succeeded"]
                                    + summary["favorite_items_detail_failed"]
                                    + summary["favorite_items_detail_skipped"]
                                ),
                                "total": int(len(detail_queue)),
                                "detail_queue_size": int(len(detail_queue)),
                            })
                            continue

            t_before = time.time()
            try:
                artists = await site.fetch_gallery_artists(sid)
                success = True
            except Exception as exc:
                artists = []
                success = False
                summary["errors"].append(f"detail_{sid}: {type(exc).__name__}: {exc}")
                print(f"[detail] {sid} FAILED: {exc}", file=sys.stderr)
                _emit_event({
                    "__kind": "error",
                    "stage": "detail",
                    "source_id": sid,
                    "error": f"{type(exc).__name__}: {exc}",
                })
            finally:
                t_after = time.time()
                detail_intervals.append(t_after - t_before)

            summary["request_timings"]["total_detail_requests"] += 1
            if success:
                summary["favorite_items_detail_succeeded"] += 1
                artists_safe = list(artists or [])
                if artists_safe:
                    in_memory_artists[sid] = artists_safe
                if not dry_run:
                    await db.update_remote_favorite_artists(
                        "exhentai", sid, artists_safe, synced_at
                    )
                _emit_event({
                    "__kind": "progress",
                    "stage": "detail",
                    "sub": "ok",
                    "source_id": sid,
                    "title": str(item.get("title") or item.get("title_jp") or ""),
                    "artists": list(artists_safe),
                    "artists_count": int(len(artists_safe)),
                    "current": int(
                        summary["favorite_items_detail_succeeded"]
                        + summary["favorite_items_detail_failed"]
                        + summary["favorite_items_detail_skipped"]
                    ),
                    "total": int(len(detail_queue)),
                    "detail_queue_size": int(len(detail_queue)),
                })
            else:
                summary["favorite_items_detail_failed"] += 1
                _emit_event({
                    "__kind": "progress",
                    "stage": "detail",
                    "sub": "fail",
                    "source_id": sid,
                    "current": int(
                        summary["favorite_items_detail_succeeded"]
                        + summary["favorite_items_detail_failed"]
                        + summary["favorite_items_detail_skipped"]
                    ),
                    "total": int(len(detail_queue)),
                    "detail_queue_size": int(len(detail_queue)),
                })

            if i % BATCH_SIZE == 0 and i < len(detail_queue):
                wait = random_module.uniform(BATCH_INTERVAL_MIN, BATCH_INTERVAL_MAX)
                next_run = datetime_module.fromtimestamp(time.time() + wait).strftime("%H:%M:%S")
                print(f"[detail] batch {i}/{len(detail_queue)} done -> long wait {wait:.0f}s until {next_run}")
                _emit_event({
                    "__kind": "wait",
                    "stage": "detail",
                    "message": f"批次 {i}/{len(detail_queue)} 完成，长等待 {wait:.0f}s 至 {next_run}",
                    "wait_s": float(wait),
                    "batch_done": int(i),
                    "total": int(len(detail_queue)),
                })
                await sleep(wait)
                continue

            if i < len(detail_queue):
                wait = random_module.uniform(DETAIL_DELAY_MIN, DETAIL_DELAY_MAX)
                await sleep(wait)

        # 5. 聚合：{artist_tagkey: set[favcat, ...]}
        #   - artist 字符串统一从「显示别名」规范化到「tag key」：
        #       * 优先 regex 取 `artist:"<KEY>$"` 内部的 KEY（如果之前 query 形式）
        #       * 再去掉「puyocha | yo」显示文本里的别名分隔及其后部分，变成 puyocha
        #       * 小写 trim，方便去重
        #   - 兼容 remote_favorites.artists_json 旧版本中保存的是显示别名的情况
        _ARTIST_TAGKEY_FROM_QUERY = _re.compile(r'^artist\s*:\s*"([^"$]+)\$?"\s*$', _re.IGNORECASE)
        def _normalize_artist_tagkey(raw) -> str:
            s = str(raw or "").strip()
            if not s: return ""
            m = _ARTIST_TAGKEY_FROM_QUERY.match(s)
            if m: s = m.group(1).strip()
            if "|" in s:
                s = s.split("|", 1)[0].strip()
            # 去结尾 $（兼容直接写 artist:"xxx$" 的行尾）
            s = s.rstrip("$").strip()
            # unquote 兜底（%xx 编码）
            if "%" in s:
                try:
                    from urllib.parse import unquote as _unquote
                    s = _unquote(s)
                except Exception:
                    pass
            return s.strip()

        _emit_event({
            "__kind": "stage",
            "stage": "aggregate",
            "message": "按作者 tagkey 维度聚合（规范化别名）",
        })
        artist_favcats.clear()
        _sid_favcats: dict[str, set[int]] = {}
        for _it in all_items:
            _sid = _it.get("source_id") or ""
            if not _sid:
                continue
            _fc = int(_it.get("favcat") or 0)
            if _sid not in _sid_favcats:
                _sid_favcats[_sid] = set()
            _sid_favcats[_sid].add(_fc)

        if dry_run:
            # dry_run：仅用内存结果聚合（不连 DB）
            for _sid, _fcs in _sid_favcats.items():
                for _a in in_memory_artists.get(_sid) or []:
                    _a_key = _normalize_artist_tagkey(_a)
                    if not _a_key:
                        continue
                    if _a_key not in artist_favcats:
                        artist_favcats[_a_key] = set()
                    artist_favcats[_a_key].update(_fcs)
        else:
            # 正式模式：先 DB 聚合
            _fc_tuple = tuple(int(c) for c in favcats)
            _db_artists: dict[str, list[str]] = {}
            if _fc_tuple:
                _placeholders = ",".join("?" for _ in _fc_tuple)
                async with _aiosqlite.connect(db._db_path) as _conn:
                    _conn.row_factory = _aiosqlite.Row
                    _cur = await _conn.execute(
                        "SELECT source_id, favcat, artists_json FROM remote_favorites "
                        "WHERE source='exhentai' AND is_removed=0 "
                        "AND COALESCE(artists_json,'')<>'' "
                        "AND favcat IN (" + _placeholders + ")",
                        list(_fc_tuple),
                    )
                    _rows = await _cur.fetchall()
                for _r in _rows:
                    _sid_v = str(_r["source_id"] or "")
                    if not _sid_v:
                        continue
                    try:
                        _arr = json.loads(_r["artists_json"]) if _r["artists_json"] else []
                    except Exception:
                        _arr = []
                    if not isinstance(_arr, list):
                        continue
                    # 旧版本 remote_favorites 里存的是显示别名，这里统一转 tagkey 再入缓存
                    _normed = []
                    _seen_n = set()
                    for _x in _arr:
                        n = _normalize_artist_tagkey(_x)
                        if n and n not in _seen_n:
                            _seen_n.add(n); _normed.append(n)
                    if _normed:
                        _db_artists[_sid_v] = _normed

            # 合并内存结果（本轮详情页抓到的）兜底：某些条目可能 DB 里还没写入（=空串）但内存里有
            for _sid, _alist in in_memory_artists.items():
                if not _alist:
                    continue
                # 内存结果（新）是 tagkey 已保证，这里做一次统一 normalize 再 merge
                _normed = []; _seen_n = set()
                for _x in _alist:
                    n = _normalize_artist_tagkey(_x)
                    if n and n not in _seen_n:
                        _seen_n.add(n); _normed.append(n)
                if _normed and not _db_artists.get(_sid):
                    _db_artists[_sid] = _normed

            for _sid, _fcs in _sid_favcats.items():
                for _a in _db_artists.get(_sid) or []:
                    _a_key = _normalize_artist_tagkey(_a)
                    if not _a_key:
                        continue
                    if _a_key not in artist_favcats:
                        artist_favcats[_a_key] = set()
                    artist_favcats[_a_key].update(_fcs)

        summary["unique_artists"] = len(artist_favcats)
        summary["artists_tagkeys"] = sorted(artist_favcats.keys())
        _emit_event({
            "__kind": "progress",
            "stage": "aggregate",
            "unique_artists": int(len(artist_favcats)),
            "artists_tagkeys": sorted(artist_favcats.keys()),
        })

        # 诊断：如果跑了详情但没抽到任何作者，抛一条诊断错误到 summary（便于排查详情页 selector 失效）
        _detail_processed = (
            int(summary.get("favorite_items_detail_succeeded") or 0)
            + int(summary.get("favorite_items_detail_failed") or 0)
            + int(summary.get("favorite_items_detail_skipped") or 0)
        )
        if _detail_processed > 0 and int(summary["unique_artists"]) == 0:
            summary["errors"].append(
                f"diagnostic: detail_processed={_detail_processed} but unique_artists=0. "
                "Likely gallery detail page artist selector (#taglist tr) doesn't match your layout."
            )

        # 6. 生成/更新 refresh_targets
        if not dry_run:
            _emit_event({
                "__kind": "stage",
                "stage": "upsert",
                "message": f"写入 refresh_targets（{len(artist_favcats)} 位作者）",
                "unique_artists": int(len(artist_favcats)),
            })
            stats = await db.upsert_favorite_artist_refresh_targets(
                artist_favcats, no_upsert_name=no_upsert_name
            )
            summary["refresh_targets_created"] += int(stats.get("created") or 0)
            summary["refresh_targets_updated"] += int(stats.get("updated") or 0)
            summary["refresh_targets_name_skipped_user_custom"] += int(stats.get("name_skipped_user_custom") or 0)
            summary["refresh_targets_skipped_unchanged"] += int(stats.get("skipped_unchanged") or 0)
            summary["refresh_targets_skipped_duplicate_existing"] += int(stats.get("skipped_duplicate_existing") or 0)
            summary["refresh_targets_origin_backfilled"] += int(stats.get("origin_backfilled") or 0)
            _emit_event({
                "__kind": "progress",
                "stage": "upsert",
                "created": int(summary["refresh_targets_created"]),
                "updated": int(summary["refresh_targets_updated"]),
                "name_skipped_user_custom": int(summary["refresh_targets_name_skipped_user_custom"]),
                "skipped_unchanged": int(summary["refresh_targets_skipped_unchanged"]),
                "skipped_duplicate_existing": int(summary["refresh_targets_skipped_duplicate_existing"]),
                "origin_backfilled": int(summary["refresh_targets_origin_backfilled"]),
            })

    except KeyboardInterrupt:
        summary["errors"].append("keyboard_interrupt")
        print("Sync cancelled by user.", file=sys.stderr)
        _emit_event({
            "__kind": "error",
            "stage": summary.get("stage") or "running",
            "error": "keyboard_interrupt",
        })
    except BaseException as _top_level_exc:
        summary["errors"].append(f"unhandled:{type(_top_level_exc).__name__}:{_top_level_exc}")
        try:
            _dump_unhandled(_top_level_exc)
        except Exception:
            pass
        print(f"[sync-fav-authors] unhandled {type(_top_level_exc).__name__}: {_top_level_exc}", file=sys.stderr)
        raise
    finally:
        try:
            await session.close()
        except Exception:
            pass

    total_elapsed = time.time() - t_start
    summary["request_timings"]["total_elapsed_s"] = round(total_elapsed, 3)
    if list_intervals:
        summary["request_timings"]["list_avg_interval_s"] = round(sum(list_intervals) / len(list_intervals), 3)
    if detail_intervals:
        summary["request_timings"]["detail_avg_interval_s"] = round(sum(detail_intervals) / len(detail_intervals), 3)

    # ===== 构造 Web 前端友好的输出字段（保留原字段保证向后兼容） =====
    # 总数别名
    summary["total_favorite_items"] = int(summary.get("favorite_items_seen") or 0)
    summary["detail_fetched"] = int(
        (summary.get("favorite_items_detail_succeeded") or 0)
        + (summary.get("favorite_items_detail_failed") or 0)
    )
    summary["detail_errors"] = int(summary.get("favorite_items_detail_failed") or 0)
    summary["detail_skipped"] = int(summary.get("favorite_items_detail_skipped") or 0)
    summary["created"] = int(summary.get("refresh_targets_created") or 0)
    summary["updated"] = int(summary.get("refresh_targets_updated") or 0)
    summary["skipped"] = int(
        (summary.get("refresh_targets_name_skipped_user_custom") or 0)
        + (summary.get("refresh_targets_skipped_unchanged") or 0)
        + (summary.get("refresh_targets_skipped_duplicate_existing") or 0)
    )
    summary["skipped_duplicate_existing"] = int(summary.get("refresh_targets_skipped_duplicate_existing") or 0)
    summary["origin_backfilled"] = int(summary.get("refresh_targets_origin_backfilled") or 0)
    summary["scanned_favcats"] = list(summary.get("favcats_visited") or summary.get("favcats_requested") or [])
    summary["dry_run"] = bool(dry_run)
    summary["no_upsert_name"] = bool(no_upsert_name)
    summary["force_metadata"] = bool(force_metadata)

    # 作者 → 收藏夹集合 映射（JSON 可序列化：set→list）
    _artist_favcats_serializable = {}
    if artist_favcats:
        for _k, _v in artist_favcats.items():
            try:
                _artist_favcats_serializable[str(_k)] = sorted(int(x) for x in _v)
            except Exception:
                pass
    summary["artist_favcats_map"] = _artist_favcats_serializable

    # 失败条目列表（从扁平 errors 字符串里尽量还原成结构化，前端兼容旧 strings）
    _failed_items: list[dict] = []
    if summary.get("errors") and isinstance(summary["errors"], list):
        for _err in summary["errors"]:
            try:
                _err_str = str(_err or "")
            except Exception:
                _err_str = ""
            if not _err_str:
                continue
            if _err_str.startswith("detail_"):
                _rest = _err_str[len("detail_"):]
                if ": " in _rest:
                    _sid, _msg = _rest.split(": ", 1)
                    _failed_items.append({"source_id": _sid, "error": _msg})
                    continue
            if _err_str.startswith("list_favcat_"):
                _failed_items.append({"title": _err_str, "error": _err_str})
                continue
            _failed_items.append({"error": _err_str})
    summary["failed_items"] = _failed_items
    # ===== Web 友好输出结束 =====

    print(json.dumps(summary, ensure_ascii=False, indent=2))

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
    crawl.add_argument("--languages", type=str, default="", help="Comma-separated languages to keep (e.g. chinese,japanese,speechless); filtered by gallery Language attribute after metadata fetch")
    crawl.add_argument("--update", action="store_true", help="Stop when encountering already-downloaded galleries (update mode)")

    subparsers.add_parser("crawl-worker", help="Process queued crawl jobs sequentially")

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

    verify = subparsers.add_parser("verify", help="Verify cached gallery metadata & re-download covers")
    verify.add_argument("source", choices=["exhentai", "nhentai"], help="Source site")

    exp = subparsers.add_parser("export", help="Export metadata to JSON")
    exp.add_argument("--output", default="metadata.json", help="Output file path")
    exp.add_argument("--format", choices=["json"], default="json", help="Export format")

    exp_pkg = subparsers.add_parser("export-package", help="Export metadata + covers + DB as ZIP")
    exp_pkg.add_argument("--output", required=True, help="Temp JSON output path")
    exp_pkg.add_argument("--zip", required=True, help="Output ZIP file path")

    subparsers.add_parser("migrate-dirs", help="Migrate gallery directories to ID-only names")

    single_verify = subparsers.add_parser("verify-single", help="Verify a single gallery metadata & cover")
    single_verify.add_argument("source", choices=["nhentai", "exhentai"], help="Source site")
    single_verify.add_argument("source_id", help="Gallery source ID")

    refresh = subparsers.add_parser("refresh-metadata", help="Re-fetch metadata for an existing gallery (preserves local images)")
    refresh.add_argument("source", choices=["nhentai", "exhentai"], help="Source site")
    refresh.add_argument("source_id", help="Gallery source ID or gid/token for exhentai")

    ua = subparsers.add_parser("update-artists", help="Execute saved search presets in update mode (stop at already-downloaded)")
    ua.add_argument("--source", choices=["exhentai", "nhentai"], default="exhentai", help="Source site")

    ut = subparsers.add_parser("update-translations", help="Download the latest EhTagTranslation database")
    ut.add_argument("--source", choices=["exhentai", "nhentai"], default="exhentai", help="Source site (unused, for consistency)")

    tt = subparsers.add_parser("translate-tags", help="Batch-translate all cached tags using the translation database")
    tt.add_argument("--source", choices=["exhentai", "nhentai"], default="exhentai", help="Source site")

    tql = subparsers.add_parser("translate-query-label", help="Translate search query tokens into a readable preset label")
    tql.add_argument("query", help="Search query to translate")

    subparsers.add_parser("backfill-search-names", help="Backfill translated names for saved search presets and refresh targets")

    sync_fav = subparsers.add_parser(
        "sync-exhentai-favorite-authors",
        help="Sync exhentai favorites favcats (0/1/9) and create/update author-level periodic refresh targets",
    )
    sync_fav.add_argument("--favcats", default="0,1,9", help="Comma-separated favorite categories to sync (0-9). Default: 0,1,9")
    sync_fav.add_argument("--pages-per-cat", default=None, help="Max pages per favcat. Omit/empty = full scan.")
    sync_fav.add_argument("--max-detail", default=None, help="Max gallery detail pages to fetch for author extraction (smoke test).")
    sync_fav.add_argument("--dry-run", action="store_true", help="Do not write DB; only print what would be done.")
    sync_fav.add_argument("--no-upsert-name", action="store_true", help="Never overwrite the refresh_target name (protect user-customized names).")
    sync_fav.add_argument("--force-metadata", action="store_true", help="Force re-fetching gallery detail even if a cached artists_json already exists in remote_favorites.")

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
            "crawl-worker": cmd_crawl_worker,
            "list": cmd_list,
            "config": cmd_config,
            "retry": cmd_retry,
            "count-retry-pages": cmd_count_retry_pages,
            "verify": cmd_verify,
            "verify-single": cmd_verify_single,
            "export": cmd_export,
            "export-package": cmd_export_package,
            "migrate-dirs": cmd_migrate_dirs,
            "refresh-metadata": cmd_refresh_metadata,
            "update-artists": cmd_update_artists,
            "update-translations": cmd_update_translations,
            "translate-tags": cmd_translate_tags,
            "translate-query-label": cmd_translate_query_label,
            "backfill-search-names": cmd_backfill_search_names,
            "sync-exhentai-favorite-authors": cmd_sync_exhentai_favorite_authors,
            "recover-orphans": cmd_recover_orphans,
        }
        handler = commands.get(args.command)
        if handler:
            if args.command == "crawl":
                try:
                    with _exclusive_crawl_lock():
                        await handler(args, config, db)
                except (CrawlLockBusy, CrawlLockError) as exc:
                    print(f"Error: {exc}", file=sys.stderr)
            elif args.command == "crawl-worker":
                try:
                    with _exclusive_crawl_lock(lock_name="crawl-worker.lock"):
                        await handler(args, config, db)
                except CrawlLockBusy:
                    print("Crawl queue worker is already running.")
                except CrawlLockError as exc:
                    print(f"Error: {exc}", file=sys.stderr)
            else:
                await handler(args, config, db)
            return

    asyncio.run(run())


if __name__ == "__main__":
    main()
