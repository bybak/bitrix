INSERT INTO oem_catalog_roots (arib_code, name, sort_order)
VALUES ('YMH-US', 'Yamaha · США', 530)
ON CONFLICT (arib_code) DO NOTHING;
