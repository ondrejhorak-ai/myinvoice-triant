-- Simplify variant status to draft / sent / approved
UPDATE tri_quote_variants SET status = 'sent' WHERE status = 'superseded';
UPDATE tri_quote_variants SET status = 'draft' WHERE status = 'rejected';

ALTER TABLE tri_quote_variants
  MODIFY status ENUM('draft', 'sent', 'approved') NOT NULL DEFAULT 'draft';
