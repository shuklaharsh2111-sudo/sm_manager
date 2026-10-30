-- SME SOP tick marks: one row per ticked SOP per day / week / month.
--
-- OPTIONAL. The dashboard creates this table automatically the first time it is
-- opened. Run this file in phpMyAdmin (database `creative_manager`) only if the
-- MySQL user used by the app is not allowed to CREATE tables.
--
-- period_key: daily = 2026-10-29, weekly = 2026-W44, monthly = 2026-10
-- A new key starts every day / week / month, so ticks "refresh" by themselves
-- while the old rows stay as history on the Saved page.

CREATE TABLE IF NOT EXISTS `sme_sop_completions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sme_user_id` int(11) NOT NULL,
  `sop_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL DEFAULT 0,
  `sop_type` varchar(10) NOT NULL,
  `period_key` varchar(12) NOT NULL,
  `sop_title` varchar(255) NOT NULL DEFAULT '',
  `company_name` varchar(255) NOT NULL DEFAULT '',
  `completed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_sop_period` (`sme_user_id`,`sop_id`,`period_key`),
  KEY `idx_user_done` (`sme_user_id`,`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
