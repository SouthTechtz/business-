-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 03, 2026 at 01:58 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u839560293_agensupply`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `activity_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `order_id` int(10) UNSIGNED DEFAULT NULL,
  `subject` varchar(191) DEFAULT NULL,
  `activity_type` varchar(20) NOT NULL DEFAULT 'note',
  `notes` text DEFAULT NULL,
  `outcome` varchar(100) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activities`
--

INSERT INTO `activities` (`activity_id`, `tenant_id`, `contact_id`, `lead_id`, `order_id`, `subject`, `activity_type`, `notes`, `outcome`, `due_date`, `status`, `created_by`, `assigned_to`, `created_at`) VALUES
(47, 1, NULL, 1, NULL, 'Lead created', 'created', 'soap', NULL, '2026-10-27', 'completed', 1, NULL, '2026-10-27 11:08:45'),
(48, 1, NULL, 3, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-10-27 13:06:38'),
(49, 1, NULL, 4, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-10-27 13:26:13'),
(50, 1, NULL, 5, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-10-27 13:59:48'),
(51, 1, NULL, 1, NULL, 'Verified by administrator', 'status_change', 'Status: New → Verified', NULL, NULL, 'completed', 1, NULL, '2026-10-27 14:54:18'),
(52, 1, NULL, 6, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-10-27 15:21:54'),
(83, 1, NULL, 6, 62, 'Verified and converted to Order ORD-000022', 'status_change', '1 stock line(s) reserved at the selected store; physical stock is deducted only when the order is fulfilled.', NULL, NULL, 'completed', 1, NULL, '2026-10-28 02:27:19'),
(84, 1, NULL, 5, 63, 'Verified and converted to Order ORD-000023', 'status_change', '1 stock line(s) reserved at the selected store; physical stock is deducted only when the order is fulfilled.', NULL, NULL, 'completed', 1, NULL, '2026-10-28 05:43:18'),
(85, 1, NULL, 4, 64, 'Verified and converted to Order ORD-000024', 'status_change', '1 stock line(s) reserved at the selected store; physical stock is deducted only when the order is fulfilled.', NULL, NULL, 'completed', 1, NULL, '2026-10-28 05:45:29'),
(86, 1, NULL, 51, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-10-29 12:37:05'),
(87, 1, NULL, 51, 65, 'Verified and converted to Order ORD-000025', 'status_change', '1 stock line(s) reserved at the selected store; physical stock is deducted only when the order is fulfilled.', NULL, NULL, 'completed', 1, NULL, '2026-10-29 12:42:45'),
(89, 1, NULL, 1, NULL, 'Verified by administrator', 'status_change', 'Status: Confirmed â†’ Verified', NULL, NULL, 'completed', 1, NULL, '2026-10-31 05:22:14'),
(90, 1, NULL, 53, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-10-31 06:15:56'),
(93, 1, NULL, 1, NULL, 'Status: Pending → Confirmed', 'status_change', 'Lead confirmed.', NULL, NULL, 'completed', 1, NULL, '2026-10-31 06:58:54'),
(97, 1, NULL, 53, NULL, 'Status: Pending → Confirmed', 'status_change', 'Lead confirmed.', NULL, NULL, 'completed', 1, NULL, '2026-11-03 06:28:50'),
(98, 1, NULL, 70, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-11-03 07:30:16'),
(99, 1, NULL, 71, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-11-03 07:53:36'),
(100, 1, NULL, 73, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-11-03 07:59:51'),
(104, 1, NULL, 81, NULL, 'Storefront order request received', 'created', 'Requested items are awaiting staff confirmation.', NULL, NULL, 'completed', NULL, NULL, '2026-11-03 11:13:05'),
(105, 1, NULL, 73, NULL, 'Status: Pending → Follow Up', 'status_change', NULL, NULL, NULL, 'completed', 46, NULL, '2026-11-03 11:22:57'),
(107, 1, NULL, 73, 86, 'Verified and converted to Order ORD-000028', 'status_change', '1 stock line(s) reserved at the shop’s store; physical stock is deducted only when the order is fulfilled.', NULL, NULL, 'completed', 46, NULL, '2026-11-03 11:34:25'),
(108, 1, NULL, 81, 90, 'Verified and converted to Order ORD-000029', 'status_change', '1 stock line(s) reserved at the shop’s store; physical stock is deducted only when the order is fulfilled.', NULL, NULL, 'completed', 46, NULL, '2026-11-03 11:51:04');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(10) UNSIGNED DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `tenant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at`) VALUES
(1, 1, 1, 'create', 'seed', 1, 'Demo data loaded for presentation', '2026-10-29 05:04:20'),
(2, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-29 06:04:58'),
(3, 1, 1, 'create', 'warehouse', 15, 'Godown created: Test Godown (GDN-TEST)', '2026-10-29 06:05:16'),
(4, 1, 1, 'delete', 'warehouse', 15, 'Godown deleted: Test Godown (GDN-TEST)', '2026-10-29 06:05:46'),
(5, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-29 06:08:11'),
(6, 1, 1, 'create', 'warehouse', 17, 'Godown created: QA Godown (GDN-QA)', '2026-10-29 06:08:14'),
(7, 1, 1, 'receive', 'godown_receipt', 3, 'Received 12 of Mineral Water 1.5L into QA Godown (GRN-000001). Source: QA delivery.', '2026-10-29 06:08:14'),
(8, 1, 1, 'configure', 'warehouse', 17, 'Godown archived with history preserved: QA Godown (GDN-QA)', '2026-10-29 06:08:22'),
(9, 1, 20, 'login', 'user', 20, 'User signed in', '2026-10-29 06:09:52'),
(10, 1, 20, 'create', 'stock_transfer', 6, 'Transfer TRF-000001 requested from QA Godown 2 to kariakoo / karikoo store with 1 product line(s).', '2026-10-29 06:11:16'),
(11, 1, 1, 'approve', 'stock_transfer', 6, 'Transfer TRF-000001 approved. No stock changed.', '2026-10-29 06:11:37'),
(12, 1, 1, 'dispatch', 'stock_transfer', 6, 'Transfer TRF-000001 dispatched; Godown stock deducted once and now in transit.', '2026-10-29 06:11:37'),
(13, 1, 1, 'receive', 'stock_transfer', 6, 'Transfer TRF-000001 received: accepted 2, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-10-29 06:11:49'),
(14, 1, 20, 'login', 'user', 20, 'User signed in', '2026-10-29 06:12:39'),
(15, 1, 20, 'login', 'user', 20, 'User signed in', '2026-10-29 06:13:43'),
(16, 1, 1, 'update', 'product', 6, 'Product updated', '2026-10-29 06:19:40'),
(17, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-29 06:33:02'),
(18, 1, 1, 'create', 'pos_order', 10, 'Sale POS-20261029-200 created', '2026-10-29 06:33:06'),
(19, 1, 1, 'create', 'pos_order', 11, 'Sale POS-20261029-913 created', '2026-10-29 06:33:26'),
(20, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-29 06:34:43'),
(21, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-29 06:57:16'),
(22, 1, 1, 'create', 'product', 10, 'Product ZZ Test Brand Product created', '2026-10-29 06:57:25'),
(23, 1, 1, 'update', 'product', 10, 'Product updated', '2026-10-29 06:57:36'),
(24, 1, 1, 'create', 'product', 10, 'Product ZZ Godown Stock Test created', '2026-10-29 07:07:51'),
(25, 1, 1, 'create', 'warehouse', 12, 'Godown created: MAin Godown (GDN-20261029-88E1)', '2026-10-29 07:55:07'),
(26, 1, 1, 'delete', 'warehouse', 12, 'Godown deleted: MAin Godown (GDN-20261029-88E1)', '2026-10-29 08:05:51'),
(27, 1, 1, 'create', 'warehouse', 13, 'Godown created: Makongo (GDN-20261029-4AF0)', '2026-10-29 08:08:06'),
(28, 1, 1, 'update', 'warehouse', 13, 'Godown updated: Makongo (GDN-20261029-4AF0)', '2026-10-29 08:08:24'),
(29, 1, 1, 'create', 'product', 10, 'Product Water created', '2026-10-29 11:17:00'),
(30, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-10-29 12:37:55'),
(31, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-29 12:38:18'),
(32, 1, 1, 'verify_request', 'lead', 51, 'Verified and converted to Order ORD-000025; 1 stock line(s) reserved', '2026-10-29 12:42:45'),
(33, 1, 1, 'update', 'order', 65, 'Quick updated amount paid and stage', '2026-10-29 14:29:31'),
(34, 1, 1, 'update', 'order', 65, 'Quick updated amount paid and stage', '2026-10-29 14:32:13'),
(35, 1, 1, 'update', 'order', 65, 'Quick updated amount paid and stage', '2026-10-29 14:38:34'),
(36, 1, 1, 'create', 'pos_order', 12, 'Sale POS-20261029-054 created', '2026-10-29 15:07:41'),
(37, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-30 05:11:05'),
(38, 1, 1, 'create', 'quotation', 1, 'Quotation QUO-000001 created', '2026-10-30 05:11:25'),
(39, 1, 1, 'convert', 'quotation', 1, 'Quotation QUO-000001 converted to invoice #9', '2026-10-30 05:12:05'),
(48, 1, 1, 'create', 'quotation', 2, 'Quotation QUO-000001 created', '2026-10-30 12:45:01'),
(49, 1, 1, 'create', 'stock_transfer', 10, 'Transfer TRF-000001 requested from Makongo to DEZL INVESTMENT LIMITED / Main Shop with 1 product line(s).', '2026-10-30 12:48:26'),
(50, 1, 1, 'approve', 'stock_transfer', 10, 'Transfer TRF-000001 approved. No stock changed.', '2026-10-30 12:48:52'),
(51, 1, 1, 'dispatch', 'stock_transfer', 10, 'Transfer TRF-000001 dispatched; source stock deducted once and now in transit.', '2026-10-30 12:49:10'),
(52, 1, 1, 'verify', 'lead', 1, 'Lead verified by administrator', '2026-10-31 05:22:14'),
(53, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-10-31 06:12:34'),
(54, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:13:40'),
(55, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:46:17'),
(56, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:46:53'),
(57, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:47:20'),
(58, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:47:36'),
(59, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:47:53'),
(60, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:48:47'),
(61, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:49:59'),
(62, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:51:12'),
(64, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:52:27'),
(65, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:52:36'),
(66, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:53:05'),
(67, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:54:58'),
(68, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:55:14'),
(69, 1, 1, 'login', 'user', 1, 'User signed in', '2026-10-31 06:55:47'),
(71, 1, 1, 'set_status', 'lead', 1, 'Status changed to confirmed', '2026-10-31 06:58:54'),
(72, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-01 12:25:32'),
(73, 1, 1, 'create', 'quotation', 3, 'Quotation QUO-000002 created', '2026-11-01 17:59:46'),
(74, 1, 1, 'convert', 'quotation', 3, 'Quotation QUO-000002 converted to invoice #23', '2026-11-02 06:59:09'),
(75, 1, 1, 'cancel', 'invoice', 23, 'Invoice cancelled', '2026-11-02 07:00:00'),
(76, 1, 1, 'create', 'quotation', 4, 'Quotation QUO-000003 created', '2026-11-02 07:02:27'),
(77, 1, 1, 'delete', 'quotation', 4, 'Quotation QUO-000003 deleted', '2026-11-02 07:03:15'),
(78, 1, 1, 'create', 'quotation', 5, 'Quotation QUO-000004 created', '2026-11-02 07:04:09'),
(79, 1, 1, 'convert', 'quotation', 5, 'Quotation QUO-000004 converted to invoice #24', '2026-11-02 07:05:09'),
(80, 1, 1, 'create', 'stock_transfer', 15, 'Transfer TRF-000002 requested from Makongo to  / Main Shop with 1 product line(s).', '2026-11-02 10:17:03'),
(81, 1, 1, 'approve', 'stock_transfer', 15, 'Transfer TRF-000002 approved. No stock changed.', '2026-11-02 10:17:18'),
(82, 1, 1, 'dispatch', 'stock_transfer', 15, 'Transfer TRF-000002 dispatched; source stock deducted once and now in transit.', '2026-11-02 10:17:27'),
(83, 1, 1, 'receive', 'stock_transfer', 15, 'Transfer TRF-000002 received: accepted 5, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-02 10:17:57'),
(84, 1, 1, 'create', 'pos_order', 25, 'Sale POS-20261102-676 created', '2026-11-02 11:23:56'),
(85, 1, 1, 'create', 'stock_transfer', 16, 'Transfer TRF-000003 requested from Makongo to DEZL INVESTMENT LIMITED / Main Shop with 1 product line(s).', '2026-11-02 11:52:54'),
(86, 1, 1, 'approve', 'stock_transfer', 16, 'Transfer TRF-000003 approved. No stock changed.', '2026-11-02 11:53:01'),
(87, 1, 1, 'dispatch', 'stock_transfer', 16, 'Transfer TRF-000003 dispatched; source stock deducted once and now in transit.', '2026-11-02 11:53:08'),
(88, 1, 1, 'receive', 'stock_transfer', 16, 'Transfer TRF-000003 received: accepted 5, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-02 11:53:13'),
(89, 1, 1, 'create', 'stock_transfer', 17, 'Transfer TRF-000004 requested from Makongo to kariakoo / karikoo store with 1 product line(s).', '2026-11-02 11:56:58'),
(90, 1, 1, 'create', 'stock_transfer', 24, 'Transfer TRF-000005 requested from Makongo to kariakoo / karikoo store with 1 product line(s).', '2026-11-02 13:19:19'),
(91, 1, 1, 'approve', 'stock_transfer', 24, 'Transfer TRF-000005 approved. No stock changed.', '2026-11-02 13:19:22'),
(92, 1, 1, 'dispatch', 'stock_transfer', 24, 'Transfer TRF-000005 dispatched; source stock deducted once and now in transit.', '2026-11-02 13:19:29'),
(93, 1, 1, 'receive', 'stock_transfer', 24, 'Transfer TRF-000005 received: accepted 5, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-02 13:19:33'),
(94, 1, 1, 'create', 'stock_transfer', 25, 'Transfer TRF-000006 requested from kariakoo / karikoo store to DEZL INVESTMENT LIMITED / DEZL INVESTMENT LIMITED with 1 product line(s).', '2026-11-02 13:20:13'),
(95, 1, 1, 'approve', 'stock_transfer', 25, 'Transfer TRF-000006 approved. No stock changed.', '2026-11-02 13:20:20'),
(96, 1, 1, 'dispatch', 'stock_transfer', 25, 'Transfer TRF-000006 dispatched; source stock deducted once and now in transit.', '2026-11-02 13:20:25'),
(97, 1, 1, 'receive', 'stock_transfer', 25, 'Transfer TRF-000006 received: accepted 2, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-02 13:20:29'),
(98, 1, 1, 'create', 'stock_transfer', 26, 'Transfer TRF-000007 requested from DEZL INVESTMENT LIMITED / Main Shop to kariakoo / kariakoo with 1 product line(s).', '2026-11-02 13:22:15'),
(99, 1, 1, 'approve', 'stock_transfer', 26, 'Transfer TRF-000007 approved. No stock changed.', '2026-11-02 13:22:19'),
(100, 1, 1, 'dispatch', 'stock_transfer', 26, 'Transfer TRF-000007 dispatched; source stock deducted once and now in transit.', '2026-11-02 13:22:25'),
(101, 1, 1, 'receive', 'stock_transfer', 26, 'Transfer TRF-000007 received: accepted 2, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-02 13:22:30'),
(102, 1, 1, 'create', 'user', 46, 'User mwenge created', '2026-11-02 13:24:22'),
(103, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-02 13:24:29'),
(104, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-02 13:24:33'),
(105, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-02 13:26:41'),
(106, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-02 13:27:09'),
(107, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-02 13:39:57'),
(108, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-02 13:40:01'),
(109, 1, 46, 'create', 'stock_transfer', 27, 'Transfer TRF-000008 requested from DEZL INVESTMENT LIMITED / DEZL INVESTMENT LIMITED to mwenge / mwenge with 1 product line(s).', '2026-11-02 14:19:49'),
(110, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-02 14:20:06'),
(111, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-02 14:20:32'),
(112, 1, 1, 'create', 'user', 47, 'User Dezl created', '2026-11-02 14:24:57'),
(113, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-02 14:25:15'),
(114, 1, 47, 'login', 'user', 47, 'User signed in', '2026-11-02 14:25:20'),
(115, 1, 47, 'approve', 'stock_transfer', 27, 'Transfer TRF-000008 approved. No stock changed.', '2026-11-02 14:33:49'),
(116, 1, 47, 'dispatch', 'stock_transfer', 27, 'Transfer TRF-000008 dispatched; source stock deducted once and now in transit.', '2026-11-02 14:33:59'),
(117, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-02 14:41:57'),
(118, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-02 14:42:07'),
(119, 1, 46, 'receive', 'stock_transfer', 27, 'Transfer TRF-000008 received: accepted 1, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-02 14:42:31'),
(120, 1, 46, 'create', 'pos_order', 26, 'Sale POS-20261102-614 created', '2026-11-02 14:43:11'),
(121, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-02 14:55:17'),
(122, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-02 14:55:34'),
(123, 1, 1, 'create', 'user', 48, 'User Gerezani created', '2026-11-02 15:18:45'),
(124, 1, 1, 'create', 'user', 49, 'User Godown created', '2026-11-02 15:21:53'),
(125, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-02 15:22:07'),
(126, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-02 15:22:10'),
(127, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-02 15:56:50'),
(128, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-02 15:56:57'),
(129, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-02 16:02:08'),
(130, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-02 16:02:21'),
(131, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 04:34:19'),
(132, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 04:34:32'),
(133, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 04:34:55'),
(134, 1, 47, 'login', 'user', 47, 'User signed in', '2026-11-03 04:35:04'),
(135, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-03 04:35:15'),
(136, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 04:35:31'),
(137, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 04:49:22'),
(138, 1, 47, 'login', 'user', 47, 'User signed in', '2026-11-03 04:49:38'),
(139, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-03 04:50:21'),
(140, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 04:50:28'),
(141, 1, 49, 'create', 'expense', 27, 'Expense recorded: furniture x5 @10,000 (TZS 50,000.00)', '2026-11-03 05:28:16'),
(142, 1, 49, 'create', 'expense', 28, 'Expense recorded: furniture x5 @10,000 (TZS 50,000.00)', '2026-11-03 05:31:59'),
(143, 1, 49, 'delete', 'expense', 27, 'Expense deleted', '2026-11-03 05:32:13'),
(144, 1, 49, 'create', 'stock_transfer', 28, 'Transfer TRF-000009 requested from Makongo to kariakoo / karikoo store with 1 product line(s).', '2026-11-03 05:38:13'),
(145, 1, 49, 'approve', 'stock_transfer', 28, 'Transfer TRF-000009 approved. No stock changed.', '2026-11-03 05:38:18'),
(146, 1, 49, 'dispatch', 'stock_transfer', 28, 'Transfer TRF-000009 dispatched; source stock deducted once and now in transit.', '2026-11-03 05:38:27'),
(147, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 05:38:38'),
(148, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 05:38:55'),
(149, 1, 1, 'receive', 'stock_transfer', 28, 'Transfer TRF-000009 received: accepted 5, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-03 05:39:32'),
(150, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 05:41:24'),
(151, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 05:41:33'),
(152, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 05:42:24'),
(153, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 05:42:32'),
(154, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 05:52:08'),
(155, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 05:52:14'),
(156, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 05:52:40'),
(157, 1, 47, 'login', 'user', 47, 'User signed in', '2026-11-03 05:54:17'),
(158, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-03 05:55:09'),
(159, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 05:55:17'),
(160, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 05:56:35'),
(161, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 05:58:43'),
(162, 1, 1, 'update', 'lead', 53, 'Lead LEAD-000008 updated', '2026-11-03 06:20:51'),
(163, 1, 1, 'update', 'lead', 53, 'Lead LEAD-000008 updated', '2026-11-03 06:28:50'),
(164, 1, 1, 'set_status', 'lead', 53, 'Status changed to confirmed', '2026-11-03 06:28:50'),
(165, 1, 1, 'update', 'order', 72, 'Quick updated total, amount paid, and stage', '2026-11-03 06:43:31'),
(166, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 06:57:54'),
(167, 1, 1, 'update', 'product', 6, 'Product updated', '2026-11-03 07:14:04'),
(168, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 07:14:15'),
(169, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 07:30:32'),
(170, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 07:47:52'),
(171, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 07:54:03'),
(172, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 08:00:33'),
(173, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 08:09:16'),
(174, 1, 1, 'update', 'lead', 73, 'Lead LEAD-000013 updated', '2026-11-03 08:11:22'),
(175, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 08:14:39'),
(176, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 08:14:49'),
(177, 1, 49, 'create', 'stock_transfer', 29, 'Transfer TRF-000010 requested from Makongo to kariakoo / karikoo store with 1 product line(s).', '2026-11-03 08:19:09'),
(178, 1, 49, 'approve', 'stock_transfer', 29, 'Transfer TRF-000010 approved. No stock changed.', '2026-11-03 08:19:19'),
(179, 1, 49, 'dispatch', 'stock_transfer', 29, 'Transfer TRF-000010 dispatched; source stock deducted once and now in transit.', '2026-11-03 08:19:31'),
(180, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 08:19:50'),
(181, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 08:20:08'),
(182, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 08:20:40'),
(183, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 08:21:12'),
(184, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 08:21:44'),
(185, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 08:21:52'),
(186, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 08:21:57'),
(187, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 08:27:17'),
(188, 1, 1, 'receive', 'stock_transfer', 29, 'Transfer TRF-000010 received: accepted 10 Bluetooth Speaker, damaged 0, shortage 0, excess 0. Destination store is Kariakoo.', '2026-11-03 09:18:40'),
(189, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 09:18:44'),
(190, 1, 1, 'delete', 'shop', 8, 'Machinga shop and unused shop inventory location removed; stock, references, transfers, and assignments verified empty.', '2026-11-03 09:19:55'),
(191, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 09:20:03'),
(192, 1, 1, 'delete', 'lead', 76, 'Unverified lead deleted: LEAD-000015', '2026-11-03 09:23:29'),
(193, 1, 1, 'delete', 'lead', 75, 'Unverified lead deleted: LEAD-000014', '2026-11-03 09:23:36'),
(194, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 09:31:31'),
(195, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 09:31:48'),
(196, 1, 49, 'create', 'stock_transfer', 30, 'Transfer TRF-000011 requested from Makongo to kariakoo / Kariakoo with 1 product line(s).', '2026-11-03 09:33:41'),
(197, 1, 49, 'approve', 'stock_transfer', 30, 'Transfer TRF-000011 approved. No stock changed.', '2026-11-03 09:33:45'),
(198, 1, 49, 'dispatch', 'stock_transfer', 30, 'Transfer TRF-000011 dispatched; source stock deducted once and now in transit.', '2026-11-03 09:33:52'),
(199, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 09:34:05'),
(200, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 09:35:02'),
(201, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 09:36:29'),
(202, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 09:36:36'),
(203, 1, 1, 'update', 'user', 48, 'User details and role-based location assignments updated', '2026-11-03 09:38:37'),
(204, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 09:38:46'),
(205, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 09:38:50'),
(206, 1, 48, 'receive', 'stock_transfer', 30, 'Transfer TRF-000011 received: accepted 10, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-03 09:39:21'),
(207, 1, 48, 'create', 'stock_transfer', 31, 'Transfer TRF-000012 requested from Makongo to kariakoo / Kariakoo with 1 product line(s).', '2026-11-03 09:40:19'),
(208, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 09:40:33'),
(209, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 09:40:44'),
(210, 1, 49, 'approve', 'stock_transfer', 31, 'Transfer TRF-000012 approved. No stock changed.', '2026-11-03 09:41:10'),
(211, 1, 49, 'dispatch', 'stock_transfer', 31, 'Transfer TRF-000012 dispatched; source stock deducted once and now in transit.', '2026-11-03 09:41:16'),
(212, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 09:41:33'),
(213, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 09:41:39'),
(214, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 09:43:45'),
(215, 1, 47, 'login', 'user', 47, 'User signed in', '2026-11-03 09:43:55'),
(216, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-03 09:45:25'),
(217, 1, 47, 'login', 'user', 47, 'User signed in', '2026-11-03 09:46:06'),
(218, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-03 09:51:28'),
(219, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 09:51:51'),
(220, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 09:52:44'),
(221, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 09:53:35'),
(222, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 09:54:24'),
(223, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 09:55:23'),
(224, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 09:55:35'),
(225, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 09:56:51'),
(226, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 09:57:48'),
(227, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 09:58:16'),
(228, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 09:58:23'),
(229, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:02:32'),
(230, 1, 46, 'create', 'stock_transfer', 33, 'Transfer TRF-000013 requested from kariakoo / Kariakoo to Mwenge / Mwenge with 1 product line(s).', '2026-11-03 10:03:38'),
(231, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 10:04:00'),
(232, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 10:04:07'),
(233, 1, 48, 'cancel', 'stock_transfer', 33, 'Transfer TRF-000013 cancelled: out of stock', '2026-11-03 10:16:22'),
(234, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 10:17:04'),
(235, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:17:11'),
(236, 1, 46, 'create', 'stock_transfer', 36, 'Transfer TRF-000014 requested from Makongo to Mwenge / Mwenge with 1 product line(s).', '2026-11-03 10:18:18'),
(237, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 10:18:32'),
(238, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 10:18:39'),
(239, 1, 49, 'approve', 'stock_transfer', 36, 'Transfer TRF-000014 approved. No stock changed.', '2026-11-03 10:19:03'),
(240, 1, 49, 'dispatch', 'stock_transfer', 36, 'Transfer TRF-000014 dispatched; source stock deducted once and now in transit.', '2026-11-03 10:19:11'),
(241, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 10:19:22'),
(242, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:19:31'),
(243, 1, 46, 'receive', 'stock_transfer', 36, 'Transfer TRF-000014 received: accepted 10, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-03 10:19:47'),
(244, 1, 46, 'create', 'stock_transfer', 38, 'Transfer TRF-000015 requested from Makongo to Mwenge / Mwenge with 1 product line(s).', '2026-11-03 10:22:58'),
(245, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 10:23:06'),
(246, 1, 49, 'login', 'user', 49, 'User signed in', '2026-11-03 10:23:13'),
(247, 1, 49, 'approve', 'stock_transfer', 38, 'Transfer TRF-000015 approved. No stock changed.', '2026-11-03 10:23:33'),
(248, 1, 49, 'dispatch', 'stock_transfer', 38, 'Transfer TRF-000015 dispatched; source stock deducted once and now in transit.', '2026-11-03 10:23:39'),
(249, 1, 49, 'logout', 'user', 49, 'User signed out', '2026-11-03 10:24:11'),
(250, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:24:19'),
(251, 1, 46, 'receive', 'stock_transfer', 38, 'Transfer TRF-000015 received: accepted 5, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-03 10:24:32'),
(252, 1, 46, 'create', 'stock_transfer', 39, 'Transfer TRF-000016 requested from Survey / Mwenge to Mwenge / Mwenge with 1 product line(s).', '2026-11-03 10:25:56'),
(253, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 10:26:10'),
(254, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:26:53'),
(255, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 10:27:10'),
(256, 1, 48, 'login', 'user', 48, 'User signed in', '2026-11-03 10:27:39'),
(257, 1, 48, 'logout', 'user', 48, 'User signed out', '2026-11-03 10:28:35'),
(258, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 10:28:45'),
(259, 1, 1, 'update', 'user', 47, 'User details and role-based location assignments updated', '2026-11-03 10:30:30'),
(260, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 10:37:55'),
(261, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 10:38:49'),
(262, 1, 1, 'update', 'user', 46, 'User details and role-based location assignments updated', '2026-11-03 10:39:46'),
(263, 1, 1, 'create', 'user', 50, 'User mwengestore created', '2026-11-03 10:41:02'),
(264, 1, 1, 'update', 'user', 20, 'User details and role-based location assignments updated', '2026-11-03 10:41:54'),
(265, 1, 1, 'update', 'user', 48, 'User details and role-based location assignments updated', '2026-11-03 10:42:17'),
(266, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 10:42:22'),
(267, 1, 50, 'login', 'user', 50, 'User signed in', '2026-11-03 10:42:33'),
(268, 1, 50, 'approve', 'stock_transfer', 39, 'Transfer TRF-000016 approved. No stock changed.', '2026-11-03 10:42:57'),
(269, 1, 50, 'dispatch', 'stock_transfer', 39, 'Transfer TRF-000016 dispatched; source stock deducted once and now in transit.', '2026-11-03 10:43:05'),
(270, 1, 50, 'logout', 'user', 50, 'User signed out', '2026-11-03 10:43:11'),
(271, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:43:21'),
(272, 1, 46, 'receive', 'stock_transfer', 39, 'Transfer TRF-000016 received: accepted 8, damaged 0, shortage 0, excess recorded 0. Only accepted quantities were added to destination stock.', '2026-11-03 10:43:54'),
(273, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 10:44:38'),
(274, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 10:46:13'),
(275, 1, 46, 'delete', 'lead', 79, 'Unverified lead deleted: LEAD-000016', '2026-11-03 10:46:41'),
(276, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 11:12:17'),
(277, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 11:13:22'),
(278, 1, 46, 'update', 'lead', 73, 'Lead LEAD-000013 updated', '2026-11-03 11:22:57'),
(279, 1, 46, 'update', 'lead', 73, 'Lead LEAD-000013 updated', '2026-11-03 11:29:18'),
(280, 1, 46, 'verify_request', 'lead', 73, 'Verified and converted to Order ORD-000028; 1 stock line(s) reserved', '2026-11-03 11:34:25'),
(281, 1, 46, 'update', 'order', 86, 'Quick updated total, amount paid, and stage', '2026-11-03 11:35:50'),
(282, 1, 46, 'update', 'order', 86, 'Quick updated total, amount paid, and stage', '2026-11-03 11:36:01'),
(283, 1, 46, 'update', 'order', 86, 'Quick updated total, amount paid, and stage', '2026-11-03 11:36:14'),
(284, 1, 46, 'update', 'order', 86, 'Quick updated total, amount paid, and stage', '2026-11-03 11:43:54'),
(285, 1, 46, 'update', 'lead', 81, 'Lead LEAD-000017 updated', '2026-11-03 11:48:33'),
(286, 1, 46, 'verify_request', 'lead', 81, 'Verified and converted to Order ORD-000029; 1 stock line(s) reserved', '2026-11-03 11:51:04'),
(287, 1, 46, 'update', 'order', 90, 'Quick updated total, amount paid, and stage', '2026-11-03 11:52:16'),
(288, 1, 46, 'create', 'stock_transfer', 40, 'Transfer TRF-000017 requested from Makongo to Mwenge / Mwenge with 1 product line(s).', '2026-11-03 11:53:11'),
(289, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 12:01:10'),
(290, 1, 46, 'login', 'user', 46, 'User signed in', '2026-11-03 12:10:42'),
(291, 1, 46, 'logout', 'user', 46, 'User signed out', '2026-11-03 12:11:46'),
(292, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-03 12:11:57'),
(293, 1, 1, 'update', 'shop', 11, 'Shop Kariakoo GPS updated', '2026-11-03 12:16:33'),
(294, 1, 1, 'update', 'shop', 7, 'Shop kariakoo updated', '2026-11-03 12:17:16'),
(295, 1, 1, 'update', 'product', 6, 'Product updated', '2026-11-03 12:19:02'),
(296, 1, 1, 'update', 'product', 8, 'Product updated', '2026-11-03 12:20:06'),
(297, 1, 1, 'update', 'product', 9, 'Product updated', '2026-11-03 12:21:01'),
(298, 1, 1, 'update', 'product', 9, 'Product updated', '2026-11-03 12:21:16'),
(299, 1, 1, 'logout', 'user', 1, 'User signed out', '2026-11-03 12:23:10');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `contact_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `contact_type_id` int(10) UNSIGNED DEFAULT 1,
  `customer_number` varchar(30) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `company_name` varchar(191) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `tax_id` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_customer` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contacts`
--

INSERT INTO `contacts` (`contact_id`, `tenant_id`, `contact_type_id`, `customer_number`, `first_name`, `last_name`, `company_name`, `email`, `phone`, `mobile`, `address`, `city`, `state`, `postal_code`, `country`, `tax_id`, `notes`, `is_customer`, `is_active`, `created_at`) VALUES
(1, 1, 1, NULL, 'Neema', 'Mkapa', 'Neema Traders', 'neema@example.com', '+255 712 345 001', NULL, 'Dar es Salaam', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 05:04:18'),
(2, 1, 1, NULL, 'Juma', 'Salum', 'Juma Electronics', 'juma@example.com', '+255 713 345 002', NULL, 'Dar es Salaam', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 05:04:18'),
(3, 1, 1, NULL, 'Baraka', 'Mwinyi', 'Baraka Supermarket', 'baraka@example.com', '+255 714 345 003', NULL, 'Arusha', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 05:04:18'),
(4, 1, 1, NULL, 'Safari', 'Kileo', 'Safari Hotel & Lodge', 'safari@example.com', '+255 715 345 004', NULL, 'Mwanza', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 05:04:18'),
(5, 1, 1, NULL, 'Tumaini', 'Kassim', 'Tumaini Pharmacy', 'tumaini@example.com', '+255 716 345 005', NULL, 'Dodoma', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 05:04:18'),
(6, 1, 1, NULL, 'Azam', 'Rashid', 'Azam Distribution', 'azam@example.com', '+255 717 345 006', NULL, 'Mbeya', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 05:04:18'),
(7, 1, 1, 'CUS-000005', 'emmanuel', NULL, NULL, NULL, '0765432212', NULL, 'Kigamboni', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-29 12:37:05'),
(8, 1, 1, 'CUS-000006', 'Mlingo', NULL, NULL, NULL, '0987654321', NULL, 'Buguruni', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-30 08:10:22'),
(9, 1, 1, 'CUS-000007', 'Bikwisa', NULL, NULL, NULL, '0712345678', NULL, 'BAGAMOYO', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-10-31 06:15:56'),
(10, 1, 1, 'CUS-000008', 'mwita', NULL, NULL, NULL, '+255767427016', NULL, 'KINONDONI DISTRICT', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-11-03 05:58:25'),
(11, 1, 1, 'CUS-000009', 'hashim', NULL, NULL, NULL, '+255754696088', NULL, 'UBUNGO DISTRICT', NULL, NULL, NULL, NULL, NULL, NULL, 1, 1, '2026-11-03 07:59:51');

-- --------------------------------------------------------

--
-- Table structure for table `contact_types`
--

CREATE TABLE `contact_types` (
  `contact_type_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `contact_types`
--

INSERT INTO `contact_types` (`contact_type_id`, `name`) VALUES
(1, 'Customer'),
(2, 'Vendor'),
(3, 'Lead');

-- --------------------------------------------------------

--
-- Table structure for table `deals`
--

CREATE TABLE `deals` (
  `deal_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `deal_name` varchar(191) NOT NULL,
  `value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(50) DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_sequences`
--

CREATE TABLE `document_sequences` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `sequence_key` varchar(30) NOT NULL,
  `next_value` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_sequences`
--

INSERT INTO `document_sequences` (`tenant_id`, `sequence_key`, `next_value`, `updated_at`) VALUES
(1, 'customer', 10, '2026-11-03 07:59:51'),
(1, 'lead', 18, '2026-11-03 11:13:05'),
(1, 'order', 30, '2026-11-03 11:51:04'),
(1, 'quotation', 5, '2026-11-02 07:04:09'),
(1, 'stock_transfer', 18, '2026-11-03 11:53:11');

-- --------------------------------------------------------

--
-- Table structure for table `document_statuses`
--

CREATE TABLE `document_statuses` (
  `status_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'invoice',
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `document_statuses`
--

INSERT INTO `document_statuses` (`status_id`, `tenant_id`, `type`, `name`, `sort_order`, `is_closed`) VALUES
(1, 1, 'invoice', 'Draft', 1, 0),
(2, 1, 'invoice', 'Sent', 2, 0),
(3, 1, 'invoice', 'Partially Paid', 3, 0),
(4, 1, 'invoice', 'Paid', 4, 1),
(5, 1, 'invoice', 'Overdue', 5, 0),
(6, 1, 'invoice', 'Cancelled', 6, 1);

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `expense_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `description` varchar(191) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expense_date` date NOT NULL,
  `payment_method_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`expense_id`, `tenant_id`, `category_id`, `description`, `amount`, `expense_date`, `payment_method_id`, `notes`, `created_by`, `created_at`) VALUES
(1, 1, 2, 'Office & shop rent — monthly', 495000.00, '2026-08-15', 1, NULL, 1, '2026-08-15 09:00:00'),
(2, 1, 3, 'Electricity bill (TANESCO)', 196100.00, '2026-08-16', 1, NULL, 1, '2026-08-16 09:00:00'),
(3, 1, 3, 'Water bill (DAWASCO)', 16800.00, '2026-08-18', 1, NULL, 1, '2026-08-18 09:00:00'),
(4, 1, 3, 'Internet & phone bundles', 82400.00, '2026-08-27', 1, NULL, 1, '2026-08-27 09:00:00'),
(5, 1, 4, 'Salaries — 4 staff (monthly)', 1512000.00, '2026-08-04', 1, NULL, 1, '2026-08-04 09:00:00'),
(6, 1, 5, 'Delivery fuel & taxi fare', 102600.00, '2026-08-17', 3, NULL, 1, '2026-08-17 09:00:00'),
(7, 1, NULL, 'Receipt books, pens, printer paper', 52000.00, '2026-08-10', 3, NULL, 1, '2026-08-10 09:00:00'),
(8, 1, 7, 'WhatsApp & Facebook ads', 46400.00, '2026-08-23', 2, NULL, 1, '2026-08-23 09:00:00'),
(9, 1, 8, 'Licence renewal & permits', 115200.00, '2026-08-27', 1, NULL, 1, '2026-08-27 09:00:00'),
(10, 1, 2, 'Office & shop rent — monthly', 505000.00, '2026-09-10', 1, NULL, 1, '2026-09-10 09:00:00'),
(11, 1, 3, 'Electricity bill (TANESCO)', 190600.00, '2026-09-11', 1, NULL, 1, '2026-09-11 09:00:00'),
(12, 1, 3, 'Water bill (DAWASCO)', 40700.00, '2026-09-11', 1, NULL, 1, '2026-09-11 09:00:00'),
(13, 1, 3, 'Internet & phone bundles', 56600.00, '2026-09-15', 1, NULL, 1, '2026-09-15 09:00:00'),
(14, 1, 4, 'Salaries — 4 staff (monthly)', 1358000.00, '2026-09-07', 1, NULL, 1, '2026-09-07 09:00:00'),
(15, 1, 5, 'Delivery fuel & taxi fare', 39900.00, '2026-09-14', 3, NULL, 1, '2026-09-14 09:00:00'),
(16, 1, NULL, 'Receipt books, pens, printer paper', 20500.00, '2026-09-26', 3, NULL, 1, '2026-09-26 09:00:00'),
(17, 1, 7, 'WhatsApp & Facebook ads', 75800.00, '2026-09-25', 2, NULL, 1, '2026-09-25 09:00:00'),
(18, 1, 8, 'Licence renewal & permits', 47000.00, '2026-09-19', 1, NULL, 1, '2026-09-19 09:00:00'),
(19, 1, 2, 'Office & shop rent — monthly', 495000.00, '2026-10-12', 1, NULL, 1, '2026-10-12 09:00:00'),
(20, 1, 3, 'Water bill (DAWASCO)', 26700.00, '2026-10-18', 1, NULL, 1, '2026-10-18 09:00:00'),
(21, 1, 3, 'Internet & phone bundles', 39600.00, '2026-10-26', 1, NULL, 1, '2026-10-26 09:00:00'),
(22, 1, 4, 'Salaries — 4 staff (monthly)', 1456000.00, '2026-10-15', 1, NULL, 1, '2026-10-15 09:00:00'),
(23, 1, 5, 'Delivery fuel & taxi fare', 50800.00, '2026-10-15', 3, NULL, 1, '2026-10-15 09:00:00'),
(24, 1, NULL, 'Receipt books, pens, printer paper', 68300.00, '2026-10-12', 3, NULL, 1, '2026-10-12 09:00:00'),
(25, 1, 7, 'WhatsApp & Facebook ads', 127200.00, '2026-10-05', 2, NULL, 1, '2026-10-05 09:00:00'),
(26, 1, 8, 'Licence renewal & permits', 83200.00, '2026-10-19', 1, NULL, 1, '2026-10-19 09:00:00'),
(28, 1, 8, 'furniture x5 @10,000', 50000.00, '2026-11-03', 1, '', 49, '2026-11-03 05:31:59');

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

CREATE TABLE `expense_categories` (
  `expense_category_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expense_categories`
--

INSERT INTO `expense_categories` (`expense_category_id`, `tenant_id`, `name`, `created_at`) VALUES
(1, 1, 'Stock Purchase', '2026-08-28 13:20:03'),
(2, 1, 'Rent', '2026-08-28 13:20:03'),
(3, 1, 'Utilities', '2026-08-28 13:20:03'),
(4, 1, 'Salaries', '2026-08-28 13:20:03'),
(5, 1, 'Transport', '2026-08-28 13:20:03'),
(6, 1, 'Tinting Supplies', '2026-08-28 13:20:03'),
(7, 1, 'Marketing', '2026-08-28 13:20:03'),
(8, 1, 'Other', '2026-08-28 13:20:03');

-- --------------------------------------------------------

--
-- Table structure for table `expense_items`
--

CREATE TABLE `expense_items` (
  `item_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `expense_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `expense_items`
--

INSERT INTO `expense_items` (`item_id`, `tenant_id`, `expense_id`, `name`, `quantity`, `unit_price`, `total`, `created_at`) VALUES
(1, 1, 1, 'Weather Guard 20L White (restock)', 3.00, 165000.00, 495000.00, '2026-08-28 13:20:04'),
(2, 1, 1, 'Silk 4L (restock)', 2.00, 42000.00, 84000.00, '2026-08-28 13:20:04'),
(3, 1, 2, 'Shop rent', 1.00, 400000.00, 400000.00, '2026-08-28 13:20:04'),
(4, 1, 3, 'Fuel', 20.00, 3000.00, 60000.00, '2026-08-28 13:20:04'),
(5, 1, 4, 'Tinting paste', 5.00, 18000.00, 90000.00, '2026-08-28 13:20:04'),
(6, 1, 4, 'Colour cards', 10.00, 1500.00, 15000.00, '2026-08-28 13:20:04'),
(7, 1, 5, 'Deliveries', 3.00, 12000.00, 36000.00, '2026-08-28 13:20:04'),
(8, 1, 6, 'Staff salary', 1.00, 350000.00, 350000.00, '2026-08-28 13:20:04'),
(9, 1, 7, 'juma kariakoo', 1.00, 6000.00, 6000.00, '2026-08-29 07:38:37'),
(10, 1, 8, 'shmnvr', 7.00, 6000.00, 42000.00, '2026-08-29 07:39:01'),
(11, 1, 8, 'aegrr6ilktun', 4.00, 23000.00, 92000.00, '2026-08-29 07:39:01'),
(12, 1, 9, 'sfthgdfehtgrf', 6.00, 4500.00, 27000.00, '2026-08-29 07:39:40'),
(13, 1, 9, 'hfdafgsrgfd', 3.00, 10000.00, 30000.00, '2026-08-29 07:39:40'),
(14, 1, 9, 'rtenvracexeetb', 4.00, 7800.00, 31200.00, '2026-08-29 07:39:40'),
(15, 1, 9, 'thytgecrvg', 6.00, 2300.00, 13800.00, '2026-08-29 07:39:40'),
(16, 1, 9, 'efrgjhgebh', 2.00, 34500.00, 69000.00, '2026-08-29 07:39:40'),
(17, 1, 27, 'furniture', 5.00, 10000.00, 50000.00, '2026-11-03 05:28:16'),
(18, 1, 28, 'furniture', 5.00, 10000.00, 50000.00, '2026-11-03 05:31:59');

-- --------------------------------------------------------

--
-- Table structure for table `godown_receipts`
--

CREATE TABLE `godown_receipts` (
  `receipt_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `receipt_number` varchar(50) NOT NULL,
  `godown_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `source_reference` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `received_by` int(10) UNSIGNED DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `movement_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED NOT NULL,
  `movement_type` varchar(50) NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `production_date` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_movements`
--

INSERT INTO `inventory_movements` (`movement_id`, `tenant_id`, `product_id`, `warehouse_id`, `movement_type`, `quantity`, `reference_id`, `reference_type`, `created_by`, `notes`, `production_date`, `expires_at`, `created_at`) VALUES
(6, 1, 6, 1, 'restock', 70.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 06:36:38'),
(10, 1, 6, 13, 'restock', 10.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 11:33:45'),
(11, 1, 6, 13, 'restock', 19.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:04:52'),
(12, 1, 6, 13, 'restock', 1.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:08:29'),
(13, 1, 6, 13, 'restock', 1.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:09:21'),
(14, 1, 6, 13, 'restock', 3.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:14:54'),
(15, 1, 6, 13, 'restock', 1.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:19:09'),
(16, 1, 8, 13, 'restock', 10.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:23:41'),
(17, 1, 7, 13, 'restock', 1.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-29 12:24:22'),
(18, 1, 6, 1, 'sale', 5.00, 12, 'pos_order', 1, NULL, NULL, NULL, '2026-10-29 15:07:41'),
(25, 1, 8, 13, 'restock', 10.00, NULL, NULL, 1, '', NULL, NULL, '2026-10-30 12:46:35'),
(26, 1, 8, 13, 'godown_transfer_out', 10.00, 10, 'stock_transfer', 1, 'Transfer TRF-000001 dispatched to DEZL INVESTMENT LIMITED / Main Shop', NULL, NULL, '2026-10-30 12:49:10'),
(27, 1, 6, 1, 'invoice', 1.00, 23, 'invoice', 1, 'Converted from quotation QUO-000002', NULL, NULL, '2026-11-02 06:59:09'),
(28, 1, 4, 1, 'invoice', 1.00, 23, 'invoice', 1, 'Converted from quotation QUO-000002', NULL, NULL, '2026-11-02 06:59:09'),
(29, 1, 6, 1, 'invoice_cancel', 1.00, 23, 'invoice', 1, 'Invoice cancelled', NULL, NULL, '2026-11-02 07:00:00'),
(30, 1, 4, 1, 'invoice_cancel', 1.00, 23, 'invoice', 1, 'Invoice cancelled', NULL, NULL, '2026-11-02 07:00:00'),
(31, 1, 6, 1, 'invoice', 5.00, 24, 'invoice', 1, 'Converted from quotation QUO-000004', NULL, NULL, '2026-11-02 07:05:09'),
(32, 1, 6, 13, 'godown_transfer_out', 5.00, 15, 'stock_transfer', 1, 'Transfer TRF-000002 dispatched to DEZL INVESTMENT LIMITED / Main Shop', NULL, NULL, '2026-11-02 10:17:27'),
(33, 1, 6, 1, 'godown_transfer_in', 5.00, 15, 'stock_transfer', 1, 'Transfer TRF-000002 received from Makongo; accepted 5, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-02 10:17:57'),
(34, 1, 6, 13, 'godown_transfer_out', 5.00, 16, 'stock_transfer', 1, 'Transfer TRF-000003 dispatched to DEZL INVESTMENT LIMITED / Main Shop', NULL, NULL, '2026-11-02 11:53:08'),
(35, 1, 6, 1, 'godown_transfer_in', 5.00, 16, 'stock_transfer', 1, 'Transfer TRF-000003 received from Makongo; accepted 5, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-02 11:53:13'),
(36, 1, 6, 13, 'godown_transfer_out', 5.00, 24, 'stock_transfer', 1, 'Transfer TRF-000005 dispatched to kariakoo / karikoo store', NULL, NULL, '2026-11-02 13:19:28'),
(37, 1, 6, 11, 'godown_transfer_in', 5.00, 24, 'stock_transfer', 1, 'Transfer TRF-000005 received from Makongo; accepted 5, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-02 13:19:33'),
(38, 1, 6, 11, 'godown_transfer_out', 2.00, 25, 'stock_transfer', 1, 'Transfer TRF-000006 dispatched to DEZL INVESTMENT LIMITED / DEZL INVESTMENT LIMITED', NULL, NULL, '2026-11-02 13:20:25'),
(39, 1, 6, 14, 'godown_transfer_in', 2.00, 25, 'stock_transfer', 1, 'Transfer TRF-000006 received from karikoo store; accepted 2, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-02 13:20:29'),
(40, 1, 6, 1, 'godown_transfer_out', 2.00, 26, 'stock_transfer', 1, 'Transfer TRF-000007 dispatched to kariakoo / kariakoo', NULL, NULL, '2026-11-02 13:22:24'),
(41, 1, 6, 15, 'godown_transfer_in', 2.00, 26, 'stock_transfer', 1, 'Transfer TRF-000007 received from Main Shop; accepted 2, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-02 13:22:30'),
(42, 1, 6, 14, 'godown_transfer_out', 1.00, 27, 'stock_transfer', 47, 'Transfer TRF-000008 dispatched to mwenge / mwenge', NULL, NULL, '2026-11-02 14:33:59'),
(43, 1, 6, 17, 'godown_transfer_in', 1.00, 27, 'stock_transfer', 46, 'Transfer TRF-000008 received from DEZL INVESTMENT LIMITED; accepted 1, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-02 14:42:31'),
(44, 1, 6, 17, 'sale', 1.00, 26, 'pos_order', 46, NULL, NULL, NULL, '2026-11-02 14:43:11'),
(45, 1, 4, 13, 'purchase', 10.00, 3, 'purchase', 49, 'Purchase #3', NULL, NULL, '2026-11-03 05:37:05'),
(46, 1, 4, 13, 'godown_transfer_out', 5.00, 28, 'stock_transfer', 49, 'Transfer TRF-000009 dispatched to kariakoo / karikoo store', NULL, NULL, '2026-11-03 05:38:26'),
(47, 1, 4, 11, 'godown_transfer_in', 5.00, 28, 'stock_transfer', 1, 'Transfer TRF-000009 received from Makongo; accepted 5, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-03 05:39:32'),
(48, 1, 6, 13, 'purchase', 10.00, 4, 'purchase', 49, 'Purchase #4', NULL, NULL, '2026-11-03 08:17:17'),
(49, 1, 6, 13, 'godown_transfer_out', 10.00, 29, 'stock_transfer', 49, 'Transfer TRF-000010 dispatched to kariakoo / karikoo store', NULL, NULL, '2026-11-03 08:19:31'),
(50, 1, 6, 11, 'godown_transfer_in', 10.00, 29, 'stock_transfer', 1, 'Transfer TRF-000010 received from Makongo; accepted 10, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-03 09:18:40'),
(51, 1, 7, 13, 'restock', 10.00, NULL, NULL, 49, '', NULL, NULL, '2026-11-03 09:32:09'),
(53, 1, 1, 13, 'restock', 10.00, NULL, NULL, 49, '', NULL, NULL, '2026-11-03 09:32:35'),
(54, 1, 6, 13, 'restock', 30.00, NULL, NULL, 49, '', NULL, NULL, '2026-11-03 09:32:49'),
(55, 1, 6, 13, 'godown_transfer_out', 10.00, 30, 'stock_transfer', 49, 'Transfer TRF-000011 dispatched to kariakoo / Kariakoo', NULL, NULL, '2026-11-03 09:33:52'),
(56, 1, 6, 11, 'godown_transfer_in', 10.00, 30, 'stock_transfer', 48, 'Transfer TRF-000011 received from Makongo; accepted 10, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-03 09:39:21'),
(57, 1, 6, 13, 'godown_transfer_out', 10.00, 31, 'stock_transfer', 49, 'Transfer TRF-000012 dispatched to kariakoo / Kariakoo', NULL, NULL, '2026-11-03 09:41:16'),
(58, 1, 6, 13, 'godown_transfer_out', 10.00, 36, 'stock_transfer', 49, 'Transfer TRF-000014 dispatched to Mwenge / Mwenge', NULL, NULL, '2026-11-03 10:19:11'),
(59, 1, 6, 17, 'godown_transfer_in', 10.00, 36, 'stock_transfer', 46, 'Transfer TRF-000014 received from Makongo; accepted 10, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-03 10:19:47'),
(60, 1, 6, 13, 'godown_transfer_out', 5.00, 38, 'stock_transfer', 49, 'Transfer TRF-000015 dispatched to Mwenge / Mwenge', NULL, NULL, '2026-11-03 10:23:38'),
(61, 1, 6, 17, 'godown_transfer_in', 5.00, 38, 'stock_transfer', 46, 'Transfer TRF-000015 received from Makongo; accepted 5, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-03 10:24:32'),
(62, 1, 6, 1, 'godown_transfer_out', 8.00, 39, 'stock_transfer', 50, 'Transfer TRF-000016 dispatched to Mwenge / Mwenge', NULL, NULL, '2026-11-03 10:43:05'),
(63, 1, 6, 17, 'godown_transfer_in', 8.00, 39, 'stock_transfer', 46, 'Transfer TRF-000016 received from Mwenge; accepted 8, damaged 0, shortage 0, excess recorded 0', NULL, NULL, '2026-11-03 10:43:54'),
(65, 1, 6, 17, 'order_transport', 2.00, 90, 'order_transport', 46, 'Stock deducted when order moved into transport', NULL, NULL, '2026-11-03 11:52:16');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_stock`
--

CREATE TABLE `inventory_stock` (
  `stock_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reorder_level` decimal(12,2) NOT NULL DEFAULT 0.00,
  `production_date` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `last_counted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `inventory_stock`
--

INSERT INTO `inventory_stock` (`stock_id`, `tenant_id`, `product_id`, `warehouse_id`, `quantity`, `reorder_level`, `production_date`, `expires_at`, `last_counted_at`, `updated_at`) VALUES
(1, 1, 1, 1, 36.00, 10.00, NULL, NULL, '2026-10-29 10:36:58', '2026-10-30 05:17:50'),
(4, 1, 4, 1, 50.00, 12.00, NULL, NULL, NULL, '2026-11-02 07:00:00'),
(5, 1, 5, 1, 12.00, 6.00, NULL, NULL, NULL, '2026-10-29 05:04:19'),
(6, 1, 6, 1, 60.00, 3.00, NULL, NULL, '2026-11-02 14:53:13', '2026-11-03 10:43:05'),
(7, 1, 7, 1, 25.00, 5.00, NULL, NULL, NULL, '2026-10-29 05:04:19'),
(8, 1, 8, 1, 0.00, 4.00, NULL, NULL, NULL, '2026-10-29 05:04:20'),
(17, 1, 6, 13, 15.00, 3.00, NULL, NULL, '2026-11-03 12:32:49', '2026-11-03 10:23:38'),
(18, 1, 8, 13, 10.00, 4.00, NULL, NULL, '2026-10-30 15:46:35', '2026-10-30 12:49:10'),
(19, 1, 7, 13, 11.00, 5.00, NULL, NULL, '2026-11-03 12:32:09', '2026-11-03 09:32:09'),
(25, 1, 6, 11, 23.00, 3.00, NULL, NULL, '2026-11-03 12:39:21', '2026-11-03 09:39:21'),
(26, 1, 6, 14, 1.00, 3.00, NULL, NULL, '2026-11-02 16:20:29', '2026-11-02 14:33:59'),
(27, 1, 6, 15, 2.00, 3.00, NULL, NULL, '2026-11-02 16:22:30', '2026-11-02 13:22:30'),
(28, 1, 6, 17, 21.00, 3.00, NULL, NULL, '2026-11-03 13:43:54', '2026-11-03 11:52:16'),
(29, 1, 4, 13, 5.00, 12.00, NULL, NULL, '2026-11-03 08:37:05', '2026-11-03 05:38:26'),
(30, 1, 4, 11, 5.00, 12.00, NULL, NULL, '2026-11-03 08:39:32', '2026-11-03 05:39:32'),
(34, 1, 1, 13, 10.00, 10.00, NULL, NULL, NULL, '2026-11-03 09:32:34');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `contact_id` int(10) UNSIGNED NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `status_id` int(10) UNSIGNED DEFAULT NULL,
  `reference` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_paid` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `returned_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_due` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`invoice_id`, `tenant_id`, `invoice_number`, `warehouse_id`, `contact_id`, `issue_date`, `due_date`, `status_id`, `reference`, `notes`, `terms`, `subtotal`, `tax_amount`, `total`, `amount_due`, `amount_paid`, `is_paid`, `created_by`, `created_at`, `updated_at`, `version`, `returned_amount`, `refund_due`) VALUES
(1, 1, 'INV-1001', NULL, 4, '2026-09-24', '2026-10-24', 1, 'PRJ-117', '', 'Payment due within 30 days', 168000.00, 30240.00, 198240.00, 0.00, 198240.00, 1, 1, '2026-09-24 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(2, 1, 'INV-1002', NULL, 5, '2026-10-12', '2026-11-11', 1, 'PRJ-739', '', 'Payment due within 30 days', 12000.00, 2160.00, 14160.00, 0.00, 14160.00, 1, 1, '2026-10-12 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(3, 1, 'INV-1003', NULL, 2, '2026-10-27', '2026-11-26', 1, 'PRJ-910', '', 'Payment due within 30 days', 316000.00, 56880.00, 372880.00, 0.00, 372880.00, 1, 1, '2026-10-27 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(4, 1, 'INV-1004', NULL, 6, '2026-09-02', '2026-10-02', 4, 'PRJ-635', '', 'Payment due within 30 days', 17500.00, 3150.00, 20650.00, 10350.00, 10300.00, 0, 1, '2026-09-02 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(5, 1, 'INV-1005', NULL, 3, '2026-08-22', '2026-09-21', 5, 'PRJ-977', '', 'Payment due within 30 days', 647000.00, 116460.00, 763460.00, 763460.00, 0.00, 0, 1, '2026-08-22 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(6, 1, 'INV-1006', NULL, 2, '2026-10-02', '2026-11-01', 2, 'PRJ-180', '', 'Payment due within 30 days', 117000.00, 21060.00, 138060.00, 138060.00, 0.00, 0, 1, '2026-10-02 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(7, 1, 'INV-1007', NULL, 4, '2026-08-21', '2026-09-20', 1, 'PRJ-825', '', 'Payment due within 30 days', 59000.00, 10080.00, 69080.00, 69080.00, 0.00, 0, 1, '2026-08-21 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(8, 1, 'INV-1008', NULL, 3, '2026-08-14', '2026-09-13', 6, 'PRJ-509', '', 'Payment due within 30 days', 364000.00, 65520.00, 429520.00, 429520.00, 0.00, 0, 1, '2026-08-14 07:00:00', '2026-11-01 05:18:31', 1, 0.00, 0.00),
(23, 1, 'INV-1009', 1, 6, '2026-11-02', '2026-12-01', 6, 'QUO-000002', '', '', 31000.00, 0.00, 31000.00, 0.00, 0.00, 0, 1, '2026-11-02 06:59:08', '2026-11-02 07:00:00', 2, 0.00, 0.00),
(24, 1, 'INV-1010', 1, 9, '2026-11-02', '2026-12-02', 1, 'QUO-000004', '', '', 140000.00, 0.00, 140000.00, 140000.00, 0.00, 0, 1, '2026-11-02 07:05:09', '2026-11-02 07:05:09', 1, 0.00, 0.00);

--
-- Triggers `invoices`
--
DELIMITER $$
CREATE TRIGGER `trg_invoices_no_godown` BEFORE INSERT ON `invoices` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A Godown cannot invoice. Transfer stock to a store first.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `invoice_item_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `tax_rate_id` int(10) UNSIGNED DEFAULT NULL,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `invoice_items`
--

INSERT INTO `invoice_items` (`invoice_item_id`, `tenant_id`, `invoice_id`, `warehouse_id`, `product_id`, `description`, `quantity`, `unit_price`, `tax_rate_id`, `tax_amount`, `subtotal`, `total`, `discount`) VALUES
(1, 1, 1, NULL, 6, 'Bluetooth Speaker', 6.00, 28000.00, NULL, 30240.00, 168000.00, 198240.00, 0.00),
(2, 1, 2, NULL, 4, 'LED Bulb 9W', 4.00, 3000.00, NULL, 2160.00, 12000.00, 14160.00, 0.00),
(3, 1, 3, NULL, 5, 'Phone Charger USB', 10.00, 7000.00, NULL, 12600.00, 70000.00, 82600.00, 0.00),
(4, 1, 3, NULL, 6, 'Bluetooth Speaker', 6.00, 28000.00, NULL, 30240.00, 168000.00, 198240.00, 0.00),
(5, 1, 3, NULL, 8, 'Cooking Oil 5L', 4.00, 19500.00, NULL, 14040.00, 78000.00, 92040.00, 0.00),
(6, 1, 4, NULL, 3, 'Mango Juice 1L', 5.00, 3500.00, NULL, 3150.00, 17500.00, 20650.00, 0.00),
(7, 1, 5, NULL, 2, 'Soda 300ml (Crate)', 4.00, 15000.00, NULL, 10800.00, 60000.00, 70800.00, 0.00),
(8, 1, 5, NULL, 6, 'Bluetooth Speaker', 14.00, 28000.00, NULL, 70560.00, 392000.00, 462560.00, 0.00),
(9, 1, 5, NULL, 8, 'Cooking Oil 5L', 10.00, 19500.00, NULL, 35100.00, 195000.00, 230100.00, 0.00),
(10, 1, 6, NULL, 8, 'Cooking Oil 5L', 6.00, 19500.00, NULL, 21060.00, 117000.00, 138060.00, 0.00),
(11, 1, 7, NULL, 6, 'Bluetooth Speaker', 2.00, 28000.00, NULL, 10080.00, 56000.00, 66080.00, 0.00),
(12, 1, 7, NULL, 9, 'Delivery Service (per km)', 3.00, 1000.00, NULL, 0.00, 3000.00, 3000.00, 0.00),
(13, 1, 8, NULL, 6, 'Bluetooth Speaker', 13.00, 28000.00, NULL, 65520.00, 364000.00, 429520.00, 0.00),
(21, 1, 23, 1, 6, 'Bluetooth Speaker', 1.00, 28000.00, NULL, 0.00, 28000.00, 28000.00, 0.00),
(22, 1, 23, 1, 4, 'LED Bulb 9W', 1.00, 3000.00, NULL, 0.00, 3000.00, 3000.00, 0.00),
(23, 1, 24, 1, 6, 'Bluetooth Speaker', 5.00, 28000.00, NULL, 0.00, 140000.00, 140000.00, 0.00);

--
-- Triggers `invoice_items`
--
DELIMITER $$
CREATE TRIGGER `trg_invoice_items_no_godown` BEFORE INSERT ON `invoice_items` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A Godown cannot invoice. Transfer stock to a store first.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `journal_entry_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `entry_date` date NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `lead_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `lead_number` varchar(30) NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `contact_name` varchar(191) NOT NULL,
  `company_name` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'staff',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `next_follow_up_at` date DEFAULT NULL,
  `last_contacted_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `lost_reason` varchar(255) DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `leads`
--

INSERT INTO `leads` (`lead_id`, `tenant_id`, `lead_number`, `contact_id`, `contact_name`, `company_name`, `phone`, `email`, `location`, `source`, `status`, `assigned_to`, `next_follow_up_at`, `last_contacted_at`, `notes`, `lost_reason`, `converted_at`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'LEAD-000001', NULL, 'bashiru ally', 'Bs', '0629102021', 'bashiru@gmail.com', 'KINONDONI DISTRICT DAR ES SALAAM', 'staff', 'confirmed', NULL, '2026-10-27', NULL, 'soap', NULL, '2026-10-31 09:58:53', 1, '2026-10-27 11:08:45', '2026-10-31 06:58:53'),
(3, 1, 'LEAD-000002', NULL, 'maria', NULL, '0787654322', NULL, 'gomzi', 'storefront', 'pending', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-27 13:06:37', '2026-10-31 06:52:21'),
(4, 1, 'LEAD-000003', NULL, 'boss', NULL, '0999999999', NULL, 'mbagala', 'storefront', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-10-28 08:45:29', NULL, '2026-10-27 13:26:13', '2026-10-31 06:49:53'),
(5, 1, 'LEAD-000004', NULL, 'husen', NULL, '+255754696088', NULL, 'UBUNGO DISTRICT', 'storefront', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-10-28 08:43:18', NULL, '2026-10-27 13:59:48', '2026-10-31 06:49:53'),
(6, 1, 'LEAD-000005', NULL, 'mapunda', NULL, '+255687039380', NULL, 'KINONDONI DISTRICT', 'storefront', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-10-28 05:27:19', NULL, '2026-10-27 15:21:54', '2026-10-31 06:49:53'),
(51, 1, 'LEAD-000006', 7, 'emmanuel', NULL, '0765432212', NULL, 'Kigamboni', 'storefront', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-10-29 15:42:45', NULL, '2026-10-29 12:37:05', '2026-10-30 10:27:10'),
(53, 1, 'LEAD-000008', 9, 'Bikwisa', NULL, '0712345678', NULL, 'BAGAMOYO', 'staff', 'confirmed', NULL, '2026-11-10', NULL, NULL, NULL, '2026-11-03 09:28:50', NULL, '2026-10-31 06:15:56', '2026-11-03 06:28:50'),
(70, 1, 'LEAD-000011', 10, 'CEO', NULL, '+255767427016', NULL, 'KINONDONI DISTRICT', 'storefront', 'new', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 07:30:16', '2026-11-03 07:30:16'),
(71, 1, 'LEAD-000012', 10, 'CRM', NULL, '+255767427016', NULL, 'KINONDONI DISTRICT', 'storefront', 'new', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 07:53:36', '2026-11-03 07:53:36'),
(73, 1, 'LEAD-000013', 11, 'hashim', NULL, '+255754696088', NULL, 'UBUNGO DISTRICT', 'storefront', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-11-03 14:34:25', NULL, '2026-11-03 07:59:51', '2026-11-03 11:34:25'),
(81, 1, 'LEAD-000017', 10, 'mwita', NULL, '+255767427016', NULL, 'KINONDONI DISTRICT', 'storefront', 'confirmed', NULL, NULL, NULL, NULL, NULL, '2026-11-03 14:51:04', NULL, '2026-11-03 11:13:05', '2026-11-03 11:51:04');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_number` varchar(30) NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `lead_id` int(10) UNSIGNED DEFAULT NULL,
  `customer_name` varchar(191) NOT NULL,
  `customer_phone` varchar(50) NOT NULL,
  `customer_location` varchar(255) NOT NULL,
  `customer_email` varchar(191) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'staff',
  `status` varchar(30) DEFAULT 'unpaid',
  `currency_code` varchar(10) NOT NULL DEFAULT 'TZS',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shop_id` int(10) UNSIGNED DEFAULT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `confirmed_by` int(10) UNSIGNED DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `rejected_by` int(10) UNSIGNED DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `cancelled_by` int(10) UNSIGNED DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `fulfilled_by` int(10) UNSIGNED DEFAULT NULL,
  `fulfilled_at` datetime DEFAULT NULL,
  `fulfilled_invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `fulfilled_invoice_number` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `Amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `stage` varchar(30) DEFAULT 'shop'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `tenant_id`, `order_number`, `contact_id`, `lead_id`, `customer_name`, `customer_phone`, `customer_location`, `customer_email`, `source`, `status`, `currency_code`, `subtotal`, `total`, `shop_id`, `warehouse_id`, `notes`, `assigned_to`, `created_by`, `confirmed_by`, `confirmed_at`, `rejected_by`, `rejected_at`, `rejection_reason`, `cancelled_by`, `cancelled_at`, `cancel_reason`, `fulfilled_by`, `fulfilled_at`, `fulfilled_invoice_id`, `fulfilled_invoice_number`, `created_at`, `updated_at`, `Amount_paid`, `stage`) VALUES
(3, 1, 'ORD-000001', NULL, NULL, 'bashiru ally', '0629102021', 'KINONDONI DISTRICT DAR ES SALAAM', 'bashiru@gmail.com', 'staff', 'unpaid', 'TZS', 60000.00, 60000.00, NULL, NULL, '', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-27 11:05:25', '2026-10-31 06:49:53', 0.00, 'shop'),
(4, 1, 'ORD-000002', NULL, NULL, 'agnes', '+255754696088', 'UBUNGO DISTRICT', NULL, 'storefront', 'unpaid', 'TZS', 2000.00, 2000.00, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-27 12:42:41', '2026-10-29 13:41:17', 0.00, 'shop'),
(62, 1, 'ORD-000022', NULL, 6, 'mapunda', '+255687039380', 'KINONDONI DISTRICT', NULL, 'storefront', 'unpaid', 'TZS', 2000.00, 2000.00, 7, 11, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-28 02:27:19', '2026-10-31 06:49:53', 0.00, 'shop'),
(63, 1, 'ORD-000023', NULL, 5, 'husen', '+255754696088', 'UBUNGO DISTRICT', NULL, 'storefront', 'unpaid', 'TZS', 2000.00, 2000.00, 7, 11, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-28 05:43:18', '2026-10-31 06:49:53', 0.00, 'shop'),
(64, 1, 'ORD-000024', NULL, 4, 'boss', '0999999999', 'mbagala', NULL, 'storefront', 'unpaid', 'TZS', 2000.00, 2000.00, 7, 11, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-28 05:45:29', '2026-10-31 06:49:53', 0.00, 'shop'),
(65, 1, 'ORD-000025', 7, 51, 'emmanuel', '0765432212', 'Kigamboni', NULL, 'storefront', 'paid', 'TZS', 28000.00, 28000.00, 1, 1, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-29 12:42:45', '2026-10-29 14:38:34', 28000.00, 'transport'),
(72, 1, 'ORD-000026', NULL, 1, 'Bs', '0629102021', 'KINONDONI DISTRICT DAR ES SALAAM', NULL, 'staff', 'unpaid', 'TZS', 1000.00, 1000.00, NULL, NULL, '', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-31 06:59:08', '2026-11-03 06:43:31', 500.00, 'shop'),
(82, 1, 'ORD-000027', 9, 53, 'Bikwisa', '0712345678', 'BAGAMOYO', NULL, 'staff', 'unpaid', 'TZS', 0.00, 0.00, NULL, NULL, '', NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 06:29:06', '2026-11-03 06:29:06', 0.00, 'shop'),
(86, 1, 'ORD-000028', 11, 73, 'hashim', '+255754696088', 'UBUNGO DISTRICT', NULL, 'storefront', 'under_review', 'TZS', 28000.00, 28000.00, 11, 17, NULL, NULL, 46, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 11:34:25', '2026-11-03 11:43:54', 28000.00, 'transport'),
(90, 1, 'ORD-000029', 10, 81, 'mwita', '+255767427016', 'KINONDONI DISTRICT', NULL, 'storefront', 'under_review', 'TZS', 56000.00, 56000.00, 11, 17, NULL, NULL, 46, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 11:51:04', '2026-11-03 11:52:16', 56000.00, 'transport');

--
-- Triggers `orders`
--
DELIMITER $$
CREATE TRIGGER `trg_orders_no_godown_ins` BEFORE INSERT ON `orders` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orders cannot be fulfilled from a Godown. Choose a store.';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_orders_no_godown_upd` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND NOT (NEW.warehouse_id <=> OLD.warehouse_id)
     AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Orders cannot be fulfilled from a Godown. Choose a store.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `line_no` smallint(5) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `product_code_snapshot` varchar(100) NOT NULL,
  `product_name_snapshot` varchar(191) NOT NULL,
  `product_description_snapshot` text DEFAULT NULL,
  `unit_label_snapshot` varchar(50) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate_snapshot` decimal(5,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`order_item_id`, `tenant_id`, `order_id`, `line_no`, `product_id`, `product_code_snapshot`, `product_name_snapshot`, `product_description_snapshot`, `unit_label_snapshot`, `quantity`, `unit_price`, `tax_rate_snapshot`, `line_total`, `notes`, `created_at`) VALUES
(2, 1, 3, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 30.00, 2000.00, 1.00, 60000.00, NULL, '2026-10-27 11:05:25'),
(3, 1, 4, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, NULL, '2026-10-27 12:42:41'),
(63, 1, 62, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, NULL, '2026-10-28 02:27:19'),
(64, 1, 63, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, NULL, '2026-10-28 05:43:18'),
(65, 1, 64, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, NULL, '2026-10-28 05:45:29'),
(66, 1, 65, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 1.00, 28000.00, 18.00, 28000.00, NULL, '2026-10-29 12:42:45'),
(69, 1, 86, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 1.00, 28000.00, 18.00, 28000.00, NULL, '2026-11-03 11:34:25'),
(72, 1, 90, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 2.00, 28000.00, 18.00, 56000.00, NULL, '2026-11-03 11:51:04');

-- --------------------------------------------------------

--
-- Table structure for table `order_stock_reservations`
--

CREATE TABLE `order_stock_reservations` (
  `reservation_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `order_item_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `status` enum('active','consumed','released') NOT NULL DEFAULT 'active',
  `consumed_invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `release_reason` varchar(255) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_stock_reservations`
--

INSERT INTO `order_stock_reservations` (`reservation_id`, `tenant_id`, `order_id`, `order_item_id`, `product_id`, `warehouse_id`, `quantity`, `status`, `consumed_invoice_id`, `created_by`, `release_reason`, `released_at`, `consumed_at`, `created_at`, `updated_at`) VALUES
(13, 1, 62, 63, 30, 11, 1.00, 'active', NULL, 1, NULL, NULL, NULL, '2026-10-28 02:27:19', '2026-10-28 02:27:19'),
(14, 1, 63, 64, 30, 11, 1.00, 'active', NULL, 1, NULL, NULL, NULL, '2026-10-28 05:43:18', '2026-10-28 05:43:18'),
(15, 1, 64, 65, 30, 11, 1.00, 'active', NULL, 1, NULL, NULL, NULL, '2026-10-28 05:45:29', '2026-10-28 05:45:29'),
(16, 1, 65, 66, 6, 1, 1.00, 'active', NULL, 1, NULL, NULL, NULL, '2026-10-29 12:42:45', '2026-10-29 12:42:45'),
(17, 1, 86, 69, 6, 17, 1.00, 'active', NULL, 46, NULL, NULL, NULL, '2026-11-03 11:34:25', '2026-11-03 11:34:25'),
(19, 1, 90, 72, 6, 17, 2.00, 'consumed', NULL, 46, NULL, NULL, '2026-11-03 14:52:16', '2026-11-03 11:51:04', '2026-11-03 11:52:16');

--
-- Triggers `order_stock_reservations`
--
DELIMITER $$
CREATE TRIGGER `trg_reservations_no_godown` BEFORE INSERT ON `order_stock_reservations` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock cannot be reserved at a Godown. Choose a store.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` datetime NOT NULL,
  `payment_method_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `submission_token` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `tenant_id`, `invoice_id`, `contact_id`, `amount`, `payment_date`, `payment_method_id`, `created_by`, `created_at`, `submission_token`) VALUES
(1, 1, 1, 4, 198240.00, '2026-09-24 00:00:00', 1, 1, '2026-10-29 05:04:19', NULL),
(2, 1, 2, 5, 14160.00, '2026-10-12 00:00:00', 1, 1, '2026-10-29 05:04:19', NULL),
(3, 1, 3, 2, 372880.00, '2026-10-27 00:00:00', 1, 1, '2026-10-29 05:04:19', NULL),
(4, 1, 4, 6, 10300.00, '2026-09-02 00:00:00', 1, 1, '2026-10-29 05:04:19', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment_methods`
--

CREATE TABLE `payment_methods` (
  `payment_method_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_methods`
--

INSERT INTO `payment_methods` (`payment_method_id`, `tenant_id`, `name`) VALUES
(1, 1, 'Cash'),
(2, 1, 'Mobile Money'),
(3, 1, 'Bank Transfer'),
(4, 1, 'Card');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `permission_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`permission_id`, `name`, `description`) VALUES
(1, 'manage_users', 'Create, edit and delete system users'),
(2, 'manage_contacts', 'Manage customers'),
(3, 'manage_suppliers', 'Manage suppliers'),
(4, 'manage_products', 'Manage products and categories'),
(5, 'manage_inventory', 'Manage inventory and stock adjustments'),
(6, 'manage_pos', 'Create and manage sales'),
(7, 'manage_invoices', 'Create and manage invoices'),
(8, 'manage_receipts', 'Record and view payments'),
(9, 'view_reports', 'View reports'),
(10, 'view_dashboard', 'View the dashboard overview'),
(11, 'manage_expenses', 'Record and view expenses'),
(12, 'view_activity_log', 'View the activity log'),
(13, 'manage_settings', 'Manage system settings'),
(15, 'manage_shops', 'Manage shops and shop access'),
(20, 'manage_orders', 'Record and manage customer orders'),
(21, 'manage_leads', 'Manage leads and customer follow-ups'),
(22, 'manage_godown', 'Configure the central Godown, receive stock, and request transfers'),
(23, 'approve_godown_transfers', 'Approve, cancel, or resolve Godown stock transfers'),
(24, 'dispatch_godown_transfers', 'Dispatch approved Godown stock transfers'),
(25, 'receive_godown_transfers', 'Receive Godown stock transfers and record discrepancies'),
(26, 'manage_stores', 'Create and manage stores and their stock'),
(27, 'view_godown', 'View Godown stock and transfers (read-only)'),
(28, 'request_godown_transfers', 'Request products from the default Godown for an assigned shop'),
(29, 'manage_purchases', 'Manage purchases');

-- --------------------------------------------------------

--
-- Table structure for table `pos_orders`
--

CREATE TABLE `pos_orders` (
  `order_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `session_id` int(10) UNSIGNED DEFAULT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `order_number` varchar(100) NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `order_date` datetime NOT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pos_orders`
--

INSERT INTO `pos_orders` (`order_id`, `tenant_id`, `session_id`, `warehouse_id`, `order_number`, `contact_id`, `order_date`, `subtotal`, `tax_amount`, `discount`, `total`, `status`, `created_by`, `created_at`) VALUES
(1, 1, 1, NULL, 'POS-20260819-413', 5, '2026-08-19 15:53:17', 68500.00, 11970.00, 0.00, 80470.00, 'completed', 1, '2026-08-19 12:53:17'),
(2, 1, 1, NULL, 'POS-20260821-177', 3, '2026-08-21 11:05:39', 61000.00, 10980.00, 0.00, 71980.00, 'completed', 1, '2026-08-21 08:05:39'),
(3, 1, 1, NULL, 'POS-20260802-704', 6, '2026-08-02 17:00:06', 24500.00, 4410.00, 0.00, 28910.00, 'completed', 1, '2026-08-02 14:00:06'),
(4, 1, 1, NULL, 'POS-20260922-460', 6, '2026-09-22 15:32:50', 123000.00, 22140.00, 0.00, 145140.00, 'cancelled', 1, '2026-09-22 12:32:50'),
(5, 1, 1, NULL, 'POS-20260922-377', 1, '2026-09-22 09:00:33', 112000.00, 20160.00, 0.00, 132160.00, 'completed', 1, '2026-09-22 06:00:33'),
(6, 1, 1, NULL, 'POS-20260923-800', 6, '2026-09-23 10:06:20', 92000.00, 16560.00, 0.00, 108560.00, 'completed', 1, '2026-09-23 07:06:20'),
(7, 1, 1, NULL, 'POS-20261018-831', 4, '2026-10-18 18:51:09', 31000.00, 5580.00, 0.00, 36580.00, 'cancelled', 1, '2026-10-18 15:51:09'),
(8, 1, 1, NULL, 'POS-20261028-035', 6, '2026-10-28 14:21:41', 86000.00, 15120.00, 0.00, 101120.00, 'completed', 1, '2026-10-28 11:21:41'),
(9, 1, 1, NULL, 'POS-20261001-844', 4, '2026-10-01 12:09:10', 112000.00, 20160.00, 0.00, 132160.00, 'completed', 1, '2026-10-01 09:09:10'),
(12, 1, 1, 1, 'POS-20261029-054', NULL, '2026-10-29 18:07:41', 147000.00, 25200.00, 0.00, 172200.00, 'completed', 1, '2026-10-29 15:07:41'),
(25, 1, NULL, 14, 'POS-20261102-676', NULL, '2026-11-02 14:23:55', 1000.00, 0.00, 0.00, 1000.00, 'completed', 1, '2026-11-02 11:23:55'),
(26, 1, NULL, 17, 'POS-20261102-614', NULL, '2026-11-02 17:43:11', 28000.00, 0.00, 0.00, 28000.00, 'completed', 46, '2026-11-02 14:43:11');

--
-- Triggers `pos_orders`
--
DELIMITER $$
CREATE TRIGGER `trg_pos_orders_no_godown` BEFORE INSERT ON `pos_orders` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A Godown cannot sell. Transfer stock to a store first.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `pos_order_items`
--

CREATE TABLE `pos_order_items` (
  `order_item_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  `total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pos_order_items`
--

INSERT INTO `pos_order_items` (`order_item_id`, `order_id`, `warehouse_id`, `product_id`, `description`, `quantity`, `unit_price`, `tax_amount`, `subtotal`, `total`) VALUES
(1, 1, NULL, 3, 'Mango Juice 1L', 4.00, 3500.00, 2520.00, 14000.00, 16520.00),
(2, 1, NULL, 5, 'Phone Charger USB', 8.00, 7000.00, 10080.00, 56000.00, 66080.00),
(3, 1, NULL, 9, 'Delivery Service (per km)', 1.00, 1000.00, 0.00, 1000.00, 1000.00),
(4, 2, NULL, 1, 'Mineral Water 1.5L', 1.00, 1500.00, 270.00, 1500.00, 1770.00),
(5, 2, NULL, 3, 'Mango Juice 1L', 4.00, 3500.00, 2520.00, 14000.00, 16520.00),
(6, 2, NULL, 5, 'Phone Charger USB', 2.00, 7000.00, 2520.00, 14000.00, 16520.00),
(7, 3, NULL, 3, 'Mango Juice 1L', 2.00, 3500.00, 1260.00, 7000.00, 8260.00),
(8, 4, NULL, 1, 'Mineral Water 1.5L', 8.00, 1500.00, 2160.00, 12000.00, 14160.00),
(9, 4, NULL, 2, 'Soda 300ml (Crate)', 1.00, 15000.00, 2700.00, 15000.00, 17700.00),
(10, 4, NULL, 4, 'LED Bulb 9W', 3.00, 3000.00, 1620.00, 9000.00, 10620.00),
(11, 5, NULL, 6, 'Bluetooth Speaker', 1.00, 28000.00, 5040.00, 28000.00, 33040.00),
(12, 6, NULL, 3, 'Mango Juice 1L', 7.00, 3500.00, 4410.00, 24500.00, 28910.00),
(13, 6, NULL, 8, 'Cooking Oil 5L', 4.00, 19500.00, 14040.00, 78000.00, 92040.00),
(14, 7, NULL, 1, 'Mineral Water 1.5L', 4.00, 1500.00, 1080.00, 6000.00, 7080.00),
(15, 7, NULL, 3, 'Mango Juice 1L', 2.00, 3500.00, 1260.00, 7000.00, 8260.00),
(16, 8, NULL, 4, 'LED Bulb 9W', 2.00, 3000.00, 1080.00, 6000.00, 7080.00),
(17, 8, NULL, 8, 'Cooking Oil 5L', 6.00, 19500.00, 21060.00, 117000.00, 138060.00),
(18, 8, NULL, 9, 'Delivery Service (per km)', 2.00, 1000.00, 0.00, 2000.00, 2000.00),
(19, 9, NULL, 6, 'Bluetooth Speaker', 1.00, 28000.00, 5040.00, 28000.00, 33040.00),
(22, 12, 1, 6, 'Bluetooth Speaker', 5.00, 28000.00, 25200.00, 140000.00, 165200.00),
(23, 12, 1, 9, 'Delivery Service (per km)', 7.00, 1000.00, 0.00, 7000.00, 7000.00),
(32, 25, 14, 9, 'Delivery Service (per km)', 1.00, 1000.00, 0.00, 1000.00, 1000.00),
(33, 26, 17, 6, 'Bluetooth Speaker', 1.00, 28000.00, 0.00, 28000.00, 28000.00);

--
-- Triggers `pos_order_items`
--
DELIMITER $$
CREATE TRIGGER `trg_pos_order_items_no_godown` BEFORE INSERT ON `pos_order_items` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A Godown cannot sell. Transfer stock to a store first.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `pos_payments`
--

CREATE TABLE `pos_payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method_id` int(10) UNSIGNED DEFAULT NULL,
  `paid_at` datetime NOT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pos_payments`
--

INSERT INTO `pos_payments` (`payment_id`, `order_id`, `amount`, `payment_method_id`, `paid_at`, `created_by`) VALUES
(1, 1, 80470.00, 1, '2026-08-19 15:53:17', 1),
(2, 2, 71980.00, 1, '2026-08-21 11:05:39', 1),
(3, 3, 28910.00, 1, '2026-08-02 17:00:06', 1),
(4, 5, 132160.00, 1, '2026-09-22 09:00:33', 1),
(5, 6, 108560.00, 1, '2026-09-23 10:06:20', 1),
(6, 8, 101120.00, 1, '2026-10-28 14:21:41', 1),
(7, 9, 132160.00, 1, '2026-10-01 12:09:10', 1),
(10, 12, 172200.00, 1, '2026-10-29 18:07:41', 1),
(11, 25, 1000.00, 1, '2026-11-02 14:23:56', 1),
(12, 26, 28000.00, 1, '2026-11-02 17:43:11', 46);

-- --------------------------------------------------------

--
-- Table structure for table `pos_sessions`
--

CREATE TABLE `pos_sessions` (
  `session_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `session_number` varchar(100) NOT NULL,
  `start_time` datetime NOT NULL,
  `starting_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pos_sessions`
--

INSERT INTO `pos_sessions` (`session_id`, `tenant_id`, `user_id`, `warehouse_id`, `session_number`, `start_time`, `starting_cash`, `is_closed`) VALUES
(1, 1, 1, 1, 'POS-SESSION-20261029-435', '2026-08-19 15:53:17', 0.00, 0);

--
-- Triggers `pos_sessions`
--
DELIMITER $$
CREATE TRIGGER `trg_pos_sessions_no_godown` BEFORE INSERT ON `pos_sessions` FOR EACH ROW BEGIN
  IF NEW.warehouse_id IS NOT NULL AND EXISTS (SELECT 1 FROM warehouses WHERE warehouse_id = NEW.warehouse_id AND location_type = 'godown') THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A Godown cannot open a POS session.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `code` varchar(100) NOT NULL,
  `name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `unit_id` int(10) UNSIGNED DEFAULT NULL,
  `purchase_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `min_stock_level` decimal(12,2) NOT NULL DEFAULT 0.00,
  `date_added` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_service` tinyint(1) NOT NULL DEFAULT 0,
  `variant_size` varchar(50) DEFAULT NULL,
  `variant_color` varchar(50) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `tenant_id`, `category_id`, `code`, `name`, `description`, `barcode`, `sku`, `brand`, `unit_id`, `purchase_price`, `sale_price`, `tax_rate`, `min_stock_level`, `date_added`, `is_active`, `is_service`, `variant_size`, `variant_color`, `image`, `created_by`, `created_at`) VALUES
(1, 1, 1, 'PRD-001', 'Mineral Water 1.5L', NULL, NULL, NULL, NULL, NULL, 900.00, 1500.00, 18.00, 10.00, NULL, 1, 0, NULL, NULL, NULL, 1, '2026-10-29 05:04:19'),
(4, 1, 2, 'PRD-004', 'LED Bulb 9W', NULL, NULL, NULL, NULL, NULL, 1800.00, 3000.00, 18.00, 12.00, NULL, 1, 0, NULL, NULL, NULL, 1, '2026-10-29 05:04:19'),
(5, 1, 2, 'PRD-005', 'Phone Charger USB', NULL, NULL, NULL, NULL, NULL, 4500.00, 7000.00, 18.00, 6.00, NULL, 1, 0, NULL, NULL, NULL, 1, '2026-10-29 05:04:19'),
(6, 1, 2, 'PRD-006', 'Bluetooth Speaker', '', '123456789', '', NULL, NULL, 18000.00, 28000.00, 18.00, 5.00, NULL, 1, 0, NULL, NULL, 'uploads/products/045f43cfec795f96e70a2a32a8a7c8fb.jpg', 1, '2026-10-29 05:04:19'),
(7, 1, 3, 'PRD-007', 'Laundry Detergent 2kg', NULL, NULL, NULL, NULL, NULL, 8500.00, 12000.00, 18.00, 5.00, NULL, 1, 0, NULL, NULL, NULL, 1, '2026-10-29 05:04:19'),
(8, 1, 3, 'PRD-008', 'Cooking Oil 5L', '', '', '', NULL, NULL, 16000.00, 19500.00, 18.00, 5.00, NULL, 1, 0, NULL, NULL, 'uploads/products/3ce33727e6811adc0e372e289183cd09.png', 1, '2026-10-29 05:04:19'),
(9, 1, 2, 'PRD-009', 'Delivery Service (per km)', '', '', '', 'GSP GROUP', NULL, 0.00, 1000.00, 0.00, 0.00, NULL, 1, 0, NULL, NULL, NULL, 1, '2026-10-29 05:04:19');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `category_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `parent_category_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `product_categories`
--

INSERT INTO `product_categories` (`category_id`, `tenant_id`, `parent_category_id`, `name`, `description`) VALUES
(1, 1, NULL, 'Beverages', NULL),
(2, 1, NULL, 'Electronics', NULL),
(3, 1, NULL, 'Household', NULL),
(4, 1, 3, 'Carpet', 'home carpets');

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `variant_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `options` text DEFAULT NULL,
  `price_adjustment` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `purchase_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`purchase_id`, `tenant_id`, `product_id`, `supplier_id`, `quantity`, `total`, `amount_paid`, `purchase_date`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 6, 3, 20.00, 360000.00, 300000.00, '2026-11-02', 'next week', 49, '2026-11-02 16:43:22', '2026-11-02 16:43:22'),
(3, 1, 4, 5, 10.00, 30000.00, 30000.00, NULL, '', 49, '2026-11-03 05:37:05', '2026-11-03 05:37:05'),
(4, 1, 6, 5, 10.00, 180000.00, 100000.00, '2026-11-03', '', 49, '2026-11-03 08:17:17', '2026-11-03 08:17:17');

-- --------------------------------------------------------

--
-- Table structure for table `quotations`
--

CREATE TABLE `quotations` (
  `quotation_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `quotation_number` varchar(100) NOT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `contact_id` int(10) UNSIGNED NOT NULL,
  `issue_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `status` enum('draft','sent','accepted','rejected','expired','converted') NOT NULL DEFAULT 'draft',
  `reference` varchar(191) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `terms` text DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `converted_invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quotations`
--

INSERT INTO `quotations` (`quotation_id`, `tenant_id`, `quotation_number`, `warehouse_id`, `contact_id`, `issue_date`, `valid_until`, `status`, `reference`, `notes`, `terms`, `subtotal`, `tax_amount`, `total`, `converted_invoice_id`, `converted_at`, `created_by`, `created_at`, `updated_at`) VALUES
(2, 1, 'QUO-000001', 1, 6, '2026-10-30', '2026-11-29', 'draft', '', '', '', 256500.00, 46170.00, 302670.00, NULL, NULL, 1, '2026-10-30 12:45:01', '2026-10-30 12:45:01'),
(3, 1, 'QUO-000002', 1, 6, '2026-11-01', '2026-12-01', 'converted', '2026111-AZM', '', '', 31000.00, 0.00, 31000.00, 23, '2026-11-02 09:59:09', 1, '2026-11-01 17:59:46', '2026-11-02 06:59:09'),
(5, 1, 'QUO-000004', 1, 9, '2026-11-02', '2026-12-02', 'converted', '2026112-BK', '', '', 140000.00, 0.00, 140000.00, 24, '2026-11-02 10:05:09', 1, '2026-11-02 07:04:09', '2026-11-02 07:05:09');

-- --------------------------------------------------------

--
-- Table structure for table `quotation_items`
--

CREATE TABLE `quotation_items` (
  `quotation_item_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `quotation_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quotation_items`
--

INSERT INTO `quotation_items` (`quotation_item_id`, `tenant_id`, `quotation_id`, `warehouse_id`, `product_id`, `description`, `quantity`, `unit_price`, `tax_amount`, `subtotal`, `total`, `discount`) VALUES
(2, 1, 2, 1, 6, 'Bluetooth Speaker', 3.00, 28000.00, 15120.00, 84000.00, 99120.00, 0.00),
(3, 1, 2, 1, 8, 'Cooking Oil 5L', 5.00, 19500.00, 17550.00, 97500.00, 115050.00, 0.00),
(4, 1, 2, 1, NULL, 'Soda 300ml (Crate)', 5.00, 15000.00, 13500.00, 75000.00, 88500.00, 0.00),
(5, 1, 3, 1, 6, 'Bluetooth Speaker', 1.00, 28000.00, 0.00, 28000.00, 28000.00, 0.00),
(6, 1, 3, 1, 4, 'LED Bulb 9W', 1.00, 3000.00, 0.00, 3000.00, 3000.00, 0.00),
(9, 1, 5, 1, 6, 'Bluetooth Speaker', 5.00, 28000.00, 0.00, 140000.00, 140000.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `tenant_id`, `name`, `description`) VALUES
(1, 1, 'Administrator', 'Full system access'),
(15, 1, 'shop manager', 'manages shop'),
(24, 1, 'Store manager', ''),
(25, 1, 'Godown Manager', '');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_permission_id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `permission_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_permission_id`, `role_id`, `permission_id`) VALUES
(10, 1, 1),
(16, 1, 2),
(9, 1, 3),
(6, 1, 4),
(3, 1, 5),
(5, 1, 6),
(4, 1, 7),
(7, 1, 8),
(13, 1, 9),
(12, 1, 10),
(2, 1, 11),
(11, 1, 12),
(8, 1, 13),
(27, 1, 15),
(56, 1, 20),
(58, 1, 21),
(86, 1, 22),
(84, 1, 23),
(85, 1, 24),
(87, 1, 25),
(94, 1, 26),
(95, 1, 27),
(96, 1, 28),
(98, 1, 29),
(66, 15, 2),
(73, 15, 4),
(68, 15, 5),
(72, 15, 6),
(69, 15, 7),
(74, 15, 8),
(76, 15, 9),
(75, 15, 10),
(67, 15, 11),
(71, 15, 20),
(70, 15, 21),
(97, 15, 28),
(101, 24, 4),
(100, 24, 5),
(105, 24, 9),
(99, 24, 11),
(103, 24, 25),
(102, 24, 26),
(104, 24, 28),
(108, 25, 2),
(117, 25, 3),
(114, 25, 4),
(111, 25, 5),
(112, 25, 7),
(116, 25, 8),
(119, 25, 9),
(109, 25, 11),
(113, 25, 20),
(110, 25, 22),
(106, 25, 23),
(107, 25, 24),
(118, 25, 27),
(115, 25, 29);

-- --------------------------------------------------------

--
-- Table structure for table `sales_returns`
--

CREATE TABLE `sales_returns` (
  `return_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `return_number` varchar(50) NOT NULL,
  `source_type` enum('pos','invoice') NOT NULL DEFAULT 'pos',
  `source_id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `shop_id` int(10) UNSIGNED DEFAULT NULL,
  `warehouse_id` int(10) UNSIGNED DEFAULT NULL,
  `return_date` datetime NOT NULL DEFAULT current_timestamp(),
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('completed','cancelled') NOT NULL DEFAULT 'completed',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `submission_token` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_return_items`
--

CREATE TABLE `sales_return_items` (
  `return_item_id` int(10) UNSIGNED NOT NULL,
  `return_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `refund_amount` decimal(12,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resale_quantity` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `migration` varchar(191) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `schema_migrations`
--

INSERT INTO `schema_migrations` (`migration`, `applied_at`) VALUES
('001_phase2_shops_stores', '2026-10-27 09:29:54'),
('002_warehouse_shop_integrity_pos_sessions', '2026-10-27 09:30:15'),
('003_orders_leads', '2026-10-27 10:59:38'),
('004_storefront_lead_requests', '2026-10-27 12:57:54'),
('005_storefront_request_notifications', '2026-10-27 13:17:39'),
('006_order_stock_reservations', '2026-10-28 01:37:35'),
('007_godown_transfers', '2026-10-28 04:25:57'),
('008_storefront_subscribers', '2026-10-28 05:23:59'),
('009_godown_shop_requests', '2026-10-29 06:09:30'),
('009_godown_store_separation', '2026-10-28 08:39:46'),
('010_fix_transfer_route_collation', '2026-10-29 06:11:08'),
('011_product_brand_date_added', '2026-10-29 06:56:57'),
('012_quotations_default_godown', '2026-10-30 05:06:51'),
('016_sales_returns', '2026-10-31 15:29:14'),
('017_phase722_invoice_integration', '2026-11-01 05:18:31'),
('018_shop_transfer_routes', '2026-11-02 12:31:14'),
('020_godown_to_shop_transfer_route', '2026-11-03 09:56:50');

-- --------------------------------------------------------

--
-- Table structure for table `shops`
--

CREATE TABLE `shops` (
  `shop_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `code` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `shops`
--

INSERT INTO `shops` (`shop_id`, `tenant_id`, `name`, `code`, `address`, `phone`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Survey', 'SHOP-001', NULL, NULL, 1, '2026-10-27 09:00:31', '2026-11-03 09:11:57'),
(7, 1, 'kariakoo', 'SHOP-002', 'Kariakoo', '0767427016', 1, '2026-10-27 09:51:15', '2026-11-03 12:17:16'),
(11, 1, 'Kariakoo GPS', 'SHOP-004', 'Kariakoo', '0987654323', 1, '2026-10-28 06:32:17', '2026-11-03 12:16:33');

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfers`
--

CREATE TABLE `stock_transfers` (
  `transfer_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `transfer_number` varchar(50) NOT NULL,
  `source_warehouse_id` int(10) UNSIGNED NOT NULL,
  `destination_shop_id` int(10) UNSIGNED DEFAULT NULL,
  `destination_warehouse_id` int(10) UNSIGNED NOT NULL,
  `status` enum('draft','approved','dispatched','received','discrepancy','cancelled') NOT NULL DEFAULT 'draft',
  `has_discrepancy` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `cancel_reason` varchar(500) DEFAULT NULL,
  `discrepancy_resolution` text DEFAULT NULL,
  `requested_by` int(10) UNSIGNED DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `dispatched_by` int(10) UNSIGNED DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `received_by` int(10) UNSIGNED DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `discrepancy_resolved_by` int(10) UNSIGNED DEFAULT NULL,
  `discrepancy_resolved_at` datetime DEFAULT NULL,
  `cancelled_by` int(10) UNSIGNED DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `stock_transfers`
--

INSERT INTO `stock_transfers` (`transfer_id`, `tenant_id`, `transfer_number`, `source_warehouse_id`, `destination_shop_id`, `destination_warehouse_id`, `status`, `has_discrepancy`, `notes`, `cancel_reason`, `discrepancy_resolution`, `requested_by`, `requested_at`, `approved_by`, `approved_at`, `dispatched_by`, `dispatched_at`, `received_by`, `received_at`, `discrepancy_resolved_by`, `discrepancy_resolved_at`, `cancelled_by`, `cancelled_at`, `created_at`, `updated_at`) VALUES
(10, 1, 'TRF-000001', 13, 1, 1, 'dispatched', 0, 'order of bra bra', NULL, NULL, 1, '2026-10-30 15:48:26', 1, '2026-10-30 15:48:52', 1, '2026-10-30 15:49:10', NULL, NULL, NULL, NULL, NULL, NULL, '2026-10-30 12:48:26', '2026-10-30 12:49:10'),
(15, 1, 'TRF-000002', 13, 1, 1, 'received', 0, NULL, NULL, NULL, 1, '2026-11-02 13:17:03', 1, '2026-11-02 13:17:18', 1, '2026-11-02 13:17:27', 1, '2026-11-02 13:17:57', NULL, NULL, NULL, NULL, '2026-11-02 10:17:03', '2026-11-02 10:17:57'),
(16, 1, 'TRF-000003', 13, 1, 1, 'received', 0, NULL, NULL, NULL, 1, '2026-11-02 14:52:54', 1, '2026-11-02 14:53:01', 1, '2026-11-02 14:53:08', 1, '2026-11-02 14:53:13', NULL, NULL, NULL, NULL, '2026-11-02 11:52:54', '2026-11-02 11:53:13'),
(17, 1, 'TRF-000004', 13, 7, 11, 'draft', 0, NULL, NULL, NULL, 1, '2026-11-02 14:56:58', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-02 11:56:58', '2026-11-02 11:56:58'),
(24, 1, 'TRF-000005', 13, 7, 11, 'received', 0, NULL, NULL, NULL, 1, '2026-11-02 16:19:19', 1, '2026-11-02 16:19:22', 1, '2026-11-02 16:19:28', 1, '2026-11-02 16:19:33', NULL, NULL, NULL, NULL, '2026-11-02 13:19:19', '2026-11-02 13:19:33'),
(25, 1, 'TRF-000006', 11, 1, 14, 'received', 0, NULL, NULL, NULL, 1, '2026-11-02 16:20:13', 1, '2026-11-02 16:20:20', 1, '2026-11-02 16:20:25', 1, '2026-11-02 16:20:29', NULL, NULL, NULL, NULL, '2026-11-02 13:20:13', '2026-11-02 13:20:29'),
(26, 1, 'TRF-000007', 1, 7, 15, 'received', 0, NULL, NULL, NULL, 1, '2026-11-02 16:22:15', 1, '2026-11-02 16:22:19', 1, '2026-11-02 16:22:25', 1, '2026-11-02 16:22:30', NULL, NULL, NULL, NULL, '2026-11-02 13:22:15', '2026-11-02 13:22:30'),
(27, 1, 'TRF-000008', 14, 11, 17, 'received', 0, 'i need this ASAP', NULL, NULL, 46, '2026-11-02 17:19:49', 47, '2026-11-02 17:33:48', 47, '2026-11-02 17:33:59', 46, '2026-11-02 17:42:31', NULL, NULL, NULL, NULL, '2026-11-02 14:19:49', '2026-11-02 14:42:31'),
(28, 1, 'TRF-000009', 13, 7, 11, 'received', 0, NULL, NULL, NULL, 49, '2026-11-03 08:38:13', 49, '2026-11-03 08:38:18', 49, '2026-11-03 08:38:26', 1, '2026-11-03 08:39:32', NULL, NULL, NULL, NULL, '2026-11-03 05:38:13', '2026-11-03 05:39:32'),
(29, 1, 'TRF-000010', 13, 7, 11, 'received', 0, NULL, NULL, NULL, 49, '2026-11-03 11:19:09', 49, '2026-11-03 11:19:19', 49, '2026-11-03 11:19:31', 1, '2026-11-03 12:18:40', NULL, NULL, NULL, NULL, '2026-11-03 08:19:09', '2026-11-03 09:18:40'),
(30, 1, 'TRF-000011', 13, 7, 11, 'received', 0, NULL, NULL, NULL, 49, '2026-11-03 12:33:41', 49, '2026-11-03 12:33:45', 49, '2026-11-03 12:33:52', 48, '2026-11-03 12:39:21', NULL, NULL, NULL, NULL, '2026-11-03 09:33:41', '2026-11-03 09:39:21'),
(31, 1, 'TRF-000012', 13, 7, 11, 'dispatched', 0, NULL, NULL, NULL, 48, '2026-11-03 12:40:19', 49, '2026-11-03 12:41:10', 49, '2026-11-03 12:41:16', NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 09:40:19', '2026-11-03 09:41:16'),
(33, 1, 'TRF-000013', 11, 11, 17, 'cancelled', 0, NULL, 'out of stock', NULL, 46, '2026-11-03 13:03:38', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 48, '2026-11-03 13:16:22', '2026-11-03 10:03:38', '2026-11-03 10:16:22'),
(36, 1, 'TRF-000014', 13, 11, 17, 'received', 0, NULL, NULL, NULL, 46, '2026-11-03 13:18:18', 49, '2026-11-03 13:19:02', 49, '2026-11-03 13:19:11', 46, '2026-11-03 13:19:47', NULL, NULL, NULL, NULL, '2026-11-03 10:18:18', '2026-11-03 10:19:47'),
(38, 1, 'TRF-000015', 13, 11, 17, 'received', 0, NULL, NULL, NULL, 46, '2026-11-03 13:22:57', 49, '2026-11-03 13:23:32', 49, '2026-11-03 13:23:38', 46, '2026-11-03 13:24:32', NULL, NULL, NULL, NULL, '2026-11-03 10:22:57', '2026-11-03 10:24:32'),
(39, 1, 'TRF-000016', 1, 11, 17, 'received', 0, NULL, NULL, NULL, 46, '2026-11-03 13:25:56', 50, '2026-11-03 13:42:56', 50, '2026-11-03 13:43:05', 46, '2026-11-03 13:43:54', NULL, NULL, NULL, NULL, '2026-11-03 10:25:56', '2026-11-03 10:43:54'),
(40, 1, 'TRF-000017', 13, 11, 17, 'draft', 0, NULL, NULL, NULL, 46, '2026-11-03 14:53:11', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-11-03 11:53:11', '2026-11-03 11:53:11');

--
-- Triggers `stock_transfers`
--
DELIMITER $$
CREATE TRIGGER `trg_transfers_route` BEFORE INSERT ON `stock_transfers` FOR EACH ROW BEGIN
  DECLARE src_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE dst_type VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  DECLARE dst_shop INT UNSIGNED;
  DECLARE dst_active TINYINT;

  SELECT location_type INTO src_type
    FROM warehouses
   WHERE warehouse_id = NEW.source_warehouse_id AND tenant_id = NEW.tenant_id;

  SELECT location_type, shop_id, is_active INTO dst_type, dst_shop, dst_active
    FROM warehouses
   WHERE warehouse_id = NEW.destination_warehouse_id AND tenant_id = NEW.tenant_id;

  IF NEW.source_warehouse_id = NEW.destination_warehouse_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Source and destination must be different locations.';
  END IF;

  IF dst_active IS NULL OR dst_active <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The destination is not an active location.';
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM transfer_routes
     WHERE from_type = src_type COLLATE utf8mb4_unicode_ci
       AND to_type = dst_type COLLATE utf8mb4_unicode_ci
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This transfer route is not allowed.';
  END IF;

  SET NEW.destination_shop_id = dst_shop;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfer_items`
--

CREATE TABLE `stock_transfer_items` (
  `item_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `transfer_id` int(10) UNSIGNED NOT NULL,
  `line_no` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `product_id` int(10) UNSIGNED NOT NULL,
  `requested_quantity` decimal(12,2) NOT NULL,
  `dispatched_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `received_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `damaged_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shortage_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `excess_quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `stock_transfer_items`
--

INSERT INTO `stock_transfer_items` (`item_id`, `tenant_id`, `transfer_id`, `line_no`, `product_id`, `requested_quantity`, `dispatched_quantity`, `received_quantity`, `damaged_quantity`, `shortage_quantity`, `excess_quantity`, `line_notes`, `created_at`, `updated_at`) VALUES
(9, 1, 10, 1, 8, 10.00, 10.00, 0.00, 0.00, 0.00, 0.00, NULL, '2026-10-30 12:48:26', '2026-10-30 12:49:10'),
(14, 1, 15, 1, 6, 5.00, 5.00, 5.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 10:17:03', '2026-11-02 10:17:57'),
(15, 1, 16, 1, 6, 5.00, 5.00, 5.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 11:52:54', '2026-11-02 11:53:13'),
(16, 1, 17, 1, 6, 1.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 11:56:58', '2026-11-02 11:56:58'),
(18, 1, 24, 1, 6, 5.00, 5.00, 5.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 13:19:19', '2026-11-02 13:19:33'),
(19, 1, 25, 1, 6, 2.00, 2.00, 2.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 13:20:13', '2026-11-02 13:20:29'),
(20, 1, 26, 1, 6, 2.00, 2.00, 2.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 13:22:15', '2026-11-02 13:22:30'),
(21, 1, 27, 1, 6, 1.00, 1.00, 1.00, 0.00, 0.00, 0.00, NULL, '2026-11-02 14:19:49', '2026-11-02 14:42:31'),
(22, 1, 28, 1, 4, 5.00, 5.00, 5.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 05:38:13', '2026-11-03 05:39:32'),
(23, 1, 29, 1, 6, 10.00, 10.00, 10.00, 0.00, 0.00, 0.00, 'Goods confirmed physically received; receipt recorded following administrator confirmation.', '2026-11-03 08:19:09', '2026-11-03 09:18:40'),
(24, 1, 30, 1, 6, 10.00, 10.00, 10.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 09:33:41', '2026-11-03 09:39:21'),
(25, 1, 31, 1, 6, 10.00, 10.00, 0.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 09:40:19', '2026-11-03 09:41:16'),
(27, 1, 33, 1, 6, 3.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 10:03:38', '2026-11-03 10:03:38'),
(30, 1, 36, 1, 6, 10.00, 10.00, 10.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 10:18:18', '2026-11-03 10:19:47'),
(32, 1, 38, 1, 6, 5.00, 5.00, 5.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 10:22:57', '2026-11-03 10:24:32'),
(33, 1, 39, 1, 6, 8.00, 8.00, 8.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 10:25:56', '2026-11-03 10:43:54'),
(34, 1, 40, 1, 6, 4.00, 0.00, 0.00, 0.00, 0.00, 0.00, NULL, '2026-11-03 11:53:11', '2026-11-03 11:53:11');

-- --------------------------------------------------------

--
-- Table structure for table `storefront_lead_requests`
--

CREATE TABLE `storefront_lead_requests` (
  `request_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `lead_id` int(10) UNSIGNED NOT NULL,
  `currency_code` varchar(10) NOT NULL DEFAULT 'TZS',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `converted_order_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `converted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `storefront_lead_requests`
--

INSERT INTO `storefront_lead_requests` (`request_id`, `tenant_id`, `lead_id`, `currency_code`, `subtotal`, `converted_order_id`, `created_at`, `converted_at`) VALUES
(2, 1, 3, 'TZS', 2000.00, NULL, '2026-10-27 13:06:38', NULL),
(3, 1, 4, 'TZS', 2000.00, 64, '2026-10-27 13:26:13', '2026-10-28 08:45:29'),
(4, 1, 5, 'TZS', 2000.00, 63, '2026-10-27 13:59:48', '2026-10-28 08:43:18'),
(5, 1, 6, 'TZS', 2000.00, 62, '2026-10-27 15:21:54', '2026-10-28 05:27:19'),
(47, 1, 51, 'TZS', 28000.00, 65, '2026-10-29 12:37:05', '2026-10-29 15:42:45'),
(49, 1, 53, 'TZS', 28000.00, NULL, '2026-10-31 06:15:56', NULL),
(52, 1, 70, 'TZS', 28000.00, NULL, '2026-11-03 07:30:16', NULL),
(53, 1, 71, 'TZS', 112000.00, NULL, '2026-11-03 07:53:36', NULL),
(55, 1, 73, 'TZS', 28000.00, 86, '2026-11-03 07:59:51', '2026-11-03 14:34:25'),
(63, 1, 81, 'TZS', 56000.00, 90, '2026-11-03 11:13:05', '2026-11-03 14:51:04');

-- --------------------------------------------------------

--
-- Table structure for table `storefront_lead_request_items`
--

CREATE TABLE `storefront_lead_request_items` (
  `request_item_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `request_id` int(10) UNSIGNED NOT NULL,
  `line_no` smallint(5) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `product_code_snapshot` varchar(100) NOT NULL,
  `product_name_snapshot` varchar(191) NOT NULL,
  `product_description_snapshot` text DEFAULT NULL,
  `unit_label_snapshot` varchar(50) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate_snapshot` decimal(5,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `storefront_lead_request_items`
--

INSERT INTO `storefront_lead_request_items` (`request_item_id`, `tenant_id`, `request_id`, `line_no`, `product_id`, `product_code_snapshot`, `product_name_snapshot`, `product_description_snapshot`, `unit_label_snapshot`, `quantity`, `unit_price`, `tax_rate_snapshot`, `line_total`, `created_at`) VALUES
(2, 1, 2, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, '2026-10-27 13:06:38'),
(3, 1, 3, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, '2026-10-27 13:26:13'),
(4, 1, 4, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, '2026-10-27 13:59:48'),
(5, 1, 5, 1, NULL, 'PRD-001', 'soap', 'for home uses', 'Litre', 1.00, 2000.00, 1.00, 2000.00, '2026-10-27 15:21:54'),
(47, 1, 47, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 1.00, 28000.00, 18.00, 28000.00, '2026-10-29 12:37:05'),
(49, 1, 49, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 1.00, 28000.00, 18.00, 28000.00, '2026-10-31 06:15:56'),
(52, 1, 52, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 1.00, 28000.00, 18.00, 28000.00, '2026-11-03 07:30:16'),
(53, 1, 53, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 4.00, 28000.00, 18.00, 112000.00, '2026-11-03 07:53:36'),
(55, 1, 55, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 1.00, 28000.00, 18.00, 28000.00, '2026-11-03 07:59:51'),
(66, 1, 63, 1, 6, 'PRD-006', 'Bluetooth Speaker', '', '', 2.00, 28000.00, 18.00, 56000.00, '2026-11-03 11:13:05');

-- --------------------------------------------------------

--
-- Table structure for table `storefront_request_notification_reads`
--

CREATE TABLE `storefront_request_notification_reads` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `last_seen_lead_id` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `storefront_request_notification_reads`
--

INSERT INTO `storefront_request_notification_reads` (`tenant_id`, `user_id`, `last_seen_lead_id`, `updated_at`) VALUES
(1, 1, 76, '2026-11-03 09:22:24'),
(1, 20, 6, '2026-10-28 06:55:53'),
(1, 46, 81, '2026-11-03 11:13:27'),
(1, 47, 73, '2026-11-03 09:46:15');

-- --------------------------------------------------------

--
-- Table structure for table `storefront_subscribers`
--

CREATE TABLE `storefront_subscribers` (
  `subscriber_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(191) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(191) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `tenant_id`, `company_name`, `first_name`, `last_name`, `email`, `phone`, `address`, `tax_id`, `created_at`) VALUES
(1, 1, 'Nakumatt Wholesale', NULL, NULL, 'wholesale@nakumatt.co.tz', '+255 718 400 001', 'Dar es Salaam', NULL, '2026-10-29 05:04:18'),
(2, 1, 'TBL Distributors', NULL, NULL, 'orders@tbl.co.tz', '+255 719 400 002', 'Dar es Salaam', NULL, '2026-10-29 05:04:18'),
(3, 1, 'Bakhresa Group', NULL, NULL, 'sales@bakhresa.com', '+255 720 400 003', 'Mombasa Rd, Dar', NULL, '2026-10-29 05:04:18'),
(4, 1, 'Kenya Canners', NULL, NULL, 'info@kencan.com', '+255 721 400 004', 'Arusha', NULL, '2026-10-29 05:04:19'),
(5, 1, 'Dezl', 'Dezl Kamata', '', '', '0712345678', '', '', '2026-11-03 04:51:38');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `setting_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `company_name` varchar(191) DEFAULT NULL,
  `company_email` varchar(191) DEFAULT NULL,
  `company_phone` varchar(50) DEFAULT NULL,
  `company_address` text DEFAULT NULL,
  `default_currency` varchar(10) NOT NULL DEFAULT 'TZS',
  `invoice_prefix` varchar(20) NOT NULL DEFAULT 'INV-',
  `invoice_next_number` int(10) UNSIGNED NOT NULL DEFAULT 1001,
  `apply_tax` tinyint(1) NOT NULL DEFAULT 1,
  `logo_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`setting_id`, `tenant_id`, `company_name`, `company_email`, `company_phone`, `company_address`, `default_currency`, `invoice_prefix`, `invoice_next_number`, `apply_tax`, `logo_path`) VALUES
(1, 1, 'TECHDOKTA INVESTMENT LIMITED & GSP LIMITED', '', '', '', 'TZS', 'INV-', 1001, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tax_rates`
--

CREATE TABLE `tax_rates` (
  `tax_rate_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tax_rates`
--

INSERT INTO `tax_rates` (`tax_rate_id`, `tenant_id`, `name`, `rate`) VALUES
(1, 1, 'No Tax', 0.00),
(2, 1, 'VAT 18%', 18.00),
(3, 1, 'Insurance', 1.00);

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

CREATE TABLE `tenants` (
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(191) NOT NULL,
  `email` varchar(191) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `currency_code` varchar(10) NOT NULL DEFAULT 'TZS',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tenants`
--

INSERT INTO `tenants` (`tenant_id`, `name`, `email`, `phone`, `address`, `currency_code`, `created_at`) VALUES
(1, 'TECHDOKTA INVESTMENT LIMITED & GSP LIMITED', '', '', '', 'TZS', '2026-08-28 12:52:20');

-- --------------------------------------------------------

--
-- Table structure for table `transfer_routes`
--

CREATE TABLE `transfer_routes` (
  `from_type` enum('store','godown','shop') NOT NULL,
  `to_type` enum('store','godown','shop') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transfer_routes`
--

INSERT INTO `transfer_routes` (`from_type`, `to_type`) VALUES
('store', 'store'),
('store', 'shop'),
('godown', 'store'),
('godown', 'shop'),
('shop', 'shop');

-- --------------------------------------------------------

--
-- Table structure for table `units_of_measurement`
--

CREATE TABLE `units_of_measurement` (
  `unit_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `short_name` varchar(20) NOT NULL,
  `is_decimal` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `units_of_measurement`
--

INSERT INTO `units_of_measurement` (`unit_id`, `tenant_id`, `name`, `short_name`, `is_decimal`) VALUES
(1, 1, 'Pieces', 'pcs', 0),
(2, 1, 'Litre', 'L', 1),
(3, 1, 'Kilogram', 'kg', 1),
(4, 1, 'Box', 'box', 0),
(5, 1, 'Roll', 'roll', 0);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `tenant_id`, `username`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `is_active`, `last_login`, `created_at`) VALUES
(1, 1, 'admin', 'dezlinvestment@gmail.com', '$2y$10$fcpe/kqLWziTy77xn6Gp0OkZdLIIn67EIFd5uhWymJ9tv2jzkm64y', 'System', 'Administrator', '+255677004313', 1, '2026-11-03 15:11:56', '2026-08-28 13:20:03'),
(20, 1, 'kariakooshop', 'kariakooshop@gmail.com', '$2y$10$1geoMyYQHX14IjaBDTPZaO3kMZp5YVVbXojQVZSUH7wj.Kr9qlLxa', 'kariakoo', 'shop', '+255688228112', 1, '2026-10-29 09:13:43', '2026-10-28 01:34:48'),
(46, 1, 'mwengeshop', 'mwengeshop@gmail.com', '$2y$10$a2wmqJO2B91K628zTQMPq.L.7FK2Lf4PBMAfEoDar/kweodp3dVoq', 'mwenge', 'shop', '', 1, '2026-11-03 15:10:41', '2026-11-02 13:24:22'),
(47, 1, 'surveyshop', 'Surveyshop@gmail.com', '$2y$10$XfaE1sNr4SUUQoc7L42RV.we32J7US1MDqFPMKg6bHJK1mi2fRFoe', 'Survey', 'Shop', '', 1, '2026-11-03 12:46:06', '2026-11-02 14:24:57'),
(48, 1, 'kariakoostore', 'Kariakoostore@gmail.com', '$2y$10$qSq5Ug2NqVkXdPCEvBTNoOwpflCHOt0rl63U4fYEK.JDc8pQ6iRhq', 'kariakoo', 'Store', '', 1, '2026-11-03 13:27:38', '2026-11-02 15:18:44'),
(49, 1, 'Godown', 'Godown@gmail.com', '$2y$10$F9Np.89ShGC8dmwOXsjlzubD2HjXTYqMZH0Sx5V.IRX4zq4.Dk3AS', 'Godown', 'Godown', '', 1, '2026-11-03 13:23:13', '2026-11-02 15:21:53'),
(50, 1, 'mwengestore', 'mwengestore@gmail.com', '$2y$10$FC46NWljPNFXsty6Zfk1deLD8x61YOFmuPO.jEVx3Uh1Q1JJXv2Yi', 'mwenge', 'store', 'admin@123', 1, '2026-11-03 13:42:32', '2026-11-03 10:41:02');

-- --------------------------------------------------------

--
-- Table structure for table `user_godowns`
--

CREATE TABLE `user_godowns` (
  `user_godown_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `warehouse_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_godowns`
--

INSERT INTO `user_godowns` (`user_godown_id`, `tenant_id`, `user_id`, `warehouse_id`, `created_at`) VALUES
(1, 1, 1, 13, '2026-10-29 08:08:24'),
(2, 1, 49, 13, '2026-11-02 15:21:53');

-- --------------------------------------------------------

--
-- Table structure for table `user_inventory_locations`
--

CREATE TABLE `user_inventory_locations` (
  `user_location_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `location_id` int(10) UNSIGNED NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_inventory_locations`
--

INSERT INTO `user_inventory_locations` (`user_location_id`, `tenant_id`, `user_id`, `location_id`, `assigned_at`) VALUES
(1, 1, 1, 13, '2026-10-31 09:53:36'),
(2, 1, 1, 14, '2026-10-31 09:53:36'),
(6, 1, 49, 13, '2026-11-02 15:21:53'),
(8, 1, 47, 14, '2026-11-03 10:30:30'),
(9, 1, 46, 17, '2026-11-03 10:39:46'),
(10, 1, 50, 1, '2026-11-03 10:41:02'),
(11, 1, 20, 15, '2026-11-03 10:41:54'),
(12, 1, 48, 11, '2026-11-03 10:42:17');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_role_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_role_id`, `user_id`, `role_id`, `tenant_id`, `assigned_at`) VALUES
(2, 1, 1, 1, '2026-10-27 06:29:22'),
(53, 49, 25, 1, '2026-11-02 15:21:53'),
(55, 47, 15, 1, '2026-11-03 10:30:30'),
(56, 46, 15, 1, '2026-11-03 10:39:46'),
(57, 50, 24, 1, '2026-11-03 10:41:02'),
(58, 20, 15, 1, '2026-11-03 10:41:54'),
(59, 48, 24, 1, '2026-11-03 10:42:17');

-- --------------------------------------------------------

--
-- Table structure for table `user_shops`
--

CREATE TABLE `user_shops` (
  `user_shop_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `shop_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_shops`
--

INSERT INTO `user_shops` (`user_shop_id`, `tenant_id`, `user_id`, `shop_id`, `created_at`) VALUES
(1, 1, 1, 1, '2026-10-27 09:00:31'),
(16, 1, 47, 1, '2026-11-03 10:30:30'),
(17, 1, 46, 11, '2026-11-03 10:39:46'),
(18, 1, 20, 7, '2026-11-03 10:41:54');

-- --------------------------------------------------------

--
-- Table structure for table `warehouses`
--

CREATE TABLE `warehouses` (
  `warehouse_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `shop_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `manager_user_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_godown` tinyint(1) GENERATED ALWAYS AS (`location_type` = 'godown') VIRTUAL,
  `location_type` enum('store','godown','shop') NOT NULL DEFAULT 'store',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `godown_tenant_key` int(10) UNSIGNED GENERATED ALWAYS AS (case when `location_type` = 'godown' and `is_active` = 1 then `tenant_id` else NULL end) STORED
) ;

--
-- Dumping data for table `warehouses`
--

INSERT INTO `warehouses` (`warehouse_id`, `tenant_id`, `shop_id`, `name`, `code`, `address`, `phone`, `manager_user_id`, `is_active`, `location_type`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Mwenge', 'STORE-001', NULL, NULL, NULL, 1, 'store', '2026-10-28 08:39:44', '2026-11-03 09:11:57'),
(11, 1, 7, 'Kariakoo', 'STORE-002', NULL, NULL, NULL, 1, 'store', '2026-10-28 08:39:44', '2026-11-03 09:11:57'),
(13, 1, NULL, 'Makongo', 'GDN-20261029-4AF0', NULL, NULL, 1, 1, 'godown', '2026-10-29 08:08:06', '2026-10-29 08:08:24'),
(14, 1, 1, 'Survey', 'SHOP-001', NULL, NULL, NULL, 1, 'shop', '2026-10-31 09:50:08', '2026-11-03 09:11:57'),
(15, 1, 7, 'kariakoo', 'SHOP-002', NULL, NULL, NULL, 1, 'shop', '2026-10-31 09:50:08', '2026-10-31 09:50:08'),
(17, 1, 11, 'Mwenge', 'SHOP-004', NULL, NULL, NULL, 1, 'shop', '2026-10-31 09:50:08', '2026-11-03 09:11:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`activity_id`),
  ADD KEY `fk_act_tenant` (`tenant_id`),
  ADD KEY `idx_act_lead` (`lead_id`,`tenant_id`,`created_at`),
  ADD KEY `idx_act_order` (`order_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_al_tenant` (`tenant_id`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`contact_id`),
  ADD UNIQUE KEY `uq_contacts_customer_number` (`tenant_id`,`customer_number`),
  ADD KEY `fk_contacts_tenant` (`tenant_id`),
  ADD KEY `fk_contacts_type` (`contact_type_id`);

--
-- Indexes for table `contact_types`
--
ALTER TABLE `contact_types`
  ADD PRIMARY KEY (`contact_type_id`);

--
-- Indexes for table `deals`
--
ALTER TABLE `deals`
  ADD PRIMARY KEY (`deal_id`),
  ADD KEY `fk_deals_tenant` (`tenant_id`);

--
-- Indexes for table `document_sequences`
--
ALTER TABLE `document_sequences`
  ADD PRIMARY KEY (`tenant_id`,`sequence_key`);

--
-- Indexes for table `document_statuses`
--
ALTER TABLE `document_statuses`
  ADD PRIMARY KEY (`status_id`),
  ADD KEY `fk_ds_tenant` (`tenant_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`expense_id`),
  ADD KEY `fk_ex_tenant` (`tenant_id`),
  ADD KEY `fk_ex_cat` (`category_id`);

--
-- Indexes for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD PRIMARY KEY (`expense_category_id`),
  ADD KEY `fk_ec_tenant` (`tenant_id`);

--
-- Indexes for table `expense_items`
--
ALTER TABLE `expense_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `fk_ei_tenant` (`tenant_id`),
  ADD KEY `fk_ei_expense` (`expense_id`);

--
-- Indexes for table `godown_receipts`
--
ALTER TABLE `godown_receipts`
  ADD PRIMARY KEY (`receipt_id`),
  ADD UNIQUE KEY `uq_godown_receipt_number` (`tenant_id`,`receipt_number`),
  ADD KEY `idx_godown_receipts_godown` (`tenant_id`,`godown_id`,`received_at`),
  ADD KEY `idx_godown_receipts_product` (`tenant_id`,`product_id`,`received_at`),
  ADD KEY `fk_gr_godown` (`godown_id`,`tenant_id`),
  ADD KEY `fk_gr_product` (`product_id`),
  ADD KEY `fk_gr_user` (`received_by`),
  ADD KEY `fk_gr_supplier` (`supplier_id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `fk_im_tenant` (`tenant_id`),
  ADD KEY `fk_im_product` (`product_id`),
  ADD KEY `fk_im_wh` (`warehouse_id`);

--
-- Indexes for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  ADD PRIMARY KEY (`stock_id`),
  ADD UNIQUE KEY `uq_stock` (`product_id`,`warehouse_id`),
  ADD KEY `fk_stock_tenant` (`tenant_id`),
  ADD KEY `fk_stock_wh` (`warehouse_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `uq_invoice_number` (`tenant_id`,`invoice_number`),
  ADD KEY `fk_invoices_contact` (`contact_id`),
  ADD KEY `fk_invoices_status` (`status_id`),
  ADD KEY `fk_invoices_wh` (`warehouse_id`),
  ADD KEY `idx_invoices_wh_date` (`warehouse_id`,`issue_date`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`invoice_item_id`),
  ADD KEY `fk_ii_invoice` (`invoice_id`),
  ADD KEY `fk_ii_tax` (`tax_rate_id`),
  ADD KEY `fk_ii_wh` (`warehouse_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`journal_entry_id`),
  ADD KEY `fk_je_tenant` (`tenant_id`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`lead_id`),
  ADD UNIQUE KEY `uq_lead_number` (`tenant_id`,`lead_number`),
  ADD UNIQUE KEY `uq_lead_tenant` (`lead_id`,`tenant_id`),
  ADD KEY `idx_leads_status` (`tenant_id`,`status`),
  ADD KEY `idx_leads_assigned` (`tenant_id`,`assigned_to`,`status`),
  ADD KEY `idx_leads_followup` (`tenant_id`,`next_follow_up_at`),
  ADD KEY `idx_leads_created` (`tenant_id`,`created_at`),
  ADD KEY `fk_leads_contact` (`contact_id`),
  ADD KEY `fk_leads_assigned` (`assigned_to`),
  ADD KEY `fk_leads_created_by` (`created_by`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD UNIQUE KEY `uq_order_number` (`tenant_id`,`order_number`),
  ADD UNIQUE KEY `uq_order_tenant` (`order_id`,`tenant_id`),
  ADD UNIQUE KEY `uq_order_invoice` (`fulfilled_invoice_id`),
  ADD KEY `idx_orders_status` (`tenant_id`,`status`,`created_at`),
  ADD KEY `idx_orders_shop` (`shop_id`,`tenant_id`,`status`),
  ADD KEY `idx_orders_assigned` (`tenant_id`,`assigned_to`,`status`),
  ADD KEY `idx_orders_created` (`tenant_id`,`created_at`),
  ADD KEY `fk_cord_contact` (`contact_id`),
  ADD KEY `fk_cord_lead` (`lead_id`),
  ADD KEY `fk_cord_wh` (`warehouse_id`),
  ADD KEY `fk_cord_assigned` (`assigned_to`),
  ADD KEY `fk_cord_created_by` (`created_by`),
  ADD KEY `fk_cord_confirmed_by` (`confirmed_by`),
  ADD KEY `fk_cord_rejected_by` (`rejected_by`),
  ADD KEY `fk_cord_cancelled_by` (`cancelled_by`),
  ADD KEY `fk_cord_fulfilled_by` (`fulfilled_by`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD UNIQUE KEY `uq_order_line` (`order_id`,`tenant_id`,`line_no`),
  ADD KEY `idx_oi_product` (`tenant_id`,`product_id`),
  ADD KEY `fk_coi_product` (`product_id`);

--
-- Indexes for table `order_stock_reservations`
--
ALTER TABLE `order_stock_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD UNIQUE KEY `uq_order_item_reservation` (`order_item_id`),
  ADD UNIQUE KEY `uq_reservation_tenant` (`reservation_id`,`tenant_id`),
  ADD KEY `idx_reservation_stock` (`tenant_id`,`warehouse_id`,`product_id`,`status`),
  ADD KEY `idx_reservation_order` (`tenant_id`,`order_id`,`status`),
  ADD KEY `fk_osr_order` (`order_id`,`tenant_id`),
  ADD KEY `fk_osr_product` (`product_id`),
  ADD KEY `fk_osr_warehouse` (`warehouse_id`),
  ADD KEY `fk_osr_invoice` (`consumed_invoice_id`),
  ADD KEY `fk_osr_created_by` (`created_by`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `uq_payment_submission_token` (`tenant_id`,`submission_token`),
  ADD KEY `fk_pay_tenant` (`tenant_id`),
  ADD KEY `fk_pay_invoice` (`invoice_id`),
  ADD KEY `fk_pay_method` (`payment_method_id`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`payment_method_id`),
  ADD KEY `fk_pm_tenant` (`tenant_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permission_id`),
  ADD UNIQUE KEY `uq_permission_name` (`name`);

--
-- Indexes for table `pos_orders`
--
ALTER TABLE `pos_orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `fk_orders_tenant` (`tenant_id`),
  ADD KEY `fk_orders_contact` (`contact_id`),
  ADD KEY `fk_orders_session` (`session_id`),
  ADD KEY `fk_orders_wh` (`warehouse_id`),
  ADD KEY `idx_orders_wh_date` (`warehouse_id`,`order_date`);

--
-- Indexes for table `pos_order_items`
--
ALTER TABLE `pos_order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `fk_poi_order` (`order_id`),
  ADD KEY `fk_poi_wh` (`warehouse_id`);

--
-- Indexes for table `pos_payments`
--
ALTER TABLE `pos_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `fk_pp_order` (`order_id`);

--
-- Indexes for table `pos_sessions`
--
ALTER TABLE `pos_sessions`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `fk_ps_tenant` (`tenant_id`),
  ADD KEY `fk_ps_wh` (`warehouse_id`),
  ADD KEY `idx_ps_user_open` (`tenant_id`,`user_id`,`is_closed`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `uq_product_code` (`tenant_id`,`code`),
  ADD KEY `fk_products_cat` (`category_id`),
  ADD KEY `fk_products_uom` (`unit_id`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD KEY `fk_pc_tenant` (`tenant_id`),
  ADD KEY `fk_pc_parent` (`parent_category_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`variant_id`),
  ADD KEY `fk_pv_product` (`product_id`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`purchase_id`),
  ADD KEY `idx_purch_tenant` (`tenant_id`),
  ADD KEY `fk_purch_product` (`product_id`),
  ADD KEY `fk_purch_supplier` (`supplier_id`);

--
-- Indexes for table `quotations`
--
ALTER TABLE `quotations`
  ADD PRIMARY KEY (`quotation_id`),
  ADD UNIQUE KEY `uq_quotation_number` (`tenant_id`,`quotation_number`),
  ADD KEY `idx_quotations_tenant_date` (`tenant_id`,`issue_date`),
  ADD KEY `fk_q_wh` (`warehouse_id`),
  ADD KEY `fk_q_contact` (`contact_id`),
  ADD KEY `fk_q_invoice` (`converted_invoice_id`);

--
-- Indexes for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD PRIMARY KEY (`quotation_item_id`),
  ADD KEY `fk_qi_quotation` (`quotation_id`),
  ADD KEY `fk_qi_product` (`product_id`),
  ADD KEY `fk_qi_wh` (`warehouse_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD KEY `fk_roles_tenant` (`tenant_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_permission_id`),
  ADD UNIQUE KEY `uq_role_perm` (`role_id`,`permission_id`),
  ADD KEY `fk_rp_perm` (`permission_id`);

--
-- Indexes for table `sales_returns`
--
ALTER TABLE `sales_returns`
  ADD PRIMARY KEY (`return_id`),
  ADD UNIQUE KEY `uq_return_number` (`tenant_id`,`return_number`),
  ADD UNIQUE KEY `uq_sr_submission_token` (`tenant_id`,`submission_token`),
  ADD KEY `idx_sr_tenant` (`tenant_id`),
  ADD KEY `idx_sr_source` (`tenant_id`,`source_type`,`source_id`),
  ADD KEY `fk_sr_shop` (`shop_id`),
  ADD KEY `fk_sr_warehouse` (`warehouse_id`);

--
-- Indexes for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  ADD PRIMARY KEY (`return_item_id`),
  ADD KEY `idx_sri_return` (`return_id`),
  ADD KEY `idx_sri_tenant` (`tenant_id`),
  ADD KEY `fk_sri_product` (`product_id`);

--
-- Indexes for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`migration`);

--
-- Indexes for table `shops`
--
ALTER TABLE `shops`
  ADD PRIMARY KEY (`shop_id`),
  ADD UNIQUE KEY `uq_shop_code` (`tenant_id`,`code`),
  ADD UNIQUE KEY `uq_shops_shop_tenant` (`shop_id`,`tenant_id`),
  ADD KEY `fk_shops_tenant` (`tenant_id`);

--
-- Indexes for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD PRIMARY KEY (`transfer_id`),
  ADD UNIQUE KEY `uq_stock_transfer_number` (`tenant_id`,`transfer_number`),
  ADD UNIQUE KEY `uq_stock_transfer_tenant` (`transfer_id`,`tenant_id`),
  ADD KEY `idx_transfer_status` (`tenant_id`,`status`,`requested_at`),
  ADD KEY `idx_transfer_source` (`tenant_id`,`source_warehouse_id`,`status`),
  ADD KEY `idx_transfer_destination` (`tenant_id`,`destination_shop_id`,`destination_warehouse_id`,`status`),
  ADD KEY `fk_st_source` (`source_warehouse_id`,`tenant_id`),
  ADD KEY `fk_st_destination_store` (`destination_warehouse_id`,`tenant_id`),
  ADD KEY `fk_st_requested_by` (`requested_by`),
  ADD KEY `fk_st_approved_by` (`approved_by`),
  ADD KEY `fk_st_dispatched_by` (`dispatched_by`),
  ADD KEY `fk_st_received_by` (`received_by`),
  ADD KEY `fk_st_discrepancy_by` (`discrepancy_resolved_by`),
  ADD KEY `fk_st_cancelled_by` (`cancelled_by`),
  ADD KEY `fk_st_destination_shop` (`destination_shop_id`,`tenant_id`);

--
-- Indexes for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `uq_transfer_item_product` (`transfer_id`,`product_id`),
  ADD KEY `idx_transfer_items_product` (`tenant_id`,`product_id`),
  ADD KEY `fk_sti_transfer` (`transfer_id`,`tenant_id`),
  ADD KEY `fk_sti_product` (`product_id`);

--
-- Indexes for table `storefront_lead_requests`
--
ALTER TABLE `storefront_lead_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD UNIQUE KEY `uq_storefront_request_lead` (`lead_id`),
  ADD UNIQUE KEY `uq_storefront_request_tenant` (`request_id`,`tenant_id`),
  ADD UNIQUE KEY `uq_storefront_request_order` (`converted_order_id`),
  ADD KEY `idx_storefront_request_tenant` (`tenant_id`,`created_at`),
  ADD KEY `fk_sfr_lead` (`lead_id`,`tenant_id`);

--
-- Indexes for table `storefront_lead_request_items`
--
ALTER TABLE `storefront_lead_request_items`
  ADD PRIMARY KEY (`request_item_id`),
  ADD UNIQUE KEY `uq_storefront_request_line` (`request_id`,`tenant_id`,`line_no`),
  ADD KEY `idx_storefront_request_product` (`tenant_id`,`product_id`),
  ADD KEY `fk_sfri_product` (`product_id`);

--
-- Indexes for table `storefront_request_notification_reads`
--
ALTER TABLE `storefront_request_notification_reads`
  ADD PRIMARY KEY (`tenant_id`,`user_id`),
  ADD KEY `fk_srn_user` (`user_id`);

--
-- Indexes for table `storefront_subscribers`
--
ALTER TABLE `storefront_subscribers`
  ADD PRIMARY KEY (`subscriber_id`),
  ADD UNIQUE KEY `uq_storefront_subscriber_email` (`tenant_id`,`email`),
  ADD KEY `idx_storefront_subscriber_tenant` (`tenant_id`,`created_at`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD KEY `fk_suppliers_tenant` (`tenant_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_id`),
  ADD KEY `fk_ss_tenant` (`tenant_id`);

--
-- Indexes for table `tax_rates`
--
ALTER TABLE `tax_rates`
  ADD PRIMARY KEY (`tax_rate_id`),
  ADD KEY `fk_tr_tenant` (`tenant_id`);

--
-- Indexes for table `tenants`
--
ALTER TABLE `tenants`
  ADD PRIMARY KEY (`tenant_id`);

--
-- Indexes for table `transfer_routes`
--
ALTER TABLE `transfer_routes`
  ADD PRIMARY KEY (`from_type`,`to_type`);

--
-- Indexes for table `units_of_measurement`
--
ALTER TABLE `units_of_measurement`
  ADD PRIMARY KEY (`unit_id`),
  ADD KEY `fk_uom_tenant` (`tenant_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_user_username` (`tenant_id`,`username`),
  ADD UNIQUE KEY `uq_user_email` (`tenant_id`,`email`),
  ADD KEY `fk_users_tenant` (`tenant_id`);

--
-- Indexes for table `user_godowns`
--
ALTER TABLE `user_godowns`
  ADD PRIMARY KEY (`user_godown_id`),
  ADD UNIQUE KEY `uq_user_godown` (`user_id`,`warehouse_id`),
  ADD KEY `fk_ug_tenant` (`tenant_id`),
  ADD KEY `fk_ug_wh` (`warehouse_id`,`tenant_id`);

--
-- Indexes for table `user_inventory_locations`
--
ALTER TABLE `user_inventory_locations`
  ADD PRIMARY KEY (`user_location_id`),
  ADD UNIQUE KEY `uq_user_location` (`tenant_id`,`user_id`,`location_id`),
  ADD KEY `idx_uil_tenant` (`tenant_id`),
  ADD KEY `idx_uil_user` (`user_id`),
  ADD KEY `idx_uil_location` (`location_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_role_id`),
  ADD UNIQUE KEY `uq_user_role` (`user_id`,`role_id`),
  ADD KEY `fk_ur_role` (`role_id`),
  ADD KEY `fk_ur_tenant` (`tenant_id`);

--
-- Indexes for table `user_shops`
--
ALTER TABLE `user_shops`
  ADD PRIMARY KEY (`user_shop_id`),
  ADD UNIQUE KEY `uq_user_shop` (`user_id`,`shop_id`),
  ADD KEY `fk_us_tenant` (`tenant_id`),
  ADD KEY `fk_us_shop` (`shop_id`);

--
-- Indexes for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`warehouse_id`),
  ADD UNIQUE KEY `uq_wh_warehouse_tenant` (`warehouse_id`,`tenant_id`),
  ADD UNIQUE KEY `uq_wh_code` (`tenant_id`,`code`),
  ADD UNIQUE KEY `uq_wh_active_godown` (`godown_tenant_key`),
  ADD KEY `fk_wh_tenant` (`tenant_id`),
  ADD KEY `fk_wh_shop` (`shop_id`),
  ADD KEY `idx_wh_active` (`tenant_id`,`is_active`),
  ADD KEY `idx_wh_shop_tenant` (`shop_id`,`tenant_id`),
  ADD KEY `fk_wh_manager` (`manager_user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `activity_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=300;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `contact_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `contact_types`
--
ALTER TABLE `contact_types`
  MODIFY `contact_type_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `deals`
--
ALTER TABLE `deals`
  MODIFY `deal_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `document_statuses`
--
ALTER TABLE `document_statuses`
  MODIFY `status_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `expense_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `expense_category_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `expense_items`
--
ALTER TABLE `expense_items`
  MODIFY `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `godown_receipts`
--
ALTER TABLE `godown_receipts`
  MODIFY `receipt_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `movement_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  MODIFY `stock_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `invoice_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `journal_entry_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `lead_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=85;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `order_stock_reservations`
--
ALTER TABLE `order_stock_reservations`
  MODIFY `reservation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `payment_method_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `permission_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `pos_orders`
--
ALTER TABLE `pos_orders`
  MODIFY `order_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `pos_order_items`
--
ALTER TABLE `pos_order_items`
  MODIFY `order_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `pos_payments`
--
ALTER TABLE `pos_payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `pos_sessions`
--
ALTER TABLE `pos_sessions`
  MODIFY `session_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `category_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `variant_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `purchase_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `quotation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `quotation_items`
--
ALTER TABLE `quotation_items`
  MODIFY `quotation_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `role_permission_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120;

--
-- AUTO_INCREMENT for table `sales_returns`
--
ALTER TABLE `sales_returns`
  MODIFY `return_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  MODIFY `return_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `shops`
--
ALTER TABLE `shops`
  MODIFY `shop_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `transfer_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  MODIFY `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storefront_lead_requests`
--
ALTER TABLE `storefront_lead_requests`
  MODIFY `request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `storefront_lead_request_items`
--
ALTER TABLE `storefront_lead_request_items`
  MODIFY `request_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `storefront_subscribers`
--
ALTER TABLE `storefront_subscribers`
  MODIFY `subscriber_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `setting_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tax_rates`
--
ALTER TABLE `tax_rates`
  MODIFY `tax_rate_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tenants`
--
ALTER TABLE `tenants`
  MODIFY `tenant_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `units_of_measurement`
--
ALTER TABLE `units_of_measurement`
  MODIFY `unit_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `user_godowns`
--
ALTER TABLE `user_godowns`
  MODIFY `user_godown_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_inventory_locations`
--
ALTER TABLE `user_inventory_locations`
  MODIFY `user_location_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `user_role_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `user_shops`
--
ALTER TABLE `user_shops`
  MODIFY `user_shop_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `warehouse_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activities`
--
ALTER TABLE `activities`
  ADD CONSTRAINT `fk_act_lead` FOREIGN KEY (`lead_id`,`tenant_id`) REFERENCES `leads` (`lead_id`, `tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_act_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_act_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_al_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `fk_contacts_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_contacts_type` FOREIGN KEY (`contact_type_id`) REFERENCES `contact_types` (`contact_type_id`);

--
-- Constraints for table `deals`
--
ALTER TABLE `deals`
  ADD CONSTRAINT `fk_deals_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `document_sequences`
--
ALTER TABLE `document_sequences`
  ADD CONSTRAINT `fk_docseq_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `document_statuses`
--
ALTER TABLE `document_statuses`
  ADD CONSTRAINT `fk_ds_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_ex_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD CONSTRAINT `fk_ec_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `godown_receipts`
--
ALTER TABLE `godown_receipts`
  ADD CONSTRAINT `fk_gr_godown` FOREIGN KEY (`godown_id`,`tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`),
  ADD CONSTRAINT `fk_gr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_gr_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_gr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gr_user` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `fk_im_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_im_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_im_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  ADD CONSTRAINT `fk_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stock_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`),
  ADD CONSTRAINT `fk_invoices_status` FOREIGN KEY (`status_id`) REFERENCES `document_statuses` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_invoices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_invoices_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ii_tax` FOREIGN KEY (`tax_rate_id`) REFERENCES `tax_rates` (`tax_rate_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ii_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `fk_je_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `fk_leads_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leads_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_cord_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_fulfilled_by` FOREIGN KEY (`fulfilled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_invoice` FOREIGN KEY (`fulfilled_invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`lead_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cord_shop` FOREIGN KEY (`shop_id`,`tenant_id`) REFERENCES `shops` (`shop_id`, `tenant_id`),
  ADD CONSTRAINT `fk_cord_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cord_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_coi_order` FOREIGN KEY (`order_id`,`tenant_id`) REFERENCES `orders` (`order_id`, `tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_coi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

--
-- Constraints for table `order_stock_reservations`
--
ALTER TABLE `order_stock_reservations`
  ADD CONSTRAINT `fk_osr_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_osr_invoice` FOREIGN KEY (`consumed_invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_osr_order` FOREIGN KEY (`order_id`,`tenant_id`) REFERENCES `orders` (`order_id`, `tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_osr_order_item` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`order_item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_osr_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_osr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_osr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pay_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`payment_method_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pay_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD CONSTRAINT `fk_pm_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_orders`
--
ALTER TABLE `pos_orders`
  ADD CONSTRAINT `fk_orders_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_session` FOREIGN KEY (`session_id`) REFERENCES `pos_sessions` (`session_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_orders_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_orders_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `pos_order_items`
--
ALTER TABLE `pos_order_items`
  ADD CONSTRAINT `fk_poi_order` FOREIGN KEY (`order_id`) REFERENCES `pos_orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_poi_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `pos_payments`
--
ALTER TABLE `pos_payments`
  ADD CONSTRAINT `fk_pp_order` FOREIGN KEY (`order_id`) REFERENCES `pos_orders` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `pos_sessions`
--
ALTER TABLE `pos_sessions`
  ADD CONSTRAINT `fk_ps_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ps_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_cat` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_products_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_products_uom` FOREIGN KEY (`unit_id`) REFERENCES `units_of_measurement` (`unit_id`) ON DELETE SET NULL;

--
-- Constraints for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD CONSTRAINT `fk_pc_parent` FOREIGN KEY (`parent_category_id`) REFERENCES `product_categories` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `fk_pv_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `fk_purch_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_purch_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_purch_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `quotations`
--
ALTER TABLE `quotations`
  ADD CONSTRAINT `fk_q_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`),
  ADD CONSTRAINT `fk_q_invoice` FOREIGN KEY (`converted_invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_q_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_q_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `quotation_items`
--
ALTER TABLE `quotation_items`
  ADD CONSTRAINT `fk_qi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_qi_quotation` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`quotation_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_qi_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_roles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_returns`
--
ALTER TABLE `sales_returns`
  ADD CONSTRAINT `fk_sr_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sr_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `fk_sri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_sri_return` FOREIGN KEY (`return_id`) REFERENCES `sales_returns` (`return_id`) ON DELETE CASCADE;

--
-- Constraints for table `shops`
--
ALTER TABLE `shops`
  ADD CONSTRAINT `fk_shops_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD CONSTRAINT `fk_st_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_destination_shop` FOREIGN KEY (`destination_shop_id`,`tenant_id`) REFERENCES `shops` (`shop_id`, `tenant_id`),
  ADD CONSTRAINT `fk_st_destination_store` FOREIGN KEY (`destination_warehouse_id`,`tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`),
  ADD CONSTRAINT `fk_st_discrepancy_by` FOREIGN KEY (`discrepancy_resolved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_dispatched_by` FOREIGN KEY (`dispatched_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_st_source` FOREIGN KEY (`source_warehouse_id`,`tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`),
  ADD CONSTRAINT `fk_st_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  ADD CONSTRAINT `fk_sti_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_sti_transfer` FOREIGN KEY (`transfer_id`,`tenant_id`) REFERENCES `stock_transfers` (`transfer_id`, `tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `storefront_lead_requests`
--
ALTER TABLE `storefront_lead_requests`
  ADD CONSTRAINT `fk_sfr_lead` FOREIGN KEY (`lead_id`,`tenant_id`) REFERENCES `leads` (`lead_id`, `tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sfr_order` FOREIGN KEY (`converted_order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `storefront_lead_request_items`
--
ALTER TABLE `storefront_lead_request_items`
  ADD CONSTRAINT `fk_sfri_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sfri_request` FOREIGN KEY (`request_id`,`tenant_id`) REFERENCES `storefront_lead_requests` (`request_id`, `tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `storefront_request_notification_reads`
--
ALTER TABLE `storefront_request_notification_reads`
  ADD CONSTRAINT `fk_srn_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_srn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `storefront_subscribers`
--
ALTER TABLE `storefront_subscribers`
  ADD CONSTRAINT `fk_storefront_subscriber_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `fk_suppliers_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `fk_ss_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `tax_rates`
--
ALTER TABLE `tax_rates`
  ADD CONSTRAINT `fk_tr_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `units_of_measurement`
--
ALTER TABLE `units_of_measurement`
  ADD CONSTRAINT `fk_uom_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_godowns`
--
ALTER TABLE `user_godowns`
  ADD CONSTRAINT `fk_ug_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ug_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ug_wh` FOREIGN KEY (`warehouse_id`,`tenant_id`) REFERENCES `warehouses` (`warehouse_id`, `tenant_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_inventory_locations`
--
ALTER TABLE `user_inventory_locations`
  ADD CONSTRAINT `fk_uil_location` FOREIGN KEY (`location_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_uil_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_uil_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ur_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_shops`
--
ALTER TABLE `user_shops`
  ADD CONSTRAINT `fk_us_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`shop_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_us_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD CONSTRAINT `fk_wh_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wh_shop_tenant` FOREIGN KEY (`shop_id`,`tenant_id`) REFERENCES `shops` (`shop_id`, `tenant_id`),
  ADD CONSTRAINT `fk_wh_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
