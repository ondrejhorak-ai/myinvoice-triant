-- Odběratel (firma / soukromá osoba) a kontaktní osoba. Adresa může chybět,
-- dokud se nevystavuje doklad. Stávající řádky zůstávají firmami.

SET NAMES utf8mb4;

ALTER TABLE clients
  MODIFY street VARCHAR(190) NULL,
  MODIFY city VARCHAR(120) NULL,
  MODIFY zip VARCHAR(10) NULL,
  ADD COLUMN party_kind ENUM('company','private','person') NOT NULL DEFAULT 'company' AFTER company_name,
  ADD COLUMN company_client_id BIGINT UNSIGNED NULL AFTER party_kind,
  ADD KEY idx_clients_company_link (company_client_id),
  ADD CONSTRAINT fk_clients_company_link FOREIGN KEY (company_client_id) REFERENCES clients(id) ON DELETE SET NULL;

ALTER TABLE tri_job_contacts
  ADD COLUMN role ENUM('customer','architect','contact') NOT NULL DEFAULT 'contact' AFTER client_id;
