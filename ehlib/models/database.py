import json
from datetime import datetime
import aiosqlite
from pathlib import Path

from ehlib.models.schemas import Gallery, Tag
from ehlib.translate.tag_translator import TagTranslator
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

DB_PATH = Path("data/ehlib.db")
_QUERY_LABEL_TRANSLATOR = TagTranslator()
_QUERY_LABEL_TRANSLATOR_LOADED = None

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
    languages   TEXT DEFAULT NULL,
    force_crawl INTEGER DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT ''
)
"""

CREATE_REFRESH_TARGETS = """
CREATE TABLE IF NOT EXISTS refresh_targets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    preset_id INTEGER UNIQUE,
    origin_job_id INTEGER DEFAULT NULL,
    name TEXT NOT NULL,
    query TEXT NOT NULL,
    categories TEXT DEFAULT '',
    languages TEXT DEFAULT '',
    force_crawl INTEGER DEFAULT 0,
    completed_at TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT ''
)
"""
CREATE_CRAWL_JOBS = """
CREATE TABLE IF NOT EXISTS crawl_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL DEFAULT 'exhentai',
    query TEXT NOT NULL,
    categories TEXT DEFAULT '',
    languages TEXT DEFAULT '',
    force_crawl INTEGER DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'pending',
    error TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT '',
    started_at TEXT DEFAULT '',
    finished_at TEXT DEFAULT '',
    refresh_target_id INTEGER DEFAULT NULL
)
"""

CREATE_REMOTE_FAVORITES = """
CREATE TABLE IF NOT EXISTS remote_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL DEFAULT 'exhentai',
    source_id TEXT NOT NULL,
    favcat INTEGER NOT NULL,
    title_en TEXT DEFAULT '',
    title_jp TEXT DEFAULT '',
    artists_json TEXT DEFAULT '',
    category TEXT DEFAULT '',
    added_at TEXT DEFAULT '',
    note TEXT DEFAULT '',
    thumbnail_url TEXT DEFAULT '',
    synced_at TEXT NOT NULL DEFAULT '',
    is_removed INTEGER NOT NULL DEFAULT 0,
    UNIQUE(source, source_id, favcat)
)
"""

REMOTE_FAVORITE_INDEXES = [
    "CREATE INDEX IF NOT EXISTS idx_remote_favorites_favcat ON remote_favorites(favcat)",
    "CREATE INDEX IF NOT EXISTS idx_remote_favorites_synced ON remote_favorites(synced_at)",
    "CREATE INDEX IF NOT EXISTS idx_remote_favorites_source_id ON remote_favorites(source, source_id)",
]

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


def build_query_display_name(query: str) -> str:
    query = (query or "").strip()
    if not query:
        return "未命名"

    global _QUERY_LABEL_TRANSLATOR_LOADED
    if _QUERY_LABEL_TRANSLATOR_LOADED is None:
        _QUERY_LABEL_TRANSLATOR_LOADED = _QUERY_LABEL_TRANSLATOR.load()

    if _QUERY_LABEL_TRANSLATOR_LOADED:
        translated = _QUERY_LABEL_TRANSLATOR.translate_query_label(query).strip()
        if translated:
            return translated
    return query


class Database:
    def __init__(self, db_path: str | None = None):
        self._db_path = str(db_path or DB_PATH)
        Path(self._db_path).parent.mkdir(parents=True, exist_ok=True)

    async def _resolve_saved_search_name(
        self,
        db: aiosqlite.Connection,
        query: str,
        categories: str = "",
        languages: str = "",
        force_crawl: int | bool = 0,
    ) -> str:
        normalized_query = (query or "").strip()
        normalized_categories = categories or ""
        normalized_languages = languages or ""
        normalized_force = 1 if force_crawl else 0
        cursor = await db.execute(
            """SELECT name FROM search_presets
               WHERE keyword=? AND categories=? AND COALESCE(languages,'')=? AND force_crawl=?
               ORDER BY id DESC LIMIT 1""",
            (normalized_query, normalized_categories, normalized_languages, normalized_force),
        )
        row = await cursor.fetchone()
        if row and row[0]:
            return str(row[0])
        return build_query_display_name(normalized_query)

    async def init(self) -> None:
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute("PRAGMA journal_mode=WAL")
            await db.execute("PRAGMA foreign_keys=ON")
            await db.execute(CREATE_GALLERIES)
            await db.execute(CREATE_TAGS)
            await db.execute(CREATE_GALLERY_TAGS)
            await db.execute(CREATE_SEARCH_CACHE)
            await db.execute(CREATE_SEARCH_PRESETS)
            await db.execute(CREATE_CRAWL_JOBS)
            await db.execute(CREATE_REFRESH_TARGETS)
            await db.execute(CREATE_REMOTE_FAVORITES)
            for index_sql in CREATE_INDEXES:
                await db.execute(index_sql)
            for index_sql in REMOTE_FAVORITE_INDEXES:
                await db.execute(index_sql)
            # 兼容旧库：添加可能缺失的列
            for col in ["uploaded_at TEXT DEFAULT ''", "language TEXT DEFAULT ''", "group_name TEXT DEFAULT ''", "tags TEXT DEFAULT ''", "tags_cn TEXT DEFAULT ''"]:
                try:
                    await db.execute(f"ALTER TABLE search_cache ADD COLUMN {col}")
                except Exception:
                    pass
            # 兼容旧库：search_presets 增加 languages 列，既存预设补默认语种（中日+speechless+text cleaned）
            try:
                await db.execute("ALTER TABLE search_presets ADD COLUMN languages TEXT DEFAULT NULL")
            except Exception:
                pass
            try:
                await db.execute("UPDATE search_presets SET languages='japanese,chinese,speechless,text cleaned' WHERE languages IS NULL")
            except Exception:
                pass
            try:
                await db.execute("ALTER TABLE crawl_jobs ADD COLUMN refresh_target_id INTEGER DEFAULT NULL")
            except Exception:
                pass
            try:
                await db.execute("ALTER TABLE refresh_targets ADD COLUMN origin_job_id INTEGER DEFAULT NULL")
            except Exception:
                pass
            try:
                await db.execute("ALTER TABLE refresh_targets ADD COLUMN origin_kind TEXT DEFAULT ''")
            except Exception:
                pass
            try:
                await db.execute("ALTER TABLE refresh_targets ADD COLUMN origin_artist TEXT DEFAULT ''")
            except Exception:
                pass
            try:
                await db.execute("ALTER TABLE refresh_targets ADD COLUMN origin_favcats TEXT DEFAULT ''")
            except Exception:
                pass
            await db.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_refresh_targets_origin_job ON refresh_targets(origin_job_id) WHERE origin_job_id IS NOT NULL")
            await db.execute(
                "CREATE INDEX IF NOT EXISTS idx_refresh_targets_origin_kind_artist "
                "ON refresh_targets(origin_kind, origin_artist)"
            )
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

    async def set_search_cache_origin(self, source: str, source_ids: list[str], job_id: int) -> None:
        if not source_ids:
            return
        async with aiosqlite.connect(self._db_path) as db:
            placeholders = ",".join("?" for _ in source_ids)
            await db.execute(
                f"UPDATE search_cache SET origin_job_id=? WHERE source=? AND source_id IN ({placeholders})",
                [job_id, source, *source_ids],
            )
            await db.commit()

    async def register_completed_refresh_target(self, source: str, source_id: str) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                """SELECT j.* FROM search_cache sc
                   JOIN crawl_jobs j ON j.id=sc.origin_job_id
                   WHERE sc.source=? AND sc.source_id=?""",
                (source, source_id),
            )
            job = await cursor.fetchone()
            if job is None or job["refresh_target_id"] is not None:
                return False
            name = await self._resolve_saved_search_name(
                db,
                job["query"],
                job["categories"],
                job["languages"],
                job["force_crawl"],
            )
            cursor = await db.execute(
                """INSERT OR IGNORE INTO refresh_targets
                   (preset_id,origin_job_id,name,query,categories,languages,force_crawl,completed_at,enabled,created_at)
                   VALUES (NULL,?,?,?,?,?,?,?,1,?)""",
                (job["id"], name, job["query"], job["categories"], job["languages"], job["force_crawl"], datetime.now().isoformat(), datetime.now().isoformat()),
            )
            await db.commit()
            return cursor.rowcount > 0

    async def register_completed_crawl_job(self, job_id: int) -> bool:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cursor = await db.execute(
                """SELECT id, query, categories, languages, force_crawl, refresh_target_id, status
                   FROM crawl_jobs
                   WHERE id=?""",
                (job_id,),
            )
            job = await cursor.fetchone()
            if job is None or job["refresh_target_id"] is not None or job["status"] != "completed":
                return False
            name = await self._resolve_saved_search_name(
                db,
                job["query"],
                job["categories"],
                job["languages"],
                job["force_crawl"],
            )
            cursor = await db.execute(
                """INSERT OR IGNORE INTO refresh_targets
                   (preset_id,origin_job_id,name,query,categories,languages,force_crawl,completed_at,enabled,created_at)
                   VALUES (NULL,?,?,?,?,?,?,?,1,?)""",
                (
                    job["id"],
                    name,
                    job["query"],
                    job["categories"],
                    job["languages"],
                    job["force_crawl"],
                    datetime.now().isoformat(),
                    datetime.now().isoformat(),
                ),
            )
            await db.commit()
            return cursor.rowcount > 0

    async def backfill_search_and_refresh_names(self) -> dict[str, int]:
        stats = {"search_presets": 0, "refresh_targets": 0}
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            preset_rows = await (await db.execute(
                "SELECT id, keyword FROM search_presets ORDER BY id"
            )).fetchall()
            for row in preset_rows:
                name = build_query_display_name(row["keyword"] or "")
                cursor = await db.execute(
                    "UPDATE search_presets SET name=? WHERE id=? AND COALESCE(name,'')<>?",
                    (name, row["id"], name),
                )
                stats["search_presets"] += cursor.rowcount

            target_rows = await (await db.execute(
                "SELECT id, query, categories, languages, force_crawl FROM refresh_targets ORDER BY id"
            )).fetchall()
            for row in target_rows:
                name = await self._resolve_saved_search_name(
                    db,
                    row["query"],
                    row["categories"],
                    row["languages"],
                    row["force_crawl"],
                )
                cursor = await db.execute(
                    "UPDATE refresh_targets SET name=? WHERE id=? AND COALESCE(name,'')<>?",
                    (name, row["id"], name),
                )
                stats["refresh_targets"] += cursor.rowcount

            await db.commit()
        return stats
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
                "SELECT id, name, keyword, categories, languages, force_crawl FROM search_presets ORDER BY name"
            )
            rows = await cursor.fetchall()
            return [dict(r) for r in rows]

    async def enqueue_enabled_refresh_targets(self) -> int:
        category_map = {
            "Misc": 1, "Doujinshi": 2, "Manga": 4, "Artist CG": 8,
            "Game CG": 16, "Image Set": 32, "Cosplay": 64,
            "Asian Porn": 128, "Non-H": 256, "Western": 512,
        }
        queued = 0
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            await db.execute("BEGIN IMMEDIATE")
            active_batch = await db.execute(
                "SELECT 1 FROM crawl_jobs WHERE refresh_target_id IS NOT NULL "
                "AND status IN ('pending','running','cancel_requested') LIMIT 1"
            )
            if await active_batch.fetchone():
                await db.rollback()
                return 0
            cursor = await db.execute("SELECT * FROM refresh_targets WHERE enabled=1 ORDER BY id")
            for row in await cursor.fetchall():
                active = await db.execute(
                    "SELECT 1 FROM crawl_jobs WHERE refresh_target_id=? AND status IN ('pending','running','cancel_requested') LIMIT 1",
                    (row["id"],),
                )
                if await active.fetchone():
                    continue
                category_values = [str(category_map[name.strip()]) for name in (row["categories"] or "").split(",") if name.strip() in category_map]
                await db.execute(
                    """INSERT INTO crawl_jobs
                       (source,query,categories,languages,force_crawl,status,created_at,refresh_target_id)
                       VALUES ('exhentai',?,?,?,?, 'pending', ?, ?)""",
                    (row["query"], ",".join(category_values), row["languages"], row["force_crawl"], datetime.now().isoformat(), row["id"]),
                )
                queued += 1
            await db.commit()
        return queued
    async def recover_interrupted_crawl_jobs(self) -> None:
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute("UPDATE crawl_jobs SET status='pending', started_at='' WHERE status='running'")
            await db.execute(
                "UPDATE crawl_jobs SET status='cancelled', finished_at=? WHERE status='cancel_requested'",
                (datetime.now().isoformat(),),
            )
            await db.commit()
    async def claim_next_crawl_job(self) -> dict | None:
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            await db.execute("BEGIN IMMEDIATE")
            cursor = await db.execute(
                "SELECT * FROM crawl_jobs WHERE status='pending' ORDER BY id LIMIT 1"
            )
            row = await cursor.fetchone()
            if row is None:
                await db.commit()
                return None
            await db.execute(
                "UPDATE crawl_jobs SET status='running', started_at=?, error='' WHERE id=?",
                (datetime.now().isoformat(), row["id"]),
            )
            await db.commit()
            return dict(row)

    async def get_crawl_job_status(self, job_id: int) -> str:
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute("SELECT status FROM crawl_jobs WHERE id=?", (job_id,))
            row = await cursor.fetchone()
            return str(row[0]) if row else ""

    async def finish_crawl_job(self, job_id: int, status: str, error: str = "") -> None:
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute(
                "UPDATE crawl_jobs SET status=?, error=?, finished_at=? WHERE id=?",
                (status, error, datetime.now().isoformat(), job_id),
            )
            await db.commit()
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

    async def upsert_remote_favorites(
        self,
        items: list[dict],
        favcat: int,
        synced_at: str,
    ) -> tuple[set[str], int]:
        """对单个 favcat 的一页列表做快照 upsert。
        返回 (当前 favcat 本轮已见到的 source_id 集合, updated_count)。
        """
        now = synced_at or datetime.now().isoformat()
        seen: set[str] = set()
        updated = 0
        if not items:
            return seen, 0
        async with aiosqlite.connect(self._db_path) as db:
            await db.execute("PRAGMA synchronous=OFF")
            for r in items:
                source = (r.get("source") or "exhentai").strip() or "exhentai"
                source_id = (r.get("source_id") or "").strip()
                if not source_id:
                    continue
                seen.add(source_id)
                title = (r.get("title") or r.get("title_en") or "").strip()
                title_jp = (r.get("title_jp") or "").strip()
                artists_json_raw = r.get("artists_json")
                if artists_json_raw is None:
                    artists_list = r.get("artists") or []
                    artists_json = json.dumps(artists_list, ensure_ascii=False) if artists_list else ""
                elif isinstance(artists_json_raw, list):
                    artists_json = json.dumps(artists_json_raw, ensure_ascii=False)
                else:
                    artists_json = str(artists_json_raw)
                category = (r.get("category") or "").strip()
                added_at = (r.get("added_at") or "").strip()
                note = (r.get("note") or "").strip()
                thumb = (r.get("thumbnail_url") or r.get("thumbnail") or "").strip()
                cursor = await db.execute(
                    """
                    INSERT INTO remote_favorites
                      (source, source_id, favcat, title_en, title_jp, artists_json,
                       category, added_at, note, thumbnail_url, synced_at, is_removed)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,0)
                    ON CONFLICT(source, source_id, favcat) DO UPDATE SET
                        title_en=excluded.title_en,
                        title_jp=CASE WHEN excluded.title_jp<>'' THEN excluded.title_jp ELSE remote_favorites.title_jp END,
                        artists_json=CASE WHEN excluded.artists_json<>'' THEN excluded.artists_json ELSE remote_favorites.artists_json END,
                        category=excluded.category,
                        added_at=CASE WHEN excluded.added_at<>'' THEN excluded.added_at ELSE remote_favorites.added_at END,
                        note=excluded.note,
                        thumbnail_url=CASE WHEN excluded.thumbnail_url<>'' THEN excluded.thumbnail_url ELSE remote_favorites.thumbnail_url END,
                        synced_at=excluded.synced_at,
                        is_removed=0
                    """,
                    (
                        source, source_id, int(favcat),
                        title, title_jp, artists_json,
                        category, added_at, note, thumb, now,
                    ),
                )
                updated += max(0, cursor.rowcount) if cursor.rowcount is not None else 0
            await db.commit()
        return seen, updated

    async def mark_removed_remote_favorites(
        self,
        source: str,
        favcat: int,
        current_ids: set[str],
        synced_at: str,
    ) -> int:
        """对单个 favcat：没出现在 current_ids 里的条目标记 is_removed=1。
        返回被标记为移除的行数。
        """
        src = (source or "exhentai").strip() or "exhentai"
        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            cur = await db.execute(
                "SELECT source_id FROM remote_favorites WHERE source=? AND favcat=? AND is_removed=0",
                (src, int(favcat)),
            )
            rows = await cur.fetchall()
            existing = {row["source_id"] for row in rows}
            missing = existing - set(current_ids or set())
            if not missing:
                return 0
            placeholders = ",".join("?" for _ in missing)
            params = [synced_at or datetime.now().isoformat(), src, int(favcat), *list(missing)]
            cursor = await db.execute(
                f"UPDATE remote_favorites SET is_removed=1, synced_at=? "
                f"WHERE source=? AND favcat=? AND source_id IN ({placeholders})",
                params,
            )
            await db.commit()
            return max(0, cursor.rowcount) if cursor.rowcount is not None else 0

    async def update_remote_favorite_artists(
        self,
        source: str,
        source_id: str,
        artists: list[str],
        synced_at: str,
    ) -> bool:
        """抓完详情页后，把一本收藏的多作者写回快照表。
        返回 True 表示更新过。
        """
        src = (source or "exhentai").strip() or "exhentai"
        if not source_id:
            return False
        artists_json = json.dumps(list(artists or []), ensure_ascii=False) if artists else ""
        now = synced_at or datetime.now().isoformat()
        async with aiosqlite.connect(self._db_path) as db:
            cursor = await db.execute(
                "UPDATE remote_favorites SET artists_json=?, synced_at=? "
                "WHERE source=? AND source_id=?",
                (artists_json, now, src, source_id),
            )
            await db.commit()
            count = cursor.rowcount or 0
            return count > 0

    async def upsert_favorite_artist_refresh_targets(
        self,
        artist_favcats_map: dict[str, set[int]],
        no_upsert_name: bool = False,
    ) -> dict:
        """根据聚合好的 {artist: set[favcat, ...]} 生成/更新刷新对象。
        去重键：origin_kind='favorite_artist' AND origin_artist=?
        返回统计 dict：
          created, updated, name_skipped_user_custom, skipped_unchanged,
          skipped_duplicate_existing（完全一致的老对象，跳过）,
          origin_backfilled（老对象 origin_kind 空，被我们补了 origin 字段）,
          total_unique_artists
        """
        stats = {
            "created": 0,
            "updated": 0,
            "name_skipped_user_custom": 0,
            "skipped_unchanged": 0,
            "skipped_duplicate_existing": 0,
            "origin_backfilled": 0,
            "total_unique_artists": len(artist_favcats_map or {}),
        }
        if not artist_favcats_map:
            return stats

        DEFAULT_LANGS = "japanese,chinese,speechless,text cleaned"
        now = datetime.now().isoformat()

        async with aiosqlite.connect(self._db_path) as db:
            db.row_factory = aiosqlite.Row
            for artist, favcats_set in (artist_favcats_map or {}).items():
                artist_clean = str(artist or "").strip()
                if not artist_clean:
                    continue
                query = f'artist:"{artist_clean}$"'
                categories = "Doujinshi,Manga,Artist CG,Game CG,Image Set"
                languages = DEFAULT_LANGS
                force_crawl = 0
                enabled_target = 1
                system_computed_name = await self._resolve_saved_search_name(
                    db, query, categories, languages, force_crawl
                )
                origin_favcats_csv = ",".join(
                    str(c) for c in sorted({int(c) for c in (favcats_set or set()) if 0 <= int(c) <= 9})
                )

                existing = await (await db.execute(
                    "SELECT * FROM refresh_targets WHERE origin_kind='favorite_artist' AND origin_artist=? LIMIT 1",
                    (artist_clean,),
                )).fetchone()

                backfill_hit = False
                if existing is None:
                    existing_by_query = await (await db.execute(
                        """SELECT * FROM refresh_targets
                           WHERE query=? AND COALESCE(categories,'')=? AND COALESCE(languages,'')=?
                           AND force_crawl=0 AND (COALESCE(origin_kind,'')='' OR origin_kind='favorite_artist')
                           LIMIT 1""",
                        (query, categories, languages),
                    )).fetchone()
                    if existing_by_query is not None:
                        existing = existing_by_query
                        backfill_hit = True

                if existing is None:
                    cursor = await db.execute(
                        """INSERT INTO refresh_targets
                           (preset_id,origin_job_id,name,query,categories,languages,force_crawl,
                            completed_at,enabled,created_at,origin_kind,origin_artist,origin_favcats)
                           VALUES (NULL,NULL,?,?,?,?,?, '',1,?, 'favorite_artist',?, ?)""",
                        (
                            system_computed_name, query, categories, languages, force_crawl,
                            now, artist_clean, origin_favcats_csv,
                        ),
                    )
                    if (cursor.rowcount or 0) > 0:
                        stats["created"] += 1
                    continue

                target_id = existing["id"]
                # UPDATE 保护：避免覆盖用户手动值
                current_name = str(existing["name"] or "")
                current_origin_favcats = str(existing["origin_favcats"] or "")
                current_enabled = 1 if bool(existing["enabled"]) else 0
                current_origin_kind = str(existing["origin_kind"] or "")
                current_origin_artist = str(existing["origin_artist"] or "")
                current_query = str(existing["query"] or "")
                current_languages = str(existing["languages"] or "")
                current_categories = str(existing["categories"] or "")
                current_force_crawl = int(existing["force_crawl"]) if str(existing["force_crawl"]).strip() != "" else 0

                user_changed_name = bool(current_name) and current_name != system_computed_name
                set_clauses = []
                params = []

                origin_fields_changed = False
                if current_origin_kind != "favorite_artist":
                    set_clauses.append("origin_kind=?")
                    params.append("favorite_artist")
                    origin_fields_changed = True
                if current_origin_artist != artist_clean:
                    set_clauses.append("origin_artist=?")
                    params.append(artist_clean)
                    origin_fields_changed = True

                old_set = {
                    c.strip() for c in (current_origin_favcats or "").split(",") if c.strip()
                }
                new_set = {str(c) for c in sorted({int(c) for c in (favcats_set or set()) if 0 <= int(c) <= 9})}
                merged_favcats_csv = ",".join(sorted(old_set | new_set, key=lambda x: int(x)))
                if merged_favcats_csv != current_origin_favcats:
                    set_clauses.append("origin_favcats=?")
                    params.append(merged_favcats_csv)
                    origin_fields_changed = True

                if backfill_hit and origin_fields_changed:
                    stats["origin_backfilled"] += 1

                if (not current_languages) and languages:
                    set_clauses.append("languages=?")
                    params.append(languages)
                if (not current_categories) and categories:
                    set_clauses.append("categories=?")
                    params.append(categories)
                if (not current_query) and query:
                    set_clauses.append("query=?")
                    params.append(query)

                changed_any = bool(set_clauses)

                name_updated = False
                if not no_upsert_name and not user_changed_name and current_name != system_computed_name:
                    set_clauses.append("name=?")
                    params.append(system_computed_name)
                    name_updated = True
                elif user_changed_name:
                    stats["name_skipped_user_custom"] += 1

                exactly_matches = (
                    current_query == query
                    and (current_categories or "") == categories
                    and (current_languages or "") == languages
                    and current_force_crawl == force_crawl
                    and current_enabled == enabled_target
                    and current_name == system_computed_name
                    and (current_origin_kind or "") == "favorite_artist"
                    and current_origin_artist == artist_clean
                    and (current_origin_favcats or "") == origin_favcats_csv
                )

                if exactly_matches and not changed_any and not name_updated:
                    stats["skipped_duplicate_existing"] += 1
                    continue

                if not set_clauses:
                    stats["skipped_unchanged"] += 1
                    continue

                params.append(target_id)
                sql = f"UPDATE refresh_targets SET {', '.join(set_clauses)} WHERE id=?"
                cursor = await db.execute(sql, params)
                if (cursor.rowcount or 0) > 0:
                    stats["updated"] += 1

            await db.commit()
        return stats

