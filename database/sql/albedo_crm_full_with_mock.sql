-- Albedo CRM full schema + mock data (MySQL 8)
-- Generated for: u262074081_albedo_market

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- -----------------------------------------------------
-- Core framework tables
-- -----------------------------------------------------
DROP TABLE IF EXISTS `personal_access_tokens`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- RBAC tables
-- -----------------------------------------------------
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

CREATE TABLE `roles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `permission_level` tinyint unsigned NOT NULL DEFAULT '10',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role_id` bigint unsigned NOT NULL,
  `department` enum('PM','IM','SALES','OPS') DEFAULT NULL,
  `reporting_manager_id` bigint unsigned DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `phone_extension` varchar(20) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_id_index` (`role_id`),
  KEY `users_reporting_manager_id_index` (`reporting_manager_id`),
  KEY `users_department_status_index` (`department`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(80) NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint unsigned NOT NULL,
  `permission_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- CRM tables
-- -----------------------------------------------------
DROP TABLE IF EXISTS `lead_stage_transitions`;
DROP TABLE IF EXISTS `assessments`;
DROP TABLE IF EXISTS `lead_activities`;
DROP TABLE IF EXISTS `tasks`;
DROP TABLE IF EXISTS `unknown_calls`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `enrollments`;
DROP TABLE IF EXISTS `attendance_logs`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `lead_stages`;

CREATE TABLE `lead_stages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `group` enum('active','inactive') NOT NULL DEFAULT 'active',
  `order` smallint unsigned NOT NULL DEFAULT '0',
  `color` varchar(16) DEFAULT NULL,
  `is_terminal` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lead_stages_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `leads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_name` varchar(160) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `parent_name` varchar(160) DEFAULT NULL,
  `parent_relation` enum('father','mother','guardian') DEFAULT NULL,
  `class` varchar(20) DEFAULT NULL,
  `syllabus` enum('STATE','CBSE','ICSE','IGCSE','IB') DEFAULT NULL,
  `course` enum('Foundation','Academics','Crash','Repeater','Other') DEFAULT NULL,
  `subjects` json DEFAULT NULL,
  `school` varchar(160) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `district` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `country` varchar(80) DEFAULT NULL,
  `pincode` varchar(12) DEFAULT NULL,
  `source_group` enum('influence','performance','albedo','reference','other') DEFAULT NULL,
  `source_code` varchar(40) DEFAULT NULL,
  `campaign` varchar(120) DEFAULT NULL,
  `stage_id` bigint unsigned DEFAULT NULL,
  `status` varchar(40) DEFAULT NULL,
  `owner_id` bigint unsigned DEFAULT NULL,
  `assigned_dept` enum('SALES','MARKETING') NOT NULL DEFAULT 'SALES',
  `is_read_only` tinyint(1) NOT NULL DEFAULT '0',
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `dnd` tinyint(1) NOT NULL DEFAULT '0',
  `next_action_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `leads_phone_unique` (`phone`),
  KEY `leads_owner_id_stage_id_index` (`owner_id`,`stage_id`),
  KEY `leads_source_group_source_code_index` (`source_group`,`source_code`),
  KEY `leads_next_action_at_index` (`next_action_at`),
  KEY `leads_assigned_dept_stage_id_index` (`assigned_dept`,`stage_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_stage_transitions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `from_stage_id` bigint unsigned DEFAULT NULL,
  `to_stage_id` bigint unsigned NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_by` bigint unsigned NOT NULL,
  `changed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `lead_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` enum('call','whatsapp','sms','email','note','assessment','meeting') NOT NULL,
  `direction` enum('inbound','outbound') DEFAULT NULL,
  `connected` tinyint(1) DEFAULT NULL,
  `outcome` varchar(60) DEFAULT NULL,
  `duration_sec` int DEFAULT NULL,
  `recording_url` varchar(255) DEFAULT NULL,
  `comments` text,
  `payload` json DEFAULT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `assessments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `activity_id` bigint unsigned NOT NULL,
  `scheduled_at` datetime NOT NULL,
  `student_profile` enum('AVG','WEAK','BRIGHT') DEFAULT NULL,
  `parent_availability` enum('MC','FC','FMC') DEFAULT NULL,
  `notes` text,
  `status` enum('booked','done','no_show','cancelled') NOT NULL DEFAULT 'booked',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `enrollments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `advisor_id` bigint unsigned NOT NULL,
  `enrollment_type` enum('new_admission','repackage') NOT NULL,
  `admission_status` enum('DP','partial','full') NOT NULL,
  `package_amount` decimal(12,2) NOT NULL,
  `spot_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `fee_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `balance_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','upi','card','bank_transfer','emi') DEFAULT NULL,
  `course_start_date` date DEFAULT NULL,
  `course_end_date` date DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `enrollment_id` bigint unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` enum('cash','upi','card','bank_transfer','emi') NOT NULL,
  `reference` varchar(80) DEFAULT NULL,
  `received_at` timestamp NOT NULL,
  `received_by` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `work_mode` enum('OFFICE','WFH') NOT NULL,
  `check_in_at` timestamp NOT NULL,
  `check_out_at` timestamp NULL DEFAULT NULL,
  `net_minutes` int DEFAULT NULL,
  `session_number` tinyint unsigned NOT NULL DEFAULT '1',
  `day_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attendance_logs_user_id_day_date_index` (`user_id`,`day_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_id` bigint unsigned DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` bigint unsigned DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unknown_calls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `call_id` varchar(80) DEFAULT NULL,
  `direction` enum('inbound','outbound') DEFAULT NULL,
  `from_phone` varchar(20) DEFAULT NULL,
  `to_phone` varchar(20) DEFAULT NULL,
  `agent_extension` varchar(20) DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `duration_sec` int DEFAULT NULL,
  `recording_url` varchar(255) DEFAULT NULL,
  `disposition` varchar(40) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `assigned_to` bigint unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `description` text,
  `status` enum('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `due_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Optional foreign keys (safe for clean import)
-- -----------------------------------------------------
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_users_manager` FOREIGN KEY (`reporting_manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

ALTER TABLE `leads`
  ADD CONSTRAINT `fk_leads_stage` FOREIGN KEY (`stage_id`) REFERENCES `lead_stages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `lead_stage_transitions`
  ADD CONSTRAINT `fk_transitions_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_transitions_from_stage` FOREIGN KEY (`from_stage_id`) REFERENCES `lead_stages` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transitions_to_stage` FOREIGN KEY (`to_stage_id`) REFERENCES `lead_stages` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_transitions_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `lead_activities`
  ADD CONSTRAINT `fk_activities_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_activities_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `assessments`
  ADD CONSTRAINT `fk_assessments_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_assessments_activity` FOREIGN KEY (`activity_id`) REFERENCES `lead_activities` (`id`) ON DELETE CASCADE;

ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enrollments_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_enrollments_advisor` FOREIGN KEY (`advisor_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_enrollment` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payments_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

ALTER TABLE `attendance_logs`
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `tasks`
  ADD CONSTRAINT `fk_tasks_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tasks_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE RESTRICT;

-- -----------------------------------------------------
-- Seed data
-- -----------------------------------------------------

INSERT INTO `roles` (`id`, `key`, `name`, `permission_level`, `created_at`, `updated_at`) VALUES
(1, 'super_admin', 'Super Admin', 100, NOW(), NOW()),
(2, 'admin', 'Admin', 90, NOW(), NOW()),
(3, 'dept_head', 'Dept Head', 80, NOW(), NOW()),
(4, 'marketer', 'Marketer', 40, NOW(), NOW()),
(5, 'psa', 'PSA', 30, NOW(), NOW()),
(6, 'advisor', 'Advisor', 20, NOW(), NOW()),
(7, 'telecaller', 'Telecaller', 10, NOW(), NOW());

INSERT INTO `permissions` (`id`, `key`, `name`, `created_at`, `updated_at`) VALUES
(1, 'lead.view', 'View Leads', NOW(), NOW()),
(2, 'lead.edit', 'Edit Leads', NOW(), NOW()),
(3, 'lead.assign', 'Assign Leads', NOW(), NOW()),
(4, 'lead.stage_change', 'Change Lead Stage', NOW(), NOW()),
(5, 'lead.import', 'Import Leads', NOW(), NOW()),
(6, 'user.manage', 'Manage Users', NOW(), NOW()),
(7, 'audit.view', 'View Audit Logs', NOW(), NOW()),
(8, 'enrollment.manage', 'Manage Enrollments', NOW(), NOW());

INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`, `updated_at`) VALUES
(1,1,NOW(),NOW()),(1,2,NOW(),NOW()),(1,3,NOW(),NOW()),(1,4,NOW(),NOW()),(1,5,NOW(),NOW()),(1,6,NOW(),NOW()),(1,7,NOW(),NOW()),(1,8,NOW(),NOW()),
(2,1,NOW(),NOW()),(2,2,NOW(),NOW()),(2,3,NOW(),NOW()),(2,4,NOW(),NOW()),(2,5,NOW(),NOW()),(2,7,NOW(),NOW()),
(3,1,NOW(),NOW()),(3,2,NOW(),NOW()),(3,3,NOW(),NOW()),(3,4,NOW(),NOW()),(3,7,NOW(),NOW()),
(4,1,NOW(),NOW()),(4,5,NOW(),NOW()),
(5,1,NOW(),NOW()),(5,2,NOW(),NOW()),(5,4,NOW(),NOW()),
(6,1,NOW(),NOW()),(6,2,NOW(),NOW()),(6,8,NOW(),NOW()),
(7,1,NOW(),NOW()),(7,2,NOW(),NOW()),(7,4,NOW(),NOW());

-- password hash below corresponds to "Admin@12345"
INSERT INTO `users` (`id`,`first_name`,`last_name`,`email`,`password_hash`,`phone`,`role_id`,`department`,`reporting_manager_id`,`status`,`phone_extension`,`last_login_at`,`created_at`,`updated_at`) VALUES
(1,'Yadukrishnan','P','yadukrishnan@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000001',1,'OPS',NULL,'active','1001',NULL,NOW(),NOW()),
(2,'Dilshada',NULL,'dilshada@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000002',2,'OPS',1,'active','1002',NULL,NOW(),NOW()),
(3,'Naseef',NULL,'naseef@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000003',3,'PM',2,'active','1101',NULL,NOW(),NOW()),
(4,'Ajmal',NULL,'ajmal@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000004',3,'SALES',2,'active','1201',NULL,NOW(),NOW()),
(5,'Fahis',NULL,'fahis@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000005',6,'SALES',4,'active','1301',NULL,NOW(),NOW()),
(6,'Raoof',NULL,'raoof@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000006',7,'SALES',4,'active','1401',NULL,NOW(),NOW()),
(7,'Shibin',NULL,'shibin@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000007',5,'SALES',4,'active','1501',NULL,NOW(),NOW()),
(8,'Irshad',NULL,'irshad@albedoedu.com','$2y$12$Mf6aEuHn3x7RzP7KQHhXru2Qd2GcYf5bFwoQzM5D9d9GQZsD2k6HW','+919900000008',4,'IM',3,'active','1601',NULL,NOW(),NOW());

INSERT INTO `lead_stages` (`id`,`key`,`label`,`group`,`order`,`color`,`is_terminal`,`created_at`,`updated_at`) VALUES
(1,'new_lead','New Lead','active',1,'#3b82f6',0,NOW(),NOW()),
(2,'prospect','Prospect','active',2,'#06b6d4',0,NOW(),NOW()),
(3,'assessment_booked','Assessment Booked','active',3,'#a855f7',0,NOW(),NOW()),
(4,'assessment_done','Assessment Done','active',4,'#7c3aed',0,NOW(),NOW()),
(5,'itb','ITB','active',5,'#f97316',0,NOW(),NOW()),
(6,'enrolled','Enrolled','active',6,'#22c55e',0,NOW(),NOW()),
(7,'nifc','NIFC','inactive',7,'#6b7280',1,NOW(),NOW()),
(8,'invalid_junk','Invalid / Junk','inactive',8,'#4b5563',1,NOW(),NOW()),
(9,'disqualified','Disqualified','inactive',9,'#991b1b',1,NOW(),NOW());

INSERT INTO `leads`
(`id`,`student_name`,`phone`,`whatsapp`,`email`,`parent_name`,`parent_relation`,`class`,`syllabus`,`course`,`subjects`,`school`,`city`,`district`,`state`,`country`,`pincode`,`source_group`,`source_code`,`campaign`,`stage_id`,`status`,`owner_id`,`assigned_dept`,`is_read_only`,`priority`,`dnd`,`next_action_at`,`created_by`,`created_at`,`updated_at`)
VALUES
(1,'Aisha Rahman','919876543210','919876543210','aisha@example.com','Rahman','father','10','CBSE','Foundation',JSON_ARRAY('Maths','Physics'),'Model School','Kochi','Ernakulam','Kerala','India','682001','performance','NSF 014','May Science Campaign',2,'Prospect',6,'SALES',0,'high',0,DATE_ADD(NOW(), INTERVAL 1 DAY),3,NOW(),NOW()),
(2,'Muhammed Asif','919812345678','919812345678','asif@example.com','Shahana','mother','12','STATE','Crash',JSON_ARRAY('Biology','Chemistry'),'Govt HSS','Kozhikode','Kozhikode','Kerala','India','673001','influence','YT 003','NEET Shorts',3,'Assessment Booked',7,'SALES',0,'normal',0,DATE_ADD(NOW(), INTERVAL 2 DAY),3,NOW(),NOW()),
(3,'Ananya Menon','919934567890','919934567890','ananya@example.com','Suresh','father','11','ICSE','Academics',JSON_ARRAY('English','Social'),'St Marys','Thrissur','Thrissur','Kerala','India','680001','albedo','Website','HomePage Organic',1,'New Lead',NULL,'MARKETING',0,'normal',0,NULL,8,NOW(),NOW()),
(4,'Fathima N','919945612345','919945612345','fathima@example.com','Nazeer','guardian','UG','CBSE','Repeater',JSON_ARRAY('Physics','Chemistry'),'MES College','Kannur','Kannur','Kerala','India','670001','reference','Student Ref','Referral Batch',5,'ITB',5,'SALES',0,'high',0,DATE_ADD(NOW(), INTERVAL 1 DAY),4,NOW(),NOW()),
(5,'Rohan Babu','919956789012','919956789012','rohan@example.com','Babu','father','9','STATE','Foundation',JSON_ARRAY('Maths'),'Public School','Malappuram','Malappuram','Kerala','India','676001','other','Offline Event','Trade Expo',7,'NIFC',6,'SALES',1,'low',1,NULL,3,NOW(),NOW());

INSERT INTO `lead_stage_transitions`
(`lead_id`,`from_stage_id`,`to_stage_id`,`reason`,`changed_by`,`changed_at`,`created_at`,`updated_at`) VALUES
(1,1,2,'Initial qualification call',6,NOW(),NOW(),NOW()),
(2,2,3,'Parent agreed for assessment slot',7,NOW(),NOW(),NOW()),
(4,4,5,'Assessment passed; moved to ITB',5,NOW(),NOW(),NOW()),
(5,2,7,'Not interested in current cycle',6,NOW(),NOW(),NOW());

INSERT INTO `lead_activities`
(`lead_id`,`user_id`,`type`,`direction`,`connected`,`outcome`,`duration_sec`,`recording_url`,`comments`,`payload`,`occurred_at`,`created_at`,`updated_at`) VALUES
(1,6,'call','outbound',1,'Interested',242,NULL,'Parent asked for details over WhatsApp',JSON_OBJECT('next_step','share_brochure'),NOW(),NOW(),NOW()),
(2,7,'call','outbound',1,'Assessment Booked',312,NULL,'Booked Saturday slot',JSON_OBJECT('assessment','booked','slot','11:00'),NOW(),NOW(),NOW()),
(5,6,'call','outbound',0,'Not Interested',35,NULL,'Requested do not call',JSON_OBJECT('dnd',true),NOW(),NOW(),NOW());

INSERT INTO `assessments`
(`lead_id`,`activity_id`,`scheduled_at`,`student_profile`,`parent_availability`,`notes`,`status`,`created_at`,`updated_at`) VALUES
(2,2,DATE_ADD(NOW(), INTERVAL 3 DAY),'BRIGHT','FMC','Sister already enrolled','booked',NOW(),NOW());

INSERT INTO `tasks`
(`lead_id`,`assigned_to`,`title`,`description`,`status`,`due_at`,`completed_at`,`created_at`,`updated_at`) VALUES
(1,6,'Follow up after brochure','Call parent after document review','pending',DATE_ADD(NOW(), INTERVAL 1 DAY),NULL,NOW(),NOW()),
(2,7,'Assessment reminder','Reminder call one day before assessment','in_progress',DATE_ADD(NOW(), INTERVAL 2 DAY),NULL,NOW(),NOW());

INSERT INTO `attendance_logs`
(`user_id`,`work_mode`,`check_in_at`,`check_out_at`,`net_minutes`,`session_number`,`day_date`,`created_at`,`updated_at`) VALUES
(6,'OFFICE',DATE_SUB(NOW(), INTERVAL 5 HOUR),NULL,NULL,1,CURDATE(),NOW(),NOW()),
(7,'OFFICE',DATE_SUB(NOW(), INTERVAL 4 HOUR),NULL,NULL,1,CURDATE(),NOW(),NOW());

INSERT INTO `enrollments`
(`lead_id`,`advisor_id`,`enrollment_type`,`admission_status`,`package_amount`,`spot_amount`,`fee_amount`,`balance_amount`,`payment_method`,`course_start_date`,`course_end_date`,`confirmed_at`,`created_at`,`updated_at`)
VALUES
(4,5,'new_admission','DP',50000.00,10000.00,50000.00,40000.00,'upi',CURDATE(),DATE_ADD(CURDATE(), INTERVAL 180 DAY),NOW(),NOW(),NOW());

INSERT INTO `payments`
(`enrollment_id`,`amount`,`method`,`reference`,`received_at`,`received_by`,`created_at`,`updated_at`) VALUES
(1,10000.00,'upi','UPI-TXN-ALB-1001',NOW(),5,NOW(),NOW());

INSERT INTO `unknown_calls`
(`call_id`,`direction`,`from_phone`,`to_phone`,`agent_extension`,`started_at`,`duration_sec`,`recording_url`,`disposition`,`created_at`,`updated_at`) VALUES
('fx_unknown_001','inbound','919999999999','914841234567','1401',NOW(),48,NULL,'missed',NOW(),NOW());

INSERT INTO `audit_logs`
(`actor_id`,`action`,`entity_type`,`entity_id`,`old_values`,`new_values`,`ip`,`user_agent`,`created_at`,`updated_at`) VALUES
(6,'lead.stage_change','lead',1,JSON_OBJECT('stage_id',1),JSON_OBJECT('stage_id',2),'127.0.0.1','Seeder',NOW(),NOW()),
(7,'lead.activity.create','lead_activity',2,NULL,JSON_OBJECT('outcome','Assessment Booked'),'127.0.0.1','Seeder',NOW(),NOW()),
(5,'enrollment.confirm','enrollment',1,NULL,JSON_OBJECT('admission_status','DP'),'127.0.0.1','Seeder',NOW(),NOW());

-- -----------------------------------------------------
-- Optional migration ledger seed
-- -----------------------------------------------------
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`,`batch`) VALUES
('0001_01_01_000000_create_users_table',1),
('0001_01_01_000001_create_cache_table',1),
('0001_01_01_000002_create_jobs_table',1),
('2026_05_07_055508_create_personal_access_tokens_table',1),
('2026_05_07_055511_create_roles_table',1),
('2026_05_07_055511_create_leads_table',1),
('2026_05_07_055511_create_lead_stages_table',1),
('2026_05_07_055511_create_lead_stage_transitions_table',1),
('2026_05_07_055511_create_lead_activities_table',1),
('2026_05_07_055511_create_assessments_table',1),
('2026_05_07_055511_create_enrollments_table',1),
('2026_05_07_055511_create_payments_table',1),
('2026_05_07_055511_create_attendance_logs_table',1),
('2026_05_07_055511_create_audit_logs_table',1),
('2026_05_07_055512_create_unknown_calls_table',1),
('2026_05_07_055512_create_tasks_table',1),
('2026_05_07_060147_create_permissions_tables',1);

SET FOREIGN_KEY_CHECKS = 1;
