import logging
from io import BytesIO
from pathlib import Path
from typing import Any

logger = logging.getLogger(__name__)

_IMAGE_EXTENSIONS = (".webp", ".jpg", ".jpeg", ".png", ".gif")
_FORMAT_EXTENSIONS = {"WEBP": ".webp", "JPEG": ".jpg", "PNG": ".png", "GIF": ".gif"}


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


class ImageCompressor:
    """Save downloaded pages atomically, using WebP only when it is worthwhile."""

    def __init__(
        self,
        *,
        enabled: bool = True,
        quality: int = 88,
        method: int = 4,
        min_savings_percent: float = 5,
    ) -> None:
        self.enabled = bool(enabled)
        self.quality = _bounded_int(quality, 88, 1, 100)
        self.method = _bounded_int(method, 4, 0, 6)
        self.min_savings_percent = _bounded_float(min_savings_percent, 5.0, 0.0, 100.0)
        self._webp_available: bool | None = None
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

    def save_page_bytes(self, data: bytes, path: Path) -> Path:
        if not self.enabled:
            return self._write_bytes_atomic(path, data)

        try:
            from PIL import Image, ImageOps, features
        except ImportError:
            self._webp_available = False
            self._warn_once("Pillow is unavailable; downloaded pages will keep their original format")
            return self._write_bytes_atomic(path, data)

        if self._webp_available is None:
            self._webp_available = bool(features.check("webp"))
            if not self._webp_available:
                self._warn_once("Pillow has no WebP encoder; downloaded pages will keep their original format")
        if self._webp_available is False:
            return self._write_bytes_atomic(path, data)

        try:
            with Image.open(BytesIO(data)) as source_image:
                source_format = (source_image.format or "").upper()
                if source_format == "WEBP" or getattr(source_image, "is_animated", False):
                    target = path.with_suffix(_FORMAT_EXTENSIONS.get(source_format, path.suffix.lower()))
                    return self._write_bytes_atomic(target, data)

                source_image.load()
                image = ImageOps.exif_transpose(source_image)
                has_alpha = image.mode in ("RGBA", "LA") or "transparency" in image.info
                image = image.convert("RGBA" if has_alpha else "RGB")
                encoded = BytesIO()
                save_options = {
                    "format": "WEBP",
                    "quality": self.quality,
                    "method": self.method,
                }
                if has_alpha:
                    save_options["exact"] = True
                image.save(encoded, **save_options)
                webp_data = encoded.getvalue()

            minimum_saving = len(data) * (self.min_savings_percent / 100.0)
            if len(data) - len(webp_data) < minimum_saving:
                return self._write_bytes_atomic(path, data)

            webp_path = path.with_suffix(".webp")
            self._write_bytes_atomic(webp_path, webp_data)
            logger.debug(
                "Compressed %s to WebP: %d -> %d bytes (quality=%d)",
                path.name,
                len(data),
                len(webp_data),
                self.quality,
            )
            return webp_path
        except Exception as exc:
            self._warn_once("WebP conversion failed; keeping original page format: %s", exc)
            return self._write_bytes_atomic(path, data)