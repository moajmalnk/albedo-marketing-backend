-- =============================================================================
-- Albedo Marketing API — MySQL setup / repair (Hostinger & similar)
-- =============================================================================
-- Run against your app database (e.g. u262074081_albedo_market):
--   mysql -h HOST -u USER -p DATABASE < backend/database/sql/setup_challenge_categories_lead_form_options.sql
-- Or paste into phpMyAdmin → SQL.
--
-- If `leads.course` / `leads.syllabus` are still ENUM and block new picklist values, run:
--   backend/database/sql/patch_leads_course_syllabus_to_varchar.sql
-- For capture qualification + nullable lead name (Create Lead form), run:
--   backend/database/sql/patch_leads_capture_qualification.sql
-- Fixes common 500s when migrations were never applied on production:
--   - GET /api/v1/challenge-categories  → table `challenge_categories` missing
--   - GET /api/v1/lead-form-options     → tables `lead_form_option_*` missing
--
-- Safe to re-run: uses IF NOT EXISTS / INSERT IGNORE where appropriate.
-- Requires: MySQL 5.7.8+ / MariaDB 10.2+ (JSON column). InnoDB. Existing `users` table for FK on marketing_challenges.created_by.
-- Note: MariaDB does not accept CAST(x AS JSON) the same way as MySQL 8; this script uses JSON_OBJECT / plain strings only.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- 1) Challenge categories (Settings → Challenges)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `challenge_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `department` VARCHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `challenge_categories_name_department_unique` (`name`, `department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `challenge_categories` (`name`, `department`, `status`, `created_at`, `updated_at`) VALUES
('Ad creative fatigue', 'Performance Marketing', 'Active', NOW(), NOW()),
('Lead quality from paid ads', 'Performance Marketing', 'Active', NOW(), NOW()),
('CPL increase', 'Performance Marketing', 'Active', NOW(), NOW()),
('Landing page conversion', 'Performance Marketing', 'Active', NOW(), NOW()),
('Pixel / tracking issue', 'Performance Marketing', 'Active', NOW(), NOW()),
('Sales closing hurdle', 'Performance Marketing', 'Active', NOW(), NOW()),
('Other', 'Performance Marketing', 'Active', NOW(), NOW()),
('Influencer lead quality', 'Influence Marketing', 'Active', NOW(), NOW()),
('Campaign coordination', 'Influence Marketing', 'Active', NOW(), NOW()),
('Influencer payment delay', 'Influence Marketing', 'Active', NOW(), NOW()),
('Content approval delay', 'Influence Marketing', 'Active', NOW(), NOW()),
('Reach / engagement drop', 'Influence Marketing', 'Active', NOW(), NOW()),
('Sales closing hurdle', 'Influence Marketing', 'Active', NOW(), NOW()),
('Other', 'Influence Marketing', 'Active', NOW(), NOW());

-- -----------------------------------------------------------------------------
-- 2) Marketing challenges (depends on `users` for created_by FK)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `marketing_challenges` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` VARCHAR(191) NOT NULL,
  `description` TEXT NOT NULL,
  `department` VARCHAR(64) NOT NULL,
  `reported_by` VARCHAR(120) NOT NULL,
  `affected_leads` JSON NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'Open',
  `date_reported` DATE NOT NULL,
  `date_resolved` DATE NULL DEFAULT NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marketing_challenges_created_by_foreign` (`created_by`),
  CONSTRAINT `marketing_challenges_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3) Lead form picklists (Settings → Lead form, LeadCaptureForm)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_form_option_groups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lead_form_option_groups_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lead_form_options` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` BIGINT UNSIGNED NOT NULL,
  `value` VARCHAR(191) NOT NULL,
  `label` VARCHAR(191) NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `meta` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lead_form_options_group_id_value_unique` (`group_id`, `value`),
  KEY `lead_form_options_group_id_is_active_sort_order_index` (`group_id`, `is_active`, `sort_order`),
  CONSTRAINT `lead_form_options_group_id_foreign`
    FOREIGN KEY (`group_id`) REFERENCES `lead_form_option_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Groups (idempotent)
INSERT IGNORE INTO `lead_form_option_groups` (`slug`, `label`, `created_at`, `updated_at`) VALUES
('connected_by', 'Connected By', NOW(), NOW()),
('source_name', 'Source Name', NOW(), NOW()),
('source_code', 'Source Code', NOW(), NOW()),
('children_count', 'Number of Children', NOW(), NOW()),
('yes_no_enrolled', 'Already Enrolled', NOW(), NOW()),
('country', 'Country', NOW(), NOW()),
('state', 'State', NOW(), NOW()),
('city', 'City', NOW(), NOW()),
('course', 'Course', NOW(), NOW()),
('subject', 'Subject', NOW(), NOW()),
('syllabus', 'Syllabus', NOW(), NOW());

-- Options: connected_by
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'connected_by' AS slug, 'INBOUND_CALL' AS value, 'Inbound Call' AS label, 10 AS sort_order UNION ALL
  SELECT 'connected_by', 'INBOUND_WHATSAPP', 'Inbound WhatsApp', 20 UNION ALL
  SELECT 'connected_by', 'OUTBOUND_CALL', 'Outbound Call', 30 UNION ALL
  SELECT 'connected_by', 'OUTBOUND_WHATSAPP', 'Outbound WhatsApp', 40 UNION ALL
  SELECT 'connected_by', 'WEBSITE_ENQUIRY', 'Website Enquiry', 50
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- source_name (with JSON meta — JSON_OBJECT works on MariaDB 10.2.7+ / MySQL 5.7.8+)
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, v.meta_obj, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'source_name' AS slug, 'influence' AS value, 'Influence Marketing' AS label, 10 AS sort_order, JSON_OBJECT('source_group', 'influence') AS meta_obj UNION ALL
  SELECT 'source_name', 'performance', 'Performance Marketing', 20, JSON_OBJECT('source_group', 'performance') UNION ALL
  SELECT 'source_name', 'customer_referral', 'Customer Referral', 30, JSON_OBJECT('source_group', 'reference') UNION ALL
  SELECT 'source_name', 'employee_referral', 'Employee Referral', 40, JSON_OBJECT('source_group', 'reference') UNION ALL
  SELECT 'source_name', 'reference', 'Reference', 50, JSON_OBJECT('source_group', 'reference') UNION ALL
  SELECT 'source_name', 'albedo', 'Albedo', 60, JSON_OBJECT('source_group', 'albedo') UNION ALL
  SELECT 'source_name', 'other', 'Other', 70, JSON_OBJECT('source_group', 'other')
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `meta` = VALUES(`meta`), `updated_at` = NOW();

-- source_code
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'source_code' AS slug, 'NSF_014' AS value, 'NSF 014' AS label, 10 AS sort_order UNION ALL
  SELECT 'source_code', 'YT_003', 'YT 003', 20 UNION ALL
  SELECT 'source_code', 'WEB_ORG', 'Website Organic', 30 UNION ALL
  SELECT 'source_code', 'STU_REF', 'Student Referral', 40
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- children_count 1–10
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'children_count' AS slug, '1' AS value, '01' AS label, 10 AS sort_order UNION ALL
  SELECT 'children_count', '2', '02', 20 UNION ALL SELECT 'children_count', '3', '03', 30 UNION ALL
  SELECT 'children_count', '4', '04', 40 UNION ALL SELECT 'children_count', '5', '05', 50 UNION ALL
  SELECT 'children_count', '6', '06', 60 UNION ALL SELECT 'children_count', '7', '07', 70 UNION ALL
  SELECT 'children_count', '8', '08', 80 UNION ALL SELECT 'children_count', '9', '09', 90 UNION ALL
  SELECT 'children_count', '10', '10', 100
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- yes_no_enrolled
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'yes_no_enrolled' AS slug, 'yes' AS value, 'Yes' AS label, 10 AS sort_order UNION ALL
  SELECT 'yes_no_enrolled', 'no', 'No', 20
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- country
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'country' AS slug, 'India' AS value, 'India' AS label, 10 AS sort_order UNION ALL
  SELECT 'country', 'UAE', 'United Arab Emirates', 20 UNION ALL
  SELECT 'country', 'Saudi Arabia', 'Saudi Arabia', 30 UNION ALL
  SELECT 'country', 'Oman', 'Oman', 40 UNION ALL
  SELECT 'country', 'United States', 'United States / Canada', 50
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- state
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'state' AS slug, 'Kerala' AS value, 'Kerala' AS label, 10 AS sort_order UNION ALL
  SELECT 'state', 'Karnataka', 'Karnataka', 20 UNION ALL
  SELECT 'state', 'Tamil Nadu', 'Tamil Nadu', 30 UNION ALL
  SELECT 'state', 'Maharashtra', 'Maharashtra', 40
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- city
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'city' AS slug, 'Kochi' AS value, 'Kochi' AS label, 10 AS sort_order UNION ALL
  SELECT 'city', 'Kozhikode', 'Kozhikode', 20 UNION ALL
  SELECT 'city', 'Thrissur', 'Thrissur', 30 UNION ALL
  SELECT 'city', 'Bengaluru', 'Bengaluru', 40
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- course (programme)
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'course' AS slug, 'Foundation' AS value, 'Foundation' AS label, 10 AS sort_order UNION ALL
  SELECT 'course', 'Academics', 'Academics', 20 UNION ALL
  SELECT 'course', 'Crash', 'Crash', 30 UNION ALL
  SELECT 'course', 'Repeater', 'Repeater', 40 UNION ALL
  SELECT 'course', 'Other', 'Other', 50 UNION ALL
  SELECT 'course', 'A_PLUS_CAMPUS_CBSE', 'A+ Campus CBSE', 60 UNION ALL
  SELECT 'course', 'A_PLUS_CAMPUS', 'A+ Campus', 70 UNION ALL
  SELECT 'course', 'ONLINE_SCHOOL', 'Online School', 80 UNION ALL
  SELECT 'course', 'LP_UP', 'LP & UP', 90 UNION ALL
  SELECT 'course', 'ESPEAK', 'Espeak', 100 UNION ALL
  SELECT 'course', 'PENCIL_FOUNDATION', 'Pencil Foundation', 110 UNION ALL
  SELECT 'course', 'FOUNDATION_PLUS_ACADEMICS', 'Foundation + Academics', 120 UNION ALL
  SELECT 'course', 'BATCH', 'Batch', 130
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- subject
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'subject' AS slug, 'MATHS' AS value, 'Maths' AS label, 10 AS sort_order UNION ALL
  SELECT 'subject', 'SCIENCE', 'Science', 20 UNION ALL
  SELECT 'subject', 'ENGLISH', 'English', 30 UNION ALL
  SELECT 'subject', 'SOCIAL_SCIENCE', 'Social Science', 40 UNION ALL
  SELECT 'subject', 'PHYSICS', 'Physics', 50 UNION ALL
  SELECT 'subject', 'CHEMISTRY', 'Chemistry', 60 UNION ALL
  SELECT 'subject', 'BIOLOGY', 'Biology', 70 UNION ALL
  SELECT 'subject', 'ALL_SUBJECTS', 'All Subjects', 80
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- syllabus
INSERT INTO `lead_form_options` (`group_id`, `value`, `label`, `sort_order`, `is_active`, `meta`, `created_at`, `updated_at`)
SELECT g.id, v.value, v.label, v.sort_order, 1, NULL, NOW(), NOW()
FROM `lead_form_option_groups` g
JOIN (
  SELECT 'syllabus' AS slug, 'STATE' AS value, 'State Board' AS label, 10 AS sort_order UNION ALL
  SELECT 'syllabus', 'CBSE', 'CBSE', 20 UNION ALL
  SELECT 'syllabus', 'ICSE', 'ICSE', 30 UNION ALL
  SELECT 'syllabus', 'IGCSE', 'IGCSE', 40 UNION ALL
  SELECT 'syllabus', 'IB', 'IB', 50
) v ON v.slug = g.slug
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), `sort_order` = VALUES(`sort_order`), `updated_at` = NOW();

-- =============================================================================
-- Done. Verify:
--   SELECT COUNT(*) FROM challenge_categories;
--   SELECT slug, COUNT(*) FROM lead_form_options o JOIN lead_form_option_groups g ON g.id=o.group_id GROUP BY g.slug;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 4) Optional — extend `leads` for capture form (if those columns are missing)
-- -----------------------------------------------------------------------------
-- If lead create/profile returns SQLSTATE[42S22] on `alternate_phone`, `notes_html`, etc.,
-- prefer on the server:  php artisan migrate
-- Migration reference: 2026_05_14_181000_extend_leads_for_capture_form.php
--
-- MySQL 8.0.12+ example (run only if columns do not exist yet):
--
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `alternate_phone` VARCHAR(20) NULL AFTER `phone`;
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `children_count` TINYINT UNSIGNED NULL AFTER `email`;
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `already_enrolled` TINYINT(1) NULL AFTER `children_count`;
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `connected_by` VARCHAR(64) NULL AFTER `campaign`;
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `enquiry_at` TIMESTAMP NULL AFTER `connected_by`;
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `notes_html` LONGTEXT NULL AFTER `next_action_at`;
-- ALTER TABLE `leads` ADD COLUMN IF NOT EXISTS `generated_by_user_id` BIGINT UNSIGNED NULL AFTER `created_by`;
-- ALTER TABLE `leads` ADD CONSTRAINT `leads_generated_by_user_id_foreign`
--   FOREIGN KEY (`generated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
-- =============================================================================
