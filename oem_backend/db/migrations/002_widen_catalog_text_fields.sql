-- Widen catalog fields for Remotors mega-designations (Can-Am Outlander SKU lists).
ALTER TABLE oem_model_families ALTER COLUMN name TYPE TEXT;
ALTER TABLE oem_model_families ALTER COLUMN normalized_name TYPE TEXT;
ALTER TABLE oem_model_aliases ALTER COLUMN alias TYPE TEXT;
ALTER TABLE oem_model_aliases ALTER COLUMN normalized_alias TYPE TEXT;
ALTER TABLE oem_vehicle_variants ALTER COLUMN market_name TYPE TEXT;
ALTER TABLE oem_vehicle_variants ALTER COLUMN source_designation TYPE TEXT;
