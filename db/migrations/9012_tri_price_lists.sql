-- TRIANT: ceníky dodavatele a jejich položky

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tri_price_lists (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id TINYINT UNSIGNED NOT NULL,
  name        VARCHAR(190) NOT NULL,
  note        TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tri_pl_supplier (supplier_id),
  CONSTRAINT fk_tri_pl_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_price_list_items (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  price_list_id     BIGINT UNSIGNED NOT NULL,
  sort_order        INT UNSIGNED NOT NULL DEFAULT 0,
  image_id          BIGINT UNSIGNED NULL,
  designation       VARCHAR(120) NOT NULL DEFAULT '',
  title             TEXT NOT NULL,
  description       TEXT NULL,
  default_quantity  DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit              VARCHAR(20) NOT NULL DEFAULT 'ks',
  base_unit_price   DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_rate          TINYINT UNSIGNED NOT NULL DEFAULT 21,
  price_updated_at  DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tri_pli_list (price_list_id, sort_order),
  KEY idx_tri_pli_image (image_id),
  CONSTRAINT fk_tri_pli_list FOREIGN KEY (price_list_id) REFERENCES tri_price_lists(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_pli_image FOREIGN KEY (image_id) REFERENCES tri_quote_images(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
