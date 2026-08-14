-- TRIANT: kalendare smen / expedice / vyroby

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tri_calendar_events (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  TINYINT UNSIGNED NOT NULL,
  calendar     ENUM('shifts','dispatch','production') NOT NULL,
  job_id       BIGINT UNSIGNED NULL,
  title        VARCHAR(255) NOT NULL,
  station      ENUM('konstrukce','vyroba','kompletace','lakovna','expedice','montaz') NULL,
  starts_at    DATETIME NOT NULL,
  ends_at      DATETIME NULL,
  all_day      TINYINT(1) NOT NULL DEFAULT 1,
  status       ENUM('planned','confirmed','in_progress','done') NOT NULL DEFAULT 'planned',
  note         TEXT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tri_cal_range (supplier_id, calendar, starts_at),
  KEY idx_tri_cal_job (job_id),
  CONSTRAINT fk_tri_cal_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_cal_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
