-- TRIANT: znovu-seed výchozích tagů (Zákazník, Architekt) pro všechny suppliery.
-- Seed z 9000 se na supplierech založených po migraci neaplikoval; UI slugifikace
-- navíc do opravy komolila diakritiku ("Zákazník" → "z-kazn-k").

-- Oprava zkomolených slugů z UI (jen pokud správný slug u dodavatele ještě neexistuje)
UPDATE tri_tags t
   SET t.slug = 'zakaznik'
 WHERE t.slug = 'z-kazn-k'
   AND NOT EXISTS (
        SELECT 1 FROM (SELECT supplier_id, slug FROM tri_tags) x
         WHERE x.supplier_id = t.supplier_id AND x.slug = 'zakaznik'
   );

UPDATE tri_tags t
   SET t.slug = 'architekt'
 WHERE t.slug IN ('architekt-2')
   AND NOT EXISTS (
        SELECT 1 FROM (SELECT supplier_id, slug FROM tri_tags) x
         WHERE x.supplier_id = t.supplier_id AND x.slug = 'architekt'
   );

-- Idempotentní seed pro všechny suppliery (sjednotí i název „zakaznik" → „Zákazník")
INSERT INTO tri_tags (supplier_id, name, slug, color)
SELECT s.id, 'Zákazník', 'zakaznik', '#059669' FROM supplier s
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO tri_tags (supplier_id, name, slug, color)
SELECT s.id, 'Architekt', 'architekt', '#6366f1' FROM supplier s
ON DUPLICATE KEY UPDATE name = VALUES(name);
