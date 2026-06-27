import json
import aiosqlite
from pathlib import Path

from ehlib.models.schemas import Gallery, Tag
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

DB_PATH = Path("data/ehlib.db")

CREATE_GALLERIES = """
CREATE TABLE IF NOT EXISTS galleries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_id TEXT NOT NULL,
    title TEXT DEFAULT '',
    title_jp TEXT DEFAULT '',
    artist TEXT DEFAULT '',
    group_name TEXT DEFAULT '',
    language TEXT DEFAULT '',
    category TEXT DEFAULT '',
    total_pages INTEGER DEFAULT 0,
    cover_url TEXT DEFAULT '',
    cover_path TEXT DEFAULT '',
    thumbnail_url TEXT DEFAULT '',
    uploaded_at TEXT DEFAULT '',
    local_path TEXT DEFAULT '',
    downloaded_at TEXT DEFAULT '',
    file_size INTEGER DEFAULT 0,
    is_complete INTEGER DEFAULT 0,
    created_at TEXT DEFAULT '',
    updated_at TEXT DEFAULT '',
    UNIQUE(source, source_id)
)
"""

CREATE_TAGS = """
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    UNIQUE(type, name)
)
"""

CREATE_GALLERY_TAGS = """
CREATE TABLE IF NOT EXISTS gallery_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gallery_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
    UNIQUE(gallery_id, tag_id)
)
"""

CREATE_INDEXES = [
    "CREATE INDEX IF NOT EXISTS idx_galleries_source ON galleries(source, source_id)",
    "CREATE INDEX IF NOT EXISTS idx_tags_type_name ON tags(type, name)",
    "CREATE INDEX IF NOT EXISTS idx_gallery_tags_gid ON gallery_tags(gallery_id)",
    "CREATE INDEX IF NOT EXISTS idx_gallery_tags_tid ON gallery_tags(tag_id)",
]


class Database:
    def __init__(self, db_path: str | None = None):
        self._db_path = str(db_path or DB_PATH)
        Path(self._db_path).parent.mkdir(parents=True, exist_ok=True)

    async def init(self) -> None:
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute("PRAGMA journal_mode=WAL")
            await db.execute("PRAGMA foreign_keys=ON")
            await db.execute(CREATE_GALLERIES)
            await db.execute(CREATE_TAGS)
            await db.execute(CREATE_GALLERY_TAGS)
            for index_sql in CREATE_INDEXES:
                await db.execute(index_sql)
            await db.commit()

    async def gallery_exists(self, source: str, source_id: str) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT id FROM galleries WHERE source=? AND source_id=?",
                (source, source_id),
            )
            row = await cursor.fetchone()
            return row is not None

    async def get_gallery(self, source: str, source_id: str) -> Gallery | None:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                "SELECT * FROM galleries WHERE source=? AND source_id=?",
                (source, source_id),
            )
            row = await cursor.fetchone()
            if row is None:
                return None
            return self._row_to_gallery(dict(row))

    async def save_gallery(self, gallery: Gallery) -> int:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                """INSERT OR REPLACE INTO galleries
                   (source, source_id, title, title_jp, artist, group_name,
                    language, category, total_pages, cover_url, cover_path,
                    thumbnail_url, uploaded_at, local_path, downloaded_at,
                    file_size, is_complete, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    gallery.source, gallery.source_id, gallery.title,
                    gallery.title_jp, gallery.artist, gallery.group_name,
                    gallery.language, gallery.category, gallery.total_pages,
                    gallery.cover_url, gallery.cover_path, gallery.thumbnail_url,
                    gallery.uploaded_at, gallery.local_path, gallery.downloaded_at,
                    gallery.file_size, int(gallery.is_complete),
                    gallery.created_at, gallery.updated_at,
                ),
            )
            gallery_id = cursor.lastrowid or 0

            if gallery.tags:
                tag_ids = await self._save_tags(db, gallery.tags)
                await self._link_tags(db, gallery_id, tag_ids)

            await db.commit()
            return gallery_id

    async def _save_tags(self, db: aiosqlite.Connection, tags: list[Tag]) -> list[int]:
        tag_ids = []
        for tag in tags:
            cursor = await db.execute(
                "INSERT OR IGNORE INTO tags (type, name) VALUES (?, ?)",
                (tag.type, tag.name),
            )
            if cursor.lastrowid:
                tag_ids.append(cursor.lastrowid)
            else:
                cursor = await db.execute(
                    "SELECT id FROM tags WHERE type=? AND name=?",
                    (tag.type, tag.name),
                )
                row = await cursor.fetchone()
                if row:
                    tag_ids.append(row[0])
        return tag_ids

    async def _link_tags(self, db: aiosqlite.Connection, gallery_id: int, tag_ids: list[int]) -> None:
        for tag_id in tag_ids:
            await db.execute(
                "INSERT OR IGNORE INTO gallery_tags (gallery_id, tag_id) VALUES (?, ?)",
                (gallery_id, tag_id),
            )

    async def get_incomplete_downloads(self) -> list[Gallery]:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                "SELECT * FROM galleries WHERE is_complete=0"
            )
            rows = await cursor.fetchall()
            return [self._row_to_gallery(dict(row)) for row in rows]

    async def search_galleries(
        self,
        source: str | None = None,
        artist: str | None = None,
        tag_name: str | None = None,
        tag_names: list[str] | None = None,
        tag_mode: str = "any",
        language: str | None = None,
        limit: int = 50,
    ) -> list[Gallery]:
        if tag_name and not tag_names:
            tag_names = [tag_name]

        if tag_names and len(tag_names) > 0 and tag_mode == "any":
            return await self._search_galleries_any(source, artist, tag_names, language, limit)

        if tag_names and len(tag_names) > 0 and tag_mode == "all":
            return await self._search_galleries_all(source, artist, tag_names, language, limit)

        query = "SELECT * FROM galleries WHERE 1=1"
        params: list = []
        if source:
            query += " AND source=?"
            params.append(source)
        if artist:
            query += " AND artist LIKE ?"
            params.append(f"%{artist}%")
        if language:
            query += " AND language=?"
            params.append(language)
        query += " ORDER BY downloaded_at DESC LIMIT ?"
        params.append(limit)

        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(query, params)
            rows = await cursor.fetchall()
            return [self._row_to_gallery(dict(row)) for row in rows]

    async def _search_galleries_any(self, source, artist, tag_names, language, limit):
        query = """SELECT DISTINCT g.* FROM galleries g
                   JOIN gallery_tags gt ON g.id = gt.gallery_id
                   JOIN tags t ON gt.tag_id = t.id
                   WHERE ("""
        query += " OR ".join(["t.name LIKE ?"] * len(tag_names))
        query += ")"
        params = [f"%{t}%" for t in tag_names]
        if source:
            query += " AND g.source=?"
            params.append(source)
        if artist:
            query += " AND g.artist LIKE ?"
            params.append(f"%{artist}%")
        if language:
            query += " AND g.language=?"
            params.append(language)
        query += " ORDER BY g.downloaded_at DESC LIMIT ?"
        params.append(limit)
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(query, params)
            rows = await cursor.fetchall()
            return [self._row_to_gallery(dict(row)) for row in rows]

    async def _search_galleries_all(self, source, artist, tag_names, language, limit):
        like_conditions = " OR ".join(["t.name LIKE ?"] * len(tag_names))
        query = f"""SELECT g.* FROM galleries g
                    JOIN gallery_tags gt ON g.id = gt.gallery_id
                    JOIN tags t ON gt.tag_id = t.id
                    WHERE ({like_conditions})"""
        params = [f"%{t}%" for t in tag_names]
        if source:
            query += " AND g.source=?"
            params.append(source)
        if artist:
            query += " AND g.artist LIKE ?"
            params.append(f"%{artist}%")
        if language:
            query += " AND g.language=?"
            params.append(language)
        query += " GROUP BY g.id"
        query += f" HAVING COUNT(DISTINCT t.id) = {len(tag_names)}"
        query += " ORDER BY g.downloaded_at DESC LIMIT ?"
        params.append(limit)
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(query, params)
            rows = await cursor.fetchall()
            return [self._row_to_gallery(dict(row)) for row in rows]

    async def get_gallery_detail(self, gallery_id: int) -> Gallery | None:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                "SELECT * FROM galleries WHERE id=?", (gallery_id,)
            )
            row = await cursor.fetchone()
            if row is None:
                return None
            gallery = self._row_to_gallery(dict(row))
            gallery.tags = await self._get_tags_for_gallery(db, gallery_id)
            return gallery

    async def _get_tags_for_gallery(self, db: aiosqlite.Connection, gallery_id: int) -> list[Tag]:
        cursor = await db.execute(
            """SELECT t.id, t.type, t.name
               FROM tags t
               JOIN gallery_tags gt ON t.id = gt.tag_id
               WHERE gt.gallery_id = ?
               ORDER BY t.type, t.name""",
            (gallery_id,),
        )
        rows = await cursor.fetchall()
        return [Tag(id=row[0], type=row[1], name=row[2]) for row in rows]

    async def get_gallery_tags(self, gallery_id: int) -> list[Tag]:
        async with aiosqlite.connect(self._db_path) as db:
            return await self._get_tags_for_gallery(db, gallery_id)

    async def get_all_tags(self, source: str | None = None) -> list[dict]:
        query = """
            SELECT DISTINCT t.type, t.name, COUNT(gt.id) as count
            FROM tags t
            JOIN gallery_tags gt ON t.id = gt.tag_id
            JOIN galleries g ON gt.gallery_id = g.id
        """
        params: list = []
        if source:
            query += " WHERE g.source = ?"
            params.append(source)
        query += " GROUP BY t.type, t.name ORDER BY count DESC"

        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(query, params)
            rows = await cursor.fetchall()
            return [dict(row) for row in rows]

    def _row_to_gallery(self, row: dict) -> Gallery:
        return Gallery(
            id=row.get("id"),
            source=row.get("source", ""),
            source_id=row.get("source_id", ""),
            title=row.get("title", ""),
            title_jp=row.get("title_jp", ""),
            artist=row.get("artist", ""),
            group_name=row.get("group_name", ""),
            language=row.get("language", ""),
            category=row.get("category", ""),
            total_pages=row.get("total_pages", 0),
            cover_url=row.get("cover_url", ""),
            cover_path=row.get("cover_path", ""),
            thumbnail_url=row.get("thumbnail_url", ""),
            uploaded_at=row.get("uploaded_at", ""),
            local_path=row.get("local_path", ""),
            downloaded_at=row.get("downloaded_at", ""),
            file_size=row.get("file_size", 0),
            is_complete=bool(row.get("is_complete", False)),
            created_at=row.get("created_at", ""),
            updated_at=row.get("updated_at", ""),
        )

    async def export_json(self, output_path: str) -> None:
        galleries = await self.search_galleries(limit=999999)
        result = []
        for g in galleries:
            result.append({
                "source": g.source,
                "source_id": g.source_id,
                "title": g.title,
                "title_jp": g.title_jp,
                "artist": g.artist,
                "group": g.group_name,
                "language": g.language,
                "category": g.category,
                "total_pages": g.total_pages,
                "local_path": g.local_path,
                "downloaded_at": g.downloaded_at,
                "file_size": g.file_size,
            })
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(result, f, ensure_ascii=False, indent=2)
        logger.info("Exported %d galleries to %s", len(result), output_path)
