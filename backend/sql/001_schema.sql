-- MySQL 8.0.16+ / MariaDB 10.4+ (phpMyAdmin)
-- Select an empty database before importing. Safe to re-run on this schema.
SET NAMES utf8mb4 COLLATE utf8mb4_bin;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  username VARCHAR(191) PRIMARY KEY,
  password_hash TEXT NOT NULL,
  password_scheme VARCHAR(64) NOT NULL DEFAULT 'sha256-legacy',
  name TEXT NOT NULL,
  role VARCHAR(64) NOT NULL DEFAULT 'user' CHECK (role IN ('user', 'superuser')),
  active boolean NOT NULL DEFAULT true,
  permissions JSON NOT NULL DEFAULT ('{}'),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS sessions (
  token VARCHAR(191) PRIMARY KEY,
  username VARCHAR(191) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  FOREIGN KEY (username) REFERENCES users(username) ON DELETE CASCADE,
  INDEX sessions_expires_idx (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS master_values (
  category VARCHAR(32) NOT NULL CHECK (category IN ('operator','produk','botol','botolpecah','apdCriteria')),
  value VARCHAR(191) NOT NULL,
  position integer NOT NULL DEFAULT 0,
  PRIMARY KEY (category, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS spk (
  batch_no VARCHAR(191) PRIMARY KEY,
  tanggal date NOT NULL,
  produk VARCHAR(191) NOT NULL,
  botol VARCHAR(191) NOT NULL,
  produksi_dus DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (produksi_dus >= 0),
  qty_per_dus DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (qty_per_dus >= 0),
  qty DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (qty >= 0),
  created_by VARCHAR(191),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3),
  update_count integer NOT NULL DEFAULT 0 CHECK (update_count >= 0),
  status VARCHAR(64) NOT NULL DEFAULT 'normal',
  FOREIGN KEY (created_by) REFERENCES users(username) ON UPDATE CASCADE,
  INDEX spk_tanggal_idx (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS entries (
  id VARCHAR(191) PRIMARY KEY,
  report_id VARCHAR(191) NOT NULL,
  tab VARCHAR(191) NOT NULL CHECK (tab IN ('filling','press')),
  tanggal date NOT NULL,
  operator VARCHAR(191) NOT NULL,
  produk VARCHAR(191) NOT NULL,
  botol VARCHAR(191) NOT NULL,
  qty_kardus DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (qty_kardus >= 0),
  qty_botol_per_kardus DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (qty_botol_per_kardus >= 0),
  total_qty DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (total_qty >= 0),
  botol_pecah_jenis TEXT NOT NULL DEFAULT (''),
  qty_botol_pecah DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (qty_botol_pecah >= 0),
  qty_kardus_basah DECIMAL(20,6) NOT NULL DEFAULT 0 CHECK (qty_kardus_basah >= 0),
  created_by VARCHAR(191),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3),
  update_count integer NOT NULL DEFAULT 0 CHECK (update_count >= 0),
  sisa_press_tanggal_asal TEXT NOT NULL DEFAULT (''),
  keterangan TEXT NOT NULL DEFAULT (''),
  FOREIGN KEY (created_by) REFERENCES users(username) ON UPDATE CASCADE,
  INDEX entries_tab_tanggal_idx (tab, tanggal),
  INDEX entries_produk_botol_idx (produk, botol),
  INDEX entries_report_id_idx (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS deleted_entry_audits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  line TEXT NOT NULL,
  tanggal date,
  operator VARCHAR(191),
  produk VARCHAR(191),
  botol VARCHAR(191),
  batch_no VARCHAR(191),
  next_update_count integer NOT NULL DEFAULT 1,
  deleted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  deleted_by TEXT,
  restored_entry_id TEXT,
  restored_at DATETIME(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS press_adjustments (
  id VARCHAR(191) PRIMARY KEY,
  tanggal date NOT NULL,
  produk VARCHAR(191) NOT NULL,
  botol VARCHAR(191) NOT NULL,
  qty_ditutup DECIMAL(20,6) NOT NULL CHECK (qty_ditutup >= 0),
  alasan TEXT NOT NULL,
  closed_by TEXT,
  closed_by_name TEXT,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  qty_botol_per_kardus DECIMAL(20,6) NOT NULL DEFAULT 0,
  target_batch_no TEXT NOT NULL DEFAULT (''),
  target_tanggal_asal TEXT NOT NULL DEFAULT (''),
  archived boolean NOT NULL DEFAULT false
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS apd_entries (
  id VARCHAR(191) PRIMARY KEY,
  tanggal date NOT NULL,
  operator VARCHAR(191) NOT NULL,
  scores JSON NOT NULL DEFAULT ('{}'),
  total_points DECIMAL(20,6) NOT NULL DEFAULT 0,
  percentage DECIMAL(20,6) NOT NULL DEFAULT 0,
  alasan TEXT NOT NULL DEFAULT (''),
  created_by VARCHAR(191),
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3),
  UNIQUE (tanggal, operator),
  FOREIGN KEY (created_by) REFERENCES users(username) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS apd_photos (
  id VARCHAR(191) PRIMARY KEY,
  apd_id VARCHAR(191),
  uploaded_by VARCHAR(191),
  mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
  data LONGBLOB NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  FOREIGN KEY (apd_id) REFERENCES apd_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(username) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS downtime_entries (
  tanggal date PRIMARY KEY,
  production_start_time time NOT NULL,
  arrival_timestamp DATETIME(3) NOT NULL,
  down_time DECIMAL(20,6) NOT NULL,
  alasan TEXT NOT NULL,
  keterangan TEXT NOT NULL DEFAULT (''),
  updated_by VARCHAR(191),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  FOREIGN KEY (updated_by) REFERENCES users(username) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(191) PRIMARY KEY,
  value JSON NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_by VARCHAR(191)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO settings(setting_key, value, updated_by) VALUES
  ('kpiFillingOutputTargetMonthly', '150000', 'setup'),
  ('kpiPressOutputTargetMonthly', '70000', 'setup')
ON DUPLICATE KEY UPDATE setting_key=setting_key;
