from datetime import datetime

from ehlib.config import Config
from ehlib.core.session_manager import SessionManager
from ehlib.models.schemas import Gallery, Tag, sort_tags
from ehlib.sites.base import SiteBase
from ehlib.utils.helpers import parse_nhentai_url
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

NHENTAI_API_BASE = "https://nhentai.net/api/v2"


class NhentaiSite(SiteBase):
    name = "nhentai"

    def __init__(self, config: Config, session: SessionManager):
        super().__init__(config, session)
        self._cdn_config: dict | None = None

    def parse_gallery_id_from_url(self, url: str) -> str:
        gid = parse_nhentai_url(url)
        if gid:
            return gid
        raise ValueError(f"Cannot parse nhentai gallery ID from URL: {url}")

    async def fetch_gallery(self, gallery_id: str) -> Gallery:
        await self._ensure_cdn_config()
        api_url = f"{NHENTAI_API_BASE}/galleries/{gallery_id}"
        response = await self._session.fetch(self.name, api_url)
        if response.status_code == 404:
            raise ValueError(f"Gallery {gallery_id} not found on nhentai")
        response.raise_for_status()
        data = response.json()
        return self._parse_gallery_detail(data)

    async def search(self, query: str, page: int = 1) -> list[Gallery]:
        await self._ensure_cdn_config()
        api_url = f"{NHENTAI_API_BASE}/search"
        params = {"query": query, "page": page}
        response = await self._session.fetch(self.name, api_url, params=params)
        response.raise_for_status()
        data = response.json()
        results = []
        for item in data.get("items", []):
            results.append(self._parse_gallery_list_item(item))
        return results

    async def _ensure_cdn_config(self) -> None:
        if self._cdn_config is not None:
            return
        try:
            response = await self._session.fetch(self.name, f"{NHENTAI_API_BASE}/cdn")
            response.raise_for_status()
            self._cdn_config = response.json()
        except Exception as e:
            logger.warning("Failed to fetch nhentai CDN config, using defaults: %s", e)
            self._cdn_config = {
                "image_servers": ["https://i1.nhentai.net"],
                "thumb_servers": ["https://t1.nhentai.net"],
            }

    def _image_base(self) -> str:
        if self._cdn_config and self._cdn_config.get("image_servers"):
            return self._cdn_config["image_servers"][0].rstrip("/")
        return "https://i1.nhentai.net"

    def _thumb_base(self) -> str:
        if self._cdn_config and self._cdn_config.get("thumb_servers"):
            return self._cdn_config["thumb_servers"][0].rstrip("/")
        return "https://t1.nhentai.net"

    def _full_image_url(self, relative_path: str) -> str:
        return f"{self._image_base()}/{relative_path.lstrip('/')}"

    def _full_thumb_url(self, relative_path: str) -> str:
        return f"{self._thumb_base()}/{relative_path.lstrip('/')}"

    def _parse_gallery_detail(self, data: dict) -> Gallery:
        gallery_id = str(data.get("id", 0))
        media_id = str(data.get("media_id", ""))
        title_data = data.get("title", {})
        title = (
            title_data.get("english", "")
            or title_data.get("pretty", "")
            or title_data.get("japanese", "")
        )
        title_jp = title_data.get("japanese", "")

        tags = []
        for tag_data in data.get("tags", []):
            tag_type = tag_data.get("type", "tag")
            tag_name = tag_data.get("name", "")
            if tag_name:
                tags.append(Tag(type=tag_type, name=tag_name))

        artist = ""
        group_name = ""
        language = ""
        category = ""
        for tag in tags:
            if tag.type == "artist":
                artist = tag.name
            elif tag.type == "group":
                group_name = tag.name
            elif tag.type == "language":
                language = tag.name
            elif tag.type == "category":
                category = tag.name

        uploaded_str = ""
        upload_date = data.get("upload_date")
        if upload_date:
            try:
                uploaded_str = datetime.fromtimestamp(upload_date).isoformat()
            except Exception:
                uploaded_str = str(upload_date)

        cover = data.get("cover", {})
        thumbnail = data.get("thumbnail", {})
        pages = data.get("pages", [])
        page_urls = [
            self._full_image_url(page.get("path", ""))
            for page in pages
            if page.get("path")
        ]

        return Gallery(
            source=self.name,
            source_id=gallery_id,
            title=title,
            title_jp=title_jp,
            artist=artist,
            group_name=group_name,
            language=language,
            category=category,
            total_pages=int(data.get("num_pages", 0)),
            cover_url=self._full_thumb_url(cover.get("path", "")) if cover.get("path") else "",
            thumbnail_url=self._full_thumb_url(thumbnail.get("path", "")) if thumbnail.get("path") else "",
            uploaded_at=uploaded_str,
            tags=sort_tags(tags),
            page_urls=page_urls,
        )

    def _parse_gallery_list_item(self, item: dict) -> Gallery:
        gallery_id = str(item.get("id", 0))
        title = item.get("english_title") or item.get("japanese_title") or ""
        title_jp = item.get("japanese_title") or ""
        thumbnail = item.get("thumbnail", "")

        return Gallery(
            source=self.name,
            source_id=gallery_id,
            title=title,
            title_jp=title_jp,
            total_pages=int(item.get("num_pages", 0)),
            thumbnail_url=self._full_thumb_url(thumbnail) if thumbnail else "",
        )
