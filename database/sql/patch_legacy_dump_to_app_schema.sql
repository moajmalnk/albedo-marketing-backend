-- =====================================================================
-- Patch: legacy / old SQL dump → current Laravel schema (Albedo Marketing)
--
-- Use when you imported an older `albedo_crm_full_with_mock.sql` (or similar)
-- and the API returns SQLSTATE[42S22] (unknown column), 500 on PATCH /users,
-- or 500 on GET /users/{id}/stats.
--
-- Requirements:
--   • MySQL 8.0.12+ or MariaDB 10.5.2+ (for `ADD COLUMN IF NOT EXISTS`).
--   • Tables `users`, `leads`, `lead_activities` already exist.
--   • Laravel `migrations` table exists (so optional INSERT IGNORE rows apply).
--
-- Safe to re-run: idempotent guards / IF NOT EXISTS where possible.
-- Run on the SAME database your Laravel `DB_*` points to, then deploy latest PHP.
--
-- Individual equivalents (if you prefer smaller steps):
--   extend_users_profile_fields.sql
--   create_departments_and_department_user.sql
--   whatsapp_lead_capture.sql
--   patch_mock_user_password_to_admin.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1) users — profile columns (PATCH /api/v1/users, updateMe)
--     Migration: 2026_05_09_140000_extend_users_with_profile_fields
-- ---------------------------------------------------------------------

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
     (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM migrations) AS m));

-- ---------------------------------------------------------------------
-- 2) departments + pivot + widen users.department
--     Migration: 2026_05_14_120000_create_departments_and_department_user
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `departments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(32) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `category` VARCHAR(100) NULL DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `departments_code_unique` (`code`),
    UNIQUE KEY `departments_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `department_user` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `department_id` BIGINT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`user_id`, `department_id`),
    KEY `department_user_department_id_foreign` (`department_id`),
    CONSTRAINT `department_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `department_user_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `departments` (`code`, `name`, `category`, `status`, `created_at`, `updated_at`)
VALUES
    ('PM', 'Performance Marketing', 'Internal', 'active', NOW(), NOW()),
    ('IM', 'Influence Marketing', 'Internal', 'active', NOW(), NOW()),
    ('SALES', 'Sales', 'Internal', 'active', NOW(), NOW()),
    ('OPS', 'Operations', 'Internal', 'active', NOW(), NOW());

ALTER TABLE `users` MODIFY `department` VARCHAR(32) NULL;

INSERT IGNORE INTO `department_user` (`user_id`, `department_id`, `is_primary`)
SELECT u.`id`, d.`id`, 1
FROM `users` u
INNER JOIN `departments` d ON d.`code` = u.`department`
WHERE u.`department` IS NOT NULL
  AND u.`deleted_at` IS NULL;

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES
    ('2026_05_14_120000_create_departments_and_department_user',
     (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM migrations) AS m));

-- ---------------------------------------------------------------------
-- 3) leads + whatsapp_sessions (GET /users/{id}/stats, WhatsApp worker)
--     Migrations: 2026_05_09_120000_add_whatsapp_columns_to_leads_table
--                 2026_05_09_120100_create_whatsapp_sessions_table
-- ---------------------------------------------------------------------

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `whatsapp_id` VARCHAR(64) NULL AFTER `whatsapp`;

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `captured_by_user_id` BIGINT UNSIGNED NULL AFTER `owner_id`;

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `last_contacted_at` TIMESTAMP NULL DEFAULT NULL AFTER `next_action_at`;

SET @idx := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'leads'
      AND index_name = 'leads_whatsapp_id_unique'
);
SET @sql := IF(@idx = 0,
    'ALTER TABLE `leads` ADD UNIQUE KEY `leads_whatsapp_id_unique` (`whatsapp_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND constraint_name = 'leads_captured_by_user_id_foreign'
);
SET @sql := IF(@fk = 0,
    'ALTER TABLE `leads` ADD CONSTRAINT `leads_captured_by_user_id_foreign` FOREIGN KEY (`captured_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `whatsapp_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `session_name` VARCHAR(80) NOT NULL,
    `status` ENUM('DISCONNECTED','PAIRING','CONNECTED','ERROR') NOT NULL DEFAULT 'DISCONNECTED',
    `phone_number` VARCHAR(32) NULL DEFAULT NULL,
    `last_qr` TEXT NULL,
    `last_sync` TIMESTAMP NULL DEFAULT NULL,
    `last_error` TEXT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `whatsapp_sessions_user_id_session_name_unique` (`user_id`, `session_name`),
    KEY `whatsapp_sessions_status_index` (`status`),
    CONSTRAINT `whatsapp_sessions_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES
    ('2026_05_09_120000_add_whatsapp_columns_to_leads_table',
     (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM migrations) AS m)),
    ('2026_05_09_120100_create_whatsapp_sessions_table',
     (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM migrations) AS m));

-- ---------------------------------------------------------------------
-- 4) lead_activities.type — add `followup`
--     Migration: 2026_05_14_130000_add_followup_to_lead_activities_type_enum
--     Skip if this errors (already applied). MySQL only.
-- ---------------------------------------------------------------------

ALTER TABLE `lead_activities`
    MODIFY COLUMN `type` ENUM(
        'call','whatsapp','sms','email','note','assessment','meeting','followup'
    ) NOT NULL;

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES
    ('2026_05_14_130000_add_followup_to_lead_activities_type_enum',
     (SELECT COALESCE(MAX(batch), 0) + 1 FROM (SELECT batch FROM migrations) AS m));

-- ---------------------------------------------------------------------
-- 5) Optional — mock accounts password = Admin@12345
--     Only if you use the eight @albedoedu.com seed users from the CRM dump.
-- ---------------------------------------------------------------------

UPDATE `users`
SET `password_hash` = '$2y$12$sHfpH1dFtgUfUdWR6q3znehjkTPjIAuf6xR5jVo5AQCGRwMxubZba'
WHERE `email` IN (
    'yadukrishnan@albedoedu.com',
    'dilshada@albedoedu.com',
    'naseef@albedoedu.com',
    'ajmal@albedoedu.com',
    'fahis@albedoedu.com',
    'raoof@albedoedu.com',
    'shibin@albedoedu.com',
    'irshad@albedoedu.com'
);

-- =====================================================================
-- Done. Verify: SHOW COLUMNS FROM users LIKE 'whatsapp';
--               SHOW COLUMNS FROM leads LIKE 'captured_by_user_id';
--               SHOW TABLES LIKE 'whatsapp_sessions';
-- =====================================================================
