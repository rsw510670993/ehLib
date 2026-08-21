from dataclasses import dataclass, field, asdict
from datetime import datetime


@dataclass
class Tag:
    id: int | None = None
    type: str = ""
    name: str = ""
    match_keys: list[str] = field(default_factory=list)

    def to_dict(self) -> dict:
        return {
            "id": self.id,
            "type": self.type,
            "name": self.name,
            "match_keys": list(self.match_keys) if self.match_keys else [],
        }


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
