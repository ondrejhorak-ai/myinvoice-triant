-- TRIANT: tagging for Klienti (Architekt, Zákazník, …)

CREATE TABLE IF NOT EXISTS tri_tags (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  INT UNSIGNED NOT NULL,
  name         VARCHAR(80) NOT NULL,
  slug         VARCHAR(80) NOT NULL,
  color        VARCHAR(7) NOT NULL DEFAULT '#6366f1',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_tags_supplier_slug (supplier_id, slug),
  KEY idx_tri_tags_supplier (supplier_id),
  CONSTRAINT fk_tri_tags_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_client_tags (
  client_id BIGINT UNSIGNED NOT NULL,
  tag_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (client_id, tag_id),
  KEY idx_tri_client_tags_tag (tag_id),
  CONSTRAINT fk_tri_ct_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_tri_ct_tag FOREIGN KEY (tag_id) REFERENCES tri_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default tags for every existing supplier
INSERT INTO tri_tags (supplier_id, name, slug, color)
SELECT s.id, 'Architekt', 'architekt', '#6366f1' FROM supplier s
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO tri_tags (supplier_id, name, slug, color)
SELECT s.id, 'Zákazník', 'zakaznik', '#059669' FROM supplier s
ON DUPLICATE KEY UPDATE name = VALUES(name);
