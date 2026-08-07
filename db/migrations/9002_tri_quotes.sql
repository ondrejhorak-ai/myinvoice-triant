-- TRIANT: cenové nabídky / varianty

CREATE TABLE IF NOT EXISTS tri_quote_variants (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id               BIGINT UNSIGNED NOT NULL,
  variant_code         CHAR(1) NOT NULL,
  number               VARCHAR(32) NOT NULL,
  job_date             DATE NOT NULL,
  status               ENUM('draft','sent','approved','rejected','superseded') NOT NULL DEFAULT 'draft',
  customer_client_id   BIGINT UNSIGNED NULL,
  valid_until          DATE NULL,
  quote_discount_type  ENUM('percent','absolute') NULL,
  quote_discount_value DECIMAL(12,2) NULL,
  subtotal             DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_base_21          DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_amount_21        DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_base_12          DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_amount_12        DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_base_0           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_with_vat       DECIMAL(12,2) NOT NULL DEFAULT 0,
  commission_total     DECIMAL(12,2) NOT NULL DEFAULT 0,
  version              INT UNSIGNED NOT NULL DEFAULT 1,
  lock_version         INT UNSIGNED NOT NULL DEFAULT 1,
  internal_notes       TEXT NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_qv_number (number),
  UNIQUE KEY uq_tri_qv_job_code (job_id, variant_code),
  KEY idx_tri_qv_job (job_id),
  CONSTRAINT fk_tri_qv_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_qv_customer FOREIGN KEY (customer_client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_quote_sections (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_variant_id BIGINT UNSIGNED NOT NULL,
  sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
  title            TEXT NOT NULL,
  KEY idx_tri_qs_variant (quote_variant_id),
  CONSTRAINT fk_tri_qs_variant FOREIGN KEY (quote_variant_id) REFERENCES tri_quote_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_quote_line_items (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_variant_id     BIGINT UNSIGNED NOT NULL,
  quote_section_id     BIGINT UNSIGNED NULL,
  sort_order           INT UNSIGNED NOT NULL DEFAULT 0,
  catalog_item_id      BIGINT UNSIGNED NULL,
  designation          VARCHAR(120) NOT NULL DEFAULT '',
  title                TEXT NOT NULL,
  description          TEXT NULL,
  quantity             DECIMAL(12,3) NOT NULL,
  unit                 VARCHAR(20) NOT NULL DEFAULT 'ks',
  base_unit_price      DECIMAL(12,2) NOT NULL,
  vat_rate             TINYINT UNSIGNED NOT NULL DEFAULT 21,
  markup_type          ENUM('percent','absolute') NULL,
  markup_value         DECIMAL(12,2) NULL,
  markup_amount        DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_discount_type   ENUM('percent','absolute') NULL,
  line_discount_value  DECIMAL(12,2) NULL,
  line_total           DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tri_qli_variant (quote_variant_id),
  CONSTRAINT fk_tri_qli_variant FOREIGN KEY (quote_variant_id) REFERENCES tri_quote_variants(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_qli_section FOREIGN KEY (quote_section_id) REFERENCES tri_quote_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tri_jobs
  ADD COLUMN approved_variant_id BIGINT UNSIGNED NULL AFTER notes,
  ADD CONSTRAINT fk_tri_jobs_approved_variant
    FOREIGN KEY (approved_variant_id) REFERENCES tri_quote_variants(id) ON DELETE SET NULL;
