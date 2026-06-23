import re
import unicodedata


def normalize_text(value: str | None) -> str:
    if not value:
        return ""
    value = unicodedata.normalize("NFKC", value)
    value = value.strip().casefold()
    value = re.sub(r"\s+", " ", value)
    return value


def normalize_part_number(value: str | None) -> str:
    if not value:
        return ""
    value = unicodedata.normalize("NFKC", value).upper()
    return re.sub(r"[^A-Z0-9]", "", value)


def normalize_slug(value: str | None) -> str:
    return normalize_text(value).replace(" ", "-")
