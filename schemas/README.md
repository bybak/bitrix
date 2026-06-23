# OEM Schemas Catalog

This directory describes a separate OEM parts catalog for powersports vehicles. The catalog will combine data from:

- RE Motors part finder: `https://remotors.fi/eng/partfinder`
- Megazip: `https://www.megazip.ru/`

The goal is to build one normalized database, one backend, and one frontend for exploded OEM diagrams. Bitrix products will be linked later by OEM part number and internal product identifiers.

## First Scope

The first version includes:

- motorcycles
- ATVs
- SSV / side-by-side vehicles
- snowmobiles
- jet skis / personal watercraft
- outboard motors

Cars, generators, and stationary engines are intentionally out of scope for the first version.

## Storage Strategy

We normalize core data and keep source provenance:

- source ids and URLs are always stored
- source hierarchy nodes are stored separately from canonical entities
- diagram images are downloaded locally first
- local images can later be moved to our FTP/CDN without changing canonical data
- source prices are not trusted as selling prices
- our own prices should come from Bitrix or our own pricing import

## Source Summary

### RE Motors / ARI PartStream

RE Motors embeds ARI PartStream rather than rendering its own static catalog. The observed scripts include:

- `https://services.arinet.com/PartStream/?appKey=...`
- `https://partstream.arinet.com/Parts/Script?...`
- `https://partstream.arinet.com/ContentManager/Js?...`
- `https://cdn.datamanager.arinet.com/image/...`

Observed hierarchy:

```text
brand -> year -> source model node -> assembly -> diagram -> parts/hotspots
```

Important source attributes:

- `arib`: source brand code, for example `KTM`
- `aria`: opaque source node id
- `slug`: source path for assemblies
- `rel`: `folder` or `assembly`

### Megazip

Megazip exposes indexable URLs and HTML pages. Observed hierarchy:

```text
vehicle type -> brand -> model family -> concrete variant -> assembly -> diagram -> parts/hotspots
```

Final assembly pages include:

- diagram image in `storage.megazip.ru/catalog/...`
- image-map hotspots in `map area coords`
- `data-items-list-id` linking hotspots to part-list rows
- row-level JSON with OEM number, ref, quantity, manufacturer, tech type, variant, search URL, and price offers

## Normalization Rules

Do not deduplicate by display name alone.

Examples that require alias handling:

- `FZ10/ MTN1000`, `MTN1000`, `MT-10`, `FZ-10`
- `Husqvarna Motorcycle`, `Husqvarna`
- `Bombardier`, `BRP`, `Can-Am`, `Sea-Doo`, `Ski-Doo`
- ARI nodes like `125 SX CHASSIS - 2025` and `125 SX/XC ENGINE - 2025`

Use canonical entities for navigation, but keep every source row and source node for traceability.

## Documents

- `database.md` - ER model, table responsibilities, and normalization notes
- `oem_schema.sql` - first DDL draft
- `source-remotors-ari.md` - ARI PartStream observations
- `source-megazip.md` - Megazip observations
- `import-pipeline.md` - crawler/import/update pipeline
- `backend-api.md` - future backend API contract
- `sample-mapping.md` - sample records from both sources mapped into the schema
