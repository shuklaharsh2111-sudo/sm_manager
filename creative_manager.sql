-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 28, 2026 at 01:53 PM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `creative_manager`
--

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `comp_name` varchar(255) DEFAULT NULL,
  `comp_logo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `comp_name`, `comp_logo`) VALUES
(1, 'ABcd', 'e39b66951427ffe4ffe1a730275d3c68.png'),
(2, 'NovaTech Solutions', 'img1.jpg'),
(3, 'PixelCraft Media', 'img2.jpg'),
(4, 'BrightWave Digital', 'img3.jpg'),
(5, 'Aether Brands', 'img4.jpg'),
(6, 'Lumina Creative', 'img5.jpg'),
(7, 'Orbit Marketing', 'img6.jpg'),
(8, 'Vertex Studios', 'img7.jpg'),
(9, 'Echo Social', 'img8.jpg'),
(10, 'Summit Agency', 'img9.jpg'),
(11, 'Forge Digital', 'img10.jpg'),
(12, 'Horizon Ads', 'img11.jpg'),
(13, 'Spark Content', 'img12.jpg'),
(14, 'Nexus Growth', 'e39b66951427ffe4ffe1a730275d3c68.png'),
(15, 'Pulse Creatives', 'f88c3f61b579038667575510359ce555.png'),
(16, 'Crest Media House', 'img1.jpg'),
(17, 'Metrics Mart', '48038666dc429318c47c2ad487ca853f.jpg'),
(18, 'RedSea', NULL),
(19, 'Thunga International', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `company_sops`
--

CREATE TABLE `company_sops` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `sop_type` enum('daily','weekly','monthly') NOT NULL,
  `sop_title` varchar(255) NOT NULL,
  `sop_description` text DEFAULT NULL,
  `sop_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_sops`
--

INSERT INTO `company_sops` (`id`, `company_id`, `sop_type`, `sop_title`, `sop_description`, `sop_date`, `created_at`, `updated_at`) VALUES
(1, 18, 'daily', 'Nilesh Daily Sop1', NULL, '2026-10-16', '2026-10-15 10:46:49', '2026-10-15 10:46:49'),
(2, 18, 'daily', 'Nilesh Daily Sop2', NULL, '2026-10-17', '2026-10-15 10:46:49', '2026-10-15 10:46:49'),
(3, 18, 'weekly', 'Nilesh Weekly Sop1', NULL, '2026-10-22', '2026-10-15 10:46:49', '2026-10-15 10:46:49'),
(4, 18, 'weekly', 'Nilesh Weekly Sop2', NULL, '2026-10-29', '2026-10-15 10:46:49', '2026-10-15 10:46:49'),
(5, 18, 'monthly', 'Nilesh Monthly Sop1', NULL, '2026-11-15', '2026-10-15 10:46:49', '2026-10-15 10:46:49'),
(6, 19, 'weekly', 'nilesh thunga internations weekly sop', '', '2026-11-05', '2026-10-15 11:55:06', '2026-10-15 11:55:06');

-- --------------------------------------------------------

--
-- Table structure for table `creatives`
--

CREATE TABLE `creatives` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `sop_id` int(11) DEFAULT NULL,
  `platform` enum('instagram','facebook','linkedin','google','youtube','x','pinterest','threads') NOT NULL,
  `creative_date` date NOT NULL,
  `title` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `reference_link` varchar(2048) DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `status` enum('draft','pending','approved','rejected','revision') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `revision_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `creatives`
--

INSERT INTO `creatives` (`id`, `user_id`, `company_id`, `sop_id`, `platform`, `creative_date`, `title`, `caption`, `description`, `image_path`, `reference_link`, `keywords`, `status`, `created_at`, `revision_reason`) VALUES
(1, 1, NULL, NULL, 'google', '2026-10-01', 'asdf', 'checking backend', 'hii hello', 'uploads/creatives/662f2feca0b6f619ac304c4091c69817.jpg', NULL, NULL, '', '2026-10-13 11:34:09', NULL),
(2, 22, 19, 6, 'facebook', '2026-11-05', 'Testing the title fullstack', 'hii hellow, testing the fullstack here on the creative.', '', 'uploads/creatives/8b8077913dfd9cb6ebd887203d571682.jpg', 'https://abc.om', 'hii, hellow, how', 'approved', '2026-10-19 10:54:30', NULL),
(3, 22, 18, 3, 'instagram', '2026-10-22', 'you can give any', 'hii hello how are', '', 'uploads/creatives/2c9151fa3e5a49d5f92f68cdc5ecce9a.png', 'https://creativemanager.site.je', 'testing for calendar', 'pending', '2026-10-21 06:46:52', NULL),
(4, 22, 18, 2, 'instagram', '2026-10-17', 'creative regarding ads', 'the creative should look like attractive, eye catching and every beautiful', '', 'uploads/creatives/e397ede6eee5b365e6581ab213398c69.png', 'https://pinterest.com', 'hii, hello, testing, etc, etc', 'revision', '2026-10-26 11:13:25', 'this is a little bit not attractive and eye catching.');

-- --------------------------------------------------------

--
-- Table structure for table `creative_smi_assignments`
--

CREATE TABLE `creative_smi_assignments` (
  `id` int(10) UNSIGNED NOT NULL,
  `creative_id` int(11) NOT NULL,
  `smi_user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_type` enum('PFI','TI') NOT NULL,
  `invoice_number` varchar(60) NOT NULL,
  `invoice_reference` varchar(60) NOT NULL,
  `bill_to` varchar(255) NOT NULL,
  `company_contact` varchar(255) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `company_pan` varchar(10) DEFAULT NULL,
  `ship_to` text NOT NULL,
  `issue_date` date NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ship_gstin` varchar(20) DEFAULT NULL,
  `terms` varchar(255) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `subtotal_amount` decimal(12,2) DEFAULT NULL,
  `gst_amount` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `invoice_type`, `invoice_number`, `invoice_reference`, `bill_to`, `company_contact`, `gstin`, `company_pan`, `ship_to`, `issue_date`, `total_amount`, `created_at`, `ship_gstin`, `terms`, `due_date`, `notes`, `subtotal_amount`, `gst_amount`) VALUES
(1, 'TI', 'TI - 0124', 'INV - 000124', 'ABCD Company', NULL, '123789456369741', '123ASD456W', 'ABCD efgh', '2026-10-16', 17500.00, '2026-10-16 08:14:16', '123789456369741', 'Extended due will be charged extra', '2026-10-25', 'Thanks for choosing us', NULL, 0.00),
(2, 'PFI', 'PFI - 0001', 'INV - 000002', 'ABCD Company', NULL, '456789123369874', NULL, 'asdf123', '2026-10-16', 17000.00, '2026-10-16 08:37:35', NULL, 'Due on reciept', '2026-10-22', 'Do not forget to pay on this time.', NULL, 0.00),
(3, 'PFI', 'PFI - 0002', 'INV - 000003', 'ABCD Updated Company', NULL, '123789456369741', 'ABCDE1234A', 'ABCD efgh', '2026-10-16', 17500.00, '2026-10-16 11:06:36', '123789456369741', 'Extended due will be charged extra', '2026-11-20', 'Thanks for choosing us', 17500.00, 0.00),
(4, 'TI', 'TI - 0125', 'INV - 000125', 'testing gst pricing', NULL, '123456789258016', 'ASDFG3634A', 'Veera desai industrial estate rode', '2026-10-16', 1770.00, '2026-10-16 13:20:07', '123456789258016', 'Due on reciept', '2026-10-16', 'thanks connecting to us', 1500.00, 270.00),
(5, 'PFI', 'PFI - 0003', 'INV - 000004', 'asdf', '1234567890', '964785133445934', 'ABCDE1234S', 'asdf', '2026-10-17', 2200.00, '2026-10-17 07:25:46', '964785133445934', 'asdf', '2026-10-22', 'Hii hello testing no refreshing', 2200.00, 0.00),
(6, 'PFI', 'PFI - 0004', 'INV - 000005', 'asdf', 'Harsh Shukla 1234567890', '964785133445934', 'ABCDE1234S', 'ASDFTEST', '2026-10-17', 900.00, '2026-10-17 08:19:24', '964785133445934', 'TEST', '2026-10-20', 'HII', 900.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_counters`
--

CREATE TABLE `invoice_counters` (
  `invoice_type` enum('PFI','TI') NOT NULL,
  `next_number` int(10) UNSIGNED NOT NULL,
  `next_reference` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_counters`
--

INSERT INTO `invoice_counters` (`invoice_type`, `next_number`, `next_reference`) VALUES
('PFI', 5, 6),
('TI', 126, 126);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `invoice_id` bigint(20) UNSIGNED NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `rate` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`id`, `invoice_id`, `item_name`, `description`, `quantity`, `rate`, `amount`) VALUES
(1, 1, 'Social Media management', NULL, 1.00, 5000.00, 5000.00),
(2, 1, 'Marketing', 'Running Ads', 5.00, 2500.00, 12500.00),
(3, 2, 'Creative Generation', 'All are inclusive in one', 1.00, 5000.00, 5000.00),
(4, 2, 'Social Media management', NULL, 3.00, 1800.00, 5400.00),
(5, 2, 'Meta Ads', 'Running Ads', 3.00, 2200.00, 6600.00),
(8, 4, 'Meta Ads', 'Running ads on insta and facebook', 1.00, 1500.00, 1500.00),
(12, 3, 'Social Media management', NULL, 1.00, 5000.00, 5000.00),
(13, 3, 'Marketing', 'Running Ads', 5.00, 2500.00, 12500.00),
(14, 5, 'first one', 'hii hellow let\'s see if it\'s visible without refreshing', 1.00, 2200.00, 2200.00),
(16, 6, 'first one', 'hii hellow let\'s see if it\'s visible without refreshing', 1.00, 900.00, 900.00);

-- --------------------------------------------------------

--
-- Table structure for table `sme_company_assignments`
--

CREATE TABLE `sme_company_assignments` (
  `id` int(11) NOT NULL,
  `sme_user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sme_company_assignments`
--

INSERT INTO `sme_company_assignments` (`id`, `sme_user_id`, `company_id`, `assigned_by`, `assigned_at`) VALUES
(1, 3, 1, 2, '2026-10-15 06:09:41'),
(2, 3, 2, 1, '2026-10-15 01:30:00'),
(3, 9, 3, 2, '2026-10-15 01:35:00'),
(4, 10, 4, 1, '2026-10-15 01:40:00'),
(5, 17, 5, 2, '2026-10-15 01:45:00'),
(6, 3, 6, 7, '2026-10-15 01:50:00'),
(7, 9, 7, 8, '2026-10-15 01:55:00'),
(8, 10, 8, 1, '2026-10-15 02:00:00'),
(9, 17, 9, 2, '2026-10-15 02:05:00'),
(10, 3, 10, 7, '2026-10-15 02:10:00'),
(11, 9, 11, 8, '2026-10-15 02:15:00'),
(12, 10, 12, 1, '2026-10-15 02:20:00'),
(13, 17, 13, 2, '2026-10-15 02:25:00'),
(14, 3, 14, 7, '2026-10-15 02:30:00'),
(15, 9, 15, 8, '2026-10-15 02:35:00'),
(16, 10, 16, 1, '2026-10-15 02:40:00'),
(17, 22, 18, 2, '2026-10-15 10:46:49'),
(18, 22, 19, 2, '2026-10-15 11:22:11');

-- --------------------------------------------------------

--
-- Table structure for table `sme_fathom_keys`
--

CREATE TABLE `sme_fathom_keys` (
  `user_id` int(11) NOT NULL,
  `api_key_enc` text NOT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sme_fathom_sync`
--

CREATE TABLE `sme_fathom_sync` (
  `id` tinyint(4) NOT NULL,
  `last_synced_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sme_meetings`
--

CREATE TABLE `sme_meetings` (
  `id` int(11) NOT NULL,
  `sme_user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `meeting_date` date NOT NULL,
  `meeting_time` time NOT NULL,
  `meeting_link` varchar(2048) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `meeting_status` enum('not_done','done') NOT NULL DEFAULT 'not_done',
  `points_discussed` text DEFAULT NULL,
  `next_steps` text DEFAULT NULL,
  `next_meeting_date` date DEFAULT NULL,
  `next_meeting_time` time DEFAULT NULL,
  `next_meeting_agenda` varchar(500) DEFAULT NULL,
  `next_meeting_attendees` varchar(500) DEFAULT NULL,
  `mom_status` varchar(12) NOT NULL DEFAULT 'none',
  `mom_sent_at` datetime DEFAULT NULL,
  `mom_reviewed_at` datetime DEFAULT NULL,
  `mom_reviewed_by` int(11) DEFAULT NULL,
  `mom_admin_note` varchar(500) DEFAULT NULL,
  `meeting_ended_at` datetime DEFAULT NULL,
  `details_saved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sme_meetings`
--

INSERT INTO `sme_meetings` (`id`, `sme_user_id`, `company_id`, `subject`, `meeting_date`, `meeting_time`, `meeting_link`, `created_at`, `meeting_status`, `points_discussed`, `next_steps`, `next_meeting_date`, `next_meeting_time`, `next_meeting_agenda`, `next_meeting_attendees`, `mom_status`, `mom_sent_at`, `mom_reviewed_at`, `mom_reviewed_by`, `mom_admin_note`, `meeting_ended_at`, `details_saved_at`) VALUES
(1, 22, 18, 'Meeting regarding creative', '2026-11-21', '17:00:00', NULL, '2026-10-15 12:37:19', 'not_done', NULL, NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(2, 3, 14, 'Creative Rev', '2026-10-13', '16:00:00', 'https://meet.google.com/mfp-qkxp-qpp', '2026-10-15 13:18:40', 'not_done', NULL, NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(3, 22, 19, 'testing', '2026-10-20', '14:00:00', 'https://meet.google.com/mfp-qkxp-qpp', '2026-10-15 13:22:06', 'done', '[\"wefradsfv\"]', NULL, NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, '2026-10-28 16:59:45'),
(4, 22, 18, 'subjct', '2026-10-30', '12:34:00', 'https://meet.jit.si/Subjct-47a1cf5c6a83', '2026-10-28 08:11:47', 'not_done', '[\"wefradsfv\",\"xscdvf\"]', '[{\"text\":\"adsfg\",\"owner\":\"Amit\",\"due_date\":\"0343-02-12\"}]', NULL, NULL, NULL, NULL, 'none', NULL, NULL, NULL, NULL, NULL, NULL),
(5, 22, 18, 'harsh', '2026-10-28', '15:38:00', 'https://meet.jit.si/Harsh-d5d401ee2ba8', '2026-10-28 10:13:46', 'done', '[\"jnjnjnj\"]', NULL, NULL, NULL, NULL, NULL, 'approved', '2026-10-28 16:27:44', '2026-10-28 16:49:40', 2, NULL, '2026-10-28 16:25:25', '2026-10-28 16:51:03'),
(6, 22, 19, 'harsh', '2026-10-28', '16:00:00', 'https://meet.jit.si/Harsh-ecfd15e80c3b', '2026-10-28 10:24:16', 'done', NULL, NULL, NULL, NULL, NULL, NULL, 'approved', '2026-10-28 16:40:50', '2026-10-28 16:49:37', 2, NULL, NULL, NULL),
(7, 22, 19, 'subjct', '2026-10-28', '16:05:00', 'https://meet.google.com/xqs-pbcu-ycb', '2026-10-28 10:30:33', 'done', NULL, NULL, NULL, NULL, NULL, NULL, 'approved', '2026-10-28 17:12:46', '2026-10-28 17:13:38', 2, NULL, NULL, NULL),
(8, 22, 18, 'ad', '2026-10-28', '18:16:00', 'https://meet.jit.si/Ad-d0f74b0da7e3', '2026-10-28 12:46:25', 'done', '[\"sss\"]', NULL, NULL, NULL, NULL, NULL, 'approved', '2026-10-28 18:16:51', '2026-10-28 18:17:13', 2, NULL, NULL, '2026-10-28 18:16:51');

-- --------------------------------------------------------

--
-- Table structure for table `sme_meeting_attendees`
--

CREATE TABLE `sme_meeting_attendees` (
  `id` int(11) NOT NULL,
  `meeting_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `designation` varchar(150) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sme_meeting_attendees`
--

INSERT INTO `sme_meeting_attendees` (`id`, `meeting_id`, `user_id`, `name`, `designation`, `created_at`) VALUES
(1, 4, 20, 'Amit', 'add', '2026-10-28 08:11:47'),
(2, 4, 7, 'Ananya', 'dsdds', '2026-10-28 08:11:47'),
(5, 6, 26, 'Amit Verma', 'yup', '2026-10-28 10:24:16'),
(6, 6, NULL, 'harsh', 'yup', '2026-10-28 10:24:16'),
(7, 7, NULL, 'harsh', 'yup', '2026-10-28 10:30:33'),
(8, 7, NULL, 'shu', 'yup', '2026-10-28 10:30:33'),
(11, 5, 26, 'Amit Verma', 'yup', '2026-10-28 11:21:03'),
(12, 3, 5, 'Bhavesh', 'yup', '2026-10-28 11:29:45'),
(13, 8, 20, 'Amit', 'yup', '2026-10-28 12:46:51');

-- --------------------------------------------------------

--
-- Table structure for table `sme_meeting_recordings`
--

CREATE TABLE `sme_meeting_recordings` (
  `id` int(11) NOT NULL,
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
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sme_taskmaster_items`
--

CREATE TABLE `sme_taskmaster_items` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `card` varchar(10) NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `link_url` varchar(2000) DEFAULT NULL,
  `stored_name` varchar(80) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `is_image` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `brand_logo` varchar(255) DEFAULT NULL,
  `fullname` varchar(255) DEFAULT NULL,
  `prof_image` varchar(255) DEFAULT NULL,
  `contact` varchar(13) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`role`)),
  `gstin` varchar(20) DEFAULT NULL,
  `reporting_manager` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `brand_name`, `brand_logo`, `fullname`, `prof_image`, `contact`, `email`, `password`, `role`, `gstin`, `reporting_manager`) VALUES
(1, 'unstoppable solutions', NULL, 'Main Admin', NULL, '9876543210', 'mainadmin123@gmail.com', '$2y$10$n/heirdRatPWfuU.Oq7HueX8fgZIPSwyzbUp26yxQPlN8/rQatcky', '[\"admin\"]', NULL, NULL),
(2, 'unstoppable solutions', NULL, 'Mithilesh', NULL, '0123456789', 'mithilesh123@gmail.com', '$2y$10$j7Fj0MiQ8JzXsJTqgtZ8NOYX95iUwhqjQ696hBepEk.3oTU8fM/Pe', '[\"admin\"]', NULL, NULL),
(3, 'Unstoppable solutions', NULL, 'Falguni', NULL, '1234567896', 'falguni123@gmail.com', '$2y$10$qhRVl71M/ym0ioq8h7GYaeouxRfN.jSmtGDjOzeOCBU3UUBmYWT2i', '[\"sme\"]', NULL, NULL),
(4, 'Unstoppable solutions', NULL, 'Divesh', NULL, '9632587410', 'divesh123@gmail.com', '$2y$10$ssuzWzF6FAqMYq1dInV0v.z37wibTsi5OCwb4wMxgRU.7l23m6KOO', '[\"des\"]', NULL, NULL),
(5, 'Unstoppable solutions', NULL, 'Bhavesh', NULL, '1478523690', 'bhavesh123@gmail.com', '$2y$10$yxDlfJorj/X3IPT7SPGls.Jf/YPUKzr/Mw8dhosw1cJP/JL1z4rUG', '[\"smi\"]', NULL, NULL),
(6, 'Unstoppable Solutions', NULL, 'Pankaj', 'f88c3f61b579038667575510359ce555.png', '4567891230', 'pankaj123@gmail.com', '$2y$10$xmbbCp6LtI2aWqGGzShSau7ZhIC.FimZ2KF33uiVe4kL4WekVkODe', '[\"clients\"]', NULL, NULL),
(7, 'Unstoppable Solutions', NULL, 'Ananya', 'img3.jpg', '9876501234', 'ananya123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"admin\"]', NULL, NULL),
(8, 'Unstoppable Solutions', NULL, 'Rohan', 'img4.jpg', '9876501235', 'rohan123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"admin\"]', NULL, NULL),
(9, 'Unstoppable Solutions', NULL, 'Priya', 'img5.jpg', '9876501236', 'priya123@gmail.com', '$2y$10$naQ1NNQPb5EsoF6LsD4kcelEM0.PRxQ50C2Et1ASd8zydBJBp2POq', '[\"sme\"]', NULL, NULL),
(10, 'Unstoppable Solutions', NULL, 'Arjun', 'img6.jpg', '9876501237', 'arjun123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"sme\"]', NULL, NULL),
(11, 'Unstoppable Solutions', NULL, 'Sneha', 'img7.jpg', '9876501238', 'sneha123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"des\"]', NULL, NULL),
(12, 'Unstoppable Solutions', NULL, 'Vikram', 'img8.jpg', '9876501239', 'vikram123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"des\"]', NULL, NULL),
(13, 'Unstoppable Solutions', NULL, 'Meera', 'img9.jpg', '9876501240', 'meera123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"smi\"]', NULL, NULL),
(14, 'Unstoppable Solutions', NULL, 'Karan', 'img10.jpg', '9876501241', 'karan123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"smi\"]', NULL, NULL),
(15, 'Unstoppable Solutions', NULL, 'Isha', 'img11.jpg', '9876501242', 'isha123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"clients\"]', NULL, NULL),
(16, 'Unstoppable Solutions', NULL, 'Rahul', 'img12.jpg', '9876501243', 'rahul123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"clients\"]', NULL, NULL),
(17, 'Unstoppable Solutions', NULL, 'Neha', 'img1.jpg', '9876501244', 'neha123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"sme\"]', NULL, NULL),
(18, 'Unstoppable Solutions', NULL, 'Siddharth', 'img2.jpg', '9876501245', 'siddharth123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"des\"]', NULL, NULL),
(19, 'Unstoppable Solutions', NULL, 'Pooja', 'img3.jpg', '9876501246', 'pooja123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"smi\"]', NULL, NULL),
(20, 'Unstoppable Solutions', NULL, 'Amit', 'img4.jpg', '9876501247', 'amit123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"clients\"]', NULL, NULL),
(21, 'Unstoppable Solutions', NULL, 'Kavya', 'img5.jpg', '9876501248', 'kavya123@gmail.com', '$2y$10$abcdefghijklmnopqrstuvwx1234567890ABCDEFGHIJKLMNOP', '[\"admin\"]', NULL, NULL),
(22, 'Unstoppable Solutions', NULL, 'Nilesh Prajapati', '1a249662a4912eac79fc61a6142f2d1f.png', '1234567890', 'nilesh123@gmail.com', '$2y$12$RRLe.8oGhOs1xE5yyVjCNuzOZtCicu40MMh8.7RHWEUlO5B17U.RG', '[\"sme\"]', NULL, 'Karan Mehta'),
(23, 'Unstoppable Solutions', NULL, 'abcd testing', '1a7b6dcdc33a0abe9d59190b13b36383.jpg', '4563219870', 'testing123@gmail.com', '$2y$10$wKkZc3bwpGlnyV5rcQxES.ScOS1b6w7szoPJrRa9Tuej0vZMmKoSu', '[\"smi\"]', NULL, NULL),
(24, 'Unstoppable Solutions', NULL, 'Rahul Sharma', NULL, '9876500001', 'rahul.sharma@example.com', NULL, '[\"reporting_manager\"]', NULL, NULL),
(25, 'Unstoppable Solutions', NULL, 'Priya Patel', NULL, '9876500002', 'priya.patel@example.com', NULL, '[\"reporting_manager\"]', NULL, NULL),
(26, 'Unstoppable Solutions', NULL, 'Amit Verma', NULL, '9876500003', 'amit.verma@example.com', NULL, '[\"reporting_manager\"]', NULL, NULL),
(27, 'Unstoppable Solutions', NULL, 'Sneha Joshi', NULL, '9876500004', 'sneha.joshi@example.com', NULL, '[\"reporting_manager\"]', NULL, NULL),
(28, 'Unstoppable Solutions', NULL, 'Karan Mehta', NULL, '9876500005', 'karan.mehta@example.com', NULL, '[\"reporting_manager\"]', NULL, NULL),
(29, 'Unstoppable Solutions', NULL, 'hamza ali mazari', NULL, '9873216540', 'hamza123@gmail.com', '$2y$10$aQosXV5MtU/kBxjL42cCBu0wgEQ/cX/bQ/FiKpMmp9Rr42YqfSVzu', '[\"sme\",\"smi\"]', NULL, 'Karan Mehta'),
(30, 'Unstoppable Solutions', NULL, 'pankaj rauniyar', NULL, '4569873210', 'pankaj123@gmail.com', '$2y$10$b1gVbN0dXhAwm363/cJAAumnXfTonE0SlhBxJi.PDo07tNvWPl05i', '[\"des\"]', NULL, 'Rahul Sharma'),
(31, 'Unstoppable Solutions', NULL, 'pankaj rauniyar', NULL, '4569873210', 'pankaj123@gmail.com', '$2y$10$MmxFpsUEESrjjOcn0KvUTuVxD1r7x6Bpk4SVPevyIWR6k80xRet72', '[\"des\"]', NULL, 'Rahul Sharma'),
(32, 'Unstoppable solutions', NULL, 'Shushant Gautam', NULL, '9348875632', 'shushant123@gmail.com', '$2y$10$AjJ3WuXx9e3.w1Ip9fJql.bohj5KsCAauFktN71ctkNV2eQuOAm8O', '[\"smi\"]', NULL, 'Priya Patel'),
(33, 'brand', NULL, 'brad', NULL, '1234567885', 'brad@gmail.com', '$2y$12$1LLdVsLECSVq6nz9xR8kfO8jBkzQO4WY9tlVPGmG3aHKk09ryrPFC', '[\"sme\"]', NULL, 'Priya Patel'),
(34, 'new', NULL, 'new', NULL, '1234567884', 'new@gmail.com', '$2y$12$OPFg5gAFpEz90bJcUm2F8eHWoDX2OG/NMM3h2Iw1CUPFseWjSVsJm', '[\"smi\"]', NULL, 'Rahul Sharma');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `user_id` int(11) NOT NULL,
  `notify_approved` tinyint(1) NOT NULL DEFAULT 1,
  `notify_rejected` tinyint(1) NOT NULL DEFAULT 1,
  `notify_revision` tinyint(1) NOT NULL DEFAULT 1,
  `notify_meeting` tinyint(1) NOT NULL DEFAULT 1,
  `clock_format` varchar(3) NOT NULL DEFAULT '24h',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `company_sops`
--
ALTER TABLE `company_sops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_sop` (`company_id`,`sop_type`,`sop_title`);

--
-- Indexes for table `creatives`
--
ALTER TABLE `creatives`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_creatives_platform_date` (`platform`,`creative_date`),
  ADD KEY `idx_creatives_user_id` (`user_id`);

--
-- Indexes for table `creative_smi_assignments`
--
ALTER TABLE `creative_smi_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_creative_assignment` (`creative_id`),
  ADD KEY `idx_smi_user_id` (`smi_user_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `invoice_reference` (`invoice_reference`),
  ADD KEY `idx_invoice_date` (`issue_date`),
  ADD KEY `idx_invoice_type` (`invoice_type`);

--
-- Indexes for table `invoice_counters`
--
ALTER TABLE `invoice_counters`
  ADD PRIMARY KEY (`invoice_type`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_invoice_items_invoice` (`invoice_id`);

--
-- Indexes for table `sme_company_assignments`
--
ALTER TABLE `sme_company_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_company_assignment` (`company_id`),
  ADD UNIQUE KEY `unique_sme_company` (`sme_user_id`,`company_id`),
  ADD KEY `idx_sme_user_id` (`sme_user_id`);

--
-- Indexes for table `sme_fathom_keys`
--
ALTER TABLE `sme_fathom_keys`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `sme_fathom_sync`
--
ALTER TABLE `sme_fathom_sync`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sme_meetings`
--
ALTER TABLE `sme_meetings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sme_meetings_user_date` (`sme_user_id`,`meeting_date`,`meeting_time`),
  ADD KEY `idx_sme_meetings_company` (`company_id`);

--
-- Indexes for table `sme_meeting_attendees`
--
ALTER TABLE `sme_meeting_attendees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_meeting` (`meeting_id`);

--
-- Indexes for table `sme_meeting_recordings`
--
ALTER TABLE `sme_meeting_recordings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_recording` (`sme_user_id`,`recording_id`),
  ADD KEY `idx_user_started` (`sme_user_id`,`started_at`);

--
-- Indexes for table `sme_taskmaster_items`
--
ALTER TABLE `sme_taskmaster_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_card` (`user_id`,`card`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `company_sops`
--
ALTER TABLE `company_sops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `creatives`
--
ALTER TABLE `creatives`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `creative_smi_assignments`
--
ALTER TABLE `creative_smi_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `sme_company_assignments`
--
ALTER TABLE `sme_company_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `sme_meetings`
--
ALTER TABLE `sme_meetings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `sme_meeting_attendees`
--
ALTER TABLE `sme_meeting_attendees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `sme_meeting_recordings`
--
ALTER TABLE `sme_meeting_recordings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sme_taskmaster_items`
--
ALTER TABLE `sme_taskmaster_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `company_sops`
--
ALTER TABLE `company_sops`
  ADD CONSTRAINT `fk_company_sops_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `creatives`
--
ALTER TABLE `creatives`
  ADD CONSTRAINT `fk_creatives_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sme_company_assignments`
--
ALTER TABLE `sme_company_assignments`
  ADD CONSTRAINT `fk_assignment_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assignment_sme` FOREIGN KEY (`sme_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
