-- Optional follow-up to patch_leads_analytics_indexes.sql
-- PREREQUISITE: column `leads.generated_by_user_id` must exist. If phpMyAdmin shows
-- #1072 for this index, run patch_leads_extend_capture_form.sql (or `php artisan migrate`) first.

ALTER TABLE `leads`
  ADD INDEX `leads_generated_by_user_id_created_at_idx` (`generated_by_user_id`, `created_at`);
