-- =====================================================================
-- Departments + department_user pivot + widen users.department
-- Equivalent to:
--   backend/database/migrations/2026_05_14_120000_create_departments_and_department_user.php
--
-- MySQL / MariaDB. Run after prior migrations exist (users table).
-- Safe-ish: uses IF NOT EXISTS for tables; ALTER may fail if already VARCHAR.
-- =====================================================================

SET NAMES utf8mb4;

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
