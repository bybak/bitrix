-- Arctic Cat catalog DB + brand root ARC only.
-- Apply via: python -m app.cli migrate-registry

INSERT INTO oem_catalog_databases (code, name, connection_dsn, parser_type) VALUES
  (
    'arctic',
    'Arctic Cat PartStream',
    'postgresql://arctic_user:arctic_password@arctic_db:5432/arctic_catalog',
    'ari_partstream'
  )
ON CONFLICT (code) DO UPDATE SET
  name = EXCLUDED.name,
  connection_dsn = EXCLUDED.connection_dsn,
  parser_type = EXCLUDED.parser_type,
  updated_at = now();

INSERT INTO oem_brands (code, name, catalog_db_code, sort_order, is_active) VALUES
  ('arctic_cat', 'Arctic Cat', 'arctic', 600, TRUE)
ON CONFLICT (code) DO UPDATE SET
  name = EXCLUDED.name,
  catalog_db_code = EXCLUDED.catalog_db_code,
  sort_order = EXCLUDED.sort_order,
  is_active = EXCLUDED.is_active,
  updated_at = now();

INSERT INTO oem_brand_roots (brand_code, root_arib, name, sort_order, is_active) VALUES
  ('arctic_cat', 'ARC', 'Arctic Cat', 600, TRUE)
ON CONFLICT (brand_code, root_arib) DO UPDATE SET
  name = EXCLUDED.name,
  sort_order = EXCLUDED.sort_order,
  is_active = EXCLUDED.is_active;

-- If an older draft registered ARC_CDN, drop it.
DELETE FROM oem_brand_roots
WHERE brand_code = 'arctic_cat' AND root_arib = 'ARC_CDN';
