-- TRIANT: po 0115_supplier_id_int sjednotit supplier_id na INT UNSIGNED a vrátit FK.
-- ALTER TABLE IF EXISTS: na čisté instalaci TRI tabulky vznikají až v 9000+ (tam už INT UNSIGNED).

ALTER TABLE IF EXISTS tri_calendar_events MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE IF EXISTS tri_jobs            MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE IF EXISTS tri_number_seq      MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE IF EXISTS tri_price_lists     MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE IF EXISTS tri_quote_images    MODIFY supplier_id INT UNSIGNED NOT NULL;
ALTER TABLE IF EXISTS tri_tags            MODIFY supplier_id INT UNSIGNED NOT NULL;

ALTER TABLE IF EXISTS tri_calendar_events ADD CONSTRAINT fk_tri_cal_supplier  FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE IF EXISTS tri_jobs            ADD CONSTRAINT fk_tri_jobs_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE IF EXISTS tri_number_seq      ADD CONSTRAINT fk_tri_ns_supplier   FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE IF EXISTS tri_price_lists     ADD CONSTRAINT fk_tri_pl_supplier   FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE IF EXISTS tri_quote_images    ADD CONSTRAINT fk_tri_qi_supplier   FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
ALTER TABLE IF EXISTS tri_tags            ADD CONSTRAINT fk_tri_tags_supplier FOREIGN KEY IF NOT EXISTS (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE;
