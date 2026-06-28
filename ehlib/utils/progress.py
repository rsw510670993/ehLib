import json
import time
from pathlib import Path


PROGRESS_DIR = Path("data/progress")


def _safe_key(source: str, source_id: str) -> str:
    safe = source_id.replace("/", "_").replace("\\", "_").replace(":", "_")
    return f"{source}__{safe}"


def _progress_path(source: str, source_id: str) -> Path:
    return PROGRESS_DIR / f"{_safe_key(source, source_id)}.json"


def write_progress(
    source: str,
    source_id: str,
    title: str,
    total_pages: int,
    current: int,
    status: str,
    message: str = "",
) -> None:
    PROGRESS_DIR.mkdir(parents=True, exist_ok=True)
    data = {
        "source": source,
        "source_id": source_id,
        "title": title,
        "total_pages": total_pages,
        "current": current,
        "status": status,
        "message": message,
        "updated_at": time.time(),
    }
    _progress_path(source, source_id).write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")


def remove_progress(source: str, source_id: str) -> None:
    path = _progress_path(source, source_id)
    if path.exists():
        path.unlink()
