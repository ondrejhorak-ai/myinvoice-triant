-- TRIANT: Chat + log událostí u zakázek TRI
-- Jedna tabulka pro komentáře uživatelů (kind=comment) i automaticky
-- logované systémové události (kind=event) — chronologie je jeden SELECT.

CREATE TABLE IF NOT EXISTS tri_job_activity (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id      BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NULL,          -- NULL = systém
  kind        ENUM('comment','event') NOT NULL,
  event_type  VARCHAR(40) NULL,              -- jen pro kind=event
  body        TEXT NULL,                     -- text komentáře
  payload     JSON NULL,                     -- detaily události (from/to, jména…)
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NULL,                -- editace vlastního komentáře
  deleted_at  TIMESTAMP NULL,                -- soft-delete komentáře
  KEY idx_tja_job (job_id, id),
  CONSTRAINT fk_tja_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tja_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
