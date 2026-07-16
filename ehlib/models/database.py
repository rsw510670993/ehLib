import json
from datetime import datetime
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

CREATE_SEARCH_CACHE = """
CREATE TABLE IF NOT EXISTS search_cache (
    source       TEXT NOT NULL,
    source_id    TEXT NOT NULL,
    title        TEXT DEFAULT '',
    title_jp     TEXT DEFAULT '',
    artist       TEXT DEFAULT '',
    category     TEXT DEFAULT '',
    total_pages  INTEGER DEFAULT 0,
    uploaded_at  TEXT DEFAULT '',
    thumbnail    TEXT DEFAULT '',
    thumb_path   TEXT DEFAULT '',
    searched_at  TEXT DEFAULT '',
    crawled_at   TEXT DEFAULT '',
    PRIMARY KEY (source, source_id)
)
"""

CREATE_SEARCH_PRESETS = """
CREATE TABLE IF NOT EXISTS search_presets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL UNIQUE,
    keyword     TEXT DEFAULT '',
    categories  TEXT DEFAULT '',
    force_crawl INTEGER DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT ''
)
"""

CREATE_INDEXES = [
    "CREATE INDEX IF NOT EXISTS idx_galleries_source ON galleries(source, source_id)",
    "CREATE INDEX IF NOT EXISTS idx_tags_type_name ON tags(type, name)",
    "CREATE INDEX IF NOT EXISTS idx_gallery_tags_gid ON gallery_tags(gallery_id)",
    "CREATE INDEX IF NOT EXISTS idx_gallery_tags_tid ON gallery_tags(tag_id)",
    "CREATE INDEX IF NOT EXISTS idx_search_cache_artist ON search_cache(artist)",
    "CREATE INDEX IF NOT EXISTS idx_search_cache_category ON search_cache(category)",
    "CREATE INDEX IF NOT EXISTS idx_search_cache_searched ON search_cache(searched_at)",
    "CREATE INDEX IF NOT EXISTS idx_search_cache_uploaded ON search_cache(uploaded_at)",
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
            await db.execute(CREATE_SEARCH_CACHE)
            await db.execute(CREATE_SEARCH_PRESETS)
            for index_sql in CREATE_INDEXES:
                await db.execute(index_sql)
            # 兼容旧库：添加可能缺失的列
            for col in ["uploaded_at TEXT DEFAULT ''", "language TEXT DEFAULT ''", "group_name TEXT DEFAULT ''", "tags TEXT DEFAULT ''", "tags_cn TEXT DEFAULT ''"]:
                try:
                    await db.execute(f"ALTER TABLE search_cache ADD COLUMN {col}")
                except Exception:
                    pass
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

    async def get_all_galleries(self) -> list[Gallery]:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                "SELECT * FROM galleries ORDER BY COALESCE(NULLIF(uploaded_at, ''), '0000-00-00') DESC, CAST(SUBSTR(source_id || '/', 1, INSTR(source_id || '/', '/') - 1) AS INTEGER) DESC"
            )
            rows = await cursor.fetchall()
            return [self._row_to_gallery(dict(row)) for row in rows]

    async def save_gallery(self, gallery: Gallery) -> int:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT id, local_path FROM galleries WHERE source=? AND source_id=?",
                (gallery.source, gallery.source_id),
            )
            existing = await cursor.fetchone()

            if existing:
                gallery_id = existing[0]
                await db.execute(
                    """UPDATE galleries SET
                       title=?, title_jp=?, artist=?, group_name=?,
                       language=?, category=?, total_pages=?, cover_url=?, cover_path=?,
                       thumbnail_url=?, uploaded_at=?, local_path=?, downloaded_at=?,
                       file_size=?, is_complete=?, created_at=?, updated_at=?
                       WHERE id=?""",
                    (
                        gallery.title, gallery.title_jp, gallery.artist,
                        gallery.group_name, gallery.language, gallery.category,
                        gallery.total_pages, gallery.cover_url, gallery.cover_path,
                        gallery.thumbnail_url, gallery.uploaded_at,
                        gallery.local_path or existing[1], gallery.downloaded_at,
                        gallery.file_size, int(gallery.is_complete),
                        gallery.created_at, gallery.updated_at,
                        gallery_id,
                    ),
                )
            else:
                cursor = await db.execute(
                    """INSERT INTO galleries
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
                await db.execute("DELETE FROM gallery_tags WHERE gallery_id=?", (gallery_id,))
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
            if cursor.rowcount > 0 and cursor.lastrowid:
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
        query += " ORDER BY COALESCE(NULLIF(uploaded_at, ''), '0000-00-00') DESC, CAST(SUBSTR(source_id || '/', 1, INSTR(source_id || '/', '/') - 1) AS INTEGER) DESC LIMIT ?"
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
        query += " ORDER BY COALESCE(NULLIF(g.uploaded_at, ''), '0000-00-00') DESC, CAST(SUBSTR(g.source_id || '/', 1, INSTR(g.source_id || '/', '/') - 1) AS INTEGER) DESC LIMIT ?"
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
        query += " ORDER BY COALESCE(NULLIF(g.uploaded_at, ''), '0000-00-00') DESC, CAST(SUBSTR(g.source_id || '/', 1, INSTR(g.source_id || '/', '/') - 1) AS INTEGER) DESC LIMIT ?"
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

    # ── Search Cache ──────────────────────────────────────────

    async def save_search_results(self, results: list[dict]) -> int:
        saved = 0
        now = datetime.now().isoformat()
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute("PRAGMA synchronous=OFF")
            for r in results:
                cursor = await db.execute(
                    """INSERT OR IGNORE INTO search_cache
                       (source, source_id, title, title_jp, artist, category,
                        total_pages, uploaded_at, thumbnail, searched_at, crawled_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
                    (
                        r.get("source", "exhentai"),
                        r.get("source_id", ""),
                        r.get("title", ""),
                        r.get("title_jp", ""),
                        r.get("artist", ""),
                        r.get("category", ""),
                        r.get("total_pages", 0),
                        r.get("uploaded_at", ""),
                        r.get("thumbnail", ""),
                        now,
                        now,
                    ),
                )
                if cursor.rowcount > 0:
                    saved += 1
            # Update searched_at + thumbnail for existing records
            for r in results:
                await db.execute(
                    "UPDATE search_cache SET searched_at=?, thumbnail=? WHERE source=? AND source_id=?",
                    (now, r.get("thumbnail", ""), r.get("source", "exhentai"), r.get("source_id", "")),
                )
            await db.commit()
        return saved

    async def search_cache_exists(self, source: str, source_id: str) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT 1 FROM search_cache WHERE source=? AND source_id=?",
                (source, source_id),
            )
            return await cursor.fetchone() is not None

    async def get_search_cache(
        self,
        source: str | None = None,
        artist: str | None = None,
        title: str | None = None,
        category: str | None = None,
        categories: list[str] | None = None,
        limit: int = 50,
        offset: int = 0,
    ) -> list[dict]:
        query = "SELECT sc.*, g.id IS NOT NULL as is_local FROM search_cache sc LEFT JOIN galleries g ON g.source = sc.source AND g.source_id = sc.source_id WHERE 1=1"
        params: list = []
        if source:
            query += " AND sc.source=?"
            params.append(source)
        if artist:
            query += " AND sc.artist LIKE ?"
            params.append(f"%{artist}%")
        if title:
            query += " AND (sc.title LIKE ? OR sc.title_jp LIKE ?)"
            params.append(f"%{title}%")
            params.append(f"%{title}%")
        if category:
            query += " AND sc.category=?"
            params.append(category)
        if categories:
            query += " AND sc.category IN (" + ",".join("?" * len(categories)) + ")"
            params.extend(categories)
        query += " ORDER BY COALESCE(NULLIF(sc.uploaded_at, ''), '0000-00-00') DESC, sc.crawled_at DESC LIMIT ? OFFSET ?"
        params.append(limit)
        params.append(offset)

        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(query, params)
            rows = await cursor.fetchall()
            return [dict(row) for row in rows]

    async def count_search_cache(
        self,
        source: str | None = None,
        artist: str | None = None,
        title: str | None = None,
        category: str | None = None,
        categories: list[str] | None = None,
    ) -> int:
        query = "SELECT COUNT(*) FROM search_cache WHERE 1=1"
        params: list = []
        if source:
            query += " AND source=?"
            params.append(source)
        if artist:
            query += " AND artist LIKE ?"
            params.append(f"%{artist}%")
        if title:
            query += " AND (title LIKE ? OR title_jp LIKE ?)"
            params.append(f"%{title}%")
            params.append(f"%{title}%")
        if category:
            query += " AND category=?"
            params.append(category)
        if categories:
            query += " AND category IN (" + ",".join("?" * len(categories)) + ")"
            params.extend(categories)

        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(query, params)
            row = await cursor.fetchone()
            return row[0] if row else 0

    async def update_search_cache_metadata(self, source: str, source_id: str, artist: str, thumb_path: str, uploaded_at: str = "", category: str = "", thumbnail: str = "", language: str = "", title_jp: str = "", group_name: str = "", tags: str = "", tags_cn: str = "") -> None:
        async with aiosqlite.connect(self._db_path) as db:
            if uploaded_at:
                await db.execute(
                    "UPDATE search_cache SET artist=?, thumb_path=?, uploaded_at=?, category=?, thumbnail=?, language=?, title_jp=?, group_name=?, tags=?, tags_cn=?, crawled_at=? WHERE source=? AND source_id=?",
                    (artist, thumb_path, uploaded_at, category, thumbnail, language, title_jp, group_name, tags, tags_cn, datetime.now().isoformat(), source, source_id),
                )
            else:
                await db.execute(
                    "UPDATE search_cache SET artist=?, thumb_path=?, category=?, thumbnail=?, language=?, title_jp=?, group_name=?, tags=?, tags_cn=?, crawled_at=? WHERE source=? AND source_id=?",
                    (artist, thumb_path, category, thumbnail, language, title_jp, group_name, tags, tags_cn, datetime.now().isoformat(), source, source_id),
                )
            await db.commit()

    async def get_cached_artists(self, source: str = "exhentai") -> list[str]:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT DISTINCT artist FROM search_cache WHERE source=? AND artist!='' ORDER BY artist",
                (source,),
            )
            rows = await cursor.fetchall()
            return [row[0] for row in rows]

    async def get_cached_categories(self, source: str = "exhentai") -> list[str]:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT DISTINCT category FROM search_cache WHERE source=? AND category!='' ORDER BY category",
                (source,),
            )
            rows = await cursor.fetchall()
            return [row[0] for row in rows]

    async def get_search_presets(self) -> list[dict]:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                "SELECT id, name, keyword, categories, force_crawl FROM search_presets ORDER BY name"
            )
            rows = await cursor.fetchall()
            return [dict(r) for r in rows]

    async def delete_search_cache(self, source: str, source_id: str) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "DELETE FROM search_cache WHERE source=? AND source_id=?",
                (source, source_id),
            )
            await db.commit()
            return cursor.rowcount > 0

    async def get_cached_source_ids(self, source: str = "exhentai") -> list[str]:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT source_id FROM search_cache WHERE source=? ORDER BY uploaded_at DESC, source_id DESC",
                (source,),
            )
            rows = await cursor.fetchall()
            return [row[0] for row in rows]

    async def get_all_cache_tags(self, source: str = "exhentai") -> list[dict]:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT source_id, tags FROM search_cache WHERE source=? AND tags!='' AND tags!='[]' ORDER BY source_id",
                (source,),
            )
            rows = await cursor.fetchall()
            return [{"source_id": r[0], "tags": r[1]} for r in rows]

    async def update_cache_tags_cn(self, source: str, source_id: str, tags_cn: str) -> None:
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute(
                "UPDATE search_cache SET tags_cn=? WHERE source=? AND source_id=?",
                (tags_cn, source, source_id),
            )
            await db.commit()

    async def get_search_cache_row(self, source: str, source_id: str) -> dict | None:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT * FROM search_cache WHERE source=? AND source_id=?",
                (source, source_id),
            )
            row = await cursor.fetchone()
            if row is None:
                return None
            columns = [d[0] for d in cursor.description]
            return dict(zip(columns, row))

    # ── Legacy: delete_gallery ──────────────────────────────

    async def delete_gallery(self, source: str, source_id: str) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "SELECT id FROM galleries WHERE source=? AND source_id=?",
                (source, source_id),
            )
            row = await cursor.fetchone()
            if row is None:
                return False
            await db.execute("DELETE FROM galleries WHERE id=?", (row[0],))
            await db.commit()
            return True

    async def update_gallery_local_path(self, source: str, source_id: str, local_path: str) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "UPDATE galleries SET local_path=?, updated_at=? WHERE source=? AND source_id=?",
                (local_path, datetime.now().isoformat(), source, source_id),
            )
            await db.commit()
            return cursor.rowcount > 0

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
