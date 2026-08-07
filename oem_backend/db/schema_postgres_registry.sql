-- OEM Registry — маршрутизация брендов к каталожным базам (не содержит OEM-данных)

CREATE TABLE IF NOT EXISTS oem_catalog_databases (
  code VARCHAR(64) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  connection_dsn TEXT NOT NULL,
  parser_type VARCHAR(64) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS oem_brands (
  code VARCHAR(64) PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  catalog_db_code VARCHAR(64) NOT NULL REFERENCES oem_catalog_databases(code),
  sort_order INTEGER NOT NULL DEFAULT 500,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ix_oem_brands_catalog_db ON oem_brands(catalog_db_code);

CREATE TABLE IF NOT EXISTS oem_brand_roots (
  brand_code VARCHAR(64) NOT NULL REFERENCES oem_brands(code) ON DELETE CASCADE,
  root_arib VARCHAR(16) NOT NULL,
  name VARCHAR(255) NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 500,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (brand_code, root_arib)
);

CREATE INDEX IF NOT EXISTS ix_oem_brand_roots_root ON oem_brand_roots(root_arib);

-- Физические каталожные базы
INSERT INTO oem_catalog_databases (code, name, connection_dsn, parser_type) VALUES
  (
    'remotors',
    'Remotors ARI',
    'postgresql://oem_user:oem_password@oem_db:5432/oem_catalog',
    'ari_partstream'
  ),
  (
    'yamaha',
    'Yamaha YPEC',
    'postgresql://yamaha_user:yamaha_password@yamaha_db:5432/yamaha_catalog',
    'yamaha_ypec'
  )
ON CONFLICT (code) DO UPDATE SET
  name = EXCLUDED.name,
  connection_dsn = EXCLUDED.connection_dsn,
  parser_type = EXCLUDED.parser_type,
  updated_at = now();

-- Бренды (шаг 1 на фронте)
INSERT INTO oem_brands (code, name, catalog_db_code, sort_order) VALUES
  ('husqvarna', 'Husqvarna', 'remotors', 100),
  ('ktm', 'KTM', 'remotors', 200),
  ('lynx', 'Lynx', 'remotors', 300),
  ('brp', 'BRP', 'remotors', 400),
  ('yamaha', 'Yamaha', 'yamaha', 500)
ON CONFLICT (code) DO UPDATE SET
  name = EXCLUDED.name,
  catalog_db_code = EXCLUDED.catalog_db_code,
  sort_order = EXCLUDED.sort_order,
  updated_at = now();

-- Корни каталога под каждым брендом
-- Remotors: один корень = бренд; Yamaha: шаг 2 — регионы внутри одного бренда
INSERT INTO oem_brand_roots (brand_code, root_arib, name, sort_order, is_active) VALUES
  ('husqvarna', 'HUM', 'Husqvarna', 100, TRUE),
  ('ktm', 'KTM', 'KTM', 200, TRUE),
  ('lynx', 'LNX', 'Lynx', 300, TRUE),
  ('brp', 'BRP', 'BRP', 400, TRUE),
  ('yamaha', 'YMH-EU', 'Европа', 510, TRUE),
  ('yamaha', 'YMH-JP', 'Япония', 520, TRUE),
  ('yamaha', 'YMH-US', 'США', 530, FALSE)
ON CONFLICT (brand_code, root_arib) DO UPDATE SET
  name = EXCLUDED.name,
  sort_order = EXCLUDED.sort_order,
  is_active = EXCLUDED.is_active;
