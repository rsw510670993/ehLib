from abc import ABC, abstractmethod

from ehlib.config import Config
from ehlib.core.session_manager import SessionManager
from ehlib.models.schemas import Gallery


class SiteBase(ABC):
    name: str = "base"

    def __init__(self, config: Config, session: SessionManager):
        self._config = config
        self._session = session

    @abstractmethod
    async def fetch_gallery(self, gallery_id: str) -> Gallery:
        ...

    @abstractmethod
    async def search(self, query: str, page: int = 1) -> list[Gallery]:
        ...

    @abstractmethod
    def parse_gallery_id_from_url(self, url: str) -> str:
        ...
