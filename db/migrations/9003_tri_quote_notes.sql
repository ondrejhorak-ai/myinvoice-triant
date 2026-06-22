-- TRIANT: poznámky nad/pod položkami u cenové nabídky (varianty)

ALTER TABLE tri_quote_variants
  ADD COLUMN note_above_items TEXT NULL AFTER internal_notes,
  ADD COLUMN note_below_items TEXT NULL AFTER note_above_items;
