# OEM Backend (Remotors v3)

Separate backend for the OEM Schemas Catalog — 1:1 tree with [remotors.fi](https://remotors.fi/eng/partfinder).

Four top-level roots: **Husqvarna (HUM)**, **KTM**, **Lynx (LNX)**, **BRP**.

## Services

- `oem_db` — PostgreSQL 16, database `oem_catalog`
- `oem_backend` — FastAPI on `http://localhost:8088`

```bash
docker compose up -d oem_db oem_backend
```

Schema: `oem_backend/db/schema_postgres.sql` (applied only on **new** `oem_pg_data` volume).

## Full rebuild (v3 pipeline)

All long steps print progress every **5 seconds**, use **bulk SQL**, and **resume** after stop.

```bash
chmod +x scripts/oem-remotors-v3-wipe.sh scripts/oem-remotors-v3-pipeline.sh

# Fresh postgres + wipe storage
bash scripts/oem-remotors-v3-pipeline.sh wipe

# Phases (run in tmux for full crawl — takes days)
bash scripts/oem-remotors-v3-pipeline.sh snapshot
bash scripts/oem-remotors-v3-pipeline.sh snapshot-resume   # if interrupted
bash scripts/oem-remotors-v3-pipeline.sh import-structure
bash scripts/oem-remotors-v3-pipeline.sh crawl-html
bash scripts/oem-remotors-v3-pipeline.sh crawl-images
bash scripts/oem-remotors-v3-pipeline.sh parse
bash scripts/oem-remotors-v3-pipeline.sh verify
```

Test run with limits:

```bash
LIMIT_ASSEMBLIES=50 bash scripts/oem-remotors-v3-pipeline.sh snapshot
LIMIT=10 bash scripts/oem-remotors-v3-pipeline.sh crawl-html
```

Environment:

| Variable | Purpose |
|----------|---------|
| `SNAPSHOT` | default `storage/remotors-snapshot-v3.db` |
| `SIDECAR` | default `storage/remotors-details-crawl.db` |
| `LIMIT_ASSEMBLIES` | cap snapshot scan |
| `LIMIT` | cap html/images/parse batch |

## CLI commands

```bash
docker compose exec -T oem_backend python -m app.cli snapshot-remotors-v3 --resume
docker compose exec -T oem_backend python -m app.cli import-remotors-structure
docker compose exec -T oem_backend python -m app.cli seed-remotors-crawl
docker compose exec -T oem_backend python -m app.cli crawl-remotors-details --phase html
docker compose exec -T oem_backend python -m app.cli crawl-remotors-details --phase images
docker compose exec -T oem_backend python -m app.cli parse-remotors-details
docker compose exec -T oem_backend python -m app.cli verify-remotors-v3
```

## API (v3)

- `GET /api/oem/roots`
- `GET /api/oem/nav?root=KTM&parent_id=`
- `GET /api/oem/variants?nav_node_id=`
- `GET /api/oem/assemblies?variant_id=`
- `GET /api/oem/diagrams/{assembly_id}`
- `GET /api/oem/parts/search?q=`

## Storage layout

```text
storage/remotors-snapshot-v3.db      # structure snapshot (SQLite)
storage/remotors-details-crawl.db    # crawl/parse checkpoints
storage/remotors-html/{root}/        # archived GetDetails HTML
storage/oem-diagrams/remotors/{root}/ # diagram PNGs
```

## Yamaha USA (PartStream → YMH-US)

USA catalog uses ARI PartStream demo (not yamaha-motor.com):

| UI nav (under США) | Brand | Portal |
|--------------------|-------|--------|
| Yamaha PowerSport | `YAM` | streamsdemo Power |
| Yamaha Marine | `YAMMR` | streamsdemo Marine |

Single registry root: **YMH-US** («США»). JP (`YMH-JP`) / EU (`YMH-EU`) pipelines are unchanged.

```bash
chmod +x scripts/oem-yamaha-ps-pipeline.sh

# Wipe only YMH-US (PG + local assets); then full pipeline
bash scripts/oem-yamaha-ps-pipeline.sh wipe
bash scripts/oem-yamaha-ps-pipeline.sh snapshot --brand all
# or per brand: --brand YAM then snapshot-resume --brand YAMMR
bash scripts/oem-yamaha-ps-pipeline.sh import
bash scripts/oem-yamaha-ps-pipeline.sh crawl-html
bash scripts/oem-yamaha-ps-pipeline.sh crawl-images
bash scripts/oem-yamaha-ps-pipeline.sh parse
```

Activate USA in registry (once, after smoke):

```bash
docker compose exec -T oem_backend python -m app.cli migrate-registry
# includes migrations_registry/003_yamaha_us_active.sql → YMH-US is_active=TRUE
```

Deprecated: `scripts/oem-yamaha-us-pipeline.sh` and `*-yamaha-us*` CLI (yamaha-motor.com) redirect / point to PartStream.
