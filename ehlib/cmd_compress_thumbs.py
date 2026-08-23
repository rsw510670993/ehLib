import argparse
import json
import sqlite3
import time
from pathlib import Path
from typing import Callable

from ehlib.utils.image_compression import ImageCompressor


ProgressCallback = Callable[[dict], None]


def _thumb_reference_key(thumbs_root: Path, path: Path) -> tuple[str, str]:
    relative = path.relative_to(thumbs_root)
    source = relative.parts[0] if len(relative.parts) > 1 else ""
    return source, path.name.lower()


def _load_thumb_references(
    conn: sqlite3.Connection,
) -> dict[tuple[str, str], list[tuple[str, str]]]:
    references: dict[tuple[str, str], list[tuple[str, str]]] = {}
    rows = conn.execute(
        "SELECT source,source_id,thumb_path FROM search_cache "
        "WHERE COALESCE(thumb_path,'')<>''"
    ).fetchall()
    for source, source_id, thumb_path in rows:
        normalized = str(thumb_path or "").replace("\\", "/")
        name = normalized.rsplit("/", 1)[-1].lower()
        if not name:
            continue
        references.setdefault((str(source or ""), name), []).append(
            (str(source or ""), str(source_id or ""))
        )
    return references


def compress_thumbnails(
    thumbs_root: Path,
    db_path: Path,
    *,
    quality: int = 65,
    speed: int = 5,
    min_savings_percent: float = 5.0,
    limit: int = 0,
    dry_run: bool = False,
    progress_callback: ProgressCallback | None = None,
) -> dict:
    """把现有 WebP 封面迁移到 AVIF；仅在体积达到节省门槛时替换原文件。"""
    thumbs_root = thumbs_root.resolve()
    db_path = db_path.resolve()
    if not thumbs_root.is_dir():
        raise FileNotFoundError(f"封面目录不存在: {thumbs_root}")
    if not db_path.is_file():
        raise FileNotFoundError(f"数据库不存在: {db_path}")

    compressor = ImageCompressor(
        enabled=True,
        quality=quality,
        speed=speed,
        min_savings_percent=min_savings_percent,
    )
    if not compressor._ensure_pillow_avif():
        raise RuntimeError("Pillow AVIF encoder unavailable")

    files = sorted(path for path in thumbs_root.rglob("*.webp") if path.is_file())
    if limit > 0:
        files = files[:limit]

    summary = {
        "total": len(files),
        "processed": 0,
        "converted": 0,
        "skipped": 0,
        "failed": 0,
        "cleanup_failed": 0,
        "updated_rows": 0,
        "original_bytes": 0,
        "result_bytes": 0,
        "saved_bytes": 0,
        "savings_pct": 0.0,
        "quality": compressor.quality,
        "speed": compressor.speed,
        "min_savings_percent": compressor.min_savings_percent,
        "dry_run": bool(dry_run),
        "elapsed_seconds": 0.0,
        "converted_samples": [],
        "errors": [],
    }
    started = time.monotonic()

    conn = sqlite3.connect(str(db_path), timeout=30)
    try:
        conn.execute("PRAGMA busy_timeout=30000")
        references = _load_thumb_references(conn)
        for index, source_path in enumerate(files, start=1):
            original_size = 0
            try:
                data = source_path.read_bytes()
                original_size = len(data)
                used, candidate_data, stats = compressor.encode_avif_candidate(data)
                summary["original_bytes"] += original_size

                if not used or candidate_data is None:
                    summary["skipped"] += 1
                    summary["result_bytes"] += original_size
                else:
                    target_path = source_path.with_suffix(".avif")
                    rows = references.get(_thumb_reference_key(thumbs_root, source_path), [])
                    original_removed = bool(dry_run)
                    if not dry_run:
                        ImageCompressor._write_bytes_atomic(target_path, candidate_data)
                        try:
                            if rows:
                                conn.execute("BEGIN IMMEDIATE")
                                for source, source_id in rows:
                                    conn.execute(
                                        "UPDATE search_cache SET thumb_path=? "
                                        "WHERE source=? AND source_id=?",
                                        (str(target_path.resolve()), source, source_id),
                                    )
                            if rows:
                                conn.commit()
                        except Exception:
                            conn.rollback()
                            target_path.unlink(missing_ok=True)
                            raise
                        try:
                            source_path.unlink()
                            original_removed = True
                        except OSError as exc:
                            summary["cleanup_failed"] += 1
                            if len(summary["errors"]) < 20:
                                summary["errors"].append({
                                    "path": str(source_path),
                                    "error": f"原 WebP 删除失败: {exc}",
                                })
                    summary["converted"] += 1
                    summary["updated_rows"] += len(rows)
                    result_size = len(candidate_data) if original_removed else original_size + len(candidate_data)
                    summary["result_bytes"] += result_size
                    summary["saved_bytes"] += original_size - result_size
                    if len(summary["converted_samples"]) < 20:
                        summary["converted_samples"].append({
                            "source": str(source_path),
                            "target": str(target_path),
                            "original_bytes": original_size,
                            "avif_bytes": len(candidate_data),
                            "savings_pct": stats.get("savings_pct", 0.0),
                        })
            except Exception as exc:
                summary["failed"] += 1
                summary["result_bytes"] += original_size
                if len(summary["errors"]) < 20:
                    summary["errors"].append({
                        "path": str(source_path),
                        "error": f"{type(exc).__name__}: {exc}",
                    })
            finally:
                summary["processed"] = index
                if progress_callback is not None:
                    progress_callback(dict(summary))
    finally:
        conn.close()

    if summary["original_bytes"] > 0:
        summary["savings_pct"] = round(
            summary["saved_bytes"] / summary["original_bytes"] * 100.0,
            2,
        )
    summary["elapsed_seconds"] = round(time.monotonic() - started, 2)
    return summary


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Convert cached WebP thumbnails to AVIF")
    parser.add_argument("--thumbs-root", default="data/thumbs")
    parser.add_argument("--db-path", default="data/ehlib.db")
    parser.add_argument("--quality", type=int, default=65)
    parser.add_argument("--speed", type=int, default=5)
    parser.add_argument("--min-savings-percent", type=float, default=5.0)
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)

    def report(progress: dict) -> None:
        processed = int(progress["processed"])
        total = int(progress["total"])
        if processed == total or processed % 10 == 0:
            print(
                f"{processed}/{total} converted={progress['converted']} "
                f"skipped={progress['skipped']} failed={progress['failed']}",
                flush=True,
            )

    summary = compress_thumbnails(
        Path(args.thumbs_root),
        Path(args.db_path),
        quality=args.quality,
        speed=args.speed,
        min_savings_percent=args.min_savings_percent,
        limit=max(0, args.limit),
        dry_run=args.dry_run,
        progress_callback=report,
    )
    print(json.dumps(summary, ensure_ascii=False))
    return 0 if summary["failed"] == 0 else 1


if __name__ == "__main__":
    raise SystemExit(main())
