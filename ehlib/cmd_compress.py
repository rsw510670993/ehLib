import argparse
import json
import logging
import os
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

from ehlib.models.database import DB_PATH, ensure_gallery_compression_columns_v1
from ehlib.utils.image_compression import (
    ImageCompressor,
    cli_crash_recovery_cleanup,
)

logger = logging.getLogger("cmd_compress")

_EXIT_OK = 0
_EXIT_ARGS = 1
_EXIT_NOT_FOUND = 2
_EXIT_FAILED = 3
_EXIT_SKIP_WORKDIR_EXISTS = 4

_PAGE_EXTS_FOR_SCAN = (".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif")


class _ArgParser(argparse.ArgumentParser):
    def error(self, message: str) -> None:  # noqa: D401
        self.print_usage(sys.stderr)
        args = {"prog": self.prog, "message": message}
        self.exit(_EXIT_ARGS, "%(prog)s: error: %(message)s\n" % args)


def _build_parser() -> argparse.ArgumentParser:
    parser = _ArgParser(
        prog="cmd_compress",
        description="Phase 1: 单本 AVIF 两阶段压缩候选 → compress_work/<id>/",
    )
    grp = parser.add_mutually_exclusive_group(required=True)
    grp.add_argument("--gallery-id", type=int, help="直接按 galleries.id 主键定位")
    grp.add_argument("--source", type=str, help="配合 --source-id 按复合唯一键定位")

    parser.add_argument("--source-id", type=str, default=None, help="配合 --source 使用")
    parser.add_argument(
        "--work-root",
        type=str,
        default="data/downloads/compress_work",
        help="compress_work 根路径 (默认 data/downloads/compress_work)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="只读 DB + 扫描目录，打印摘要；不读图片字节 / 不写 AVIF / 不改 DB",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="work 目录存在时清空重跑；不加则直接 skip 退出码 4",
    )
    parser.add_argument(
        "--force-candidates",
        action="store_true",
        help="忽略最小节省率保留所有变小候选；相等或增大的结果仍会丢弃",
    )
    parser.add_argument("--quality", type=int, default=None, help="覆盖 ImageCompressor.quality (1..100)")
    parser.add_argument(
        "--speed",
        "--method",
        dest="speed",
        type=int,
        default=None,
        help="覆盖 AVIF 编码 speed (0..10；--method 为旧参数兼容别名)",
    )
    parser.add_argument(
        "--min-savings",
        type=float,
        default=None,
        help="覆盖 ImageCompressor.min_savings_percent (0..100)",
    )
    parser.add_argument(
        "--log-level",
        choices=("DEBUG", "INFO", "WARNING", "ERROR"),
        default="INFO",
        help="Python logging 级别",
    )
    parser.add_argument(
        "--progress-file",
        type=str,
        default=None,
        help="可选：把页级进度原子写入 JSON 文件，供 Web 维护页轮询",
    )
    parser.add_argument(
        "--db-path",
        type=str,
        default=None,
        help=f"覆盖默认 DB 路径 (默认 {DB_PATH})",
    )
    return parser


def _write_progress(path: Path | None, payload: dict) -> None:
    if path is None:
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    data = dict(payload)
    data["updated_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
    temp_path = path.with_name(path.name + ".tmp")
    temp_path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(temp_path, path)


def _validate_args(args: argparse.Namespace, parser: argparse.ArgumentParser) -> int:
    if args.source is not None and (not args.source_id or not str(args.source_id).strip()):
        parser.error("--source 必须同时提供 --source-id")
        return _EXIT_ARGS

    if args.quality is not None and not (1 <= int(args.quality) <= 100):
        parser.error("--quality 越界，要求 1..100")
        return _EXIT_ARGS
    if args.speed is not None and not (0 <= int(args.speed) <= 10):
        parser.error("--speed 越界，要求 0..10")
        return _EXIT_ARGS
    if args.min_savings is not None and not (0.0 <= float(args.min_savings) <= 100.0):
        parser.error("--min-savings 越界，要求 0..100")
        return _EXIT_ARGS
    return 0


def _query_gallery(db_conn: sqlite3.Connection, args: argparse.Namespace):
    cols = {row[1] for row in db_conn.execute("PRAGMA table_info(galleries)").fetchall()}
    select_cols = ["id", "source", "source_id", "is_complete"]
    # 兼容列：local_path 优先；没有就拿 file_path；实在没有就退 ''
    for c in ("local_path", "file_path", "gallery_dir"):
        if c in cols:
            select_cols.append(c)
            break

    sql = "SELECT " + ", ".join(select_cols) + " FROM galleries WHERE "
    params: list
    if args.gallery_id is not None:
        sql += "id = ?"
        params = [int(args.gallery_id)]
    else:
        sql += "source = ? AND source_id = ?"
        params = [str(args.source), str(args.source_id)]

    cur = db_conn.execute(sql, params)
    row = cur.fetchone()
    if row is None:
        return None

    desc = [d[0] for d in cur.description]
    result = dict(zip(desc, row))

    path_val = ""
    for c in ("local_path", "file_path", "gallery_dir"):
        if c in result and result[c]:
            path_val = str(result[c])
            break
    result["_resolved_dir"] = path_val
    return result


def _print_dry_run(gallery: dict, gallery_dir: Path) -> None:
    total_pages = 0
    total_bytes = 0
    by_ext: dict[str, int] = {}
    if gallery_dir.exists() and gallery_dir.is_dir():
        for entry in gallery_dir.glob("*"):
            if not entry.is_file():
                continue
            ext = entry.suffix.lower()
            if ext not in _PAGE_EXTS_FOR_SCAN:
                continue
            total_pages += 1
            sz = entry.stat().st_size
            total_bytes += sz
            by_ext[ext] = by_ext.get(ext, 0) + 1
    print(f"gallery_id     : {gallery.get('id')}")
    print(f"source/sourceId: {gallery.get('source', '')} / {gallery.get('source_id', '')}")
    print(f"gallery_dir    : {gallery_dir}")
    ext_str = ", ".join(f"{k}={v}" for k, v in sorted(by_ext.items())) or "<none>"
    print(f"pages scan     : total={total_pages}, total_bytes={total_bytes} ({ext_str})")


def main(argv: list[str] | None = None) -> int:
    parser = _build_parser()
    try:
        args = parser.parse_args(argv)
    except SystemExit as e:
        return int(e.code) if isinstance(e.code, int) else _EXIT_ARGS

    logging.basicConfig(
        level=getattr(logging, args.log_level),
        format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    )

    rc = _validate_args(args, parser)
    if rc != 0:
        return rc

    progress_path = Path(args.progress_file) if args.progress_file else None
    progress_gallery_id = int(args.gallery_id) if args.gallery_id is not None else None

    def report_progress(status: str, message: str, **extra) -> None:
        payload = {
            "gallery_id": progress_gallery_id,
            "status": status,
            "message": message,
            "current": 0,
            "total": 0,
        }
        payload.update(extra)
        _write_progress(progress_path, payload)

    db_path = Path(args.db_path) if args.db_path else Path(DB_PATH)
    Path(db_path).parent.mkdir(parents=True, exist_ok=True)

    db_conn = sqlite3.connect(str(db_path))
    try:
        try:
            ensure_gallery_compression_columns_v1(db_conn)
        except Exception as exc:
            logger.error("DB 迁移失败: %s", exc)
            report_progress("failed", f"DB 迁移失败: {exc}")
            return _EXIT_NOT_FOUND

        try:
            recovered = cli_crash_recovery_cleanup(db_conn)
            if recovered:
                logger.info("崩溃恢复：复位了 %s 本卡死的压缩任务", recovered)
        except Exception as exc:
            logger.warning("崩溃恢复 SQL 跳过 (非致命): %s", exc)

        gallery = _query_gallery(db_conn, args)
        if gallery is None:
            ident = (
                f"galleries.id={args.gallery_id}"
                if args.gallery_id is not None
                else f"source={args.source!r}, source_id={args.source_id!r}"
            )
            print(f"找不到 {ident}", file=sys.stderr)
            report_progress("failed", f"找不到 {ident}")
            return _EXIT_NOT_FOUND

        progress_gallery_id = int(gallery["id"])

        gallery_dir_raw = gallery.get("_resolved_dir", "") or ""
        if not gallery_dir_raw:
            print("该 gallery 没有可用的 local_path/file_path 列，请确认漫画已下载完成且路径可读", file=sys.stderr)
            report_progress("failed", "该 gallery 没有可用的本地路径")
            return _EXIT_NOT_FOUND

        gallery_dir = Path(gallery_dir_raw)
        if not gallery_dir.is_dir():
            print(
                f"gallery_dir 不存在或不是目录: {gallery_dir}。请确认漫画已下载完成且路径可读。",
                file=sys.stderr,
            )
            report_progress("failed", f"gallery_dir 不存在或不是目录: {gallery_dir}")
            return _EXIT_NOT_FOUND

        if not int(gallery.get("is_complete") or 0):
            logger.warning("这本漫画 is_complete != 1，压缩可能只跑一部分（继续）")

        if args.dry_run:
            _print_dry_run(gallery, gallery_dir)
            report_progress("completed", "dry-run 完成")
            return _EXIT_OK

        compressor = ImageCompressor(
            enabled=True,
            quality=65,
            speed=5,
            min_savings_percent=5.0,
        )
        if args.quality is not None:
            compressor.quality = int(args.quality)
        if args.speed is not None:
            compressor.speed = int(args.speed)
        if args.min_savings is not None:
            compressor.min_savings_percent = float(args.min_savings)

        def on_page_progress(event: dict) -> None:
            current = int(event.get("current", 0) or 0)
            total = int(event.get("total", 0) or 0)
            page_name = str(event.get("page_name", "") or "")
            message = f"正在处理 {page_name}" if page_name else "正在准备图片列表"
            report_progress("running", message, **event)

        report_progress("running", "正在启动压缩")
        try:
            final_status, info = compressor.compress_gallery_to_workdir(
                int(gallery["id"]),
                gallery_dir,
                Path(args.work_root),
                quality_override=args.quality,
                speed_override=args.speed,
                min_savings_override=args.min_savings,
                force=bool(args.force),
                force_candidates=bool(args.force_candidates),
                progress_callback=on_page_progress,
                db_conn=db_conn,
            )
        except Exception as exc:
            logger.exception("压缩任务异常: %s", exc)
            report_progress("failed", f"压缩任务异常: {type(exc).__name__}: {exc}")
            try:
                db_conn.execute(
                    "UPDATE galleries SET compression_status='failed',updated_at=? WHERE id=?",
                    (datetime.now(timezone.utc).isoformat(timespec="seconds"), int(gallery["id"])),
                )
                db_conn.commit()
            except Exception:
                logger.exception("写回 failed 状态失败")
            return _EXIT_FAILED

        progress_status = "completed" if final_status == "user_review_required" else final_status
        report_progress(
            progress_status,
            "压缩完成，等待人工审核" if final_status == "user_review_required" else f"压缩结束: {final_status}",
            current=int(info.get("total_pages", 0) or 0),
            total=int(info.get("total_pages", 0) or 0),
            used_candidate_count=int(info.get("used_candidate_count", 0) or 0),
            used_avif_count=int(info.get("used_avif_count", 0) or 0),
            failed_pages_count=int(info.get("failed_pages_count", 0) or 0),
            savings_pct_overall=float(info.get("savings_pct_overall", 0.0) or 0.0),
            compression_status=final_status,
        )

        if final_status == "user_review_required":
            logger.info(
                "压缩完成 → user_review_required  |  pages=%s  used_avif=%s  savings=%.2f%%  work_dir=%s",
                info.get("total_pages"),
                info.get("used_candidate_count"),
                float(info.get("savings_pct_overall", 0.0)),
                info.get("work_dir"),
            )
            if not info.get("work_dir_ok", True):
                logger.warning("work_dir_ok=false，建议人工检查磁盘或候选文件")
            return _EXIT_OK
        elif final_status == "failed":
            logger.error(
                "压缩失败（失败页占比 ≥10%%）  |  pages=%s  failed=%s  work_dir=%s",
                info.get("total_pages"),
                info.get("failed_pages_count"),
                info.get("work_dir"),
            )
            return _EXIT_FAILED
        else:
            reason = info.get("reason") if isinstance(info, dict) else ""
            if reason == "workdir_exists":
                logger.warning(
                    "work 目录已存在 (summary.json)；加 --force 重跑。path=%s",
                    info.get("summary_path"),
                )
                return _EXIT_SKIP_WORKDIR_EXISTS
            if reason == "lock_not_acquired":
                logger.info(
                    "DB 抢锁失败（状态非空串/queued）。若为上一次崩溃遗留，1h 自动复位；或手动 --force 清空后再跑。gallery_id=%s",
                    info.get("gallery_id"),
                )
                return _EXIT_OK
            if reason == "no_pages_found":
                print(
                    f"在 gallery_dir={info.get('gallery_dir')} 没找到任何 jpg/jpeg/png 页，请确认漫画已下载完成且路径可读",
                    file=sys.stderr,
                )
                return _EXIT_NOT_FOUND
            logger.info("skipped: %s  info=%s", final_status, info)
            return _EXIT_OK
    finally:
        try:
            db_conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    sys.exit(main())
