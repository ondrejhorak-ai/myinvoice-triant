-- TRIANT: flag Vyrabime na radku nabidky + FK catalog_item_id na cenik

SET NAMES utf8mb4;

ALTER TABLE tri_quote_line_items
  ADD COLUMN IF NOT EXISTS is_manufactured TINYINT(1) NOT NULL DEFAULT 1 AFTER image_id;

ALTER TABLE tri_quote_line_items
  ADD INDEX IF NOT EXISTS idx_tri_qli_catalog (catalog_item_id);

ALTER TABLE tri_quote_line_items
  ADD CONSTRAINT fk_tri_qli_catalog FOREIGN KEY IF NOT EXISTS (catalog_item_id)
    REFERENCES tri_price_list_items(id) ON DELETE SET NULL;
