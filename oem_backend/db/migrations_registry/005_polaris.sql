-- Polaris catalog DB + brand root POL only (POL_CDN is the same tree).
-- Apply via: python -m app.cli migrate-registry

INSERT INTO oem_catalog_databases (code, name, connection_dsn, parser_type) VALUES
  (
    'polaris',
    'Polaris PartStream',
    'postgresql://polaris_user:polaris_password@polaris_db:5432/polaris_catalog',
    'ari_partstream'
  )
ON CONFLICT (code) DO UPDATE SET
  name = EXCLUDED.name,
  connection_dsn = EXCLUDED.connection_dsn,
  parser_type = EXCLUDED.parser_type,
  updated_at = now();

INSERT INTO oem_brands (code, name, catalog_db_code, sort_order, is_active) VALUES
  ('polaris', 'Polaris', 'polaris', 700, TRUE)
ON CONFLICT (code) DO UPDATE SET
  name = EXCLUDED.name,
  catalog_db_code = EXCLUDED.catalog_db_code,
  sort_order = EXCLUDED.sort_order,
  is_active = EXCLUDED.is_active,
  updated_at = now();

INSERT INTO oem_brand_roots (brand_code, root_arib, name, sort_order, is_active) VALUES
  ('polaris', 'POL', 'Polaris', 700, TRUE)
ON CONFLICT (brand_code, root_arib) DO UPDATE SET
  name = EXCLUDED.name,
  sort_order = EXCLUDED.sort_order,
  is_active = EXCLUDED.is_active;

DELETE FROM oem_brand_roots
WHERE brand_code = 'polaris' AND root_arib = 'POL_CDN';
