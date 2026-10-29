-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 29, 2026 at 07:31 AM
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

-- --------------------------------------------------------

--
-- Table structure for table `creative_des_assignments`
--

CREATE TABLE `creative_des_assignments` (
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

-- --------------------------------------------------------

--
-- Table structure for table `invoice_counters`
--

CREATE TABLE `invoice_counters` (
  `invoice_type` enum('PFI','TI') NOT NULL,
  `next_number` int(10) UNSIGNED NOT NULL,
  `next_reference` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `next_meeting_date` date DEFAULT NULL,
  `next_meeting_time` time DEFAULT NULL,
  `next_meeting_agenda` varchar(500) DEFAULT NULL,
  `next_meeting_attendees` varchar(500) DEFAULT NULL,
  `details_saved_at` datetime DEFAULT NULL,
  `mom_status` varchar(12) NOT NULL DEFAULT 'none',
  `mom_sent_at` datetime DEFAULT NULL,
  `mom_reviewed_at` datetime DEFAULT NULL,
  `mom_reviewed_by` int(11) DEFAULT NULL,
  `mom_admin_note` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
-- Indexes for table `creative_des_assignments`
--
ALTER TABLE `creative_des_assignments`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_sops`
--
ALTER TABLE `company_sops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `creatives`
--
ALTER TABLE `creatives`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `creative_des_assignments`
--
ALTER TABLE `creative_des_assignments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sme_company_assignments`
--
ALTER TABLE `sme_company_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sme_meetings`
--
ALTER TABLE `sme_meetings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sme_meeting_attendees`
--
ALTER TABLE `sme_meeting_attendees`
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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
