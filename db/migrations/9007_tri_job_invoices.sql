-- TRIANT: propojení vydaných faktur se Zakázkami TRI (bez ALTER na invoices)

CREATE TABLE IF NOT EXISTS tri_job_invoices (
  invoice_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  job_id     BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tri_ji_job (job_id),
  CONSTRAINT fk_tri_ji_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_ji_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
