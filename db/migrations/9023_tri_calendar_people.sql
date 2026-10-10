-- Expedice: okno příjezdu a posádka (volný text). Služba na prodejně: jeden uživatel Office.

SET NAMES utf8mb4;

ALTER TABLE tri_calendar_events
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER job_id,
  ADD COLUMN arrival_window VARCHAR(80) NULL AFTER note,
  ADD COLUMN crew VARCHAR(255) NULL AFTER arrival_window,
  ADD KEY idx_tri_cal_user (user_id),
  ADD CONSTRAINT fk_tri_cal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;
