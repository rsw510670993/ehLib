from dataclasses import dataclass, field
from datetime import datetime


@dataclass
class Tag:
    id: int | None = None
    type: str = ""
    name: str = ""


@dataclass
class Gallery:
    id: int | None = None
    source: str = ""
    source_id: str = ""
    title: str = ""
    title_jp: str = ""
    artist: str = ""
    group_name: str = ""
    language: str = ""
    category: str = ""
    total_pages: int = 0
    cover_url: str = ""
    cover_path: str = ""
    thumbnail_url: str = ""
    uploaded_at: str = ""
    local_path: str = ""
    downloaded_at: str = ""
    file_size: int = 0
    is_complete: bool = False
    created_at: str = field(default_factory=lambda: datetime.now().isoformat())
    updated_at: str = field(default_factory=lambda: datetime.now().isoformat())
    tags: list[Tag] = field(default_factory=list)
    page_urls: list[str] = field(default_factory=list)
