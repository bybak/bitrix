-- OEM Schemas Catalog — Yamaha YPEC (отдельная БД, без Remotors)

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

CREATE TABLE IF NOT EXISTS oem_catalog_roots (
  id SMALLSERIAL PRIMARY KEY,
  arib_code VARCHAR(16) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 500,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_nav_nodes (
  id BIGSERIAL PRIMARY KEY,
  root_arib VARCHAR(16) NOT NULL REFERENCES oem_catalog_roots(arib_code),
  parent_id BIGINT REFERENCES oem_nav_nodes(id) ON DELETE CASCADE,
  aria VARCHAR(512),
  slug TEXT,
  rel VARCHAR(64) NOT NULL DEFAULT '',
  title TEXT NOT NULL,
  path_json JSONB NOT NULL,
  depth INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 500,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (root_arib, path_json, rel, title)
);

CREATE INDEX IF NOT EXISTS ix_oem_nav_nodes_root_parent ON oem_nav_nodes(root_arib, parent_id);
CREATE INDEX IF NOT EXISTS ix_oem_nav_nodes_root_depth ON oem_nav_nodes(root_arib, depth);

CREATE TABLE IF NOT EXISTS oem_variants (
  id BIGSERIAL PRIMARY KEY,
  root_arib VARCHAR(16) NOT NULL REFERENCES oem_catalog_roots(arib_code),
  variant_key TEXT NOT NULL UNIQUE,
  model_name TEXT NOT NULL,
  source_designation TEXT,
  year_from SMALLINT,
  variant_section VARCHAR(64),
  browse_line TEXT,
  path_json JSONB NOT NULL,
  assembly_count INTEGER NOT NULL DEFAULT 0,
  source_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ix_oem_variants_root ON oem_variants(root_arib);
CREATE INDEX IF NOT EXISTS ix_oem_variants_source_payload ON oem_variants USING gin (source_payload);

CREATE TABLE IF NOT EXISTS oem_assemblies (
  id BIGSERIAL PRIMARY KEY,
  variant_id BIGINT NOT NULL REFERENCES oem_variants(id) ON DELETE CASCADE,
  root_arib VARCHAR(16) NOT NULL REFERENCES oem_catalog_roots(arib_code),
  assembly_key TEXT NOT NULL,
  aria VARCHAR(512),
  slug TEXT,
  title TEXT NOT NULL,
  path_json JSONB NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 500,
  source_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (variant_id, assembly_key)
);

CREATE INDEX IF NOT EXISTS ix_oem_assemblies_variant ON oem_assemblies(variant_id);
CREATE INDEX IF NOT EXISTS ix_oem_assemblies_root_key ON oem_assemblies(root_arib, assembly_key);
CREATE INDEX IF NOT EXISTS ix_oem_assemblies_source_payload ON oem_assemblies USING gin (source_payload);

CREATE TABLE IF NOT EXISTS oem_parts (
  id BIGSERIAL PRIMARY KEY,
  root_arib VARCHAR(16) NOT NULL REFERENCES oem_catalog_roots(arib_code),
  part_number VARCHAR(255) NOT NULL,
  normalized_part_number VARCHAR(255) NOT NULL,
  name TEXT,
  -- Price enrichment (additive; does not replace part_number/name)
  full_part_number VARCHAR(255),
  name_ru TEXT,
  weight_kg NUMERIC(12, 4),
  price_jpy NUMERIC(12, 2),
  price_rub NUMERIC(12, 2),
  impex_status VARCHAR(32),
  impex_checked_at TIMESTAMPTZ,
  impex_payload JSONB,
  megazip_status VARCHAR(32),
  megazip_checked_at TIMESTAMPTZ,
  megazip_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (root_arib, normalized_part_number)
);

CREATE INDEX IF NOT EXISTS ix_oem_parts_impex_status
  ON oem_parts (root_arib, impex_status);

CREATE INDEX IF NOT EXISTS ix_oem_parts_megazip_status
  ON oem_parts (root_arib, megazip_status);

CREATE TABLE IF NOT EXISTS oem_diagrams (
  id BIGSERIAL PRIMARY KEY,
  assembly_id BIGINT NOT NULL UNIQUE REFERENCES oem_assemblies(id) ON DELETE CASCADE,
  original_url TEXT,
  local_path TEXT,
  public_url TEXT,
  width INTEGER,
  height INTEGER,
  coord_width NUMERIC(12,4),
  coord_height NUMERIC(12,4),
  mime_type VARCHAR(128),
  checksum_sha256 CHAR(64),
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_assembly_parts (
  id BIGSERIAL PRIMARY KEY,
  assembly_id BIGINT NOT NULL REFERENCES oem_assemblies(id) ON DELETE CASCADE,
  part_id BIGINT NOT NULL REFERENCES oem_parts(id),
  ref VARCHAR(64),
  quantity NUMERIC(12,3),
  row_kind VARCHAR(64) NOT NULL DEFAULT 'original',
  source_row_id VARCHAR(512),
  raw_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (assembly_id, source_row_id)
);

CREATE INDEX IF NOT EXISTS ix_oem_assembly_parts_assembly ON oem_assembly_parts(assembly_id);

CREATE TABLE IF NOT EXISTS oem_diagram_hotspots (
  id BIGSERIAL PRIMARY KEY,
  diagram_id BIGINT NOT NULL REFERENCES oem_diagrams(id) ON DELETE CASCADE,
  assembly_part_id BIGINT REFERENCES oem_assembly_parts(id) ON DELETE SET NULL,
  shape VARCHAR(32) NOT NULL DEFAULT 'rect',
  raw_coords TEXT,
  x NUMERIC(12,4),
  y NUMERIC(12,4),
  width NUMERIC(12,4),
  height NUMERIC(12,4),
  ref VARCHAR(64),
  raw_payload JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ix_oem_hotspots_diagram ON oem_diagram_hotspots(diagram_id);
CREATE INDEX IF NOT EXISTS ix_oem_hotspots_assembly_part
  ON oem_diagram_hotspots(assembly_part_id)
  WHERE assembly_part_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS oem_details_pages (
  id BIGSERIAL PRIMARY KEY,
  assembly_id BIGINT NOT NULL UNIQUE REFERENCES oem_assemblies(id) ON DELETE CASCADE,
  html_path TEXT,
  html_hash CHAR(64),
  html_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  image_path TEXT,
  image_url TEXT,
  image_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  parse_status VARCHAR(32) NOT NULL DEFAULT 'pending',
  error_message TEXT,
  fetched_at TIMESTAMPTZ,
  parsed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ix_oem_details_pages_html_status ON oem_details_pages(html_status);
CREATE INDEX IF NOT EXISTS ix_oem_details_pages_parse_status ON oem_details_pages(parse_status);

CREATE TABLE IF NOT EXISTS oem_crawl_checkpoints (
  id BIGSERIAL PRIMARY KEY,
  phase VARCHAR(64) NOT NULL,
  item_key TEXT NOT NULL,
  status VARCHAR(32) NOT NULL,
  payload JSONB,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (phase, item_key)
);

INSERT INTO oem_sources (code, name, base_url, locale, default_currency, parser_type) VALUES
  (
    'yamaha_ypec',
    'Yamaha YPEC Parts Catalog',
    'https://parts.yamaha-motor.co.jp/ypec_b2c/services/html5',
    'ru',
    'JPY',
    'yamaha_ypec'
  )
ON CONFLICT (code) DO NOTHING;

INSERT INTO oem_catalog_roots (arib_code, name, sort_order) VALUES
  ('YMH-JP', 'Япония', 520),
  ('YMH-EU', 'Европа', 510),
  ('YMH-US', 'США', 530)
ON CONFLICT (arib_code) DO UPDATE SET
  name = EXCLUDED.name,
  sort_order = EXCLUDED.sort_order;
