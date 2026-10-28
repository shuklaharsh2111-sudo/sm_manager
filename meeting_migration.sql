-- SME meetings: attendees + points discussed (added by the SME after the admin approves the MOM), details_saved_at and next meeting.
-- OPTIONAL. sme/meeting_helpers.php creates all of this automatically on first use.
-- Run in phpMyAdmin (database `creative_manager`) only if the MySQL user cannot CREATE / ALTER tables.
-- Skip any ALTER line whose column already exists.

CREATE TABLE IF NOT EXISTS `sme_meeting_attendees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `meeting_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `designation` varchar(150) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_meeting` (`meeting_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `sme_meetings`
  ADD COLUMN `points_discussed` TEXT NULL,
  ADD COLUMN `details_saved_at` DATETIME NULL,
  ADD COLUMN `next_meeting_date` DATE NULL,
  ADD COLUMN `next_meeting_time` TIME NULL,
  ADD COLUMN `next_meeting_agenda` VARCHAR(500) NULL,
  ADD COLUMN `next_meeting_attendees` VARCHAR(500) NULL,
  ADD COLUMN `mom_status` VARCHAR(12) NOT NULL DEFAULT 'none',
  ADD COLUMN `mom_sent_at` DATETIME NULL,
  ADD COLUMN `mom_reviewed_at` DATETIME NULL,
  ADD COLUMN `mom_reviewed_by` INT NULL,
  ADD COLUMN `mom_admin_note` VARCHAR(500) NULL;
