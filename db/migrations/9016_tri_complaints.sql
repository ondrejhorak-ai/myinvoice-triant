-- TRIANT: reklamace zakázek + chat komentářů

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tri_complaints (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id       BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(255) NOT NULL,
  description  TEXT NULL,
  status       ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at    DATETIME NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tri_complaints_job_status (job_id, status),
  CONSTRAINT fk_tri_complaints_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_complaint_comments (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  complaint_id  BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  body          TEXT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL,
  KEY idx_tri_complaint_comments (complaint_id, id),
  CONSTRAINT fk_tri_cc_complaint FOREIGN KEY (complaint_id) REFERENCES tri_complaints(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_cc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
