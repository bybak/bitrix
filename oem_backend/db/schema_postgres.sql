CREATE TABLE IF NOT EXISTS oem_sources (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  base_url TEXT NOT NULL,
  locale VARCHAR(16),
  default_currency CHAR(3),
  parser_type VARCHAR(64) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_vehicle_types (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 500,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_brands (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL UNIQUE,
  country_code CHAR(2),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_brand_aliases (
  id BIGSERIAL PRIMARY KEY,
  brand_id BIGINT NOT NULL REFERENCES oem_brands(id),
  source_id BIGINT REFERENCES oem_sources(id),
  alias VARCHAR(255) NOT NULL,
  normalized_alias VARCHAR(255) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (source_id, normalized_alias)
);

CREATE TABLE IF NOT EXISTS oem_model_families (
  id BIGSERIAL PRIMARY KEY,
  vehicle_type_id BIGINT NOT NULL REFERENCES oem_vehicle_types(id),
  brand_id BIGINT NOT NULL REFERENCES oem_brands(id),
  name TEXT NOT NULL,
  normalized_name TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (vehicle_type_id, brand_id, normalized_name)
);

CREATE TABLE IF NOT EXISTS oem_model_aliases (
  id BIGSERIAL PRIMARY KEY,
  model_family_id BIGINT NOT NULL REFERENCES oem_model_families(id),
  source_id BIGINT REFERENCES oem_sources(id),
  alias TEXT NOT NULL,
  normalized_alias TEXT NOT NULL,
  confidence NUMERIC(5,4) NOT NULL DEFAULT 1,
  is_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (source_id, normalized_alias)
);

CREATE TABLE IF NOT EXISTS oem_vehicle_variants (
  id BIGSERIAL PRIMARY KEY,
  model_family_id BIGINT NOT NULL REFERENCES oem_model_families(id),
  year_from SMALLINT,
  year_to SMALLINT,
  model_code VARCHAR(128),
  region VARCHAR(255),
  region_code VARCHAR(64),
  color_code VARCHAR(64),
  color_name VARCHAR(255),
  engine_cc INTEGER,
  market_name TEXT,
  source_designation TEXT,
  variant_section VARCHAR(64),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_source_nodes (
  id BIGSERIAL PRIMARY KEY,
  source_id BIGINT NOT NULL REFERENCES oem_sources(id),
  parent_id BIGINT REFERENCES oem_source_nodes(id),
  node_type VARCHAR(64) NOT NULL,
  title TEXT NOT NULL,
  normalized_title TEXT,
  source_url TEXT,
  url_path TEXT,
  external_id VARCHAR(512),
  arib VARCHAR(128),
  aria VARCHAR(512),
  slug TEXT,
  raw_hash CHAR(64),
  last_seen_at TIMESTAMPTZ,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (source_id, external_id)
);

CREATE TABLE IF NOT EXISTS oem_source_node_links (
  id BIGSERIAL PRIMARY KEY,
  source_node_id BIGINT NOT NULL REFERENCES oem_source_nodes(id),
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT NOT NULL,
  confidence NUMERIC(5,4) NOT NULL DEFAULT 1,
  is_reviewed BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (source_node_id, entity_type, entity_id)
);

CREATE TABLE IF NOT EXISTS oem_assemblies (
  id BIGSERIAL PRIMARY KEY,
  vehicle_variant_id BIGINT NOT NULL REFERENCES oem_vehicle_variants(id),
  source_node_id BIGINT REFERENCES oem_source_nodes(id),
  title TEXT NOT NULL,
  normalized_title TEXT NOT NULL,
  group_code VARCHAR(128),
  sort_order INTEGER NOT NULL DEFAULT 500,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_diagrams (
  id BIGSERIAL PRIMARY KEY,
  assembly_id BIGINT NOT NULL REFERENCES oem_assemblies(id),
  source_node_id BIGINT REFERENCES oem_source_nodes(id),
  original_url TEXT,
  local_path TEXT,
  public_url TEXT,
  source_image_id VARCHAR(512),
  width INTEGER,
  height INTEGER,
  mime_type VARCHAR(128),
  checksum_sha256 CHAR(64),
  sort_order INTEGER NOT NULL DEFAULT 500,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_parts (
  id BIGSERIAL PRIMARY KEY,
  brand_id BIGINT REFERENCES oem_brands(id),
  manufacturer VARCHAR(255),
  part_number VARCHAR(255) NOT NULL,
  normalized_part_number VARCHAR(255) NOT NULL UNIQUE,
  name TEXT,
  description TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_part_aliases (
  id BIGSERIAL PRIMARY KEY,
  part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  source_id BIGINT REFERENCES oem_sources(id),
  alias_type VARCHAR(64) NOT NULL,
  value TEXT NOT NULL,
  normalized_value TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_assembly_parts (
  id BIGSERIAL PRIMARY KEY,
  assembly_id BIGINT NOT NULL REFERENCES oem_assemblies(id),
  part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  source_node_id BIGINT REFERENCES oem_source_nodes(id),
  ref VARCHAR(64),
  quantity NUMERIC(12,3),
  row_kind VARCHAR(64) NOT NULL DEFAULT 'original',
  source_row_id VARCHAR(512),
  source_items_list_id VARCHAR(512),
  notes TEXT,
  raw_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_diagram_hotspots (
  id BIGSERIAL PRIMARY KEY,
  diagram_id BIGINT NOT NULL REFERENCES oem_diagrams(id),
  assembly_part_id BIGINT REFERENCES oem_assembly_parts(id),
  shape VARCHAR(32) NOT NULL DEFAULT 'rect',
  raw_coords TEXT,
  x NUMERIC(12,4),
  y NUMERIC(12,4),
  width NUMERIC(12,4),
  height NUMERIC(12,4),
  polygon_json JSONB,
  ref VARCHAR(64),
  source_items_list_id VARCHAR(512),
  raw_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_part_relations (
  id BIGSERIAL PRIMARY KEY,
  source_part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  target_part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  relation_type VARCHAR(64) NOT NULL,
  source_id BIGINT REFERENCES oem_sources(id),
  source_row_id VARCHAR(512),
  raw_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (source_part_id, target_part_id, relation_type)
);

CREATE TABLE IF NOT EXISTS oem_part_bitrix_links (
  id BIGSERIAL PRIMARY KEY,
  part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  bitrix_product_id BIGINT NOT NULL,
  iblock_id BIGINT,
  xml_id VARCHAR(255),
  product_url TEXT,
  match_type VARCHAR(64) NOT NULL,
  confidence NUMERIC(5,4) NOT NULL DEFAULT 1,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (part_id, bitrix_product_id)
);

CREATE TABLE IF NOT EXISTS oem_part_offers (
  id BIGSERIAL PRIMARY KEY,
  part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  bitrix_product_id BIGINT,
  price NUMERIC(14,2),
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  availability VARCHAR(64),
  stock_qty NUMERIC(12,3),
  supplier VARCHAR(255),
  offer_source VARCHAR(64) NOT NULL DEFAULT 'bitrix',
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_source_price_snapshots (
  id BIGSERIAL PRIMARY KEY,
  source_id BIGINT NOT NULL REFERENCES oem_sources(id),
  part_id BIGINT REFERENCES oem_parts(id),
  assembly_part_id BIGINT REFERENCES oem_assembly_parts(id),
  source_price_id VARCHAR(512),
  price NUMERIC(14,2),
  base_price NUMERIC(14,2),
  currency CHAR(3),
  shipper VARCHAR(255),
  min_qty NUMERIC(12,3),
  handling VARCHAR(255),
  raw_payload JSONB,
  captured_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_raw_snapshots (
  id BIGSERIAL PRIMARY KEY,
  source_id BIGINT NOT NULL REFERENCES oem_sources(id),
  source_node_id BIGINT REFERENCES oem_source_nodes(id),
  source_url TEXT,
  content_type VARCHAR(255),
  content_hash CHAR(64) NOT NULL,
  parser_version VARCHAR(64),
  storage_path TEXT,
  raw_payload TEXT,
  captured_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (source_id, content_hash)
);

CREATE INDEX IF NOT EXISTS ix_oem_source_nodes_parent ON oem_source_nodes(parent_id);
CREATE INDEX IF NOT EXISTS ix_oem_source_nodes_url_path ON oem_source_nodes(source_id, url_path);
CREATE INDEX IF NOT EXISTS ix_oem_source_nodes_ari ON oem_source_nodes(source_id, arib, aria);
CREATE INDEX IF NOT EXISTS ix_oem_vehicle_variants_model_year ON oem_vehicle_variants(model_family_id, year_from, year_to);
CREATE INDEX IF NOT EXISTS ix_oem_assemblies_variant ON oem_assemblies(vehicle_variant_id);
CREATE INDEX IF NOT EXISTS ix_oem_assemblies_source_node ON oem_assemblies(source_node_id);
CREATE INDEX IF NOT EXISTS ix_oem_diagrams_assembly ON oem_diagrams(assembly_id);
CREATE INDEX IF NOT EXISTS ix_oem_diagrams_source_node ON oem_diagrams(source_node_id);
CREATE INDEX IF NOT EXISTS ix_oem_assembly_parts_assembly_ref ON oem_assembly_parts(assembly_id, ref);
CREATE INDEX IF NOT EXISTS ix_oem_assembly_parts_source_node ON oem_assembly_parts(source_node_id);
CREATE INDEX IF NOT EXISTS ix_oem_assembly_parts_items_list ON oem_assembly_parts(source_items_list_id);
CREATE INDEX IF NOT EXISTS ix_oem_hotspots_diagram ON oem_diagram_hotspots(diagram_id);
CREATE INDEX IF NOT EXISTS ix_oem_hotspots_assembly_part ON oem_diagram_hotspots(assembly_part_id);
CREATE INDEX IF NOT EXISTS ix_oem_hotspots_items_list ON oem_diagram_hotspots(source_items_list_id);
CREATE INDEX IF NOT EXISTS ix_oem_parts_normalized ON oem_parts(normalized_part_number);
CREATE INDEX IF NOT EXISTS ix_oem_price_snapshots_assembly_part ON oem_source_price_snapshots(assembly_part_id);
CREATE INDEX IF NOT EXISTS ix_oem_raw_snapshots_source_node ON oem_raw_snapshots(source_node_id);

INSERT INTO oem_vehicle_types (code, name, sort_order) VALUES
  ('motorcycle', 'Motorcycle', 100),
  ('atv', 'ATV', 200),
  ('ssv', 'SSV / Side-by-side', 210),
  ('snowmobile', 'Snowmobile', 300),
  ('jetski', 'Jet ski / PWC', 400),
  ('outboard', 'Outboard motor', 500)
ON CONFLICT (code) DO NOTHING;

INSERT INTO oem_sources (code, name, base_url, locale, default_currency, parser_type) VALUES
  ('remotors_ari', 'RE Motors ARI PartStream', 'https://remotors.fi/eng/partfinder', 'en', 'EUR', 'ari_partstream'),
  ('megazip', 'Megazip', 'https://www.megazip.ru/', 'ru', 'RUB', 'megazip_html')
ON CONFLICT (code) DO NOTHING;
