from functools import lru_cache

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    database_dsn: str = "postgresql://oem_user:oem_password@localhost:5432/oem_catalog"
    yamaha_database_dsn: str = "postgresql://yamaha_user:yamaha_password@localhost:5432/yamaha_catalog"
    registry_database_dsn: str = (
        "postgresql://oem_registry_user:oem_registry_password@localhost:5432/oem_registry"
    )
    asset_root: str = "/app/storage/oem-diagrams"
    public_asset_base_url: str = "/oem-assets"
    http_timeout: float = 20.0
    yamaha_diagram_timeout: float = 6.0
    yamaha_diagram_api_concurrency: int = 25

    model_config = SettingsConfigDict(env_prefix="OEM_")


@lru_cache
def get_settings() -> Settings:
    return Settings()
