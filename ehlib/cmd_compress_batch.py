import argparse
import json
import os
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

from ehlib.cmd_compress import main as compress_one
from ehlib.models.database import DB_PATH, ensure_gallery_compression_columns_v1


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="逐本压缩一批已排队画廊")
    parser.add_argument("--gallery-ids", required=True, help="逗号分隔的 galleries.id")
    parser.add_argument("--work-root", default="data/downloads/compress_work")
    parser.add_argument("--progress-file", default="data/progress/compress_batch.json")
    parser.add_argument("--db-path", default=str(DB_PATH))
    parser.add_argument("--quality", type=int, default=65)
    parser.add_argument("--speed", type=int, default=5)
    parser.add_argument("--min-savings", type=float, default=5.0)
    return parser


def _parse_ids(raw: str) -> list[int]:
    result: list[int] = []
    seen: set[int] = set()
    for part in str(raw).split(","):
        part = part.strip()
        if not part:
            continue
        gallery_id = int(part)
        if gallery_id <= 0:
            raise ValueError("gallery id 必须为正整数")
        if gallery_id not in seen:
            seen.add(gallery_id)
            result.append(gallery_id)
    if not result:
        raise ValueError("gallery id 列表为空")
    return result


def _write_progress(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    data = dict(payload)
    data["updated_at"] = datetime.now(timezone.utc).isoformat(timespec="seconds")
    temp = path.with_name(path.name + ".tmp")
    temp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(temp, path)


def _gallery_row(conn: sqlite3.Connection, gallery_id: int) -> dict | None:
    conn.row_factory = sqlite3.Row
    row = conn.execute(
        "SELECT id,title,title_jp,total_pages,compression_status FROM galleries WHERE id=?",
        (gallery_id,),
    ).fetchone()
    return dict(row) if row is not None else None


def _reset_queued(conn: sqlite3.Connection, gallery_ids: list[int]) -> int:
    changed = 0
    now = datetime.now(timezone.utc).isoformat(timespec="seconds")
    for gallery_id in gallery_ids:
        cur = conn.execute(
            "UPDATE galleries SET compression_status='',updated_at=? WHERE id=? AND compression_status='queued'",
            (now, gallery_id),
        )
        changed += int(cur.rowcount or 0)
    conn.commit()
    return changed


def main(argv: list[str] | None = None) -> int:
    args = _parser().parse_args(argv)
    try:
        gallery_ids = _parse_ids(args.gallery_ids)
    except (TypeError, ValueError) as exc:
        print(f"批量参数无效: {exc}", file=sys.stderr)
        return 1

    if not (1 <= args.quality <= 100 and 0 <= args.speed <= 10 and 0 <= args.min_savings <= 100):
        print("压缩参数越界", file=sys.stderr)
        return 1

    progress_path = Path(args.progress_file)
    db_path = Path(args.db_path)
    work_root = Path(args.work_root)
    per_gallery_progress_root = progress_path.parent
    counters = {
        "finished_count": 0,
        "review_count": 0,
        "skipped_count": 0,
        "failed_count": 0,
    }
    base = {
        "status": "running",
        "message": "批量压缩正在启动",
        "gallery_ids": gallery_ids,
        "total_galleries": len(gallery_ids),
        "current_gallery_index": 0,
        "current_gallery_id": None,
        **counters,
    }
    _write_progress(progress_path, base)

    conn = sqlite3.connect(str(db_path))
    try:
        ensure_gallery_compression_columns_v1(conn)
        for index, gallery_id in enumerate(gallery_ids, start=1):
            row = _gallery_row(conn, gallery_id)
            title = "" if row is None else str(row.get("title_jp") or row.get("title") or "")
            total_pages = 0 if row is None else int(row.get("total_pages") or 0)
            current = {
                **base,
                **counters,
                "message": f"正在处理 #{gallery_id} {title}".strip(),
                "current_gallery_index": index,
                "current_gallery_id": gallery_id,
                "current_gallery_title": title,
                "current_gallery_total_pages": total_pages,
            }
            _write_progress(progress_path, current)

            if row is None or str(row.get("compression_status") or "") != "queued":
                counters["skipped_count"] += 1
                counters["finished_count"] += 1
                continue

            one_progress = per_gallery_progress_root / f"compress_{gallery_id}.json"
            one_args = [
                "--gallery-id", str(gallery_id),
                "--work-root", str(work_root),
                "--progress-file", str(one_progress),
                "--db-path", str(db_path),
                "--quality", str(args.quality),
                "--speed", str(args.speed),
                "--min-savings", str(args.min_savings),
                "--force",
            ]
            rc = compress_one(one_args)
            result = _gallery_row(conn, gallery_id) or {}
            status = str(result.get("compression_status") or "")
            if status == "user_review_required":
                counters["review_count"] += 1
            elif status == "skipped":
                counters["skipped_count"] += 1
            elif status == "failed" or rc != 0:
                counters["failed_count"] += 1
            else:
                counters["skipped_count"] += 1
            counters["finished_count"] += 1
            _write_progress(progress_path, {**current, **counters})

        final_status = "completed_with_errors" if counters["failed_count"] else "completed"
        _write_progress(progress_path, {
            **base,
            **counters,
            "status": final_status,
            "message": "批量压缩完成" if not counters["failed_count"] else "批量压缩完成，但有失败项目",
            "current_gallery_index": len(gallery_ids),
            "current_gallery_id": None,
        })
        return 0 if not counters["failed_count"] else 3
    except BaseException as exc:
        reset_count = _reset_queued(conn, gallery_ids)
        _write_progress(progress_path, {
            **base,
            **counters,
            "status": "failed",
            "message": f"批量任务异常: {type(exc).__name__}: {exc}",
            "reset_queued_count": reset_count,
        })
        if isinstance(exc, KeyboardInterrupt):
            return 130
        return 3
    finally:
        conn.close()


if __name__ == "__main__":
    raise SystemExit(main())
