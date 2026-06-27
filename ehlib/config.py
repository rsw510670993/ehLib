from pathlib import Path
from typing import Any

import yaml

DEFAULT_CONFIG = {
    "cookies": {
        "nhentai": {"csrftoken": "", "cf_clearance": ""},
        "exhentai": {"ipb_member_id": "", "ipb_pass_hash": "", "cf_clearance": ""},
    },
    "download": {
        "path": "./downloads",
        "max_concurrent": 3,
        "retry_times": 3,
        "retry_delay": 5,
    },
    "request": {
        "user_agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/131.0.0.0 Safari/537.36"
        ),
        "delay_between_requests": 1.5,
    },
    "browser": {
        "headless": False,
    },
}


class Config:
    def __init__(self, config_path: str = "config.yaml"):
        self._config_path = Path(config_path)
        self._data: dict[str, Any] = {}
        self.load()

    def load(self) -> None:
        if self._config_path.exists():
            with open(self._config_path, "r", encoding="utf-8") as f:
                self._data = yaml.safe_load(f) or {}
        self._merge_defaults()

    def _merge_defaults(self) -> None:
        for section, defaults in DEFAULT_CONFIG.items():
            if section not in self._data:
                self._data[section] = {}
            if isinstance(defaults, dict):
                for key, value in defaults.items():
                    if key not in self._data[section]:
                        self._data[section][key] = value

    def save(self) -> None:
        self._config_path.parent.mkdir(parents=True, exist_ok=True)
        with open(self._config_path, "w", encoding="utf-8") as f:
            yaml.dump(self._data, f, default_flow_style=False, allow_unicode=True)

    def get(self, *keys: str, default: Any = None) -> Any:
        value = self._data
        for key in keys:
            if isinstance(value, dict):
                value = value.get(key)
            else:
                return default
            if value is None:
                return default
        return value

    def set(self, *keys: str, value: Any) -> None:
        d = self._data
        for key in keys[:-1]:
            if key not in d or not isinstance(d[key], dict):
                d[key] = {}
            d = d[key]
        d[keys[-1]] = value

    @property
    def cookies(self) -> dict:
        return self._data.get("cookies", {})

    @property
    def download(self) -> dict:
        return self._data.get("download", {})

    @property
    def request(self) -> dict:
        return self._data.get("request", {})

    @property
    def browser(self) -> dict:
        return self._data.get("browser", {})

    @property
    def download_path(self) -> Path:
        return Path(self._data.get("download", {}).get("path", "./downloads"))


_config_instance: Config | None = None


def get_config(config_path: str = "config.yaml") -> Config:
    global _config_instance
    if _config_instance is None:
        _config_instance = Config(config_path)
    return _config_instance
