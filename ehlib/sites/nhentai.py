from datetime import datetime

from ehlib.config import Config
from ehlib.core.session_manager import SessionManager
from ehlib.models.schemas import Gallery, Tag
from ehlib.sites.base import SiteBase
from ehlib.utils.helpers import parse_nhentai_url
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

NHENTAI_API_BASE = "https://nhentai.net/api"
NHENTAI_BASE = "https://nhentai.net"


class NhentaiSite(SiteBase):
    name = "nhentai"

    def __init__(self, config: Config, session: SessionManager):
        super().__init__(config, session)

    def parse_gallery_id_from_url(self, url: str) -> str:
        gid = parse_nhentai_url(url)
        if gid:
            return gid
        raise ValueError(f"Cannot parse nhentai gallery ID from URL: {url}")

    async def fetch_gallery(self, gallery_id: str) -> Gallery:
        api_url = f"{NHENTAI_API_BASE}/gallery/{gallery_id}"
        response = await self._session.fetch(self.name, api_url)
        if response.status_code == 404:
            raise ValueError(f"Gallery {gallery_id} not found on nhentai")
        response.raise_for_status()
        data = response.json()
        return self._parse_gallery_data(data)

    async def search(self, query: str, page: int = 1) -> list[Gallery]:
        api_url = f"{NHENTAI_API_BASE}/galleries/search"
        params = {"query": query, "page": page}
        response = await self._session.fetch(self.name, api_url, params=params)
        response.raise_for_status()
        data = response.json()
        results = []
        for item in data.get("result", []):
            results.append(self._parse_gallery_data(item))
        return results

    def _parse_gallery_data(self, data: dict) -> Gallery:
        media_id = str(data.get("id", 0))
        title_data = data.get("title", {})
        title = title_data.get("english", "") or title_data.get("pretty", "") or title_data.get("japanese", "")
        title_jp = title_data.get("japanese", "")

        images = data.get("images", {})
        cover = images.get("cover", {})
        cover_ext = {"j": ".jpg", "p": ".png", "g": ".gif"}.get(cover.get("t", "j"), ".jpg")
        thumbnail = images.get("thumbnail", {})
        thumb_ext = {"j": ".jpg", "p": ".png", "g": ".gif"}.get(thumbnail.get("t", "j"), ".jpg")

        media_ext = {"j": ".jpg", "p": ".png", "g": ".gif"}.get(
            images.get("pages", [{}])[0].get("t", "j") if images.get("pages") else "j",
            ".jpg",
        )

        num_pages = int(data.get("num_pages", 0))
        page_urls = [
            f"https://i.nhentai.net/galleries/{media_id}/{i + 1}{media_ext}"
            for i in range(num_pages)
        ]

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

        gallery = Gallery(
            source=self.name,
            source_id=media_id,
            title=title,
            title_jp=title_jp,
            artist=artist,
            group_name=group_name,
            language=language,
            category=category,
            total_pages=num_pages,
            cover_url=f"https://t.nhentai.net/galleries/{media_id}/cover{cover_ext}" if cover else "",
            thumbnail_url=f"https://t.nhentai.net/galleries/{media_id}/thumb{thumb_ext}" if thumbnail else "",
            uploaded_at=uploaded_str,
            tags=tags,
            page_urls=page_urls,
        )
        return gallery
