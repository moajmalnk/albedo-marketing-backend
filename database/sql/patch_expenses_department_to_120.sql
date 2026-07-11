-- Align expenses.department with departments.name (VARCHAR 120).
-- Run if long department labels fail validation or are truncated.
--   mysql -h HOST -u USER -p DATABASE < backend/database/sql/patch_expenses_department_to_120.sql

ALTER TABLE `expenses` MODIFY `department` VARCHAR(120) NULL;
