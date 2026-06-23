# OEM Backend

Separate backend for the OEM Schemas Catalog.

## Services

Defined in the root `docker-compose.yml`:

- `oem_db` - PostgreSQL 16, database `oem_catalog`
- `oem_backend` - FastAPI application on `http://localhost:8088`

## Start

From project root:

```bash
docker compose up -d oem_db oem_backend
```

The initial schema is mounted from:

```text
oem_backend/db/schema_postgres.sql
```

It is applied automatically only when the `oem_pg_data` volume is first created.

## Pilot Import

Run both pilot imports:

```bash
docker compose exec oem_backend python -m app.cli import-pilot --source all
```

Run only Megazip:

```bash
docker compose exec oem_backend python -m app.cli import-pilot --source megazip
```

Run only ARI seed:

```bash
docker compose exec oem_backend python -m app.cli import-pilot --source ari
```

ARI import prints progress to stderr:

```text
[remotors_ari_sample] part ref=16 number=A46006015000 price=23.05 EUR | stage=parts and source prices 14/19 ( 73.7%) | overall=25/61 ( 41.0%) | elapsed=00:03 eta=00:04
```

The progress line includes the current stage counter, overall counter, percentage, elapsed time, and ETA. Do not start full Remotors crawling without an explicit controlled command/run window.

## Full Remotors Crawl

The full Remotors crawler is intentionally not started automatically and requires an explicit confirmation flag:

```bash
docker compose exec -T oem_backend python -m app.cli crawl-remotors --brands all --confirm-full-crawl
```

By default the crawler resumes safely: if an assembly already has a diagram and parts in the database, it is skipped. Use `--force` only when you intentionally want to re-parse already imported assemblies.

Useful safer variants:

```bash
docker compose exec -T oem_backend python -m app.cli crawl-remotors --brands KTM --max-assemblies 10 --confirm-full-crawl
docker compose exec -T oem_backend python -m app.cli crawl-remotors --brands KTM,HUM --no-images --confirm-full-crawl
docker compose exec -T oem_backend python -m app.cli crawl-remotors --brands KTM --year 2025 --max-models 1 --confirm-full-crawl
```

## API Checks

```bash
curl http://localhost:8088/health
curl http://localhost:8088/api/oem/vehicle-types
curl "http://localhost:8088/api/oem/brands?vehicle_type=motorcycle"
curl "http://localhost:8088/api/oem/years?vehicle_type=motorcycle&brand_id=<brand_id>"
curl "http://localhost:8088/api/oem/models?vehicle_type=motorcycle&brand_id=<brand_id>&year=2025"
curl "http://localhost:8088/api/oem/parts/search?q=B67-15100"
```

After pilot import, use returned ids to inspect diagrams:

```bash
curl "http://localhost:8088/api/oem/assemblies?variant_id=<variant_id>"
curl "http://localhost:8088/api/oem/diagrams/<assembly_id>"
```

## Notes

- Megazip pilot importer fetches the live sample assembly page and downloads its diagram image locally.
- ARI pilot importer currently seeds the researched sample ids/parts/hotspots and stores source price snapshots in `oem_source_price_snapshots`.
- Full ARI crawling should be added after direct endpoint research or a controlled Playwright parser.
- Diagram images are stored under `storage/oem-diagrams/`.
