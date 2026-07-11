-- Add capture_qualification and allow NULL student_name (MySQL/MariaDB).
-- Run when migrations are not used on the server.
SET NAMES utf8mb4;

ALTER TABLE `leads`
  ADD COLUMN `capture_qualification` VARCHAR(32) NOT NULL DEFAULT 'qualified' AFTER `student_name`;

ALTER TABLE `leads` MODIFY `student_name` VARCHAR(160) NULL;
