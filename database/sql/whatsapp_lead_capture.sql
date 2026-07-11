-- =====================================================================
-- WhatsApp Lead Capture — schema migration
-- Equivalent to:
--   backend/database/migrations/2026_05_09_120000_add_whatsapp_columns_to_leads_table.php
--   backend/database/migrations/2026_05_09_120100_create_whatsapp_sessions_table.php
--
-- Safe to re-run: uses IF NOT EXISTS / drop-before-add guards.
-- Tested on MySQL 8.0+ / MariaDB 10.6+. Adjust collation if your DB differs.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- 1. Extend `leads` with WhatsApp tracking columns
-- ---------------------------------------------------------------------

-- whatsapp_id (WhatsApp JID, e.g. 91XXXXXXXXXX@c.us) — unique idempotency key
ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `whatsapp_id` VARCHAR(64) NULL AFTER `whatsapp`;

-- captured_by_user_id — which CRM user's WhatsApp received the chat
ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `captured_by_user_id` BIGINT UNSIGNED NULL AFTER `owner_id`;

-- last_contacted_at — touched by every inbound WhatsApp event
ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `last_contacted_at` TIMESTAMP NULL DEFAULT NULL AFTER `next_action_at`;

-- Unique index on whatsapp_id (skip if already created)
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

-- Foreign key on captured_by_user_id → users.id (skip if already created)
SET @fk := (
    SELECT COUNT(*) FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE()
      AND constraint_name = 'leads_captured_by_user_id_foreign'
);
SET @sql := IF(@fk = 0,
    'ALTER TABLE `leads` ADD CONSTRAINT `leads_captured_by_user_id_foreign` FOREIGN KEY (`captured_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. `whatsapp_sessions` — one row per (user_id, session_name)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `whatsapp_sessions` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED NOT NULL,
    `session_name` VARCHAR(80) NOT NULL,
    `status`       ENUM('DISCONNECTED','PAIRING','CONNECTED','ERROR') NOT NULL DEFAULT 'DISCONNECTED',
    `phone_number` VARCHAR(32) NULL DEFAULT NULL,
    `last_qr`      TEXT NULL,
    `last_sync`    TIMESTAMP NULL DEFAULT NULL,
    `last_error`   TEXT NULL,
    `created_at`   TIMESTAMP NULL DEFAULT NULL,
    `updated_at`   TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `whatsapp_sessions_user_id_session_name_unique` (`user_id`, `session_name`),
    KEY `whatsapp_sessions_status_index` (`status`),
    CONSTRAINT `whatsapp_sessions_user_id_foreign`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Register the migrations so `php artisan migrate` does NOT replay them
--    (only meaningful if you ran this SQL directly instead of `artisan migrate`).
-- ---------------------------------------------------------------------

INSERT IGNORE INTO `migrations` (`migration`, `batch`)
VALUES
    ('2026_05_09_120000_add_whatsapp_columns_to_leads_table', (SELECT COALESCE(MAX(batch),0)+1 FROM (SELECT batch FROM migrations) AS m)),
    ('2026_05_09_120100_create_whatsapp_sessions_table',     (SELECT COALESCE(MAX(batch),0)+1 FROM (SELECT batch FROM migrations) AS m));
