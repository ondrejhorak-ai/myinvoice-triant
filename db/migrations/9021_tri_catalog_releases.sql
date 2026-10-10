-- Additive catalogue and immutable price releases. Existing offer snapshots and legacy rows remain intact.
SET NAMES utf8mb4;
ALTER TABLE tri_price_lists ADD COLUMN IF NOT EXISTS currency CHAR(3) NOT NULL DEFAULT 'CZK';
ALTER TABLE tri_price_lists ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL;
CREATE TABLE IF NOT EXISTS tri_catalog_products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 supplier_id INT UNSIGNED NOT NULL,
 code VARCHAR(160) NOT NULL,
 title VARCHAR(500) NOT NULL,
 description TEXT NULL,
 unit VARCHAR(20) NOT NULL DEFAULT 'ks',
 vat_rate DECIMAL(5,2) NOT NULL DEFAULT 21,
 default_quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
 category VARCHAR(190) NOT NULL DEFAULT '',
 tags_json TEXT NOT NULL DEFAULT '[]',
 parameters TEXT NULL,
 image_id BIGINT UNSIGNED NULL,
 legacy_item_id BIGINT UNSIGNED NULL,
 lock_version INT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tri_catalog_code (supplier_id, code),
 UNIQUE KEY uq_tri_catalog_legacy (legacy_item_id),
 CONSTRAINT fk_tri_catalog_supplier FOREIGN KEY(supplier_id) REFERENCES supplier(id),
 CONSTRAINT fk_tri_catalog_image FOREIGN KEY(image_id) REFERENCES tri_quote_images(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tri_price_releases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 price_list_id BIGINT UNSIGNED NOT NULL,
 sequence INT UNSIGNED NOT NULL,
 status ENUM('draft','published') NOT NULL DEFAULT 'draft',
 valid_from DATE NULL,
 valid_until DATE NULL,
 source_release_id BIGINT UNSIGNED NULL,
 lock_version INT UNSIGNED NOT NULL DEFAULT 0,
 published_by INT UNSIGNED NULL,
 published_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_tri_release_sequence(price_list_id, sequence),
 CONSTRAINT fk_tri_release_list FOREIGN KEY(price_list_id) REFERENCES tri_price_lists(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tri_price_release_items (
 release_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 unit_price DECIMAL(12,2) NULL,
 price_changed_at DATETIME NULL,
 sort_order INT UNSIGNED NOT NULL DEFAULT 0,
 snapshot_json LONGTEXT NOT NULL,
 PRIMARY KEY(release_id, product_id),
 CONSTRAINT fk_tri_release_item_release FOREIGN KEY(release_id) REFERENCES tri_price_releases(id),
 CONSTRAINT fk_tri_release_item_product FOREIGN KEY(product_id) REFERENCES tri_catalog_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE tri_quote_variants ADD COLUMN IF NOT EXISTS price_release_id BIGINT UNSIGNED NULL;
ALTER TABLE tri_quote_variants ADD COLUMN IF NOT EXISTS price_effective_date DATE NULL;
ALTER TABLE tri_quote_line_items ADD COLUMN IF NOT EXISTS source_product_id BIGINT UNSIGNED NULL;
ALTER TABLE tri_quote_line_items ADD COLUMN IF NOT EXISTS source_release_id BIGINT UNSIGNED NULL;
-- No automatic product merging or historical price reconstruction. Existing lists are adopted
-- transactionally on explicit creation of their first draft (PricingRepository::draft).

ALTER TABLE tri_quote_line_items MODIFY COLUMN designation VARCHAR(160) NOT NULL DEFAULT '';
