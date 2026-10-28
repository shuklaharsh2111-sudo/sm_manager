-- SME Settings page: stores each user's notification and display preferences.
--
-- OPTIONAL. settings.php creates this table automatically the first time the
-- Settings page is opened. Run this file in phpMyAdmin (database
-- `creative_manager`) only if you prefer to create it yourself, for example
-- when the MySQL user used by the app is not allowed to CREATE tables.
--
-- Profile name, email, contact number, profile photo and password use the
-- existing `users` table, so no other change is needed.

CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id` int(11) NOT NULL,
  `notify_approved` tinyint(1) NOT NULL DEFAULT 1,
  `notify_rejected` tinyint(1) NOT NULL DEFAULT 1,
  `notify_revision` tinyint(1) NOT NULL DEFAULT 1,
  `notify_meeting` tinyint(1) NOT NULL DEFAULT 1,
  `clock_format` varchar(3) NOT NULL DEFAULT '24h',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
