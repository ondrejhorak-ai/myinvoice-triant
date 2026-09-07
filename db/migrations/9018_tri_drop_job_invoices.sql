-- TRIANT: faktury jdou přes MyÚčto project_id; lokální vazba tri_job_invoices končí.
-- Override jen pro doklady, kterým už nelze změnit projekt (vystavené).

SET NAMES utf8mb4;

DROP TABLE IF EXISTS tri_job_invoices;

CREATE TABLE IF NOT EXISTS tri_job_invoice_overrides (
  invoice_id BIGINT UNSIGNED NOT NULL,
  job_id     INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (invoice_id),
  KEY idx_tri_job_inv_overrides_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
