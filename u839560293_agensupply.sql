-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 04, 2026 at 04:30 PM
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
(1, 1, 47, 'logout', 'user', 47, 'User signed out', '2026-11-04 15:27:24'),
(2, 1, 1, 'login', 'user', 1, 'User signed in', '2026-11-04 15:27:29');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `contact_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `contact_type_id` int(10) UNSIGNED DEFAULT 1,
  `customer_type` varchar(20) NOT NULL DEFAULT 'retail',
  `customer_channel` varchar(20) DEFAULT 'walk_in',
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
  `wholesale_price` decimal(12,2) DEFAULT NULL,
  `wholesale_min_qty` decimal(12,2) DEFAULT NULL,
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
(25, 1, 'Godown Manager', ''),
(26, 1, 'Wholesaler', 'Wholesale customer portal access');

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
(121, 1, 15),
(56, 1, 20),
(58, 1, 21),
(86, 1, 22),
(84, 1, 23),
(85, 1, 24),
(87, 1, 25),
(122, 1, 26),
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
(11, 1, 'Mwenge', 'SHOP-004', 'Mwenge', '0987654323', 0, '2026-10-28 06:32:17', '2026-11-04 12:56:18');

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
(1, 1, 0, '2026-11-04 15:25:00');

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
(1, 1, 'TECHDOKTA INVESTMENT LIMITED & GSP LIMITED', '', '', '', 'TZS', 'INV-', 1014, 0, 'uploads/logo_1_1793796611.jpg');

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
(1, 1, 'admin', 'dezlinvestment@gmail.com', '$2y$10$fcpe/kqLWziTy77xn6Gp0OkZdLIIn67EIFd5uhWymJ9tv2jzkm64y', 'System', 'Administrator', '+255677004313', 1, '2026-11-04 18:27:29', '2026-08-28 13:20:03'),
(20, 1, 'kariakooshop', 'kariakooshop@gmail.com', '$2y$10$1geoMyYQHX14IjaBDTPZaO3kMZp5YVVbXojQVZSUH7wj.Kr9qlLxa', 'kariakoo', 'shop', '+255688228112', 1, '2026-11-04 15:47:29', '2026-10-28 01:34:48'),
(46, 1, 'mwengeshop', 'mwengeshop@gmail.com', '$2y$10$a2wmqJO2B91K628zTQMPq.L.7FK2Lf4PBMAfEoDar/kweodp3dVoq', 'mwenge', 'shop', '', 1, '2026-11-04 17:48:29', '2026-11-02 13:24:22'),
(47, 1, 'surveyshop', 'surveyshop@gmail.com', '$2y$10$5SDbui3UP83WRITu2S89cuA.02i/0XEuZXrWCy154Vyzvpsmex0n.', 'Survey', 'Shop', '', 1, '2026-11-04 18:08:50', '2026-11-02 14:24:57'),
(48, 1, 'kariakoostore', 'Kariakoostore@gmail.com', '$2y$10$qSq5Ug2NqVkXdPCEvBTNoOwpflCHOt0rl63U4fYEK.JDc8pQ6iRhq', 'kariakoo', 'Store', '', 1, '2026-11-03 17:38:42', '2026-11-02 15:18:44'),
(49, 1, 'Godown', 'Godown@gmail.com', '$2y$10$F9Np.89ShGC8dmwOXsjlzubD2HjXTYqMZH0Sx5V.IRX4zq4.Dk3AS', 'Godown', 'Godown', '', 1, '2026-11-04 09:42:50', '2026-11-02 15:21:53'),
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
(9, 1, 46, 17, '2026-11-03 10:39:46'),
(10, 1, 50, 1, '2026-11-03 10:41:02'),
(11, 1, 20, 15, '2026-11-03 10:41:54'),
(12, 1, 48, 11, '2026-11-03 10:42:17'),
(13, 1, 47, 14, '2026-11-04 15:04:35');

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
(56, 46, 15, 1, '2026-11-03 10:39:46'),
(57, 50, 24, 1, '2026-11-03 10:41:02'),
(58, 20, 15, 1, '2026-11-03 10:41:54'),
(59, 48, 24, 1, '2026-11-03 10:42:17'),
(69, 47, 15, 1, '2026-11-04 15:04:35');

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
(17, 1, 46, 11, '2026-11-03 10:39:46'),
(18, 1, 20, 7, '2026-11-03 10:41:54'),
(19, 1, 47, 1, '2026-11-04 15:04:35');

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

-- --------------------------------------------------------

--
-- Table structure for table `wholesaler_accounts`
--

CREATE TABLE `wholesaler_accounts` (
  `wholesaler_id` int(10) UNSIGNED NOT NULL,
  `tenant_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `contact_id` int(10) UNSIGNED DEFAULT NULL,
  `business_name` varchar(191) NOT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `contact_name` varchar(191) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `monthly_volume` varchar(100) DEFAULT NULL,
  `product_interest` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  ADD KEY `fk_contacts_type` (`contact_type_id`),
  ADD KEY `idx_contacts_customer_type` (`tenant_id`,`customer_type`),
  ADD KEY `idx_contacts_customer_channel` (`tenant_id`,`customer_channel`);

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
-- Indexes for table `wholesaler_accounts`
--
ALTER TABLE `wholesaler_accounts`
  ADD PRIMARY KEY (`wholesaler_id`),
  ADD UNIQUE KEY `uq_wholesaler_user` (`user_id`),
  ADD UNIQUE KEY `uq_wholesaler_contact` (`tenant_id`,`contact_id`),
  ADD KEY `idx_wholesaler_tenant_status` (`tenant_id`,`status`),
  ADD KEY `fk_wholesaler_contact` (`contact_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `activity_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `contact_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `expense_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expense_categories`
--
ALTER TABLE `expense_categories`
  MODIFY `expense_category_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `expense_items`
--
ALTER TABLE `expense_items`
  MODIFY `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `godown_receipts`
--
ALTER TABLE `godown_receipts`
  MODIFY `receipt_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `movement_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_stock`
--
ALTER TABLE `inventory_stock`
  MODIFY `stock_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `invoice_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `journal_entry_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `lead_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_stock_reservations`
--
ALTER TABLE `order_stock_reservations`
  MODIFY `reservation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `order_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_order_items`
--
ALTER TABLE `pos_order_items`
  MODIFY `order_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_payments`
--
ALTER TABLE `pos_payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_sessions`
--
ALTER TABLE `pos_sessions`
  MODIFY `session_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `purchase_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotations`
--
ALTER TABLE `quotations`
  MODIFY `quotation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotation_items`
--
ALTER TABLE `quotation_items`
  MODIFY `quotation_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `role_permission_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=123;

--
-- AUTO_INCREMENT for table `sales_returns`
--
ALTER TABLE `sales_returns`
  MODIFY `return_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  MODIFY `return_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `shops`
--
ALTER TABLE `shops`
  MODIFY `shop_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `transfer_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  MODIFY `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storefront_lead_requests`
--
ALTER TABLE `storefront_lead_requests`
  MODIFY `request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storefront_lead_request_items`
--
ALTER TABLE `storefront_lead_request_items`
  MODIFY `request_item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `storefront_subscribers`
--
ALTER TABLE `storefront_subscribers`
  MODIFY `subscriber_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=60;

--
-- AUTO_INCREMENT for table `user_godowns`
--
ALTER TABLE `user_godowns`
  MODIFY `user_godown_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_inventory_locations`
--
ALTER TABLE `user_inventory_locations`
  MODIFY `user_location_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `user_role_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `user_shops`
--
ALTER TABLE `user_shops`
  MODIFY `user_shop_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `warehouse_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wholesaler_accounts`
--
ALTER TABLE `wholesaler_accounts`
  MODIFY `wholesaler_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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

--
-- Constraints for table `wholesaler_accounts`
--
ALTER TABLE `wholesaler_accounts`
  ADD CONSTRAINT `fk_wholesaler_contact` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`contact_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wholesaler_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wholesaler_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
