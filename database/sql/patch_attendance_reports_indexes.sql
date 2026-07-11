-- Optional: speeds admin attendance reports filtered by day_date then user_id.
-- Safe to run once on MySQL 8+ if the composite index is not already present from migrations.
-- Laravel migration: 2026_05_15_120000_add_attendance_logs_day_date_user_id_index.php

CREATE INDEX `attendance_logs_day_date_user_id_index`
  ON `attendance_logs` (`day_date`, `user_id`);
