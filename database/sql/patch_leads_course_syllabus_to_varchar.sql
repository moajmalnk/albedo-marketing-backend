-- -----------------------------------------------------------------------------
-- Alter leads.course / leads.syllabus from ENUM to VARCHAR(191) (MySQL/MariaDB)
-- Run after migrations or when ENUM blocks new picklist values from Settings.
-- Idempotent on MySQL 8+: MODIFY is reapplied; safe if already VARCHAR.
-- -----------------------------------------------------------------------------
SET NAMES utf8mb4;

ALTER TABLE `leads` MODIFY `syllabus` VARCHAR(191) NULL;
ALTER TABLE `leads` MODIFY `course` VARCHAR(191) NULL;
