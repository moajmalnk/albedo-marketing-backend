-- =============================================================================
-- Create `team_tips` (fixes 500 on GET /api/v1/team-tips when migrations were
-- never applied on production, e.g. SQLSTATE[42S02] table not found)
-- =============================================================================
-- Run against your app database (e.g. u262074081_albedo_market):
--   mysql -h HOST -u USER -p DATABASE < backend/database/sql/patch_team_tips_table.sql
-- Or paste into phpMyAdmin → SQL.
--
-- Requires: existing `users` table (for created_by FK). MySQL 5.7.8+ / MariaDB 10.2+ (JSON).
-- Preferred on deployable servers:  php artisan migrate
--   (migration: 2026_05_14_170000_create_team_tips_table.php)
-- Safe to re-run: CREATE TABLE IF NOT EXISTS only.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS `team_tips` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(191) NOT NULL,
  `description` TEXT NOT NULL,
  `sent_to` JSON NOT NULL,
  `sent_by` VARCHAR(120) NOT NULL,
  `sent_by_role` VARCHAR(64) NULL DEFAULT NULL,
  `date_sent` DATE NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'Active',
  `priority` VARCHAR(16) NULL DEFAULT NULL,
  `read_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_tips_created_by_foreign` (`created_by`),
  CONSTRAINT `team_tips_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
