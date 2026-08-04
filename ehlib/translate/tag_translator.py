import gzip
import json
import re
import shutil
from pathlib import Path
from urllib.request import urlopen, Request

GITHUB_API = "https://api.github.com/repos/EhTagTranslation/Database/releases/latest"
TRANSLATION_DB_DIR = Path(__file__).resolve().parent.parent.parent / "data"
TRANSLATION_DB_PATH = TRANSLATION_DB_DIR / "eh_tag_translation.json"


class TagTranslator:
    QUERY_TOKEN_RE = re.compile(
        r'(?<!\S)(?P<neg>-?)(?P<ns>[a-zA-Z_]+):(?:"(?P<quoted>[^"]+)"|(?P<plain>\S+))'
    )

    def __init__(self, db_path: str | Path | None = None):
        self._db_path = Path(db_path) if db_path else TRANSLATION_DB_PATH
        self._ns_map: dict[str, dict[str, str]] = {}
        self._loaded = False

    def load(self) -> bool:
        if not self._db_path.is_file():
            return False
        try:
            raw = json.loads(self._db_path.read_text(encoding="utf-8"))
            entries = raw.get("data") if isinstance(raw, dict) else raw
            if not isinstance(entries, list):
                return False
            for entry in entries:
                ns_name = entry.get("namespace", "")
                ns_data = entry.get("data")
                if not ns_name or not isinstance(ns_data, dict):
                    continue
                tag_map: dict[str, str] = {}
                for tag_key, tag_val in ns_data.items():
                    if isinstance(tag_val, dict):
                        cn = tag_val.get("name", "")
                        if cn:
                            tag_map[tag_key.lower()] = cn
                self._ns_map[ns_name] = tag_map
            self._loaded = True
            return True
        except Exception:
            return False

    NS_ALIAS = {
        "category": "reclass",
    }

    NS_DISPLAY = {
        "artist": "作者",
        "character": "角色",
        "cosplayer": "Coser",
        "female": "女性",
        "group": "社团",
        "language": "语言",
        "male": "男性",
        "mixed": "混合",
        "other": "其他",
        "parody": "原作",
        "reclass": "分类",
        "category": "分类",
    }

    def _split_multi_alias(self, s: str) -> list[str]:
        """把 'kazuto kirigaya | kirito' 或 'kirito|kazuto kirigaya' 拆成多 alias 列表"""
        if not s:
            return []
        out: list[str] = []
        for part in re.split(r'\s*\|\s*', s):
            p = part.strip()
            if p:
                out.append(p)
        return out

    def translate(self, ns: str, name: str, extra_match_keys: list[str] | None = None) -> str | None:
        if not self._loaded:
            return None
        lookup_ns = self.NS_ALIAS.get(ns, ns)
        ns_map = self._ns_map.get(lookup_ns)
        if not ns_map:
            return None
        candidates: list[str] = []
        if name:
            candidates.append(str(name))
            candidates.extend(self._split_multi_alias(str(name)))
        if extra_match_keys:
            for k in extra_match_keys:
                if k and k not in candidates:
                    candidates.append(str(k))
                    for alias in self._split_multi_alias(str(k)):
                        if alias and alias not in candidates:
                            candidates.append(alias)
        for cand in candidates:
            try:
                cn = ns_map.get(cand.lower())
            except Exception:
                cn = None
            if cn:
                return cn
        return None

    def translate_tags(self, tags_json: str) -> str:
        if not tags_json or not self._loaded:
            return tags_json
        try:
            tags = json.loads(tags_json)
            if not isinstance(tags, list):
                return tags_json
            changed = False
            for t in tags:
                if not isinstance(t, dict):
                    continue
                ns = t.get("type", "")
                name = t.get("name", "")
                extra = t.get("match_keys")
                if isinstance(extra, list):
                    extra_match_keys: list[str] = [str(x) for x in extra if x is not None]
                else:
                    extra_match_keys = []
                cn = self.translate(ns, name, extra_match_keys=extra_match_keys)
                if cn:
                    t["name_cn"] = cn
                    changed = True
            if changed:
                return json.dumps(tags, ensure_ascii=False)
            return tags_json
        except Exception:
            return tags_json

    def translate_query_label(self, query: str) -> str:
        if not query or not self._loaded:
            return query

        def replace(match: re.Match[str]) -> str:
            neg = match.group("neg") or ""
            ns = match.group("ns")
            raw_name = match.group("quoted") if match.group("quoted") is not None else (match.group("plain") or "")
            name = raw_name[:-1] if raw_name.endswith("$") else raw_name
            label = self.NS_DISPLAY.get(ns, self.NS_DISPLAY.get(self.NS_ALIAS.get(ns, ns), ns))
            translated = self.translate(ns, name)
            if not translated and label == ns:
                return match.group(0)
            return f"{neg}{label}:{translated or name}"

        return self.QUERY_TOKEN_RE.sub(replace, query).strip()


def download_latest(output_path: str | Path | None = None) -> str:
    dest = Path(output_path) if output_path else TRANSLATION_DB_PATH
    dest.parent.mkdir(parents=True, exist_ok=True)

    req = Request(GITHUB_API, headers={"User-Agent": "ehLib/1.0", "Accept": "application/json"})
    resp = urlopen(req, timeout=30)
    release = json.loads(resp.read())

    asset_url = None
    gz_url = None
    for asset in release.get("assets", []):
        name = asset.get("name", "")
        if name == "db.text.json":
            asset_url = asset["browser_download_url"]
        elif name == "db.text.json.gz":
            gz_url = asset["browser_download_url"]

    if asset_url:
        print(f"Downloading {asset_url} ...")
        req2 = Request(asset_url, headers={"User-Agent": "ehLib/1.0"})
        resp2 = urlopen(req2, timeout=60)
        dest.write_bytes(resp2.read())
        print(f"Saved to {dest} ({dest.stat().st_size / 1024:.0f} KB)")
        return str(dest)

    if gz_url:
        print(f"Downloading {gz_url} ...")
        req2 = Request(gz_url, headers={"User-Agent": "ehLib/1.0"})
        resp2 = urlopen(req2, timeout=60)
        data = gzip.decompress(resp2.read())
        dest.write_bytes(data)
        print(f"Saved to {dest} ({dest.stat().st_size / 1024:.0f} KB)")
        return str(dest)

    raise RuntimeError("No db.text.json or db.text.json.gz found in latest release")
