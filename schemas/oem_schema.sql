-- OEM Schemas Catalog DDL draft.
-- Target: MySQL 8.0 / MariaDB-compatible style.
-- Adjust engine, charset, and JSON support to the final production DB.

CREATE TABLE oem_sources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  base_url VARCHAR(1024) NOT NULL,
  locale VARCHAR(16) NULL,
  default_currency CHAR(3) NULL,
  parser_type VARCHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_sources_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_vehicle_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 500,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_vehicle_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_brands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  country_code CHAR(2) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_brands_normalized_name (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_brand_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  alias VARCHAR(255) NOT NULL,
  normalized_alias VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_brand_aliases (source_id, normalized_alias),
  KEY ix_oem_brand_aliases_brand_id (brand_id),
  CONSTRAINT fk_oem_brand_aliases_brand_id FOREIGN KEY (brand_id) REFERENCES oem_brands(id),
  CONSTRAINT fk_oem_brand_aliases_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_model_families (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_type_id BIGINT UNSIGNED NOT NULL,
  brand_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_model_families (vehicle_type_id, brand_id, normalized_name),
  KEY ix_oem_model_families_brand (brand_id),
  CONSTRAINT fk_oem_model_families_vehicle_type_id FOREIGN KEY (vehicle_type_id) REFERENCES oem_vehicle_types(id),
  CONSTRAINT fk_oem_model_families_brand_id FOREIGN KEY (brand_id) REFERENCES oem_brands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_model_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_family_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  alias VARCHAR(255) NOT NULL,
  normalized_alias VARCHAR(255) NOT NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
  is_reviewed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_model_aliases (source_id, normalized_alias),
  KEY ix_oem_model_aliases_model_family_id (model_family_id),
  CONSTRAINT fk_oem_model_aliases_model_family_id FOREIGN KEY (model_family_id) REFERENCES oem_model_families(id),
  CONSTRAINT fk_oem_model_aliases_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_vehicle_variants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  model_family_id BIGINT UNSIGNED NOT NULL,
  year_from SMALLINT NULL,
  year_to SMALLINT NULL,
  model_code VARCHAR(128) NULL,
  region VARCHAR(255) NULL,
  region_code VARCHAR(64) NULL,
  color_code VARCHAR(64) NULL,
  color_name VARCHAR(255) NULL,
  engine_cc INT NULL,
  market_name VARCHAR(255) NULL,
  source_designation VARCHAR(255) NULL,
  variant_section VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_vehicle_variants_model_year (model_family_id, year_from, year_to),
  KEY ix_oem_vehicle_variants_model_code (model_code),
  CONSTRAINT fk_oem_vehicle_variants_model_family_id FOREIGN KEY (model_family_id) REFERENCES oem_model_families(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_source_nodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  node_type VARCHAR(64) NOT NULL,
  title VARCHAR(1024) NOT NULL,
  normalized_title VARCHAR(1024) NULL,
  source_url VARCHAR(2048) NULL,
  url_path VARCHAR(2048) NULL,
  external_id VARCHAR(512) NULL,
  arib VARCHAR(128) NULL,
  aria VARCHAR(512) NULL,
  slug VARCHAR(2048) NULL,
  raw_hash CHAR(64) NULL,
  last_seen_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_source_nodes_external (source_id, external_id),
  KEY ix_oem_source_nodes_parent (parent_id),
  KEY ix_oem_source_nodes_url_path (source_id, url_path(255)),
  KEY ix_oem_source_nodes_ari (source_id, arib, aria(255)),
  CONSTRAINT fk_oem_source_nodes_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id),
  CONSTRAINT fk_oem_source_nodes_parent_id FOREIGN KEY (parent_id) REFERENCES oem_source_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_source_node_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_node_id BIGINT UNSIGNED NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
  is_reviewed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_source_node_links (source_node_id, entity_type, entity_id),
  KEY ix_oem_source_node_links_entity (entity_type, entity_id),
  CONSTRAINT fk_oem_source_node_links_source_node_id FOREIGN KEY (source_node_id) REFERENCES oem_source_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_assemblies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vehicle_variant_id BIGINT UNSIGNED NOT NULL,
  source_node_id BIGINT UNSIGNED NULL,
  title VARCHAR(1024) NOT NULL,
  normalized_title VARCHAR(1024) NOT NULL,
  group_code VARCHAR(128) NULL,
  sort_order INT NOT NULL DEFAULT 500,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_assemblies_variant_title (vehicle_variant_id, normalized_title(255)),
  KEY ix_oem_assemblies_source_node_id (source_node_id),
  CONSTRAINT fk_oem_assemblies_vehicle_variant_id FOREIGN KEY (vehicle_variant_id) REFERENCES oem_vehicle_variants(id),
  CONSTRAINT fk_oem_assemblies_source_node_id FOREIGN KEY (source_node_id) REFERENCES oem_source_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_diagrams (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assembly_id BIGINT UNSIGNED NOT NULL,
  source_node_id BIGINT UNSIGNED NULL,
  original_url VARCHAR(2048) NULL,
  local_path VARCHAR(2048) NULL,
  public_url VARCHAR(2048) NULL,
  source_image_id VARCHAR(512) NULL,
  width INT NULL,
  height INT NULL,
  mime_type VARCHAR(128) NULL,
  checksum_sha256 CHAR(64) NULL,
  sort_order INT NOT NULL DEFAULT 500,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_diagrams_assembly_id (assembly_id),
  KEY ix_oem_diagrams_checksum (checksum_sha256),
  CONSTRAINT fk_oem_diagrams_assembly_id FOREIGN KEY (assembly_id) REFERENCES oem_assemblies(id),
  CONSTRAINT fk_oem_diagrams_source_node_id FOREIGN KEY (source_node_id) REFERENCES oem_source_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  brand_id BIGINT UNSIGNED NULL,
  manufacturer VARCHAR(255) NULL,
  part_number VARCHAR(255) NOT NULL,
  normalized_part_number VARCHAR(255) NOT NULL,
  name VARCHAR(1024) NULL,
  description TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_parts_normalized (normalized_part_number),
  KEY ix_oem_parts_brand_id (brand_id),
  CONSTRAINT fk_oem_parts_brand_id FOREIGN KEY (brand_id) REFERENCES oem_brands(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_part_aliases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  part_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  alias_type VARCHAR(64) NOT NULL,
  value VARCHAR(1024) NOT NULL,
  normalized_value VARCHAR(1024) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_part_aliases_part_id (part_id),
  KEY ix_oem_part_aliases_normalized (normalized_value(255)),
  CONSTRAINT fk_oem_part_aliases_part_id FOREIGN KEY (part_id) REFERENCES oem_parts(id),
  CONSTRAINT fk_oem_part_aliases_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_assembly_parts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assembly_id BIGINT UNSIGNED NOT NULL,
  part_id BIGINT UNSIGNED NOT NULL,
  source_node_id BIGINT UNSIGNED NULL,
  ref VARCHAR(64) NULL,
  quantity DECIMAL(12,3) NULL,
  row_kind VARCHAR(64) NOT NULL DEFAULT 'original',
  source_row_id VARCHAR(512) NULL,
  source_items_list_id VARCHAR(512) NULL,
  notes TEXT NULL,
  raw_payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_assembly_parts_assembly_ref (assembly_id, ref),
  KEY ix_oem_assembly_parts_part_id (part_id),
  KEY ix_oem_assembly_parts_items_list (source_items_list_id),
  CONSTRAINT fk_oem_assembly_parts_assembly_id FOREIGN KEY (assembly_id) REFERENCES oem_assemblies(id),
  CONSTRAINT fk_oem_assembly_parts_part_id FOREIGN KEY (part_id) REFERENCES oem_parts(id),
  CONSTRAINT fk_oem_assembly_parts_source_node_id FOREIGN KEY (source_node_id) REFERENCES oem_source_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_diagram_hotspots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  diagram_id BIGINT UNSIGNED NOT NULL,
  assembly_part_id BIGINT UNSIGNED NULL,
  shape VARCHAR(32) NOT NULL DEFAULT 'rect',
  raw_coords VARCHAR(2048) NULL,
  x DECIMAL(12,4) NULL,
  y DECIMAL(12,4) NULL,
  width DECIMAL(12,4) NULL,
  height DECIMAL(12,4) NULL,
  polygon_json JSON NULL,
  ref VARCHAR(64) NULL,
  source_items_list_id VARCHAR(512) NULL,
  raw_payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_diagram_hotspots_diagram_id (diagram_id),
  KEY ix_oem_diagram_hotspots_assembly_part_id (assembly_part_id),
  KEY ix_oem_diagram_hotspots_items_list (source_items_list_id),
  CONSTRAINT fk_oem_diagram_hotspots_diagram_id FOREIGN KEY (diagram_id) REFERENCES oem_diagrams(id),
  CONSTRAINT fk_oem_diagram_hotspots_assembly_part_id FOREIGN KEY (assembly_part_id) REFERENCES oem_assembly_parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_part_relations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_part_id BIGINT UNSIGNED NOT NULL,
  target_part_id BIGINT UNSIGNED NOT NULL,
  relation_type VARCHAR(64) NOT NULL,
  source_id BIGINT UNSIGNED NULL,
  source_row_id VARCHAR(512) NULL,
  raw_payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_part_relations (source_part_id, target_part_id, relation_type),
  KEY ix_oem_part_relations_target (target_part_id),
  CONSTRAINT fk_oem_part_relations_source_part_id FOREIGN KEY (source_part_id) REFERENCES oem_parts(id),
  CONSTRAINT fk_oem_part_relations_target_part_id FOREIGN KEY (target_part_id) REFERENCES oem_parts(id),
  CONSTRAINT fk_oem_part_relations_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_part_bitrix_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  part_id BIGINT UNSIGNED NOT NULL,
  bitrix_product_id BIGINT UNSIGNED NOT NULL,
  iblock_id BIGINT UNSIGNED NULL,
  xml_id VARCHAR(255) NULL,
  product_url VARCHAR(2048) NULL,
  match_type VARCHAR(64) NOT NULL,
  confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_part_bitrix_links (part_id, bitrix_product_id),
  KEY ix_oem_part_bitrix_links_product (bitrix_product_id),
  CONSTRAINT fk_oem_part_bitrix_links_part_id FOREIGN KEY (part_id) REFERENCES oem_parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_part_offers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  part_id BIGINT UNSIGNED NOT NULL,
  bitrix_product_id BIGINT UNSIGNED NULL,
  price DECIMAL(14,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'RUB',
  availability VARCHAR(64) NULL,
  stock_qty DECIMAL(12,3) NULL,
  supplier VARCHAR(255) NULL,
  offer_source VARCHAR(64) NOT NULL DEFAULT 'bitrix',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_part_offers_part_id (part_id),
  KEY ix_oem_part_offers_product (bitrix_product_id),
  CONSTRAINT fk_oem_part_offers_part_id FOREIGN KEY (part_id) REFERENCES oem_parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_source_price_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id BIGINT UNSIGNED NOT NULL,
  part_id BIGINT UNSIGNED NULL,
  assembly_part_id BIGINT UNSIGNED NULL,
  source_price_id VARCHAR(512) NULL,
  price DECIMAL(14,2) NULL,
  base_price DECIMAL(14,2) NULL,
  currency CHAR(3) NULL,
  shipper VARCHAR(255) NULL,
  min_qty DECIMAL(12,3) NULL,
  handling VARCHAR(255) NULL,
  raw_payload JSON NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_oem_source_price_snapshots_source (source_id),
  KEY ix_oem_source_price_snapshots_part (part_id),
  CONSTRAINT fk_oem_source_price_snapshots_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id),
  CONSTRAINT fk_oem_source_price_snapshots_part_id FOREIGN KEY (part_id) REFERENCES oem_parts(id),
  CONSTRAINT fk_oem_source_price_snapshots_assembly_part_id FOREIGN KEY (assembly_part_id) REFERENCES oem_assembly_parts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oem_raw_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id BIGINT UNSIGNED NOT NULL,
  source_node_id BIGINT UNSIGNED NULL,
  source_url VARCHAR(2048) NULL,
  content_type VARCHAR(255) NULL,
  content_hash CHAR(64) NOT NULL,
  parser_version VARCHAR(64) NULL,
  storage_path VARCHAR(2048) NULL,
  raw_payload LONGTEXT NULL,
  captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_oem_raw_snapshots_hash (source_id, content_hash),
  KEY ix_oem_raw_snapshots_node (source_node_id),
  CONSTRAINT fk_oem_raw_snapshots_source_id FOREIGN KEY (source_id) REFERENCES oem_sources(id),
  CONSTRAINT fk_oem_raw_snapshots_source_node_id FOREIGN KEY (source_node_id) REFERENCES oem_source_nodes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO oem_vehicle_types (code, name, sort_order) VALUES
  ('motorcycle', 'Motorcycle', 100),
  ('atv', 'ATV', 200),
  ('ssv', 'SSV / Side-by-side', 210),
  ('snowmobile', 'Snowmobile', 300),
  ('jetski', 'Jet ski / PWC', 400),
  ('outboard', 'Outboard motor', 500);

INSERT INTO oem_sources (code, name, base_url, locale, default_currency, parser_type) VALUES
  ('remotors_ari', 'RE Motors ARI PartStream', 'https://remotors.fi/eng/partfinder', 'en', 'EUR', 'ari_partstream'),
  ('megazip', 'Megazip', 'https://www.megazip.ru/', 'ru', 'RUB', 'megazip_html');
