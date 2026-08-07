-- Yamaha: один бренд, регионы EU/JP/US на шаге 2 фронта

INSERT INTO oem_brand_roots (brand_code, root_arib, name, sort_order, is_active) VALUES
  ('yamaha', 'YMH-EU', 'Европа', 510, TRUE),
  ('yamaha', 'YMH-JP', 'Япония', 520, TRUE),
  ('yamaha', 'YMH-US', 'США', 530, FALSE)
ON CONFLICT (brand_code, root_arib) DO UPDATE SET
  name = EXCLUDED.name,
  sort_order = EXCLUDED.sort_order,
  is_active = EXCLUDED.is_active;

UPDATE oem_brand_roots SET name = 'Европа' WHERE brand_code = 'yamaha' AND root_arib = 'YMH-EU';
UPDATE oem_brand_roots SET name = 'Япония' WHERE brand_code = 'yamaha' AND root_arib = 'YMH-JP';
