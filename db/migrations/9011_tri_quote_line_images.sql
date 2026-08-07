-- TRIANT: obrazkove nahledy polozek cenove nabidky

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tri_quote_images (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id TINYINT UNSIGNED NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  sha256      CHAR(64) NOT NULL,
  width_px    SMALLINT UNSIGNED NOT NULL,
  height_px   SMALLINT UNSIGNED NOT NULL,
  size_bytes  INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_qi_supplier_hash (supplier_id, sha256),
  KEY idx_tri_qi_last_upload (last_uploaded_at),
  CONSTRAINT fk_tri_qi_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tri_quote_line_items
  ADD COLUMN IF NOT EXISTS image_id BIGINT UNSIGNED NULL AFTER catalog_item_id,
  ADD INDEX IF NOT EXISTS idx_tri_qli_image (image_id),
  ADD CONSTRAINT fk_tri_qli_image FOREIGN KEY IF NOT EXISTS (image_id)
    REFERENCES tri_quote_images(id) ON DELETE SET NULL;
