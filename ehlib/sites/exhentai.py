import json
import httpx
from asyncio import sleep
from math import ceil
from pathlib import Path
from urllib.parse import urljoin, urlencode

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

    async def fetch_metadata_and_thumb(self, source_id: str, thumbs_dir: str, cover_url: str = "") -> tuple[str, str, str, str, str, str, str, str, str]:
        gid, token = self._parse_gid_token(source_id)
        url = f"{EXHENTAI_BASE}/g/{gid}/{token}/"
        response = await self._session.fetch(self.name, url)
        if self._session.is_cloudflare_blocked(response):
            raise RuntimeError("Cloudflare blocked")
        if response.status_code == 404:
            return ("", "", "", "", "", "", "", "", "")
        response.raise_for_status()
        gallery = self._parse_html(response.text, source_id)
        artist = gallery.artist or ""
        uploaded_at = gallery.uploaded_at or ""
        category = gallery.category or ""
        language = (gallery.language or "").lower()
        title_jp = gallery.title_jp or ""
        group_name = gallery.group_name or ""
        tags_json = json.dumps([{"type": t.type, "name": t.name} for t in gallery.tags]) if gallery.tags else ""
        # always use gallery page cover URL (search thumbnail may be a placeholder)
        if gallery.cover_url:
            cover_url = gallery.cover_url
        thumb_path = ""
        if cover_url:
            safe = source_id.replace("/", "_").replace("\\", "_")
            ext = cover_url.rsplit(".", 1)[-1].split("?")[0] if "." in cover_url else "jpg"
            dest = Path(thumbs_dir) / f"{safe}.{ext}"
            dest.parent.mkdir(parents=True, exist_ok=True)
            client = await self._session.get_client(self.name)
            resp = await client.get(cover_url)
            resp.raise_for_status()
            dest.write_bytes(resp.content)
            thumb_path = str(dest.resolve())
        return artist, thumb_path, uploaded_at, category, cover_url, language, title_jp, group_name, tags_json

    async def search(self, query: str, page: int = 1, next_cursor: str = "", 
                     categories: list[int] | None = None,
                     prev_cursor: str = "", range_val: int | None = None) -> list[Gallery]:
        params = {"f_search": query, "f_sft": "on", "f_sfu": "on", "f_sfl": "on"}
        if categories is not None:
            if categories:
                params["f_cats"] = str(self._calc_categories_mask(categories))
            else:
                params["f_cats"] = "0"
        if next_cursor:
            params["next"] = next_cursor
        if prev_cursor:
            params["prev"] = prev_cursor
        if range_val is not None:
            params["range"] = str(range_val)
        self.current_page = page if (next_cursor or prev_cursor or range_val is not None) else 1
        url = f"{EXHENTAI_BASE}/"
        response = await self._session.fetch(self.name, url, params=params)
        if self._session.is_cloudflare_blocked(response):
            raise RuntimeError("Cloudflare blocked search request. Try updating cookies.")
        if response.status_code == 404:
            return []
        response.raise_for_status()
        return self._parse_search_results(response.text)

    async def crawl_all_pages(
        self, query: str, page_size: int = 25,
        categories: list[int] | None = None,
        resume_cursor: str = "",
        resume_page: int = 1,
        on_page: callable = None,
        on_wait: callable = None,
    ) -> list[dict]:
        """爬取所有搜索结果页，返回简化后的 dict 列表。
        resume_cursor: 从哪个 next_cursor 开始续传
        resume_page: 当前恢复的页码（仅用于回调）
        on_page: 每爬完一页的回调，接收 (page, items, next_cursor)
        """
        import time as time_module
        all_items: list[dict] = []
        page = resume_page
        next_cursor = resume_cursor
        has_next = True
        consecutive_empty = 0
        pages_in_batch = 0
        total_requests = 0
        start_time = time_module.time()
        page_times = []

        while has_next:
            params = {"f_search": query, "f_sname": "on", "s": "2", "f_sft": "on", "f_sfu": "on", "f_sfl": "on"}
            if categories is not None:
                params["f_cats"] = str(self._calc_categories_mask(categories))
            if next_cursor:
                params["next"] = next_cursor

            url = f"{EXHENTAI_BASE}/"
            t0 = time_module.time()
            response = await self._session.fetch(self.name, url, params=params)
            total_requests += 1
            if self._session.is_cloudflare_blocked(response):
                logger.warning("Cloudflare blocked on page %d, waiting 60s...", page)
                await sleep(60)
                response = await self._session.fetch(self.name, url, params=params)
                total_requests += 1
                if self._session.is_cloudflare_blocked(response):
                    raise RuntimeError("Cloudflare still blocking after retry")
            if response.status_code == 404:
                break
            response.raise_for_status()
            t1 = time_module.time()

            soup = BeautifulSoup(response.text, "html.parser")
            self._parse_next_cursor(soup)
            self._parse_total_results(soup)
            if page == 1:
                logger.info("ExHentai reports: %d total results, %d pages", self.total_results, self.total_pages)
            page_items = self._parse_search_results_flat(soup)
            page_times.append((page, len(page_items), t1 - t0, total_requests))

            if not page_items:
                consecutive_empty += 1
                if consecutive_empty >= 3:
                    break
            else:
                consecutive_empty = 0
                all_items.extend(page_items)

            if on_page:
                await on_page(page, page_items, self.next_cursor)

            if self.has_next and self.next_cursor:
                next_cursor = self.next_cursor
                page += 1
                pages_in_batch += 1
                if pages_in_batch % 2 == 0:
                    import random
                    delay = random.randint(180, 300)
                    next_run = time_module.strftime("%H:%M:%S", time_module.localtime(time_module.time() + delay))
                    logger.info("Batch complete (%d pages), next at %s, waiting %ds...", pages_in_batch, next_run, delay)
                    if on_wait:
                        await on_wait(next_run, delay)
                    await sleep(delay)
            else:
                has_next = False

        elapsed = time_module.time() - start_time
        elapsed_str = f"{int(elapsed//60)}m{int(elapsed%60)}s"
        avg_freq = total_requests / elapsed if elapsed > 0 else 0
        logger.info("=" * 50)
        logger.info("Crawl finished: %d pages, %d items, %d requests", len(page_times), len(all_items), total_requests)
        logger.info("Total time: %s, avg frequency: %.2f req/min", elapsed_str, avg_freq * 60)
        for pt in page_times:
            logger.info("  Page %d: %d items, %.1fs, request #%d", pt[0], pt[1], pt[2], pt[3])
        logger.info("=" * 50)

        return all_items

    def _parse_search_results_flat(self, soup: BeautifulSoup) -> list[dict]:
        """解析搜索结果页，返回简化 dict 列表（不含 Gallery 对象构造）"""
        import re
        items: list[dict] = []
        table = soup.select_one("table.itg.gltm") or soup.select_one("table.itg.gld") or soup.select_one("table.itg")
        if table:
            for row in table.select("tr"):
                link = row.select_one("td a[href*='/g/']") or row.select_one("a[href*='/g/']")
                if not link:
                    continue
                href = link.get("href", "")
                parsed = parse_exhentai_url(href)
                if not parsed:
                    continue
                gid, token = parsed
                title_elem = row.select_one(".glink, .gl3m")
                title = title_elem.get_text(strip=True) if title_elem else ""
                cat_elem = row.select_one(".glcat")
                category = cat_elem.get_text(strip=True) if cat_elem else ""
                total_pages = 0
                uploaded_at = ""
                gl2m = row.select_one(".gl2m") or row.select_one(".gl4c")
                if gl2m:
                    text = gl2m.get_text(" ", strip=True)
                    m = re.search(r"(\d+)\s+pages?", text, re.IGNORECASE)
                    if m:
                        total_pages = int(m.group(1))
                    date_m = re.search(r"(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})", text)
                    if date_m:
                        uploaded_at = date_m.group(1)
                # cover thumbnail
                thumb = ""
                img = row.select_one(".glthumb img")
                if img:
                    src = img.get("data-src", "") or img.get("src", "")
                    if src and not src.startswith("data:"):
                        thumb = src
                items.append({
                    "source": "exhentai",
                    "source_id": f"{gid}/{token}",
                    "title": title,
                    "category": category,
                    "total_pages": total_pages,
                    "uploaded_at": uploaded_at,
                    "thumbnail": thumb,
                })
        if not items:
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
                    title_elem = item.select_one(".glink")
                    title = title_elem.get_text(strip=True) if title_elem else ""
                    cat_elem = item.select_one(".glcat, .gl3")
                    category = cat_elem.get_text(strip=True) if cat_elem else ""
                    total_pages = 0
                    uploaded_at = ""
                    gl5t = item.select_one(".gl5t")
                    if gl5t:
                        text = gl5t.get_text(" ", strip=True)
                        m = re.search(r"(\d+)\s+pages?", text, re.IGNORECASE)
                        if m:
                            total_pages = int(m.group(1))
                        posted_div = gl5t.select_one("div[id^='posted_']")
                        if posted_div:
                            uploaded_at = posted_div.get_text(strip=True)
                    thumb = ""
                    img = item.select_one(".glthumb img, .gl5t img")
                    if img:
                        src = img.get("data-src", "") or img.get("src", "")
                        if src and not src.startswith("data:"):
                            thumb = src
                    items.append({
                        "source": "exhentai",
                        "source_id": f"{gid}/{token}",
                        "title": title,
                        "category": category,
                        "total_pages": total_pages,
                        "uploaded_at": uploaded_at,
                        "thumbnail": thumb,
                    })
        return items

    def _parse_next_cursor(self, soup: BeautifulSoup) -> None:
        import re
        self.next_cursor = ""
        self.has_next = False
        self.prev_cursor = ""
        for a in soup.select("a"):
            href = a.get("href", "")
            m = re.search(r"[?&]next=(\d+)", href)
            if m:
                self.next_cursor = m.group(1)
                self.has_next = True
            m = re.search(r"[?&]prev=(\d+)", href)
            if m:
                self.prev_cursor = m.group(1)

    def _parse_total_results(self, soup: BeautifulSoup) -> None:
        import re
        el = soup.select_one(".searchtext")
        if el:
            m = re.search(r"Found\s+(?:about\s+)?([\d,]+)\s+result", el.get_text())
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
        language_candidates = []
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
                    language_candidates.append(tag_name)

        # prefer specific language over "translated"
        if language_candidates:
            specific = [l for l in language_candidates if l.lower() != "translated"]
            language = (specific[0] if specific else language_candidates[0]).lower()

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
        if not cover_url or cover_url.startswith("data:"):
            # fallback: extract from CSS background (new ExHentai layout)
            cover_div = soup.select_one("#gdt a div[style*=background]")
            if cover_div:
                import re
                m = re.search(r'url\(([^)]+)\)', cover_div.get("style", ""))
                if m:
                    cover_url = m.group(1)

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
                    language = cells[1].get_text(" ", strip=True).lower()
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
