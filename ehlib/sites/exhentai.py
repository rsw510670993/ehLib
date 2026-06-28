from math import ceil
from urllib.parse import urljoin

from bs4 import BeautifulSoup

from ehlib.config import Config
from ehlib.core.session_manager import SessionManager
from ehlib.models.schemas import Gallery, Tag
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
        gallery = self._parse_html(response.text, gallery_id)
        gallery.request_stats = {
            "gallery_page_requests": 1,
            "cover_requests": 0,
            "image_page_requests": 0,
            "image_file_requests": 0,
        }

        total_pages = gallery.total_pages
        if total_pages > len(gallery.page_urls):
            extra_urls = await self._collect_additional_page_urls(gid, token, total_pages, gallery.page_urls)
            gallery.page_urls.extend(extra_urls)
            gallery.page_urls = gallery.page_urls[:total_pages]
            gallery.request_stats["gallery_page_requests"] += self._gallery_page_count(total_pages) - 1
        return gallery

    async def search(self, query: str, page: int = 1) -> list[Gallery]:
        url = f"{EHENTAI_BASE}/"
        params = {"f_search": query, "page": page - 1}
        response = await self._session.fetch(self.name, url, params=params)
        response.raise_for_status()
        return self._parse_search_results(response.text)

    def _parse_html(self, html: str, combined_id: str) -> Gallery:
        soup = BeautifulSoup(html, "html.parser")

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
            category = cat_text.strip()
            if category:
                tags.append(Tag(type="category", name=category))

        total_pages = self._extract_total_pages(soup)
        page_urls = self._extract_gallery_page_urls(soup)

        cover_url = ""
        cover_img = soup.select_one("#gd1 img") or soup.select_one("#gdt img")
        if cover_img:
            cover_url = cover_img.get("src", "")

        uploaded_at = ""
        posted_label = soup.select_one("#gdd td.gdt1")
        if posted_label:
            for row in soup.select("#gdd tr"):
                cells = row.select("td")
                if len(cells) >= 2 and cells[0].get_text(strip=True) == "Posted:":
                    uploaded_at = cells[1].get_text(" ", strip=True)
                    break

        gallery = Gallery(
            source=self.name,
            source_id=combined_id,
            title=title or title_jp,
            title_jp=title_jp,
            artist=artist,
            group_name=group_name,
            language=language,
            category=category,
            total_pages=total_pages or len(page_urls),
            cover_url=cover_url,
            thumbnail_url=cover_url,
            uploaded_at=uploaded_at,
            tags=tags,
            page_urls=page_urls,
        )
        return gallery

    async def resolve_display_image_url(self, image_page_url: str) -> str:
        response = await self._session.fetch(self.name, image_page_url)
        if self._session.is_cloudflare_blocked(response):
            raise RuntimeError("Cloudflare blocked while resolving exhentai image page.")
        response.raise_for_status()
        soup = BeautifulSoup(response.text, "html.parser")
        image = soup.select_one("#img")
        if not image:
            raise RuntimeError(f"Cannot find display image on page: {image_page_url}")
        image_url = image.get("src", "").strip()
        if not image_url:
            raise RuntimeError(f"Display image URL missing on page: {image_page_url}")
        return image_url

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

    @staticmethod
    def _extract_total_pages(soup: BeautifulSoup) -> int:
        for row in soup.select("#gdd tr"):
            cells = row.select("td")
            if len(cells) < 2:
                continue
            label = cells[0].get_text(strip=True)
            value = cells[1].get_text(" ", strip=True)
            if label == "Length:":
                first = value.split(" ", 1)[0]
                if first.isdigit():
                    return int(first)
        return 0

    @staticmethod
    def _extract_gallery_page_urls(soup: BeautifulSoup) -> list[str]:
        urls: list[str] = []
        for anchor in soup.select("#gdt a[href]"):
            href = anchor.get("href", "").strip()
            if "/s/" not in href:
                continue
            urls.append(urljoin(EXHENTAI_BASE, href))
        return urls

    @staticmethod
    def _gallery_page_count(total_pages: int) -> int:
        if total_pages <= 0:
            return 1
        return max(1, ceil(total_pages / 40))

    async def _collect_additional_page_urls(
        self,
        gid: str,
        token: str,
        total_pages: int,
        existing_urls: list[str],
    ) -> list[str]:
        collected = list(existing_urls)
        seen = set(existing_urls)
        gallery_pages = self._gallery_page_count(total_pages)
        for page in range(1, gallery_pages):
            url = f"{EXHENTAI_BASE}/g/{gid}/{token}/?p={page}"
            response = await self._session.fetch(self.name, url)
            if self._session.is_cloudflare_blocked(response):
                raise RuntimeError("Cloudflare blocked while fetching exhentai gallery pages.")
            response.raise_for_status()
            soup = BeautifulSoup(response.text, "html.parser")
            page_urls = self._extract_gallery_page_urls(soup)
            if not page_urls:
                break
            for page_url in page_urls:
                if page_url not in seen:
                    collected.append(page_url)
                    seen.add(page_url)
            if len(collected) >= total_pages:
                break
        return collected[len(existing_urls):]
