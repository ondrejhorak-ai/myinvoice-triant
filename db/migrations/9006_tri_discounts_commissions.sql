-- TRIANT: slevy a provize na úrovni sekcí a varianty

ALTER TABLE tri_quote_sections
  ADD COLUMN discount_type ENUM('percent','absolute') NULL AFTER title,
  ADD COLUMN discount_value DECIMAL(12,2) NULL AFTER discount_type,
  ADD COLUMN commission_type ENUM('percent','absolute') NULL AFTER discount_value,
  ADD COLUMN commission_value DECIMAL(12,2) NULL AFTER commission_type;

ALTER TABLE tri_quote_variants
  ADD COLUMN quote_commission_type ENUM('percent','absolute') NULL AFTER quote_discount_value,
  ADD COLUMN quote_commission_value DECIMAL(12,2) NULL AFTER quote_commission_type,
  ADD COLUMN discount_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER commission_total;
