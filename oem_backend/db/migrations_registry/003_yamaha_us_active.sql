-- Enable Yamaha US region in registry after PartStream US pipeline smoke (YAM+YAMMR → YMH-US).
-- Does not change YMH-JP / YMH-EU. Apply via: python -m app.cli migrate-registry
UPDATE oem_brand_roots
SET is_active = TRUE
WHERE brand_code = 'yamaha' AND root_arib = 'YMH-US';
