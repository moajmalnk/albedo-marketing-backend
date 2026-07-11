-- Optional manual patch for existing databases (same as migration 2026_05_14_220000).
-- Improves GET /api/v1/leads/{id}/history and any audit listing by entity.

ALTER TABLE `audit_logs`
  ADD INDEX `audit_logs_entity_created_idx` (`entity_type`, `entity_id`, `created_at`);
