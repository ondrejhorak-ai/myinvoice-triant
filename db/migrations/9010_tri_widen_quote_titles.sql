-- TRIANT: delší názvy položek a sekcí v cenové nabídce
--
-- UI používá víceřádkový textarea pro title položky, ale sloupec byl VARCHAR(190).
-- Ukládání padalo na SQLSTATE 22001 (Data too long for column 'title').
--
-- Idempotence: MODIFY je deklarativní.

SET NAMES utf8mb4;

ALTER TABLE tri_quote_line_items
  MODIFY COLUMN title TEXT NOT NULL;

ALTER TABLE tri_quote_sections
  MODIFY COLUMN title TEXT NOT NULL;
