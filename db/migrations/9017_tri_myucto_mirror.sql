-- TRIANT: zrcadlo MyÚčta (klienti/projekty/faktury/číselníky) + sync stav.
-- PK zrcadel = MyÚčto id (žádné AUTO_INCREMENT, žádné FK na core tabulky).

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS myucto_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS myucto_updated_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS mu_synced_at DATETIME NULL;

ALTER TABLE clients
  ADD UNIQUE INDEX IF NOT EXISTS uq_clients_myucto_id (myucto_id);

ALTER TABLE tri_jobs
  ADD COLUMN IF NOT EXISTS myucto_project_id BIGINT UNSIGNED NULL;

ALTER TABLE tri_jobs
  ADD UNIQUE INDEX IF NOT EXISTS uq_tri_jobs_myucto_project (myucto_project_id);

CREATE TABLE IF NOT EXISTS mu_invoices (
  id                     BIGINT UNSIGNED NOT NULL,
  client_myucto_id       BIGINT UNSIGNED NULL,
  project_id             BIGINT UNSIGNED NULL,
  varsymbol              VARCHAR(40) NULL,
  invoice_type           VARCHAR(32) NOT NULL DEFAULT 'invoice',
  status                 VARCHAR(32) NOT NULL DEFAULT 'draft',
  payment_status         VARCHAR(32) NULL,
  issue_date             DATE NULL,
  due_date               DATE NULL,
  tax_date               DATE NULL,
  currency               CHAR(3) NOT NULL DEFAULT 'CZK',
  total_without_vat      DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_vat              DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_with_vat         DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_to_pay          DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_total             DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_at                DATETIME NULL,
  supplier_order_number  VARCHAR(64) NULL,
  sent_at                DATETIME NULL,
  reminder_count         INT UNSIGNED NOT NULL DEFAULT 0,
  last_reminder_at       DATETIME NULL,
  public_token           VARCHAR(64) NULL,
  client_main_email      VARCHAR(190) NULL,
  branding_profile_id    BIGINT UNSIGNED NULL,
  items_json             LONGTEXT NULL,
  raw_json               LONGTEXT NULL,
  updated_at             DATETIME NULL,
  mu_synced_at           DATETIME NULL,
  deleted_at             DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_mu_inv_client (client_myucto_id),
  KEY idx_mu_inv_project (project_id),
  KEY idx_mu_inv_status (status, issue_date),
  KEY idx_mu_inv_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_projects (
  id                BIGINT UNSIGNED NOT NULL,
  client_myucto_id  BIGINT UNSIGNED NULL,
  name              VARCHAR(190) NOT NULL,
  project_number    VARCHAR(64) NULL,
  status            VARCHAR(32) NOT NULL DEFAULT 'active',
  updated_at        DATETIME NULL,
  raw_json          LONGTEXT NULL,
  mu_synced_at      DATETIME NULL,
  deleted_at        DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_mu_proj_client (client_myucto_id),
  KEY idx_mu_proj_number (project_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_vat_rates (
  id            BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(32) NULL,
  name          VARCHAR(120) NULL,
  rate          DECIMAL(6,3) NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  raw_json      LONGTEXT NULL,
  mu_synced_at  DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_currencies (
  id            BIGINT UNSIGNED NOT NULL,
  code          CHAR(3) NOT NULL,
  name          VARCHAR(120) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  raw_json      LONGTEXT NULL,
  mu_synced_at  DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mu_currencies_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_units (
  id            BIGINT UNSIGNED NOT NULL,
  code          VARCHAR(32) NULL,
  name          VARCHAR(120) NULL,
  raw_json      LONGTEXT NULL,
  mu_synced_at  DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_branding_profiles (
  id            BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(190) NULL,
  is_default    TINYINT(1) NOT NULL DEFAULT 0,
  raw_json      LONGTEXT NULL,
  mu_synced_at  DATETIME NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_sync_state (
  `key`         VARCHAR(64) NOT NULL,
  last_run_at   DATETIME NULL,
  last_ok_at    DATETIME NULL,
  sync_cursor   VARCHAR(190) NULL,
  last_error    TEXT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_sync_log (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind          VARCHAR(32) NOT NULL,
  direction     VARCHAR(16) NOT NULL DEFAULT 'pull',
  myucto_id     BIGINT UNSIGNED NULL,
  http_status   SMALLINT UNSIGNED NULL,
  message       VARCHAR(500) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mu_sync_log_created (created_at),
  KEY idx_mu_sync_log_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mu_commands (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind          VARCHAR(64) NOT NULL,
  payload_json  LONGTEXT NOT NULL,
  status        ENUM('pending','done','unknown','failed') NOT NULL DEFAULT 'pending',
  myucto_id     BIGINT UNSIGNED NULL,
  error         TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL,
  KEY idx_mu_commands_status (status, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
