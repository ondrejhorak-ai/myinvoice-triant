-- TRIANT: Zakázky TRI (jobs)

CREATE TABLE IF NOT EXISTS tri_number_seq (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  scope_key    VARCHAR(32) NOT NULL,
  last_value   INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_number_seq (supplier_id, scope_key),
  CONSTRAINT fk_tri_ns_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_jobs (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         INT UNSIGNED NOT NULL,
  number              VARCHAR(16) NOT NULL,
  title               VARCHAR(190) NOT NULL,
  owner_user_id       BIGINT UNSIGNED NOT NULL,
  status              ENUM('draft','active','on_hold','completed','cancelled') NOT NULL DEFAULT 'draft',
  customer_client_id  BIGINT UNSIGNED NULL,
  site_street         VARCHAR(190) NULL,
  site_city           VARCHAR(120) NULL,
  site_zip            VARCHAR(10) NULL,
  site_country        CHAR(2) NOT NULL DEFAULT 'CZ',
  notes               TEXT NULL,
  archived_at         TIMESTAMP NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_jobs_supplier_number (supplier_id, number),
  KEY idx_tri_jobs_status (supplier_id, status, archived_at),
  KEY idx_tri_jobs_customer (customer_client_id),
  CONSTRAINT fk_tri_jobs_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_jobs_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tri_jobs_customer FOREIGN KEY (customer_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_job_contacts (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id     BIGINT UNSIGNED NOT NULL,
  client_id  BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_jc_job_client (job_id, client_id),
  UNIQUE KEY uq_tri_jc_job_sort (job_id, sort_order),
  KEY idx_tri_jc_client (client_id),
  CONSTRAINT fk_tri_jc_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_jc_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
