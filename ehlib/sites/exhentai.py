import re
from datetime import datetime

from bs4 import BeautifulSoup

from ehlib.config import Config
from ehlib.core.session_manager import SessionManager
from ehlib.models.schemas import Gallery, Tag, sort_tags
from ehlib.sites.base import SiteBase
from ehlib.utils.helpers import parse_exhentai_url
from ehlib.utils.logger import get_logger

logger = get_logger(__name__)

EXHENTAI_BASE = "https://exhentai.org"
EHENTAI_BASE = "https://e-hentai.org"


class ExhentaiSite(SiteBase):
    name = "exhentai"

    def __init__(self, config: Config, session: SessionManager):
        super().__init__(config, session)

    def parse_gallery_id_from_url(self, url: str) -> str:
        result = parse_exhentai_url(url)
        if result:
            return f"{result[0]}/{result[1]}"
        raise ValueError(f"Cannot parse exhentai gallery ID from URL: {url}")

    def _parse_gid_token(self, combined_id: str) -> tuple[str, str]:
        parts = combined_id.split("/")
        if len(parts) == 2:
            return parts[0], parts[1]
        raise ValueError(f"Invalid combined_id format: {combined_id}")

    async def fetch_gallery(self, gallery_id: str) -> Gallery:
        gid, token = self._parse_gid_token(gallery_id)
        url = f"{EXHENTAI_BASE}/g/{gid}/{token}/"
        response = await self._session.fetch(self.name, url)
        if self._session.is_cloudflare_blocked(response):
            raise RuntimeError("Cloudflare blocked the request. Try browser mode or update cookies.")
        if response.status_code == 404:
            raise ValueError(f"Gallery {gallery_id} not found on exhentai")
        response.raise_for_status()
        return self._parse_html(response.text, gallery_id)

    async def search(self, query: str, page: int = 1) -> list[Gallery]:
        url = f"{EHENTAI_BASE}/"
        params = {"f_search": query, "page": page - 1}
        response = await self._session.fetch(self.name, url, params=params)
        response.raise_for_status()
        return self._parse_search_results(response.text)

    def _parse_html(self, html: str, combined_id: str) -> Gallery:
        soup = BeautifulSoup(html, "html.parser")
        gid, token = self._parse_gid_token(combined_id)

        title = ""
        title_jp = ""
        title_elem = soup.select_one("#gn")
        if title_elem:
            title = title_elem.get_text(strip=True)
        title_jp_elem = soup.select_one("#gj")
        if title_jp_elem:
            title_jp = title_jp_elem.get_text(strip=True)

        artist = ""
        group_name = ""
        language = ""
        category = ""
        tags = []

        tag_rows = soup.select("#taglist tr")
        for row in tag_rows:
            td = row.select_one("td.tc")
            if not td:
                continue
            tag_type = td.get_text(strip=True).rstrip(":").lower().replace(" ", "_")

            for tag_div in row.select("div"):
                tag_link = tag_div.select_one("a")
                if not tag_link:
                    continue
                tag_name = tag_link.get_text(strip=True)
                if not tag_name:
                    continue
                tags.append(Tag(type=tag_type, name=tag_name))

                if tag_type == "artist":
                    artist = tag_name
                elif tag_type == "group":
                    group_name = tag_name
                elif tag_type == "language":
                    language = tag_name

        category_elem = soup.select_one("#gdc")
        if category_elem:
            cat_text = category_elem.get_text(strip=True)
            cat_parts = cat_text.split("::")
            for part in cat_parts:
                part_stripped = part.strip()
                if not category:
                    category = part_stripped
                    tags.append(Tag(type="category", name=part_stripped))

        num_pages = 0
        page_divs = soup.select(".gdtm div, .gdtl div")
        if page_divs:
            num_pages = len(page_divs)

        page_urls: list[str] = []
        for div in page_divs:
            img = div.select_one("img")
            a_tag = div.select_one("a")
            if a_tag:
                image_page_url = a_tag.get("href", "")
                page_urls.append(image_page_url)

        cover_url = ""
        cover_img = soup.select_one("#gd1 img") or soup.select_one(".gdtm img")
        if cover_img:
            cover_url = cover_img.get("src", "")

        uploaded_at = ""
        time_elem = soup.select_one("#gdt")
        if time_elem:
            uploaded_at = time_elem.get_text(strip=True)

        gallery = Gallery(
            source=self.name,
            source_id=combined_id,
            title=title or title_jp,
            title_jp=title_jp,
            artist=artist,
            group_name=group_name,
            language=language,
            category=category,
            total_pages=len(page_urls) or num_pages,
            cover_url=cover_url,
            thumbnail_url=cover_url,
            uploaded_at=uploaded_at,
            tags=sort_tags(tags),
            page_urls=page_urls,
        )
        return gallery

    def _parse_search_results(self, html: str) -> list[Gallery]:
        soup = BeautifulSoup(html, "html.parser")
        results: list[Gallery] = []
        for item in soup.select(".itg.gld") or soup.select(".itg"):
            for entry in item.select("tr"):
                title_elem = entry.select_one(".it5 a, .glink a")
                if not title_elem:
                    continue
                title = title_elem.get_text(strip=True)
                url = title_elem.get("href", "")
                result = parse_exhentai_url(url)
                if result:
                    combined_id = f"{result[0]}/{result[1]}"
                    results.append(Gallery(
                        source=self.name,
                        source_id=combined_id,
                        title=title,
                    ))
        return results
