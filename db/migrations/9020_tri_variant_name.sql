-- TRIANT: volitelný lidský název varianty nabídky (např. „ekonomické lamino“)
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

ALTER TABLE tri_quote_variants
  ADD COLUMN IF NOT EXISTS name VARCHAR(120) NULL AFTER variant_code;
