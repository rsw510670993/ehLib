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
    PAGE_SIZE = 25

    def __init__(self, config: Config, session: SessionManager):
        super().__init__(config, session)
        self.current_page: int = 1
        self.next_cursor: str = ""
        self.has_next: bool = False
        self.total_results: int = 0
        self.total_pages: int = 0

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

    async def search(self, query: str, page: int = 1, next_cursor: str = "", categories: list[int] | None = None) -> list[Gallery]:
        params = {"f_search": query}
        if categories is not None:
            if categories:
                params["f_cats"] = str(self._calc_categories_mask(categories))
            else:
                params["f_cats"] = "0"
        if next_cursor:
            params["next"] = next_cursor
            self.current_page = page
        else:
            self.current_page = 1
        url = f"{EXHENTAI_BASE}/"
        response = await self._session.fetch(self.name, url, params=params)
        if self._session.is_cloudflare_blocked(response):
            raise RuntimeError("Cloudflare blocked search request. Try updating cookies.")
        if response.status_code == 404:
            return []
        response.raise_for_status()
        return self._parse_search_results(response.text)

    def _parse_next_cursor(self, soup: BeautifulSoup) -> None:
        import re
        self.next_cursor = ""
        self.has_next = False
        for a in soup.select("a"):
            href = a.get("href", "")
            m = re.search(r"[?&]next=(\d+)", href)
            if m and "next" in a.get_text(strip=True).lower():
                self.next_cursor = m.group(1)
                self.has_next = True
                break

    def _parse_total_results(self, soup: BeautifulSoup) -> None:
        import re
        el = soup.select_one(".searchtext")
        if el:
            m = re.search(r"Found\s+([\d,]+)\s+result", el.get_text())
            if m:
                self.total_results = int(m.group(1).replace(",", ""))
        if self.total_results > 0:
            self.total_pages = (self.total_results + self.PAGE_SIZE - 1) // self.PAGE_SIZE

    def _parse_search_results(self, html: str) -> list[Gallery]:
        import re
        soup = BeautifulSoup(html, "html.parser")
        results: list[Gallery] = []

        self._parse_next_cursor(soup)
        self._parse_total_results(soup)

        table = soup.select_one("table.itg.gltm")
        if table:
            for row in table.select("tr"):
                link = row.select_one("td a[href*='/g/']")
                if not link:
                    continue
                href = link.get("href", "")
                parsed = parse_exhentai_url(href)
                if not parsed:
                    continue
                gid, token = parsed
                combined_id = f"{gid}/{token}"

                title_elem = row.select_one(".glink, .gl3m")
                title = title_elem.get_text(strip=True) if title_elem else ""

                cat_elem = row.select_one(".glcat")
                category = cat_elem.get_text(strip=True) if cat_elem else ""

                total_pages_gallery = 0
                uploaded_at = ""
                gl2m = row.select_one(".gl2m")
                if gl2m:
                    text = gl2m.get_text(" ", strip=True)
                    m = re.search(r"(\d+)\s+pages?", text, re.IGNORECASE)
                    if m:
                        total_pages_gallery = int(m.group(1))
                    date_m = re.search(r"(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})", text)
                    if date_m:
                        uploaded_at = date_m.group(1)

                results.append(Gallery(
                    source="exhentai",
                    source_id=combined_id,
                    title=title,
                    category=category,
                    total_pages=total_pages_gallery,
                    uploaded_at=uploaded_at,
                ))
            return results

        if not results:
            container = soup.select_one("table.itg.gld") or soup.select_one("table.itg")
            if container:
                for row in container.select("tr"):
                    link = row.select_one("a[href*='/g/']")
                    if not link:
                        continue
                    href = link.get("href", "")
                    parsed = parse_exhentai_url(href)
                    if not parsed:
                        continue
                    gid, token = parsed
                    combined_id = f"{gid}/{token}"

                    title_elem = row.select_one(".glink")
                    title = title_elem.get_text(strip=True) if title_elem else ""

                    cat_elem = row.select_one(".glcat")
                    category = cat_elem.get_text(strip=True) if cat_elem else ""

                    total_pages_gallery = 0
                    uploaded_at = ""
                    for cell in row.select("td"):
                        cls = " ".join(cell.get("class", []))
                        text = cell.get_text(" ", strip=True)
                        if "gl4c" in cls:
                            m = re.search(r"(\d+)\s+pages?", text, re.IGNORECASE)
                            if m:
                                total_pages_gallery = int(m.group(1))
                        elif "gl7c" in cls:
                            uploaded_at = text

                    results.append(Gallery(
                        source="exhentai",
                        source_id=combined_id,
                        title=title,
                        category=category,
                        total_pages=total_pages_gallery,
                        uploaded_at=uploaded_at,
                    ))

        if not results:
            container = soup.select_one("div.itg")
            if container:
                for item in container.find_all("div", class_=re.compile(r"^gl1"), recursive=False):
                    link = item.select_one("a[href*='/g/']")
                    if not link:
                        continue
                    href = link.get("href", "")
                    parsed = parse_exhentai_url(href)
                    if not parsed:
                        continue
                    gid, token = parsed
                    combined_id = f"{gid}/{token}"

                    title_elem = item.select_one(".glink")
                    title = title_elem.get_text(strip=True) if title_elem else ""

                    cat_elem = item.select_one(".glcat, .gl3")
                    category = cat_elem.get_text(strip=True) if cat_elem else ""

                    total_pages_gallery = 0
                    uploaded_at = ""
                    gl5t = item.select_one(".gl5t")
                    if gl5t:
                        text = gl5t.get_text(" ", strip=True)
                        m = re.search(r"(\d+)\s+pages?", text, re.IGNORECASE)
                        if m:
                            total_pages_gallery = int(m.group(1))
                        posted_div = gl5t.select_one("div[id^='posted_']")
                        if posted_div:
                            uploaded_at = posted_div.get_text(strip=True)

                    results.append(Gallery(
                        source="exhentai",
                        source_id=combined_id,
                        title=title,
                        category=category,
                        total_pages=total_pages_gallery,
                        uploaded_at=uploaded_at,
                    ))
        return results

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

        if not language:
            for row in soup.select("#gdd tr"):
                cells = row.select("td")
                if len(cells) >= 2 and cells[0].get_text(strip=True) == "Language:":
                    language = cells[1].get_text(" ", strip=True)
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

    @staticmethod
    def _calc_categories_mask(category_ids: list[int]) -> int:
        CAT_BITS = {1, 2, 4, 8, 16, 32, 64, 128, 256, 512}
        include_bits = 0
        for cid in category_ids:
            if cid in CAT_BITS:
                include_bits |= cid
        return 1023 ^ include_bits

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
