-- Dva druhy kontaktu: firma a osoba. Soukromá osoba je osoba bez firmy.

SET NAMES utf8mb4;

UPDATE clients SET party_kind = 'person' WHERE party_kind = 'private';

ALTER TABLE clients
  MODIFY party_kind ENUM('company','person') NOT NULL DEFAULT 'company';
