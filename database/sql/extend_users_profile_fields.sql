-- =====================================================================
-- Users profile fields — schema migration
-- Equivalent to:
--   backend/database/migrations/2026_05_09_140000_extend_users_with_profile_fields.php
--
-- Safe to re-run: uses ADD COLUMN IF NOT EXISTS.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `whatsapp` VARCHAR(20) NULL DEFAULT NULL AFTER `phone`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `sub_brand` VARCHAR(80) NULL DEFAULT NULL AFTER `department`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `sub_brand`;

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `notes` TEXT NULL AFTER `address`;

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES
    ('2026_05_09_140000_extend_users_with_profile_fields',
     (SELECT COALESCE(MAX(batch),0)+1 FROM (SELECT batch FROM migrations) AS m));
