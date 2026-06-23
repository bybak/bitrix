from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, HTTPException, Query

from app import repository
from app.db import close_pool, open_pool


@asynccontextmanager
async def lifespan(_: FastAPI):
    open_pool()
    yield
    close_pool()


app = FastAPI(
    title="OEM Schemas Catalog API",
    version="0.1.0",
    lifespan=lifespan,
)


def ok(data: Any, **meta: Any) -> dict[str, Any]:
    return {"data": data, "meta": {"api_version": "v1", **meta}}


@app.get("/health")
def health() -> dict[str, Any]:
    return ok({"status": "ok"})


@app.get("/api/oem/vehicle-types")
def vehicle_types() -> dict[str, Any]:
    return ok(repository.list_vehicle_types())


@app.get("/api/oem/brands")
def brands(vehicle_type: str | None = None) -> dict[str, Any]:
    return ok(repository.list_brands(vehicle_type=vehicle_type))


@app.get("/api/oem/years")
def years(
    vehicle_type: str = Query(...),
    brand_id: int = Query(...),
) -> dict[str, Any]:
    return ok(repository.list_years(vehicle_type=vehicle_type, brand_id=brand_id))


@app.get("/api/oem/models")
def models(
    vehicle_type: str = Query(...),
    brand_id: int = Query(...),
    year: int | None = None,
    q: str | None = None,
) -> dict[str, Any]:
    return ok(repository.list_models(vehicle_type=vehicle_type, brand_id=brand_id, year=year, q=q))


@app.get("/api/oem/variants")
def variants(
    model_id: int = Query(...),
    year: int | None = None,
    region: str | None = None,
) -> dict[str, Any]:
    return ok(repository.list_variants(model_id=model_id, year=year, region=region))


@app.get("/api/oem/assemblies")
def assemblies(variant_id: int = Query(...), q: str | None = None) -> dict[str, Any]:
    return ok(repository.list_assemblies(variant_id=variant_id, q=q))


@app.get("/api/oem/diagrams/{assembly_id}")
def diagram(assembly_id: int) -> dict[str, Any]:
    payload = repository.get_diagram_payload(assembly_id)
    if not payload:
        raise HTTPException(status_code=404, detail="Assembly not found")
    return ok(payload)


@app.get("/api/oem/parts/search")
def part_search(
    q: str = Query(..., min_length=2),
    limit: int = Query(50, ge=1, le=100),
    offset: int = Query(0, ge=0),
) -> dict[str, Any]:
    return ok(repository.search_parts(q=q, limit=limit, offset=offset))
