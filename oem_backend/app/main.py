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
    version="3.0.0",
    lifespan=lifespan,
)


def ok(data: Any, **meta: Any) -> dict[str, Any]:
    return {"data": data, "meta": {"api_version": "v3", **meta}}


@app.get("/health")
def health() -> dict[str, Any]:
    return ok({"status": "ok"})


@app.get("/api/oem/roots")
def roots() -> dict[str, Any]:
    return ok(repository.list_roots())


@app.get("/api/oem/nav")
def nav(
    root: str = Query(...),
    parent_id: int | None = None,
) -> dict[str, Any]:
    return ok(repository.list_nav_children(root=root, parent_id=parent_id))


@app.get("/api/oem/variants")
def variants(
    nav_node_id: int | None = None,
    root: str | None = None,
    q: str | None = None,
) -> dict[str, Any]:
    if nav_node_id is not None:
        return ok(repository.list_variants_for_nav(nav_node_id))
    if root:
        return ok(repository.list_variants_by_root(root=root, q=q))
    raise HTTPException(status_code=400, detail="nav_node_id or root is required")


@app.get("/api/oem/variants/{variant_id}")
def variant_by_id(variant_id: int) -> dict[str, Any]:
    payload = repository.get_variant(variant_id)
    if not payload:
        raise HTTPException(status_code=404, detail="Variant not found")
    return ok(payload)


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
    root: str | None = None,
    limit: int = Query(50, ge=1, le=100),
    offset: int = Query(0, ge=0),
) -> dict[str, Any]:
    return ok(repository.search_parts(q=q, root=root, limit=limit, offset=offset))


@app.get("/api/oem/parts/usages")
def part_usages(
    q: str = Query(..., min_length=2),
    limit: int = Query(1000, ge=1, le=2000),
) -> dict[str, Any]:
    return ok(repository.search_part_usages(q=q, limit=limit))
