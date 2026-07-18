import gzip
import json
import shutil
from pathlib import Path
from urllib.request import urlopen, Request

GITHUB_API = "https://api.github.com/repos/EhTagTranslation/Database/releases/latest"
TRANSLATION_DB_DIR = Path(__file__).resolve().parent.parent.parent / "data"
TRANSLATION_DB_PATH = TRANSLATION_DB_DIR / "eh_tag_translation.json"


class TagTranslator:
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

    def translate(self, ns: str, name: str) -> str | None:
        if not self._loaded:
            return None
        lookup_ns = self.NS_ALIAS.get(ns, ns)
        ns_map = self._ns_map.get(lookup_ns)
        if not ns_map:
            return None
        return ns_map.get(name.lower())

    def translate_tags(self, tags_json: str) -> str:
        if not tags_json or not self._loaded:
            return tags_json
        try:
            tags = json.loads(tags_json)
            if not isinstance(tags, list):
                return tags_json
            changed = False
            for t in tags:
                ns = t.get("type", "")
                name = t.get("name", "")
                cn = self.translate(ns, name)
                if cn:
                    t["name_cn"] = cn
                    changed = True
            if changed:
                return json.dumps(tags, ensure_ascii=False)
            return tags_json
        except Exception:
            return tags_json


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
