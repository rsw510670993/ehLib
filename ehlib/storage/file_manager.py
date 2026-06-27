import json
from pathlib import Path

from ehlib.utils.helpers import format_gallery_dir, ensure_dir, sanitize_filename
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)


class FileManager:
    def __init__(self, base_path: str = "./downloads"):
        self._base_path = Path(base_path).resolve()

    def gallery_dir(self, source: str, source_id: str, title: str) -> Path:
        dir_name = format_gallery_dir(source, source_id, title)
        return self._base_path / source / dir_name

    def create_gallery_dir(self, source: str, source_id: str, title: str) -> Path:
        gallery_path = self.gallery_dir(source, source_id, title)
        ensure_dir(gallery_path)
        return gallery_path

    def page_path(self, gallery_dir: Path, page_num: int, ext: str = ".jpg") -> Path:
        page_str = str(page_num).zfill(3)
        return gallery_dir / f"{page_str}{ext}"

    def cover_path(self, gallery_dir: Path, url: str) -> Path:
        ext = self._extract_ext(url)
        return gallery_dir / f"cover{ext}"

    def metadata_path(self, gallery_dir: Path) -> Path:
        return gallery_dir / "metadata.json"

    def save_metadata(self, gallery_dir: Path, metadata: dict) -> None:
        path = self.metadata_path(gallery_dir)
        with open(path, "w", encoding="utf-8") as f:
            json.dump(metadata, f, ensure_ascii=False, indent=2)

    def load_metadata(self, gallery_dir: Path) -> dict | None:
        path = self.metadata_path(gallery_dir)
        if path.exists():
            with open(path, "r", encoding="utf-8") as f:
                return json.load(f)
        return None

    def list_downloaded_pages(self, gallery_dir: Path) -> list[int]:
        if not gallery_dir.exists():
            return []
        pages = []
        for f in sorted(gallery_dir.iterdir()):
            if f.is_file() and f.suffix.lower() in (".jpg", ".jpeg", ".png", ".gif", ".webp"):
                name = f.stem
                if name.isdigit():
                    pages.append(int(name))
        return pages

    def get_dir_size(self, gallery_dir: Path) -> int:
        if not gallery_dir.exists():
            return 0
        total = 0
        for f in gallery_dir.rglob("*"):
            if f.is_file():
                total += f.stat().st_size
        return total

    def delete_gallery_dir(self, gallery_dir: Path) -> bool:
        if not gallery_dir.exists():
            return False
        import shutil
        shutil.rmtree(gallery_dir, ignore_errors=True)
        return True

    @staticmethod
    def _extract_ext(url: str) -> str:
        url_path = url.split("?")[0]
        ext = Path(url_path).suffix
        if ext.lower() in (".jpg", ".jpeg", ".png", ".gif", ".webp"):
            return ext.lower()
        return ".jpg"

    @property
    def base_path(self) -> Path:
        return self._base_path
