-- Simplify job status to active / confirmed / rejected / completed
UPDATE tri_jobs SET status = 'active' WHERE status IN ('draft', 'on_hold');
UPDATE tri_jobs SET status = 'rejected' WHERE status = 'cancelled';

ALTER TABLE tri_jobs
  MODIFY status ENUM('active', 'confirmed', 'rejected', 'completed') NOT NULL DEFAULT 'active';
