-- Fathom recordings. OPTIONAL: created automatically on first use.
CREATE TABLE IF NOT EXISTS `sme_fathom_sync` (
  `id` tinyint(4) NOT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sme_meeting_recordings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sme_user_id` int(11) NOT NULL,
  `recording_id` varchar(64) NOT NULL,
  `meeting_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  `recording_url` varchar(2048) NOT NULL DEFAULT '',
  `share_url` varchar(2048) NOT NULL DEFAULT '',
  `summary` mediumtext DEFAULT NULL,
  `action_items` mediumtext DEFAULT NULL,
  `invitees` mediumtext DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_recording` (`sme_user_id`,`recording_id`),
  KEY `idx_user_started` (`sme_user_id`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
