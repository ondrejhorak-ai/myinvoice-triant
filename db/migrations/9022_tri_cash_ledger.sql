-- Office cash desk. Card payments do not change the physical drawer.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tri_cash_handovers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  number VARCHAR(40) NOT NULL,
  handed_on DATE NOT NULL,
  book_amount DECIMAL(14,2) NOT NULL,
  counted_amount DECIMAL(14,2) NOT NULL,
  difference_amount DECIMAL(14,2) NOT NULL,
  keep_amount DECIMAL(14,2) NOT NULL,
  remitted_amount DECIMAL(14,2) NOT NULL,
  note TEXT NULL,
  handed_by INT UNSIGNED NULL,
  client_token CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_cash_handover_number (supplier_id, number),
  UNIQUE KEY uq_tri_cash_handover_token (supplier_id, client_token),
  KEY idx_tri_cash_handover_date (supplier_id, handed_on),
  CONSTRAINT fk_tri_cash_handover_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tri_cash_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  kind ENUM('cash_in','cash_out','card','remittance') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  occurred_on DATE NOT NULL,
  description VARCHAR(500) NOT NULL,
  note VARCHAR(500) NULL,
  invoice_id BIGINT UNSIGNED NULL,
  handover_id BIGINT UNSIGNED NULL,
  recorded_by INT UNSIGNED NULL,
  client_token CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tri_cash_movement_token (supplier_id, client_token),
  KEY idx_tri_cash_movement_list (supplier_id, occurred_on, id),
  KEY idx_tri_cash_movement_open (supplier_id, handover_id),
  CONSTRAINT fk_tri_cash_movement_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id),
  CONSTRAINT fk_tri_cash_movement_handover FOREIGN KEY (handover_id) REFERENCES tri_cash_handovers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
