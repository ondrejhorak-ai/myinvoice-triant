-- TRIANT: Uživatelé přiřazení k zakázce (vypracoval)

CREATE TABLE IF NOT EXISTS tri_job_users (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id     BIGINT UNSIGNED NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_ju_job_user (job_id, user_id),
  UNIQUE KEY uq_tri_ju_job_sort (job_id, sort_order),
  KEY idx_tri_ju_user (user_id),
  CONSTRAINT fk_tri_ju_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_ju_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: existující zakázky dostanou zakladatele jako vypracovatele
INSERT IGNORE INTO tri_job_users (job_id, user_id, sort_order)
SELECT j.id, j.owner_user_id, 0
  FROM tri_jobs j
 WHERE NOT EXISTS (
       SELECT 1 FROM tri_job_users ju WHERE ju.job_id = j.id
 );
