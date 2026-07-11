-- Speeds up GET /api/v1/analytics/team-insights and date-scoped lead aggregations.
-- Safe to run once on production MySQL/MariaDB. If an index already exists, skip that
-- statement or drop the duplicate name first (MySQL has no portable IF NOT EXISTS for indexes).
--
-- PREREQUISITE: `created_at`, `owner_id`, and `created_by` must exist (standard leads table).

ALTER TABLE `leads`
  ADD INDEX `leads_created_at_idx` (`created_at`);

ALTER TABLE `leads`
  ADD INDEX `leads_owner_id_created_at_idx` (`owner_id`, `created_at`);

ALTER TABLE `leads`
  ADD INDEX `leads_created_by_created_at_idx` (`created_by`, `created_at`);

-- ---------------------------------------------------------------------------
-- OPTIONAL — run only after `generated_by_user_id` exists on `leads`
-- (e.g. after Laravel migration 2026_05_14_181000_extend_leads_for_capture_form.php
--  or your equivalent schema patch). If you run this before the column exists,
--  MySQL returns #1072 Key column 'generated_by_user_id' doesn't exist.
-- See: patch_leads_analytics_indexes_generated_by.sql
-- ---------------------------------------------------------------------------
