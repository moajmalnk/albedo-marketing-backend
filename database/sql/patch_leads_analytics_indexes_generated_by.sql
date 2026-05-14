-- Optional follow-up to patch_leads_analytics_indexes.sql
-- Run only when column `leads.generated_by_user_id` already exists.

ALTER TABLE `leads`
  ADD INDEX `leads_generated_by_user_id_created_at_idx` (`generated_by_user_id`, `created_at`);
