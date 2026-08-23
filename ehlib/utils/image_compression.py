import json
import logging
import os
import sqlite3
import time
import shutil
from datetime import datetime, timedelta, timezone
from io import BytesIO
from pathlib import Path
from typing import Any, Callable

logger = logging.getLogger(__name__)

_IMAGE_EXTENSIONS = (".avif", ".webp", ".jpg", ".jpeg", ".png", ".gif")
_FORMAT_EXTENSIONS = {
    "AVIF": ".avif",
    "WEBP": ".webp",
    "JPEG": ".jpg",
    "PNG": ".png",
    "GIF": ".gif",
}
_COMPRESSIBLE_PAGE_EXTS = (".jpg", ".jpeg", ".png", ".webp")
_TARGET_FORMAT = "AVIF"
_TARGET_EXTENSION = ".avif"


def _bounded_int(value: Any, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        parsed = default
    return max(minimum, min(maximum, parsed))


def _bounded_float(value: Any, default: float, minimum: float, maximum: float) -> float:
    try:
        parsed = float(value)
    except (TypeError, ValueError):
        parsed = default
    return max(minimum, min(maximum, parsed))


def cli_crash_recovery_cleanup(db_conn) -> int:
    """把 compressing 状态且 updated_at > 1h 前的本子复位回 ''。

    SELF-REVIEW 兼容：若 galleries 表还没 updated_at 列（极老 schema）→ 直接跳过，返回 0。
    注意：现有 galleries 表列为 updated_at（非 updated_at_iso），用 UTC ISO 字典序比较。
    """
    try:
        cols = {row[1] for row in db_conn.execute("PRAGMA table_info(galleries)").fetchall()}
    except Exception:
        return 0
    if "updated_at" not in cols or "compression_status" not in cols:
        return 0
    one_hour_ago_iso = (datetime.now(timezone.utc) - timedelta(hours=1)).isoformat(timespec="seconds")
    try:
        cur = db_conn.execute(
            """
            UPDATE galleries
               SET compression_status = ''
             WHERE compression_status = 'compressing'
               AND updated_at < ?
            """,
            (one_hour_ago_iso,),
        )
        db_conn.commit()
        return cur.rowcount or 0
    except sqlite3.OperationalError:
        return 0


class ImageCompressor:
    """Save downloaded pages atomically, using AVIF only when it is worthwhile."""

    def __init__(
        self,
        *,
        enabled: bool = True,
        quality: int = 65,
        speed: int = 5,
        min_savings_percent: float = 5,
        method: int | None = None,
    ) -> None:
        self.enabled = bool(enabled)
        self.quality = _bounded_int(quality, 65, 1, 100)
        # method 是旧 WebP 调用方的兼容别名；新代码统一使用 AVIF speed 0..10。
        effective_speed = speed if method is None else method
        self.speed = _bounded_int(effective_speed, 5, 0, 10)
        self.method = self.speed
        self.min_savings_percent = _bounded_float(min_savings_percent, 5.0, 0.0, 100.0)
        self._avif_available: bool | None = None
        self._warning_logged = False

    @staticmethod
    def find_existing_page_file(path: Path) -> Path | None:
        for ext in _IMAGE_EXTENSIONS:
            candidate = path.with_suffix(ext)
            if candidate.exists() and candidate.stat().st_size > 0:
                return candidate
        return None

    @staticmethod
    def _write_bytes_atomic(path: Path, data: bytes) -> Path:
        path.parent.mkdir(parents=True, exist_ok=True)
        temp_path = path.with_name(path.name + ".part")
        try:
            temp_path.write_bytes(data)
            temp_path.replace(path)
        finally:
            temp_path.unlink(missing_ok=True)
        return path

    def _warn_once(self, message: str, *args: object) -> None:
        if self._warning_logged:
            return
        logger.warning(message, *args)
        self._warning_logged = True

    def _ensure_pillow_avif(self) -> bool:
        if self._avif_available is not None:
            return self._avif_available
        try:
            from PIL import Image, ImageOps, features  # noqa: F401
        except ImportError:
            self._avif_available = False
            self._warn_once("Pillow is unavailable; downloaded pages will keep their original format")
            return False
        self._avif_available = bool(features.check("avif"))
        if not self._avif_available:
            self._warn_once("Pillow has no AVIF encoder; downloaded pages will keep their original format")
        return self._avif_available

    def _encode_one_page(
        self,
        data: bytes,
        *,
        quality: int | None = None,
        speed: int | None = None,
        method: int | None = None,
        min_savings_percent: float | None = None,
        force_candidate: bool = False,
    ) -> tuple[bool, bytes | None, dict]:
        """只做转码判断，不写磁盘。返回 (used_candidate, avif_bytes_or_None, stats_dict)。

        stats_dict keys 详见 PRD §4.1。
        """
        q = self.quality if quality is None else _bounded_int(quality, self.quality, 1, 100)
        speed_value = speed if speed is not None else method
        s = self.speed if speed_value is None else _bounded_int(speed_value, self.speed, 0, 10)
        ms = (
            self.min_savings_percent
            if min_savings_percent is None
            else _bounded_float(min_savings_percent, self.min_savings_percent, 0.0, 100.0)
        )

        stats: dict = {
            "used_candidate": False,
            "used_avif": False,
            "no_savings": False,
            "poor_ratio": False,
            "orig_bytes": len(data),
            "candidate_bytes": 0,
            "avif_bytes": 0,
            "savings_pct": 0.0,
            "src_format": "UNKNOWN",
            "candidate_format": _TARGET_FORMAT,
            "has_alpha": False,
            "is_animated": False,
            "forced_candidate": False,
            "below_min_savings": False,
            "exception": None,
        }

        if not self.enabled:
            return False, None, stats

        if not self._ensure_pillow_avif():
            return False, None, stats

        try:
            from PIL import Image, ImageOps
        except Exception as exc:
            stats["exception"] = f"Pillow import error: {exc!r}"
            return False, None, stats

        try:
            with Image.open(BytesIO(data)) as source_image:
                source_format = (source_image.format or "").upper()
                stats["src_format"] = source_format or "UNKNOWN"
                is_animated = bool(getattr(source_image, "is_animated", False))
                stats["is_animated"] = is_animated

                if is_animated or source_format == _TARGET_FORMAT:
                    return False, None, stats

                source_image.load()
                image = ImageOps.exif_transpose(source_image)
                has_alpha = image.mode in ("RGBA", "LA") or "transparency" in image.info
                stats["has_alpha"] = has_alpha
                image = image.convert("RGBA" if has_alpha else "RGB")

                encoded = BytesIO()
                image.save(
                    encoded,
                    format=_TARGET_FORMAT,
                    quality=q,
                    speed=s,
                    max_threads=max(1, min(8, os.cpu_count() or 1)),
                )
                candidate_data = encoded.getvalue()

            stats["candidate_bytes"] = len(candidate_data)
            stats["avif_bytes"] = len(candidate_data)
            orig_len = len(data)
            if orig_len > 0:
                stats["savings_pct"] = round((1.0 - len(candidate_data) / orig_len) * 100.0, 2)
            stats["poor_ratio"] = 0.0 < stats["savings_pct"] < 50.0

            saved_bytes = orig_len - len(candidate_data)
            stats["forced_candidate"] = bool(force_candidate)
            if saved_bytes <= 0:
                stats["no_savings"] = True
                return False, None, stats

            minimum_saving = orig_len * (ms / 100.0)
            if saved_bytes < minimum_saving:
                stats["below_min_savings"] = True
                if not force_candidate:
                    return False, None, stats

            stats["used_candidate"] = True
            stats["used_avif"] = True
            return True, candidate_data, stats
        except Exception as exc:
            stats["exception"] = f"{type(exc).__name__}: {exc!s}"
            return False, None, stats

    def save_page_bytes_with_stats(self, data: bytes, path: Path) -> tuple[Path, dict]:
        """保存单页并返回转码统计，供下载流程汇总整本节省率。"""
        try:
            used, candidate_bytes, stats = self._encode_one_page(
                data,
                quality=None,
                method=None,
                min_savings_percent=None,
            )
        except Exception as exc:
            self._warn_once("AVIF conversion failed; keeping original page format: %s", exc)
            stats = {
                "used_candidate": False,
                "used_avif": False,
                "orig_bytes": len(data),
                "candidate_bytes": 0,
                "avif_bytes": 0,
                "savings_pct": 0.0,
                "src_format": "UNKNOWN",
                "exception": f"{type(exc).__name__}: {exc}",
            }
            return self._write_bytes_atomic(path, data), stats

        if not used or candidate_bytes is None:
            src_format = stats.get("src_format", "") or ""
            target = path.with_suffix(_FORMAT_EXTENSIONS.get(src_format, path.suffix.lower()))
            return self._write_bytes_atomic(target, data), stats

        avif_path = path.with_suffix(_TARGET_EXTENSION)
        self._write_bytes_atomic(avif_path, candidate_bytes)
        logger.debug(
            "Compressed %s to AVIF: %d -> %d bytes (quality=%d, speed=%d)",
            path.name,
            len(data),
            len(candidate_bytes),
            self.quality,
            self.speed,
        )
        return avif_path, stats

    def save_page_bytes(self, data: bytes, path: Path) -> Path:
        saved_path, _ = self.save_page_bytes_with_stats(data, path)
        return saved_path

    @staticmethod
    def _build_compression_info_json(
        pages_stats: list[dict],
        gallery_id: int,
        work_dir: Path,
        gallery_dir: Path,
        started_at: datetime,
        finished_at: datetime,
        *,
        quality: int,
        speed: int,
        min_savings_percent: float,
        override_used: bool,
        force_candidates: bool,
        failed_pages_list: list[dict],
    ) -> dict:
        """构造 summary.json 与 DB compression_info 共享的同一份 dict。

        只走这一个构造函数，保证两处 JSON 字段不漂移。
        """
        total_pages = len(pages_stats)
        used_candidate_count = sum(1 for p in pages_stats if p.get("used_candidate"))
        failed_pages_count = len(failed_pages_list)
        skipped_pages_count = sum(
            1 for p in pages_stats if not p.get("used_candidate") and not p.get("exception")
        )
        orig_total = sum(int(p.get("orig_bytes", 0)) for p in pages_stats)
        encoded_candidate_total = sum(int(p.get("candidate_bytes", 0)) for p in pages_stats)
        candidate_total = sum(
            int(p.get("candidate_bytes", 0)) if p.get("used_candidate") else int(p.get("orig_bytes", 0))
            for p in pages_stats
        )
        if orig_total > 0:
            savings_pct_overall = round((1.0 - candidate_total / orig_total) * 100.0, 2)
        else:
            savings_pct_overall = 0.0

        disk_write_failures = sum(
            1 for fp in failed_pages_list
            if any(k in str(fp.get("reason", "")) for k in ("OSError", "PermissionError", "IsADirectoryError", "Disk"))
        )
        work_dir_ok = not (disk_write_failures >= 3 and failed_pages_count / max(total_pages, 1) < 0.10)

        pages_out: list[dict] = []
        for idx, p in enumerate(pages_stats):
            page_name = str(p.get("name", ""))
            page_stem = Path(page_name).stem
            page_index = max(0, int(page_stem) - 1) if page_stem.isdigit() else idx
            pages_out.append({
                "name": page_name,
                "page_index": page_index,
                "orig_bytes": int(p.get("orig_bytes", 0)),
                "candidate_bytes": int(p.get("candidate_bytes", 0)),
                "avif_bytes": int(p.get("avif_bytes", p.get("candidate_bytes", 0))),
                "savings_pct": float(p.get("savings_pct", 0.0)),
                "used_candidate": bool(p.get("used_candidate", False)),
                "used_avif": bool(p.get("used_avif", False)),
                "candidate_format": _TARGET_FORMAT,
                "candidate_file_name": (
                    page_stem + _TARGET_EXTENSION if p.get("used_candidate") else ""
                ),
                "src_format": str(p.get("src_format", "UNKNOWN")),
                "has_alpha": bool(p.get("has_alpha", False)),
                "is_animated": bool(p.get("is_animated", False)),
                "forced_candidate": bool(p.get("forced_candidate", False)),
                "no_savings": bool(p.get("no_savings", False)),
                "below_min_savings": bool(p.get("below_min_savings", False)),
                "poor_ratio": bool(p.get("poor_ratio", False)),
                "exception": p.get("exception"),
            })

        return {
            "schema_version": 2,
            "gallery_id": int(gallery_id),
            "gallery_dir": str(gallery_dir),
            "work_dir": str(work_dir),
            "tool": "pillow-avif-phase1",
            "target_format": _TARGET_FORMAT,
            "target_extension": _TARGET_EXTENSION,
            "quality": int(quality),
            "speed": int(speed),
            "min_savings_percent": float(min_savings_percent),
            "override_used": bool(override_used),
            "force_candidates": bool(force_candidates),
            "total_pages": int(total_pages),
            "used_candidate_count": int(used_candidate_count),
            "used_avif_count": int(used_candidate_count),
            "failed_pages_count": int(failed_pages_count),
            "skipped_pages_count": int(skipped_pages_count),
            "discarded_pages_count": int(skipped_pages_count),
            "orig_bytes_total": int(orig_total),
            "candidate_bytes_total": int(candidate_total),
            "avif_bytes_total": int(candidate_total),
            "encoded_candidate_bytes_total": int(encoded_candidate_total),
            "savings_pct_overall": float(savings_pct_overall),
            "failed_pages": list(failed_pages_list),
            "work_dir_ok": bool(work_dir_ok),
            "started_at": started_at.astimezone(timezone.utc).isoformat(timespec="seconds"),
            "finished_at": finished_at.astimezone(timezone.utc).isoformat(timespec="seconds"),
            "pages": pages_out,
        }

    def compress_gallery_to_workdir(
        self,
        gallery_id: int,
        gallery_dir: Path,
        work_root: Path,
        *,
        quality_override: int | None = None,
        speed_override: int | None = None,
        method_override: int | None = None,
        min_savings_override: float | None = None,
        force: bool = False,
        force_candidates: bool = False,
        progress_callback: Callable[[dict], None] | None = None,
        db_conn=None,
    ) -> tuple[str, dict]:
        """Phase 1 两阶段核心：第一阶段 → 只写候选 AVIF 到 compress_work，不碰原图。

        Returns (final_status, info_dict)，其中 info_dict 为字节级一致的公共 JSON。
        final_status ∈ { 'user_review_required', 'failed', 'skipped' }
        """
        if db_conn is None:
            raise ValueError("compress_gallery_to_workdir requires db_conn (sync sqlite3.Connection)")

        columns = {row[1] for row in db_conn.execute("PRAGMA table_info(galleries)").fetchall()}
        if "compression_savings_pct" not in columns:
            db_conn.execute(
                "ALTER TABLE galleries ADD COLUMN compression_savings_pct REAL DEFAULT NULL"
            )
            db_conn.commit()

        gallery_id = int(gallery_id)
        gallery_dir = Path(gallery_dir)
        work_root = Path(work_root)
        work_dir = work_root / str(gallery_id)

        effective_q = self.quality if quality_override is None else _bounded_int(quality_override, self.quality, 1, 100)
        legacy_speed_override = speed_override if speed_override is not None else method_override
        effective_s = (
            self.speed
            if legacy_speed_override is None
            else _bounded_int(legacy_speed_override, self.speed, 0, 10)
        )
        effective_ms = (
            self.min_savings_percent
            if min_savings_override is None
            else _bounded_float(min_savings_override, self.min_savings_percent, 0.0, 100.0)
        )
        override_used = not (
            quality_override is None
            and speed_override is None
            and method_override is None
            and min_savings_override is None
        )

        # 必须在检查/清空 work_dir 之前验证编码器。NAS 的 Python venv 若漏装
        # Pillow，不能把旧候选清空后伪装成“所有页面均跳过”。
        if not self._ensure_pillow_avif():
            raise RuntimeError(
                "Pillow/AVIF 编码器不可用；请先检查 NAS venv 的 Pillow 安装和 "
                "venv/lib/python3.12/site-packages/PIL 权限"
            )

        summary_path = work_dir / "summary.json"
        if summary_path.exists() and not force:
            return "skipped", {"reason": "workdir_exists", "summary_path": str(summary_path)}

        if force and work_dir.exists():
            # 直接清空目录内容，避免把旧 .deleted 目录不断嵌套到新的
            # .deleted 目录里。删除失败时必须中止，不能让旧候选混入新摘要。
            cleanup_errors: list[str] = []
            for sub in list(work_dir.iterdir()):
                try:
                    if sub.is_dir() and not sub.is_symlink():
                        shutil.rmtree(sub)
                    else:
                        sub.unlink(missing_ok=True)
                except Exception as exc:
                    cleanup_errors.append(f"{sub.name}: {type(exc).__name__}: {exc}")
            leftovers = list(work_dir.iterdir())
            if cleanup_errors or leftovers:
                details = "; ".join(cleanup_errors) or ", ".join(p.name for p in leftovers)
                raise RuntimeError(f"无法清空旧候选目录 {work_dir}: {details}")
        work_dir.mkdir(parents=True, exist_ok=True)
        if not work_dir.is_dir():
            raise RuntimeError(
                f"无法准备工作目录 work_dir={work_dir}（force={force}），请检查磁盘权限"
            )

        now_iso = datetime.now(timezone.utc).isoformat(timespec="seconds")
        lock_ok = False
        try:
            if force:
                # force: 允许覆盖 user_review_required / failed 已完成态或任何旧残留 queued/空串
                where_cond = """
                    compression_status IN ('', 'queued', 'user_review_required', 'failed', 'skipped')
                """
            else:
                # 非 force: 只抢空串或 queued 新入队
                where_cond = "compression_status IN ('', 'queued')"
            sql = (
                "UPDATE galleries"
                "   SET compression_status='compressing', updated_at = ?"
                " WHERE id = ? AND (" + where_cond + ")"
            )
            cur = db_conn.execute(sql, (now_iso, gallery_id))
            db_conn.commit()
            lock_ok = (cur.rowcount or 0) == 1
        except sqlite3.OperationalError:
            lock_ok = False
        if not lock_ok:
            return "skipped", {"reason": "lock_not_acquired", "gallery_id": gallery_id}

        page_files: list[Path] = []
        page_exts = _COMPRESSIBLE_PAGE_EXTS
        if gallery_dir.exists() and gallery_dir.is_dir():
            for entry in sorted(gallery_dir.glob("*")):
                if not entry.is_file():
                    continue
                ext = entry.suffix.lower()
                if ext in page_exts and entry.stem.isdigit():
                    page_files.append(entry)
        if not page_files:
            try:
                db_conn.execute(
                    "UPDATE galleries SET compression_status = '', updated_at = ? WHERE id = ?",
                    (datetime.now(timezone.utc).isoformat(timespec="seconds"), gallery_id),
                )
                db_conn.commit()
            except Exception:
                pass
            return "skipped", {"reason": "no_pages_found", "gallery_dir": str(gallery_dir)}

        if progress_callback is not None:
            try:
                progress_callback({
                    "current": 0,
                    "total": len(page_files),
                    "page_name": "",
                    "used_candidate_count": 0,
                    "used_avif_count": 0,
                    "failed_pages_count": 0,
                })
            except Exception:
                logger.debug("compression progress callback failed at start", exc_info=True)

        started_at = datetime.now(timezone.utc)
        pages_stats: list[dict] = []
        failed_pages_list: list[dict] = []

        for page_path in page_files:
            page_stat = {"name": page_path.name}
            exc: Exception | None = None
            candidate_bytes_actual: bytes | None = None
            for attempt in range(2):
                try:
                    data = page_path.read_bytes()
                    used, candidate_bytes, stats = self._encode_one_page(
                        data,
                        quality=effective_q,
                        speed=effective_s,
                        min_savings_percent=effective_ms,
                        force_candidate=force_candidates,
                    )
                    page_stat.update(stats)
                    page_stat["name"] = page_path.name
                    if used and candidate_bytes is not None:
                        target = work_dir / f"{page_path.stem}{_TARGET_EXTENSION}"
                        self._write_bytes_atomic(target, candidate_bytes)
                        candidate_bytes_actual = candidate_bytes
                    exc = None
                    break
                except Exception as e:
                    exc = e
                    if attempt == 0:
                        time.sleep(0.05)
                        continue
            if exc is not None:
                page_stat["exception"] = f"{type(exc).__name__}: {exc!s}"
                page_stat.setdefault("orig_bytes", 0)
                page_stat.setdefault("candidate_bytes", 0)
                page_stat.setdefault("avif_bytes", 0)
                page_stat.setdefault("savings_pct", 0.0)
                page_stat.setdefault("used_candidate", False)
                page_stat.setdefault("used_avif", False)
                page_stat.setdefault("no_savings", False)
                page_stat.setdefault("poor_ratio", False)
                page_stat.setdefault("src_format", "UNKNOWN")
                page_stat.setdefault("has_alpha", False)
                page_stat.setdefault("is_animated", False)
                page_stat.setdefault("forced_candidate", False)
                failed_pages_list.append({"name": page_path.name, "reason": page_stat["exception"]})
            pages_stats.append(page_stat)
            if progress_callback is not None:
                try:
                    progress_callback({
                        "current": len(pages_stats),
                        "total": len(page_files),
                        "page_name": page_path.name,
                        "used_candidate_count": sum(1 for item in pages_stats if item.get("used_candidate")),
                        "used_avif_count": sum(1 for item in pages_stats if item.get("used_candidate")),
                        "failed_pages_count": len(failed_pages_list),
                    })
                except Exception:
                    logger.debug("compression progress callback failed", exc_info=True)

        finished_at = datetime.now(timezone.utc)
        info_dict = self._build_compression_info_json(
            pages_stats,
            gallery_id,
            work_dir,
            gallery_dir,
            started_at,
            finished_at,
            quality=effective_q,
            speed=effective_s,
            min_savings_percent=effective_ms,
            override_used=override_used,
            force_candidates=force_candidates,
            failed_pages_list=failed_pages_list,
        )

        try:
            info_bytes = json.dumps(info_dict, ensure_ascii=False, indent=2).encode("utf-8")
            self._write_bytes_atomic(summary_path, info_bytes)
        except Exception as exc:
            failed_pages_list.append({"name": "summary.json", "reason": f"write summary failed: {exc!r}"})
            info_dict["failed_pages"] = list(failed_pages_list)
            info_dict["failed_pages_count"] = len(failed_pages_list)
            info_dict["work_dir_ok"] = False

        total_matched = len(page_files)
        fail_ratio = (len(failed_pages_list) / total_matched) if total_matched > 0 else 0.0
        if fail_ratio >= 0.10:
            final_status = "failed"
        elif int(info_dict.get("used_candidate_count", 0)) > 0:
            final_status = "user_review_required"
        else:
            final_status = "skipped"

        if final_status == "skipped":
            try:
                if work_dir.exists():
                    shutil.rmtree(work_dir)
            except Exception as exc:
                raise RuntimeError(f"无法清理无候选压缩工作目录 {work_dir}: {exc}") from exc
            info_dict["work_dir"] = ""
            info_dict["work_dir_cleaned"] = True

        info_bytes_for_db = json.dumps(info_dict, ensure_ascii=False, indent=2).encode("utf-8").decode("utf-8")
        stored_info = "" if final_status == "skipped" else info_bytes_for_db
        final_savings_pct = info_dict.get("savings_pct_overall") if final_status == "skipped" else None
        finish_iso = datetime.now(timezone.utc).isoformat(timespec="seconds")
        try:
            db_conn.execute(
                """
                UPDATE galleries
                   SET compression_status = ?,
                       compression_info = ?,
                       compression_savings_pct = CASE
                           WHEN ? = 'skipped' THEN ?
                           ELSE compression_savings_pct
                       END,
                       updated_at = ?
                 WHERE id = ?
                """,
                (final_status, stored_info, final_status, final_savings_pct, finish_iso, gallery_id),
            )
            db_conn.commit()
        except Exception as exc:
            logger.error("DB write compression_status/info failed for gallery_id=%s: %s", gallery_id, exc)
            try:
                db_conn.rollback()
            except Exception:
                logger.debug("DB rollback failed after compression result write error", exc_info=True)
            raise RuntimeError(f"failed to persist compression result for gallery_id={gallery_id}") from exc

        return final_status, info_dict
