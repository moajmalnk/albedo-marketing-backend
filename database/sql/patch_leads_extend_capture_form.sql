-- Aligns `leads` with Laravel migration 2026_05_14_181000_extend_leads_for_capture_form.php.
-- Fixes marketing analytics and APIs that reference `connected_by`, `notes_html`,
-- `generated_by_user_id`, etc., when `php artisan migrate` was not run on this MySQL.
--
-- If `php artisan migrate` from your laptop fails with SQLSTATE[1045] Access denied, the
-- DB user often cannot connect from outside the host (or .env credentials are wrong).
-- Run this file in phpMyAdmin (same DB as production) or SSH into the server, cd to the
-- API app, fix .env for that host, then `php artisan migrate --force`.
--
-- HOW TO RUN (phpMyAdmin): execute statements one at a time (one query per run).
-- If you see "Duplicate column name", skip that statement — partial installs are OK.
--
-- AFTER THIS: run patch_leads_analytics_indexes_generated_by.sql if you use that index.

SET NAMES utf8mb4;

ALTER TABLE `leads`
  ADD COLUMN `alternate_phone` VARCHAR(20) NULL AFTER `phone`;

ALTER TABLE `leads`
  ADD COLUMN `children_count` TINYINT UNSIGNED NULL AFTER `email`;

ALTER TABLE `leads`
  ADD COLUMN `already_enrolled` TINYINT(1) NULL AFTER `children_count`;

ALTER TABLE `leads`
  ADD COLUMN `connected_by` VARCHAR(64) NULL AFTER `campaign`;

ALTER TABLE `leads`
  ADD COLUMN `enquiry_at` TIMESTAMP NULL AFTER `connected_by`;

ALTER TABLE `leads`
  ADD COLUMN `notes_html` LONGTEXT NULL AFTER `next_action_at`;

ALTER TABLE `leads`
  ADD COLUMN `generated_by_user_id` BIGINT UNSIGNED NULL AFTER `created_by`;

-- Foreign key (skip if error "Duplicate foreign key" or incompatible engine).
ALTER TABLE `leads`
  ADD CONSTRAINT `leads_generated_by_user_id_foreign`
  FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
