-- Pokladna: značka Zaúčtováno místo předání a zamykání.
-- Předané pohyby se označí jako zaúčtované. Odvod z předání je běžný výdaj.
-- Tabulka tri_cash_handovers zůstává jako historie, aplikace ji už nepoužívá.
-- Hodnota kind 'remittance' v ENUM zůstává, aby šel skript spustit znovu;
-- žádný řádek ji po převodu nemá a API ji nepřijme.

SET NAMES utf8mb4;

ALTER TABLE tri_cash_movements
  ADD COLUMN IF NOT EXISTS accounted TINYINT(1) NOT NULL DEFAULT 0 AFTER handover_id,
  ADD COLUMN IF NOT EXISTS accounted_at DATETIME NULL AFTER accounted,
  ADD COLUMN IF NOT EXISTS accounted_by BIGINT UNSIGNED NULL AFTER accounted_at,
  ADD COLUMN IF NOT EXISTS party_user_id BIGINT UNSIGNED NULL AFTER recorded_by,
  ADD COLUMN IF NOT EXISTS job_id BIGINT UNSIGNED NULL AFTER invoice_id,
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER created_at,
  ADD COLUMN IF NOT EXISTS deleted_by BIGINT UNSIGNED NULL AFTER deleted_at,
  ADD INDEX IF NOT EXISTS idx_tri_cash_movement_accounted (supplier_id, deleted_at, accounted),
  ADD INDEX IF NOT EXISTS idx_tri_cash_movement_party (supplier_id, party_user_id),
  ADD INDEX IF NOT EXISTS idx_tri_cash_movement_job (job_id);

UPDATE tri_cash_movements m
  JOIN tri_cash_handovers h ON h.id = m.handover_id
   SET m.accounted = 1,
       m.accounted_at = COALESCE(m.accounted_at, CONCAT(h.handed_on, ' 00:00:00')),
       m.accounted_by = COALESCE(m.accounted_by, h.handed_by)
 WHERE m.handover_id IS NOT NULL;

UPDATE tri_cash_movements
   SET accounted = 1,
       accounted_at = COALESCE(accounted_at, created_at, NOW())
 WHERE handover_id IS NOT NULL
   AND accounted = 0;

UPDATE tri_cash_movements
   SET kind = 'cash_out'
 WHERE kind = 'remittance';

UPDATE tri_cash_movements
   SET party_user_id = recorded_by
 WHERE party_user_id IS NULL
   AND recorded_by IS NOT NULL
   AND recorded_by > 0;

ALTER TABLE tri_cash_movements
  ADD CONSTRAINT fk_tri_cash_movement_job FOREIGN KEY IF NOT EXISTS (job_id)
    REFERENCES tri_jobs(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS tri_cash_movement_audit (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  movement_id BIGINT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(32) NOT NULL,
  changes JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tri_cash_audit_movement (movement_id, id),
  CONSTRAINT fk_tri_cash_audit_movement FOREIGN KEY (movement_id) REFERENCES tri_cash_movements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
