-- =====================================================================
-- Expenses table — schema migration
-- Equivalent to:
--   backend/database/migrations/2026_05_09_150000_create_expenses_table.php
--
-- Safe to re-run: uses CREATE TABLE IF NOT EXISTS.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `expenses` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `title` VARCHAR(160) NOT NULL,
    `amount` DECIMAL(12, 2) NOT NULL,
    `spent_at` DATE NOT NULL,   
    `department` VARCHAR(80) NULL DEFAULT NULL,
    `reference` VARCHAR(80) NULL DEFAULT NULL,
    `notes` TEXT NULL,
    `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `expenses_category_id_index` (`category_id`),
    KEY `expenses_spent_at_index` (`spent_at`),
    KEY `expenses_created_by_index` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES
    ('2026_05_09_150000_create_expenses_table',
     (SELECT COALESCE(MAX(batch),0)+1 FROM (SELECT batch FROM migrations) AS m));
