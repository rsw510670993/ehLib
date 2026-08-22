import argparse
import logging
import sqlite3
import sys
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

_PAGE_EXTS_FOR_SCAN = (".jpg", ".jpeg", ".png", ".gif", ".webp")


class _ArgParser(argparse.ArgumentParser):
    def error(self, message: str) -> None:  # noqa: D401
        self.print_usage(sys.stderr)
        args = {"prog": self.prog, "message": message}
        self.exit(_EXIT_ARGS, "%(prog)s: error: %(message)s\n" % args)


def _build_parser() -> argparse.ArgumentParser:
    parser = _ArgParser(
        prog="cmd_compress",
        description="Phase 1: 单本两阶段压缩候选 → compress_work/<id>/",
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
        help="只读 DB + 扫描目录，打印摘要；不读图片字节 / 不写 webp / 不改 DB",
    )
    parser.add_argument(
        "--force",
        action="store_true",
        help="work 目录存在时清空重跑；不加则直接 skip 退出码 4",
    )
    parser.add_argument(
        "--force-candidates",
        action="store_true",
        help="为所有静态页生成审核候选（包括原本已是 WebP 或节省不足的页）；仍不改原图",
    )
    parser.add_argument("--quality", type=int, default=None, help="覆盖 ImageCompressor.quality (1..100)")
    parser.add_argument("--method", type=int, default=None, help="覆盖 ImageCompressor.method (0..6)")
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
        "--db-path",
        type=str,
        default=None,
        help=f"覆盖默认 DB 路径 (默认 {DB_PATH})",
    )
    return parser


def _validate_args(args: argparse.Namespace, parser: argparse.ArgumentParser) -> int:
    if args.source is not None and (not args.source_id or not str(args.source_id).strip()):
        parser.error("--source 必须同时提供 --source-id")
        return _EXIT_ARGS

    if args.quality is not None and not (1 <= int(args.quality) <= 100):
        parser.error("--quality 越界，要求 1..100")
        return _EXIT_ARGS
    if args.method is not None and not (0 <= int(args.method) <= 6):
        parser.error("--method 越界，要求 0..6")
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

    db_path = Path(args.db_path) if args.db_path else Path(DB_PATH)
    Path(db_path).parent.mkdir(parents=True, exist_ok=True)

    db_conn = sqlite3.connect(str(db_path))
    try:
        try:
            ensure_gallery_compression_columns_v1(db_conn)
        except Exception as exc:
            logger.error("DB 迁移失败: %s", exc)
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
            return _EXIT_NOT_FOUND

        gallery_dir_raw = gallery.get("_resolved_dir", "") or ""
        if not gallery_dir_raw:
            print("该 gallery 没有可用的 local_path/file_path 列，请确认漫画已下载完成且路径可读", file=sys.stderr)
            return _EXIT_NOT_FOUND

        gallery_dir = Path(gallery_dir_raw)
        if not gallery_dir.is_dir():
            print(
                f"gallery_dir 不存在或不是目录: {gallery_dir}。请确认漫画已下载完成且路径可读。",
                file=sys.stderr,
            )
            return _EXIT_NOT_FOUND

        if not int(gallery.get("is_complete") or 0):
            logger.warning("这本漫画 is_complete != 1，压缩可能只跑一部分（继续）")

        if args.dry_run:
            _print_dry_run(gallery, gallery_dir)
            return _EXIT_OK

        compressor = ImageCompressor(
            enabled=True,
            quality=88,
            method=4,
            min_savings_percent=5.0,
        )
        if args.quality is not None:
            compressor.quality = int(args.quality)
        if args.method is not None:
            compressor.method = int(args.method)
        if args.min_savings is not None:
            compressor.min_savings_percent = float(args.min_savings)

        final_status, info = compressor.compress_gallery_to_workdir(
            int(gallery["id"]),
            gallery_dir,
            Path(args.work_root),
            quality_override=args.quality,
            method_override=args.method,
            min_savings_override=args.min_savings,
            force=bool(args.force),
            force_candidates=bool(args.force_candidates),
            db_conn=db_conn,
        )

        if final_status == "user_review_required":
            logger.info(
                "压缩完成 → user_review_required  |  pages=%s  used_webp=%s  savings=%.2f%%  work_dir=%s",
                info.get("total_pages"),
                info.get("used_webp_count"),
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
