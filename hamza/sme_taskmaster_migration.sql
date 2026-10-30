-- SME Task Master: Pitch Deck and Mood Board uploads and links.
-- OPTIONAL. sme/taskmaster_api.php creates this table automatically on first use.
-- Run in phpMyAdmin (database `creative_manager`) only if the MySQL user cannot CREATE tables.

CREATE TABLE IF NOT EXISTS `sme_taskmaster_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `card` varchar(10) NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `link_url` varchar(2000) DEFAULT NULL,
  `stored_name` varchar(80) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `is_image` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_card` (`user_id`,`card`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
