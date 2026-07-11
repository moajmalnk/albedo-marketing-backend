-- Hotfix: older albedo_crm_full_with_mock.sql claimed password "Admin@12345" but used a
-- bcrypt string that does NOT verify (PHP password_verify returns false).
-- Run this against the database your Laravel API uses (local or production).
--
-- Plain text password after patch: Admin@12345
-- Applies only to the eight mock CRM accounts from the seed dump.

SET NAMES utf8mb4;

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
