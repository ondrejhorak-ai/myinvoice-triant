-- TRIANT: drop FK na supplier.id před 0115_supplier_id_int (TINYINT → INT).
-- Bez tohoto kroku ALTER TABLE supplier MODIFY id selže na TRI tabulkách.

ALTER TABLE tri_calendar_events DROP FOREIGN KEY IF EXISTS fk_tri_cal_supplier;
ALTER TABLE tri_jobs            DROP FOREIGN KEY IF EXISTS fk_tri_jobs_supplier;
ALTER TABLE tri_number_seq      DROP FOREIGN KEY IF EXISTS fk_tri_ns_supplier;
ALTER TABLE tri_price_lists     DROP FOREIGN KEY IF EXISTS fk_tri_pl_supplier;
ALTER TABLE tri_quote_images    DROP FOREIGN KEY IF EXISTS fk_tri_qi_supplier;
ALTER TABLE tri_tags            DROP FOREIGN KEY IF EXISTS fk_tri_tags_supplier;
