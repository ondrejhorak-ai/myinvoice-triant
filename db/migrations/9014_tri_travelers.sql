-- TRIANT: pruvodky vyroby (travelers) + stanviste

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tri_travelers (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id               BIGINT UNSIGNED NOT NULL,
  quote_line_item_id   BIGINT UNSIGNED NULL,
  number               VARCHAR(32) NOT NULL,
  designation          VARCHAR(120) NOT NULL DEFAULT '',
  title                TEXT NOT NULL,
  description          TEXT NULL,
  quantity             DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit                 VARCHAR(20) NOT NULL DEFAULT 'ks',
  status               ENUM('open','done') NOT NULL DEFAULT 'open',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_travelers_job_number (job_id, number),
  KEY idx_tri_travelers_job_status (job_id, status),
  KEY idx_tri_travelers_line (quote_line_item_id),
  CONSTRAINT fk_tri_travelers_job FOREIGN KEY (job_id) REFERENCES tri_jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_travelers_line FOREIGN KEY (quote_line_item_id) REFERENCES tri_quote_line_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_traveler_operations (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  traveler_id  BIGINT UNSIGNED NOT NULL,
  station      ENUM(
    'konstrukce',
    'narezove_centrum',
    'cnc',
    'olepovacka',
    'dyhovani_brouseni',
    'montaz',
    'lakovna',
    'brouseni',
    'baleni'
  ) NOT NULL,
  hours        DECIMAL(6,2) NULL,
  note         VARCHAR(255) NULL,
  UNIQUE KEY uq_tri_traveler_station (traveler_id, station),
  CONSTRAINT fk_tri_ops_traveler FOREIGN KEY (traveler_id) REFERENCES tri_travelers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
