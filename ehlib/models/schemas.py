from dataclasses import dataclass, field
from datetime import datetime

TAG_TYPE_PRIORITY = {
    "artist": 10,
    "parody": 20,
    "character": 30,
    "group": 40,
    "cosplayer": 50,
    "category": 60,
    "language": 70,
    "tag": 99,
}


def tag_priority(tag_type: str) -> int:
    return TAG_TYPE_PRIORITY.get(tag_type, 99)


def sort_tags(tags: list["Tag"]) -> list["Tag"]:
    return sorted(tags, key=lambda t: (tag_priority(t.type), t.name))


@dataclass
class Tag:
    id: int | None = None
    type: str = ""
    name: str = ""

    @property
    def priority(self) -> int:
        return tag_priority(self.type)


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
