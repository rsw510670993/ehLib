import re
from pathlib import Path


def sanitize_filename(filename: str) -> str:
    illegal_chars = r'[<>:"/\\|?*]'
    sanitized = re.sub(illegal_chars, "_", filename)
    sanitized = sanitized.strip(". ")
    if not sanitized:
        sanitized = "unnamed"
    max_len = 200
    if len(sanitized) > max_len:
        sanitized = sanitized[:max_len]
    return sanitized


def slugify(text: str) -> str:
    text = text.lower().strip()
    text = re.sub(r'[^\w\s-]', '', text)
    text = re.sub(r'[-\s]+', '-', text)
    text = text.strip('-')
    if not text:
        text = "untitled"
    return text


def parse_nhentai_url(url: str) -> str | None:
    patterns = [
        r'nhentai\.(?:net|to)/g/(\d+)',
        r'nhentai\.(?:net|to)/g/(\d+)/',
    ]
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            return match.group(1)
    return None


def parse_exhentai_url(url: str) -> tuple[str, str] | None:
    patterns = [
        r'(?:exhentai|e-hentai)\.org/g/(\d+)/([a-f0-9]+)',
        r'(?:exhentai|e-hentai)\.org/g/(\d+)/([a-f0-9]+)/',
    ]
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            return match.group(1), match.group(2)
    return None


def format_gallery_dir(source: str, gallery_id: str, title: str) -> str:
    del source, title
    return sanitize_filename(gallery_id)


def format_page_filename(page_num: int, total: int) -> str:
    digits = len(str(total))
    return f"{page_num:0{digits}d}"


def ensure_dir(path: Path) -> Path:
    path.mkdir(parents=True, exist_ok=True)
    return path


def format_file_size(size_bytes: int) -> str:
    for unit in ("B", "KB", "MB", "GB"):
        if size_bytes < 1024:
            return f"{size_bytes:.1f} {unit}"
        size_bytes /= 1024
    return f"{size_bytes:.1f} TB"
