-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Dec 20, 2025 at 03:58 AM
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
-- Database: `multi_billing`
--

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `org_id`, `name`, `status`, `created_at`) VALUES
(1, 11, 'Beverages', 1, '2025-12-19 10:46:19'),
(2, 11, 'snacks', 0, '2025-12-19 10:47:31'),
(4, 11, 'toys', 0, '2025-12-19 10:56:35'),
(5, 11, 'Grocery', 1, '2025-12-20 02:35:15'),
(6, 11, 'Dairy', 1, '2025-12-20 02:35:16'),
(7, 11, 'Bakery', 1, '2025-12-20 02:35:16'),
(8, 11, 'Personal Care', 1, '2025-12-20 02:35:16');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `org_id`, `outlet_id`, `name`, `phone`, `created_at`, `updated_at`) VALUES
(1, 4, 3, 'sri', '9874563210', '2025-09-01 17:18:28', '2025-09-02 11:30:05'),
(2, 5, 4, 'qds', '9876501234', '2025-09-02 13:19:36', '2025-09-02 13:21:17'),
(3, 6, 5, 'ragul', '99999999999', '2025-09-03 12:40:46', '2025-09-03 12:40:46'),
(4, 9, 6, 'ragul', '99999999999', '2025-12-10 12:28:03', '2025-12-10 12:28:03'),
(5, 11, 17, 'ragul', '99999999999', '2025-12-16 12:00:30', '2025-12-16 12:00:30'),
(6, 11, 17, 'jeyabala', '9874563210', '2025-12-16 17:09:42', '2025-12-16 17:09:42'),
(7, 15, 19, 'jeyabala', '9874563210', '2025-12-18 13:17:35', '2025-12-18 13:17:35'),
(8, 15, 19, 'jeyabala', '9874563211', '2025-12-18 15:07:38', '2025-12-18 15:07:38');

-- --------------------------------------------------------

--
-- Table structure for table `features`
--

CREATE TABLE `features` (
  `id` int(11) NOT NULL,
  `key_name` varchar(100) NOT NULL,
  `display_name` varchar(150) NOT NULL,
  `type` enum('boolean','number','text','json','date') DEFAULT 'boolean'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `features`
--

INSERT INTO `features` (`id`, `key_name`, `display_name`, `type`) VALUES
(1, 'loyalty_points', 'Loyalty Points', 'number'),
(2, 'barcode', 'Barcode Scanning', 'text'),
(3, 'expiry_date', 'Expiry Date', 'date');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `org_id`, `outlet_id`, `product_id`, `variant_id`, `quantity`, `updated_at`) VALUES
(1, 9, 6, 19, NULL, 12.00, '2025-12-12 11:47:25'),
(2, 9, 6, 20, NULL, 40.00, '2025-12-12 11:45:32'),
(3, 9, 6, 21, NULL, 40.00, '2025-12-10 16:33:49'),
(4, 9, 6, 22, NULL, 123.00, '2025-12-12 11:45:32'),
(5, 9, 6, 22, 7, 83.00, '2025-12-12 11:45:32'),
(6, 9, 6, 22, 8, 3.00, '2025-12-12 11:45:32'),
(9, 11, 17, 23, NULL, 15.00, '2025-12-16 17:10:25'),
(10, 11, 17, 23, 11, -10.00, '2025-12-16 17:10:25'),
(11, 11, 17, 23, 12, 0.00, '2025-12-16 17:10:25'),
(12, 11, 17, 24, NULL, 40.00, '2025-12-16 11:51:03'),
(13, 11, 17, 25, 13, 1.00, '2025-12-16 13:17:22'),
(14, 11, 17, 25, 14, -9.00, '2025-12-16 13:17:22'),
(15, 11, 17, 26, NULL, 99.00, '2025-12-16 17:25:57'),
(16, 11, 17, 27, NULL, 0.00, '2025-12-16 11:56:22'),
(17, 11, 17, 28, NULL, 0.00, '2025-12-18 15:42:06'),
(18, 11, 17, 29, 19, 37.00, '2025-12-19 08:49:42'),
(19, 11, 17, 29, NULL, 12.00, '2025-12-19 08:49:42'),
(20, 11, 17, 30, NULL, 50.00, '2025-12-19 08:17:34'),
(21, 11, 17, 31, NULL, 20.00, '2025-12-19 08:17:34'),
(22, 11, 17, 32, NULL, 100.00, '2025-12-19 08:17:34'),
(23, 11, 17, 33, 20, 20.00, '2025-12-19 08:17:34'),
(24, 11, 17, 33, 21, 20.00, '2025-12-19 08:17:34'),
(25, 11, 17, 34, NULL, 60.00, '2025-12-19 08:17:34'),
(26, 11, 17, 35, NULL, 50.00, '2025-12-19 16:56:49'),
(27, 11, 17, 35, 22, 20.00, '2025-12-19 16:56:49'),
(28, 11, 17, 35, 23, 30.00, '2025-12-19 16:56:49'),
(29, 11, 17, 35, 24, 25.00, '2025-12-19 17:01:01'),
(30, 11, 17, 36, NULL, 50.00, '2025-12-19 17:12:18'),
(31, 11, 17, 36, 25, 20.00, '2025-12-19 17:12:18'),
(32, 11, 17, 36, 26, 30.00, '2025-12-19 17:12:18'),
(33, 11, 17, 37, NULL, 80.00, '2025-12-20 08:05:16'),
(34, 11, 17, 38, NULL, 60.00, '2025-12-20 08:05:16'),
(35, 11, 17, 39, NULL, 120.00, '2025-12-20 08:05:16'),
(36, 11, 17, 40, 27, 25.00, '2025-12-20 08:05:16'),
(37, 11, 17, 41, NULL, 150.00, '2025-12-20 08:05:16'),
(38, 11, 17, 42, NULL, 90.00, '2025-12-20 08:05:16'),
(39, 11, 17, 43, NULL, 70.00, '2025-12-20 08:05:16'),
(40, 11, 17, 44, NULL, 50.00, '2025-12-20 08:05:16'),
(41, 11, 17, 45, NULL, 40.00, '2025-12-20 08:05:16'),
(42, 11, 17, 46, 28, 40.00, '2025-12-20 08:05:16'),
(43, 11, 17, 47, 29, 25.00, '2025-12-20 08:05:16'),
(44, 11, 17, 48, NULL, 100.00, '2025-12-20 08:05:16'),
(45, 11, 17, 49, NULL, 60.00, '2025-12-20 08:05:16'),
(46, 11, 17, 50, 30, 30.00, '2025-12-20 08:05:16'),
(47, 11, 17, 51, NULL, 45.00, '2025-12-20 08:05:16');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `change_type` enum('opening_stock','purchase','sale','manual_adjustment') NOT NULL,
  `quantity_change` decimal(10,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_logs`
--

INSERT INTO `inventory_logs` (`id`, `org_id`, `outlet_id`, `product_id`, `variant_id`, `change_type`, `quantity_change`, `note`, `reference_id`, `created_at`) VALUES
(1, 9, 6, 19, NULL, 'sale', -1.00, NULL, 31, '2025-12-10 12:37:46'),
(2, 9, 6, 21, NULL, '', 25.00, 'New purchase stock from supplier', NULL, '2025-12-10 15:46:45'),
(3, 9, 6, 21, NULL, '', -3.00, 'Damaged stock removed', NULL, '2025-12-10 15:48:08'),
(4, 9, 6, 21, NULL, '', 3.00, 'Extra stock adjustment', NULL, '2025-12-10 15:49:27'),
(5, 9, 6, 20, NULL, '', 20.00, 'New supply purchase', NULL, '2025-12-10 16:30:33'),
(6, 9, 6, 21, NULL, '', -5.00, 'Damaged packets', NULL, '2025-12-10 16:30:33'),
(7, 9, 6, 22, NULL, '', 100.00, 'Audit correction', NULL, '2025-12-10 16:30:33'),
(8, 9, 6, 20, NULL, '', 20.00, 'New supply purchase', NULL, '2025-12-10 16:33:49'),
(9, 9, 6, 21, NULL, '', 5.00, 'Damaged packets', NULL, '2025-12-10 16:33:49'),
(10, 9, 6, 22, NULL, '', 100.00, 'Audit correction', NULL, '2025-12-10 16:33:49'),
(11, 9, 6, 19, NULL, 'sale', -1.00, NULL, 32, '2025-12-11 15:23:43'),
(12, 9, 6, 19, NULL, 'sale', 1.00, NULL, 32, '2025-12-11 15:24:07'),
(13, 9, 6, 19, NULL, 'sale', -1.00, NULL, 33, '2025-12-11 15:26:52'),
(14, 9, 6, 19, NULL, 'sale', 1.00, NULL, 33, '2025-12-11 15:26:58'),
(15, 9, 6, 19, NULL, 'sale', 1.00, NULL, 31, '2025-12-11 15:29:09'),
(16, 9, 6, 19, NULL, 'sale', -1.00, NULL, 34, '2025-12-11 15:31:55'),
(19, 9, 6, 19, NULL, 'sale', -1.00, NULL, 35, '2025-12-11 15:52:00'),
(20, 9, 6, 22, NULL, '', 25.00, 'New purchase stock from supplier', NULL, '2025-12-12 11:10:56'),
(21, 9, 6, 22, 7, '', 25.00, 'variant stock update', NULL, '2025-12-12 11:15:14'),
(22, 9, 6, 22, 7, '', -10.00, 'damaged items', NULL, '2025-12-12 11:17:55'),
(23, 9, 6, 22, 7, '', -5.00, 'expiry removal', NULL, '2025-12-12 11:20:45'),
(24, 9, 6, 22, 7, '', 50.00, '1KG pack new stock', NULL, '2025-12-12 11:29:09'),
(25, 9, 6, 22, 8, '', -20.00, '5KG expiry removal', NULL, '2025-12-12 11:29:09'),
(26, 9, 6, 20, NULL, 'sale', -2.00, NULL, 36, '2025-12-12 11:29:44'),
(27, 9, 6, 22, NULL, 'sale', -1.00, NULL, 36, '2025-12-12 11:29:44'),
(35, 9, 6, 20, NULL, 'sale', 2.00, NULL, 36, '2025-12-12 11:45:32'),
(36, 9, 6, 22, NULL, 'sale', 1.00, NULL, 36, '2025-12-12 11:45:32'),
(37, 9, 6, 22, NULL, 'sale', -2.00, NULL, 36, '2025-12-12 11:45:32'),
(38, 9, 6, 19, NULL, 'sale', 1.00, NULL, 35, '2025-12-12 11:47:25'),
(39, 11, 17, 23, NULL, 'sale', -2.00, NULL, 37, '2025-12-16 12:14:45'),
(40, 11, 17, 25, NULL, 'sale', -1.00, NULL, 37, '2025-12-16 12:14:45'),
(41, 11, 17, 23, NULL, 'sale', -1.00, NULL, 38, '2025-12-16 12:23:34'),
(42, 11, 17, 25, NULL, 'sale', -4.00, NULL, 38, '2025-12-16 12:23:34'),
(43, 11, 17, 23, NULL, 'sale', -1.00, NULL, 39, '2025-12-16 12:57:14'),
(44, 11, 17, 25, NULL, 'sale', -3.00, NULL, 39, '2025-12-16 12:57:14'),
(45, 11, 17, 23, NULL, 'sale', -1.00, NULL, 40, '2025-12-16 13:12:47'),
(46, 11, 17, 25, NULL, 'sale', -3.00, NULL, 40, '2025-12-16 13:12:47'),
(47, 11, 17, 23, NULL, 'sale', -1.00, NULL, 41, '2025-12-16 13:17:22'),
(48, 11, 17, 25, NULL, 'sale', -3.00, NULL, 41, '2025-12-16 13:17:22'),
(49, 11, 17, 23, NULL, 'sale', -1.00, NULL, 42, '2025-12-16 13:18:21'),
(50, 11, 17, 23, NULL, 'sale', -1.00, NULL, 43, '2025-12-16 13:21:42'),
(51, 11, 17, 23, 12, '', 25.00, 'variant stock update', NULL, '2025-12-16 15:42:57'),
(52, 11, 17, 23, NULL, 'sale', -10.00, NULL, 44, '2025-12-16 15:43:43'),
(53, 11, 17, 23, NULL, 'sale', -10.00, NULL, 45, '2025-12-16 17:01:35'),
(54, 11, 17, 23, NULL, 'sale', -2.00, NULL, 46, '2025-12-16 17:04:04'),
(55, 11, 17, 23, NULL, 'sale', -2.00, NULL, 47, '2025-12-16 17:07:44'),
(56, 11, 17, 23, NULL, 'sale', -2.00, NULL, 48, '2025-12-16 17:07:49'),
(57, 11, 17, 23, NULL, 'sale', -1.00, NULL, 49, '2025-12-16 17:10:25'),
(58, 11, 17, 26, NULL, 'sale', -1.00, NULL, 50, '2025-12-16 17:25:57'),
(59, 11, 17, 29, NULL, '', 25.00, 'variant stock update', NULL, '2025-12-18 16:43:59'),
(60, 11, 17, 29, NULL, 'sale', -5.00, NULL, 54, '2025-12-18 16:58:12'),
(61, 11, 17, 29, NULL, 'sale', -5.00, NULL, 55, '2025-12-18 17:06:51'),
(62, 11, 17, 29, NULL, 'sale', -1.00, NULL, 56, '2025-12-19 08:02:04'),
(63, 11, 17, 29, NULL, 'sale', -1.00, NULL, 57, '2025-12-19 08:46:01'),
(64, 11, 17, 29, NULL, 'sale', -1.00, NULL, 58, '2025-12-19 08:49:42');

-- --------------------------------------------------------

--
-- Table structure for table `loyalty_points`
--

CREATE TABLE `loyalty_points` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `points_earned` decimal(10,2) DEFAULT 0.00,
  `points_redeemed` decimal(10,2) DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `loyalty_points`
--

INSERT INTO `loyalty_points` (`id`, `org_id`, `outlet_id`, `customer_id`, `sale_id`, `points_earned`, `points_redeemed`, `created_at`) VALUES
(1, 4, 3, 1, 17, 2.85, 0.00, '2025-09-01 17:38:04'),
(2, 5, 4, 2, 28, 1.10, 0.00, '2025-09-02 13:32:55'),
(4, 6, 5, 3, 30, 1.40, 0.00, '2025-09-03 12:52:39'),
(8, 9, 6, 4, 34, 0.90, 0.00, '2025-12-11 15:31:55'),
(10, 9, 6, 4, 36, 3.90, 0.00, '2025-12-12 11:29:44'),
(11, 11, 17, 5, 37, 6.00, 0.00, '2025-12-16 12:14:45'),
(12, 11, 17, 5, 38, 6.45, 0.00, '2025-12-16 12:23:34'),
(13, 11, 17, 5, 39, 3.60, 0.00, '2025-12-16 12:57:14'),
(14, 11, 17, 5, 40, 3.60, 0.00, '2025-12-16 13:12:47'),
(15, 11, 17, 5, 41, 3.60, 0.00, '2025-12-16 13:17:22'),
(16, 11, 17, 5, 42, 2.89, 0.00, '2025-12-16 13:18:21'),
(17, 11, 17, 5, 43, 2.89, 0.00, '2025-12-16 13:21:42'),
(18, 11, 17, 5, 44, 29.44, 0.00, '2025-12-16 15:43:43'),
(19, 11, 17, 5, 45, 29.44, 0.00, '2025-12-16 17:01:35'),
(20, 11, 17, 5, 46, 5.84, 0.00, '2025-12-16 17:04:04'),
(21, 11, 17, 5, 47, 5.84, 0.00, '2025-12-16 17:07:44'),
(22, 11, 17, 5, 48, 5.84, 0.00, '2025-12-16 17:07:49'),
(23, 11, 17, 6, 49, 2.89, 0.00, '2025-12-16 17:10:25'),
(24, 11, 17, 6, 50, 0.18, 0.00, '2025-12-16 17:25:57'),
(25, 11, 17, 6, 54, 5.25, 0.00, '2025-12-18 16:58:12'),
(26, 11, 17, 6, 55, 5.25, 0.00, '2025-12-18 17:06:51'),
(27, 11, 17, 6, 56, 1.05, 0.00, '2025-12-19 08:02:04'),
(28, 11, 17, 6, 57, 1.05, 0.00, '2025-12-19 08:46:01'),
(29, 11, 17, 6, 58, 1.05, 0.00, '2025-12-19 08:49:42'),
(30, 11, 17, 6, 57, 0.00, 5.00, '2025-12-19 09:01:20'),
(31, 11, 17, 6, 57, 0.00, 2.00, '2025-12-19 09:06:31');

-- --------------------------------------------------------

--
-- Table structure for table `numbering_schemes`
--

CREATE TABLE `numbering_schemes` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `next_invoice_no` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `numbering_schemes`
--

INSERT INTO `numbering_schemes` (`id`, `org_id`, `next_invoice_no`, `created_at`, `updated_at`) VALUES
(18, 3, 2, '2025-09-01 07:54:19', '2025-09-01 07:54:19'),
(25, 4, 3, '2025-09-01 12:06:50', '2025-09-01 12:08:04'),
(36, 5, 2, '2025-09-02 08:02:55', '2025-09-02 08:02:55'),
(38, 6, 6, '2025-09-03 07:16:04', '2025-09-03 08:12:11'),
(39, 9, 8, '2025-12-10 07:07:46', '2025-12-12 05:59:44'),
(40, 11, 51, '2025-12-16 06:44:45', '2025-12-19 03:19:42');

-- --------------------------------------------------------

--
-- Table structure for table `orgs`
--

CREATE TABLE `orgs` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `phone` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `vertical` varchar(50) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `gst_type` enum('CGST_SGST','IGST') DEFAULT 'CGST_SGST',
  `gst_rate` decimal(5,2) DEFAULT 18.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orgs`
--

INSERT INTO `orgs` (`id`, `name`, `email`, `created_at`, `phone`, `address`, `vertical`, `is_verified`, `verification_token`, `gstin`, `gst_type`, `gst_rate`) VALUES
(2, 'Test Org', 'testorg@example.com', '2025-08-30 16:17:01', NULL, NULL, 'retail', 1, NULL, NULL, 'CGST_SGST', 18.00),
(3, 'Qads', 'qads@gmail.com', '2025-09-01 11:16:25', NULL, NULL, 'Supermarket', 1, NULL, NULL, 'CGST_SGST', 18.00),
(4, 'Test Supermarket', 'testsupermarket@gamil.com', '2025-09-01 17:05:25', NULL, NULL, 'supermarket', 1, NULL, NULL, 'CGST_SGST', 18.00),
(5, 'qads corporation', 'abc@gmail.com', '2025-09-02 11:48:22', '9876543244', NULL, 'supermarket', 1, NULL, NULL, 'CGST_SGST', 18.00),
(6, 'sriram demo', 'sridemo@gmail.com', '2025-09-03 12:28:47', '9360552619', NULL, 'retail', 1, NULL, '33AAAAA0000A1Z5', 'CGST_SGST', 18.00),
(7, 'smkm', 'smkm@gmail.com', '2025-09-03 17:02:13', '936555621315', NULL, 'retail', 0, '24aaef2efc16794c3f249406a555f7a2', '33AAAAA0010A1Z5', 'CGST_SGST', 18.00),
(8, 'gani', 'gani@gmail.com', '2025-09-04 11:43:46', '9965727766', NULL, 'restarant', 0, '432b2708bb181b8c1aa247e634b64eaa', '33GANI1010A1Z5', 'CGST_SGST', 18.00),
(9, 'try pandren', 'try@example.com', '2025-12-10 11:00:20', '9585858575', NULL, 'generic', 1, NULL, '29ABCDE1234F2Z6', 'CGST_SGST', 18.00),
(10, 'my test org', 'orgtest@example.com', '2025-12-16 10:35:43', '9876543210', NULL, 'retail', 1, NULL, '29ABCDE1234F2Z5', 'CGST_SGST', 18.00),
(11, 'my test org1', 'orgtes1@example.com', '2025-12-16 11:05:47', '9876543210', NULL, 'retail', 1, NULL, '29ABCDE1234F2Z5', 'CGST_SGST', 18.00),
(12, 'my test org2', 'orgtes2@example.com', '2025-12-18 12:18:03', '9876543210', NULL, 'retail', 1, NULL, '29ABCDE1234F2Z6', 'CGST_SGST', 18.00),
(13, 'my test org3', 'orgtes3@example.com', '2025-12-18 12:47:21', '9876543210', NULL, 'retail', 1, NULL, '29ABCDE1234F2Z7', 'CGST_SGST', 18.00),
(14, 'my test org4', 'orgtes4@example.com', '2025-12-18 12:51:19', '9876543210', NULL, 'retail', 1, NULL, '29ABCDE1234F2Z8', 'CGST_SGST', 18.00),
(15, 'my test org5', 'orgtes5@example.com', '2025-12-18 12:55:38', '9876543210', NULL, 'retail', 1, NULL, '29ABCDE1234F2Z8', 'CGST_SGST', 18.00);

-- --------------------------------------------------------

--
-- Table structure for table `outlets`
--

CREATE TABLE `outlets` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `vertical` varchar(50) DEFAULT NULL,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `outlets`
--

INSERT INTO `outlets` (`id`, `org_id`, `name`, `address`, `vertical`, `config`, `created_at`) VALUES
(2, 3, 'Qads Main Branch', 'KKDI, TN', 'supermarket', NULL, '2025-09-01 11:54:42'),
(3, 4, 'Main Branch', 'Chennai', 'supermarket', NULL, '2025-09-01 17:14:28'),
(4, 5, 'Qads Store', '123 Main Street, City Center', 'supermarket', NULL, '2025-09-02 12:29:42'),
(5, 6, 'Sriram_demo', '123 Main Street,kkdi', 'retail', NULL, '2025-09-03 12:35:37'),
(6, 9, 'Default Outlet', NULL, NULL, NULL, '2025-12-10 11:31:50'),
(7, 9, 'KK Nagar', 'No.123, KK Nagar, kumbakkonam', 'generic', NULL, '2025-12-10 11:34:44'),
(8, 10, 'Default Outlet', NULL, NULL, NULL, '2025-12-16 10:38:49'),
(9, 10, 'Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:53:30'),
(10, 10, '1Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:54:05'),
(11, 10, '2Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:55:05'),
(12, 10, '3Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:55:21'),
(13, 10, '4Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:55:33'),
(14, 10, '5Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:55:43'),
(15, 10, '6Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:56:11'),
(16, 10, '7Nagar', 'No.123, RR Nagar, kumba', 'retail', NULL, '2025-12-16 10:56:24'),
(17, 11, 'Default Outlet', NULL, NULL, NULL, '2025-12-16 11:06:30'),
(18, 13, 'Default Outlet', NULL, NULL, NULL, '2025-12-18 12:48:13'),
(19, 15, 'Default Outlet', NULL, NULL, NULL, '2025-12-18 12:56:47');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_mode` enum('cash','card','upi','wallet') DEFAULT 'cash',
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `sale_id`, `org_id`, `outlet_id`, `amount`, `payment_mode`, `meta`, `created_at`) VALUES
(1, 9, 3, 2, 130.00, 'cash', NULL, '2025-09-01 13:34:07'),
(2, 17, 4, 3, 275.00, 'cash', '{\"txn_reference\":\"TXN-20250901-002\"}', '2025-09-01 17:41:28'),
(3, 28, 5, 4, 110.00, 'cash', '{\"note\":\"Paid in cash\"}', '2025-09-02 13:36:26'),
(4, 30, 6, 5, 140.00, 'upi', '\"{\\\"original_amount\\\":140,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"gst\\\":{\\\"cgst\\\":\\\"9.00\\\",\\\"sgst\\\":\\\"9.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-09-03 12:58:53'),
(5, 30, 6, 5, 140.00, 'upi', '\"{\\\"original_amount\\\":140,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"gst\\\":{\\\"cgst\\\":\\\"9.00\\\",\\\"sgst\\\":\\\"9.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-09-03 12:59:38'),
(7, 38, 11, 17, 645.00, 'cash', '\"{\\\"original_amount\\\":645,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 12:36:56'),
(8, 40, 11, 17, 360.00, 'cash', '\"{\\\"original_amount\\\":360,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 13:13:37'),
(9, 39, 11, 17, 360.00, 'cash', '\"{\\\"original_amount\\\":360,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 13:16:25'),
(10, 41, 11, 17, 360.00, 'cash', '\"{\\\"original_amount\\\":360,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 13:17:34'),
(11, 42, 11, 17, 289.00, 'cash', '\"{\\\"original_amount\\\":289,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 13:18:37'),
(12, 43, 11, 17, 289.00, 'cash', '\"{\\\"original_amount\\\":289,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 13:21:56'),
(13, 44, 11, 17, 2944.00, 'cash', '\"{\\\"original_amount\\\":2944,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"0.00\\\",\\\"sgst\\\":\\\"0.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 15:44:47'),
(14, 45, 11, 17, 2944.00, 'cash', '\"{\\\"original_amount\\\":2944,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"224.55\\\",\\\"sgst\\\":\\\"224.55\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 17:01:50'),
(15, 46, 11, 17, 584.00, 'cash', '\"{\\\"original_amount\\\":584,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"44.55\\\",\\\"sgst\\\":\\\"44.55\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 17:04:14'),
(16, 50, 11, 17, 18.00, 'cash', '\"{\\\"original_amount\\\":18,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst\\\":{\\\"cgst\\\":\\\"1.35\\\",\\\"sgst\\\":\\\"1.35\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-12-16 17:27:29'),
(17, 55, 11, 17, 525.00, 'cash', '\"{\\\"original_amount\\\":525,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst_summary\\\":{\\\"cgst\\\":12.5,\\\"sgst\\\":12.5,\\\"igst\\\":0}}\"', '2025-12-18 17:10:59'),
(18, 58, 11, 17, 105.00, 'cash', '\"{\\\"original_amount\\\":105,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"user_meta\\\":{\\\"note\\\":\\\"Paid at counter\\\"},\\\"gst_summary\\\":{\\\"cgst\\\":2.5,\\\"sgst\\\":2.5,\\\"igst\\\":0}}\"', '2025-12-19 08:56:08'),
(19, 57, 11, 17, 103.00, 'upi', '{\"original_amount\":105,\"redeem_points\":2,\"redeem_value\":2,\"user_meta\":{\"redeem_points\":2},\"gst_summary\":{\"cgst\":2.5,\"sgst\":2.5,\"igst\":0}}', '2025-12-19 09:01:20');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `category_id` int(11) DEFAULT NULL,
  `sub_category_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `org_id`, `outlet_id`, `name`, `price`, `meta`, `created_at`, `gst_rate`, `category_id`, `sub_category_id`) VALUES
(1, 3, 2, 'Pepsi 500ml', NULL, NULL, '2025-09-01 12:41:51', 0.00, NULL, NULL),
(2, 3, 2, 'Pepsi 1L', NULL, NULL, '2025-09-01 12:42:50', 0.00, NULL, NULL),
(3, 4, 3, 'Pepsi 1.5L', NULL, NULL, '2025-09-01 17:26:01', 0.00, NULL, NULL),
(14, 5, 4, 'Coca Cola', 40.00, '{\"brand\":\"Coca Cola\",\"size\":\"500ml\"}', '2025-09-02 13:02:35', 0.00, NULL, NULL),
(16, 5, 4, 'lays', 10.00, '{\"colour\":\"green\",\"size\":\"10rs pack\"}', '2025-09-02 13:09:03', 0.00, NULL, NULL),
(17, 6, 5, 'Dairy Milk Chocolate', 50.00, '{\"barcode\":\"8900060001721\"}', '2025-09-03 12:38:50', 0.00, NULL, NULL),
(18, 9, 6, 'Dairy Milk Chocolate', 50.00, '{\"barcode\":\"8900090001821\"}', '2025-12-10 11:40:18', 0.00, NULL, NULL),
(19, 9, 6, 'Chocolate', 100.00, '{\"barcode\":\"8900090001968\"}', '2025-12-10 12:19:11', 0.00, NULL, NULL),
(20, 9, 6, 'Milk 1L', 45.00, '{\"brand\":\"Aavin\",\"size\":\"1L\",\"barcode\":\"8900090002057\"}', '2025-12-10 13:38:44', 0.00, NULL, NULL),
(21, 9, 6, 'Pepsi 1.25L', 69.00, '{\"brand\":\"Pepsi\",\"size\":\"1.25L\",\"barcode\":\"8.90123E+12\"}', '2025-12-10 13:38:44', 0.00, NULL, NULL),
(22, 9, 6, 'Dhal', 110.00, '{\"brand\":\"Aashirvaad\",\"size\":\"1kg\",\"barcode\":\"8900090002279\"}', '2025-12-10 13:38:44', 0.00, NULL, NULL),
(23, 11, 17, 'Dhal', 110.00, '{\"brand\":\"Aashirvaad\",\"size\":\"1kg\",\"barcode\":\"8900110002364\"}', '2025-12-16 11:51:03', 0.00, NULL, NULL),
(24, 11, 17, 'Milk 1L', 45.00, '[]', '2025-12-16 11:51:03', 0.00, NULL, NULL),
(25, 11, 17, 'Pepsi', 50.00, '{\"brand\":\"Pepsi\",\"size\":\"500ml\",\"barcode\":\"8900110002524\"}', '2025-12-16 11:51:03', 0.00, NULL, NULL),
(26, 11, 17, 'Chocolate Bar', 20.00, '{\"brand\":\"Cadbury\",\"size\":\"25g\",\"barcode\":\"8900110002623\"}', '2025-12-16 11:51:03', 0.00, NULL, NULL),
(27, 11, 17, 'anil vermnichilli', 30.00, '{\"barcode\":\"8900110002708\"}', '2025-12-16 11:56:22', 0.00, NULL, NULL),
(28, 11, 17, 'COCA COLA', 40.00, '{\"barcode\":\"8900110002838\"}', '2025-12-18 15:42:06', 0.00, NULL, NULL),
(29, 11, 17, 'Milk', 100.00, '{\"barcode\":\"8900110002906\"}', '2025-12-18 15:56:15', 5.00, NULL, NULL),
(30, 11, 17, 'Tea Powder', 120.00, '{\"brand\":\"Tata\",\"size\":\"250g\",\"barcode\":\"8900110003026\"}', '2025-12-19 08:17:34', 5.00, NULL, NULL),
(31, 11, 17, 'Rice Bag', 1500.00, '{\"brand\":\"IndiaGate\",\"size\":\"25kg\",\"barcode\":\"8900110003149\"}', '2025-12-19 08:17:34', 0.00, NULL, NULL),
(32, 11, 17, 'Milk Packet', 30.00, '{\"brand\":\"Aavin\",\"size\":\"500ml\",\"barcode\":\"8900110003279\"}', '2025-12-19 08:17:34', 5.00, NULL, NULL),
(33, 11, 17, 'Shampoo', 180.00, '{\"brand\":\"Dove\",\"size\":\"180ml\",\"barcode\":\"8900110003361\"}', '2025-12-19 08:17:34', 18.00, NULL, NULL),
(34, 11, 17, 'Biscuits', 20.00, '{\"brand\":\"Britannia\",\"size\":\"50g\",\"barcode\":\"8900110003422\"}', '2025-12-19 08:17:34', 12.00, NULL, NULL),
(35, 11, 17, 'Tea Powder', 100.00, '[]', '2025-12-19 16:56:49', 5.00, 1, 2),
(36, 11, 17, 'chhilli Powder', 100.00, '{\"barcode\":\"8900110003644\"}', '2025-12-19 17:12:18', 5.00, 1, 2),
(37, 11, 17, 'Tea Powder', 100.00, '{\"brand\":\"Tata\",\"size\":\"250g\",\"barcode\":\"8900110003712\"}', '2025-12-20 08:05:15', 5.00, 5, 4),
(38, 11, 17, 'Coffee Powder', 180.00, '{\"brand\":\"Bru\",\"size\":\"200g\",\"barcode\":\"8900110003828\"}', '2025-12-20 08:05:16', 5.00, 5, 5),
(39, 11, 17, 'Sugar', 45.00, '{\"brand\":\"Local\",\"size\":\"1kg\",\"barcode\":\"8900110003989\"}', '2025-12-20 08:05:16', 0.00, 5, 6),
(40, 11, 17, 'Rice Bag', 1250.00, '{\"brand\":\"India Gate\",\"size\":\"5kg\",\"barcode\":\"8900110004078\"}', '2025-12-20 08:05:16', 0.00, 5, 6),
(41, 11, 17, 'Milk Packet', 30.00, '{\"brand\":\"Aavin\",\"size\":\"500ml\",\"barcode\":\"8900110004177\"}', '2025-12-20 08:05:16', 0.00, 6, 7),
(42, 11, 17, 'Curd Cup', 25.00, '{\"brand\":\"Heritage\",\"size\":\"200ml\",\"barcode\":\"8900110004238\"}', '2025-12-20 08:05:16', 0.00, 6, 8),
(43, 11, 17, 'Butter Pack', 55.00, '{\"brand\":\"Amul\",\"size\":\"100g\",\"barcode\":\"8900110004320\"}', '2025-12-20 08:05:16', 12.00, 6, 9),
(44, 11, 17, 'Bread Loaf', 40.00, '{\"brand\":\"Britannia\",\"size\":\"400g\",\"barcode\":\"8900110004481\"}', '2025-12-20 08:05:16', 0.00, 7, 10),
(45, 11, 17, 'Cake Slice', 60.00, '{\"brand\":\"Local\",\"size\":\"1pc\",\"barcode\":\"8900110004535\"}', '2025-12-20 08:05:16', 0.00, 7, 11),
(46, 11, 17, 'Shampoo', 120.00, '{\"brand\":\"Dove\",\"size\":\"100ml\",\"barcode\":\"8900110004689\"}', '2025-12-20 08:05:16', 18.00, 8, 12),
(47, 11, 17, 'Shampoo', 220.00, '{\"brand\":\"Dove\",\"size\":\"200ml\",\"barcode\":\"8900110004726\"}', '2025-12-20 08:05:16', 18.00, 8, 12),
(48, 11, 17, 'Soap Bar', 35.00, '{\"brand\":\"Pears\",\"size\":\"75g\",\"barcode\":\"8900110004894\"}', '2025-12-20 08:05:16', 18.00, 8, 13),
(49, 11, 17, 'Toothpaste', 95.00, '{\"brand\":\"Colgate\",\"size\":\"150g\",\"barcode\":\"8900110004955\"}', '2025-12-20 08:05:16', 18.00, 8, 14),
(50, 11, 17, 'Face Wash', 160.00, '{\"brand\":\"Garnier\",\"size\":\"100ml\",\"barcode\":\"8900110005082\"}', '2025-12-20 08:05:16', 18.00, 8, 15),
(51, 11, 17, 'Body Lotion', 210.00, '{\"brand\":\"Nivea\",\"size\":\"200ml\",\"barcode\":\"8900110005143\"}', '2025-12-20 08:05:16', 18.00, 8, 15);

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `gst_rate` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`id`, `product_id`, `name`, `price`, `created_at`, `gst_rate`) VALUES
(1, 17, 'Small Pack', 20.00, '2025-09-03 07:08:50', NULL),
(2, 17, 'Family Pack', 100.00, '2025-09-03 07:08:50', NULL),
(3, 18, 'Small Pack', 20.00, '2025-12-10 06:10:18', NULL),
(4, 18, 'Family Pack', 100.00, '2025-12-10 06:10:18', NULL),
(5, 19, 'Small Pack', 20.00, '2025-12-10 06:49:11', NULL),
(6, 19, 'Family Pack', 100.00, '2025-12-10 06:49:11', NULL),
(7, 22, '1KG Pack', 130.00, '2025-12-12 05:00:50', NULL),
(8, 22, '5KG Pack', 250.00, '2025-12-12 05:18:22', NULL),
(11, 23, '1KG Pack', 130.00, '2025-12-16 06:21:03', NULL),
(12, 23, '5KG Pack', 250.00, '2025-12-16 06:21:03', NULL),
(13, 25, 'Small Pack', 20.00, '2025-12-16 06:21:03', NULL),
(14, 25, 'Family Pack', 100.00, '2025-12-16 06:21:03', NULL),
(15, 27, 'Small Pack', 15.00, '2025-12-16 06:26:22', NULL),
(16, 27, 'Family Pack', 115.00, '2025-12-16 06:26:22', NULL),
(17, 28, '500 ML', 40.00, '2025-12-18 10:12:06', NULL),
(18, 28, '1 L', 75.00, '2025-12-18 10:12:06', NULL),
(19, 29, '500ml', 50.00, '2025-12-18 10:26:15', 5.00),
(20, 33, 'Small Bottle', 90.00, '2025-12-19 02:47:34', 18.00),
(21, 33, 'Large Bottle', 180.00, '2025-12-19 02:47:34', 18.00),
(22, 35, '500g', 100.00, '2025-12-19 11:26:49', 0.00),
(23, 35, '1kg', 180.00, '2025-12-19 11:26:49', 0.00),
(24, 35, '250mg Pack', 45.00, '2025-12-19 11:31:01', 5.00),
(25, 36, '500g', 100.00, '2025-12-19 11:42:18', 0.00),
(26, 36, '1kg', 180.00, '2025-12-19 11:42:18', 0.00),
(27, 40, 'Small Pack', 550.00, '2025-12-20 02:35:16', 0.00),
(28, 46, 'Small Pack', 120.00, '2025-12-20 02:35:16', 18.00),
(29, 47, 'Large Pack', 220.00, '2025-12-20 02:35:16', 18.00),
(30, 50, 'Tube', 160.00, '2025-12-20 02:35:16', 18.00);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `cgst` decimal(5,2) DEFAULT 0.00,
  `sgst` decimal(5,2) DEFAULT 0.00,
  `igst` decimal(5,2) DEFAULT 0.00,
  `round_off` decimal(10,2) NOT NULL DEFAULT 0.00,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `note` text DEFAULT NULL,
  `taxable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gst_total` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `org_id`, `outlet_id`, `customer_id`, `status`, `total_amount`, `discount`, `cgst`, `sgst`, `igst`, `round_off`, `meta`, `created_at`, `note`, `taxable_amount`, `gst_total`) VALUES
(9, 3, 2, NULL, 0, 140.00, 10.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-09-01 13:24:19', NULL, 0.00, 0.00),
(16, 4, 3, NULL, 0, 285.00, 10.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-09-01 17:36:50', NULL, 0.00, 0.00),
(17, 4, 3, NULL, 0, 285.00, 10.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-09-01 17:38:04', NULL, 0.00, 0.00),
(28, 5, 4, NULL, 0, 110.00, 10.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-09-02 13:32:55', NULL, 0.00, 0.00),
(29, 6, 5, NULL, 0, 140.00, 10.00, 9.00, 9.00, 0.00, 0.00, NULL, '2025-09-03 12:46:04', NULL, 0.00, 0.00),
(30, 6, 5, 3, 0, 140.00, 10.00, 9.00, 9.00, 0.00, 0.00, NULL, '2025-09-03 12:52:39', NULL, 0.00, 0.00),
(34, 9, 6, 4, 0, 1250.50, 50.00, 9.00, 9.00, 0.00, 0.00, NULL, '2025-12-11 15:31:55', 'customer requested packaging', 0.00, 0.00),
(36, 9, 6, NULL, 0, 260.00, 0.00, 9.00, 9.00, 0.00, 0.00, NULL, '2025-12-12 11:29:44', NULL, 0.00, 0.00),
(37, 11, 17, NULL, 0, 600.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 12:14:45', NULL, 0.00, 0.00),
(38, 11, 17, NULL, 1, 645.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 12:23:34', NULL, 0.00, 0.00),
(39, 11, 17, NULL, 1, 360.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 12:57:14', NULL, 0.00, 0.00),
(40, 11, 17, NULL, 1, 360.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 13:12:47', NULL, 0.00, 0.00),
(41, 11, 17, NULL, 1, 360.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 13:17:22', NULL, 0.00, 0.00),
(42, 11, 17, NULL, 1, 289.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 13:18:21', NULL, 0.00, 0.00),
(43, 11, 17, NULL, 1, 289.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 13:21:42', NULL, 0.00, 0.00),
(44, 11, 17, NULL, 1, 2944.00, 5.00, 0.00, 0.00, 0.00, 0.00, NULL, '2025-12-16 15:43:43', NULL, 0.00, 0.00),
(45, 11, 17, NULL, 1, 2944.00, 5.00, 224.55, 224.55, 0.00, 0.00, NULL, '2025-12-16 17:01:35', NULL, 0.00, 0.00),
(46, 11, 17, NULL, 1, 584.00, 5.00, 44.55, 44.55, 0.00, 0.00, NULL, '2025-12-16 17:04:04', NULL, 0.00, 0.00),
(47, 11, 17, NULL, 0, 584.00, 5.00, 44.55, 44.55, 0.00, 0.00, NULL, '2025-12-16 17:07:44', NULL, 0.00, 0.00),
(48, 11, 17, NULL, 0, 584.00, 5.00, 44.55, 44.55, 0.00, 0.00, NULL, '2025-12-16 17:07:49', NULL, 0.00, 0.00),
(49, 11, 17, NULL, 0, 289.00, 5.00, 22.05, 22.05, 0.00, 0.00, NULL, '2025-12-16 17:10:25', NULL, 0.00, 0.00),
(50, 11, 17, 6, 1, 18.00, 5.00, 1.35, 1.35, 0.00, 0.00, NULL, '2025-12-16 17:25:57', NULL, 0.00, 0.00),
(54, 11, 17, 6, 0, 525.00, 5.00, 12.50, 12.50, 0.00, 0.00, NULL, '2025-12-18 16:58:12', NULL, 500.00, 0.00),
(55, 11, 17, 6, 1, 525.00, 5.00, 12.50, 12.50, 0.00, 0.00, NULL, '2025-12-18 17:06:51', NULL, 500.00, 0.00),
(56, 11, 17, 6, 0, 105.00, 5.00, 2.50, 2.50, 0.00, 0.00, NULL, '2025-12-19 08:02:04', NULL, 100.00, 0.00),
(57, 11, 17, 6, 1, 105.00, 5.00, 2.50, 2.50, 0.00, 0.00, NULL, '2025-12-19 08:46:01', NULL, 100.00, 0.00),
(58, 11, 17, 6, 1, 105.00, 5.00, 2.50, 2.50, 0.00, 0.00, NULL, '2025-12-19 08:49:42', NULL, 100.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variant_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(10,2) NOT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `taxable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cgst` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sgst` decimal(10,2) NOT NULL DEFAULT 0.00,
  `igst` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `variant_id`, `quantity`, `rate`, `amount`, `meta`, `gst_rate`, `taxable_amount`, `cgst`, `sgst`, `igst`) VALUES
(1, 9, 1, NULL, 2, 35.00, 70.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(2, 9, 2, NULL, 1, 70.00, 70.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(3, 16, 3, NULL, 3, 95.00, 285.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(4, 17, 3, NULL, 3, 95.00, 285.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(5, 28, 14, NULL, 2, 80.00, 160.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(6, 28, 16, NULL, 3, 30.00, 90.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(7, 29, 17, NULL, 2, 50.00, 100.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(8, 30, 17, NULL, 2, 50.00, 100.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(12, 34, 19, NULL, 1, 100.00, 100.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(19, 36, 22, 7, 2, 130.00, 260.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(20, 37, 23, 12, 2, 250.00, 500.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(21, 37, 25, 14, 1, 100.00, 100.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(22, 38, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(23, 38, 25, 14, 4, 100.00, 400.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(24, 39, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(25, 39, 25, 13, 3, 20.00, 60.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(26, 40, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(27, 40, 25, 13, 3, 20.00, 60.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(28, 41, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(29, 41, 25, 13, 3, 20.00, 60.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(30, 42, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(31, 43, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(32, 44, 23, 12, 10, 250.00, 2500.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(33, 45, 23, 12, 10, 250.00, 2500.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(34, 46, 23, 12, 2, 250.00, 500.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(35, 47, 23, 12, 2, 250.00, 500.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(36, 48, 23, 12, 2, 250.00, 500.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(37, 49, 23, 12, 1, 250.00, 250.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(38, 50, 26, NULL, 1, 20.00, 20.00, NULL, 0.00, 0.00, 0.00, 0.00, 0.00),
(39, 54, 29, NULL, 5, 100.00, 500.00, NULL, 5.00, 500.00, 12.50, 12.50, 0.00),
(40, 55, 29, NULL, 5, 100.00, 525.00, NULL, 5.00, 500.00, 12.50, 12.50, 0.00),
(41, 56, 29, NULL, 1, 100.00, 105.00, NULL, 5.00, 100.00, 2.50, 2.50, 0.00),
(42, 57, 29, NULL, 1, 100.00, 105.00, NULL, 5.00, 100.00, 2.50, 2.50, 0.00),
(43, 58, 29, NULL, 1, 100.00, 105.00, NULL, 5.00, 100.00, 2.50, 2.50, 0.00);

--
-- Triggers `sale_items`
--
DELIMITER $$
CREATE TRIGGER `trg_after_sale_item_delete` AFTER DELETE ON `sale_items` FOR EACH ROW BEGIN
    -- Restore stock
    UPDATE inventory
    SET quantity = quantity + OLD.quantity
    WHERE product_id = OLD.product_id
      AND outlet_id = (SELECT outlet_id FROM sales WHERE id = OLD.sale_id)
      AND org_id = (SELECT org_id FROM sales WHERE id = OLD.sale_id);

    -- Log movement
    INSERT INTO inventory_logs (
        org_id, outlet_id, product_id,
        change_type, quantity_change, reference_id
    )
    SELECT
        s.org_id,
        s.outlet_id,
        OLD.product_id,
        'sale',
        OLD.quantity,
        OLD.sale_id
    FROM sales s
    WHERE s.id = OLD.sale_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_after_sale_item_insert` AFTER INSERT ON `sale_items` FOR EACH ROW BEGIN
    -- Reduce current stock
    UPDATE inventory
    SET quantity = quantity - NEW.quantity
    WHERE product_id = NEW.product_id
      AND outlet_id = (SELECT outlet_id FROM sales WHERE id = NEW.sale_id)
      AND org_id = (SELECT org_id FROM sales WHERE id = NEW.sale_id);

    -- Log the stock movement
    INSERT INTO inventory_logs (
        org_id, outlet_id, product_id,
        change_type, quantity_change, reference_id
    )
    SELECT
        s.org_id,
        s.outlet_id,
        NEW.product_id,
        'sale',
        -(NEW.quantity),
        NEW.sale_id
    FROM sales s
    WHERE s.id = NEW.sale_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_after_sale_item_update` AFTER UPDATE ON `sale_items` FOR EACH ROW BEGIN
    DECLARE diff DECIMAL(10,2);

    -- Difference between new qty and old qty
    SET diff = NEW.quantity - OLD.quantity;

    -- Adjust stock only if qty changed
    IF diff <> 0 THEN
        UPDATE inventory
        SET quantity = quantity - diff
        WHERE product_id = NEW.product_id
          AND outlet_id = (SELECT outlet_id FROM sales WHERE id = NEW.sale_id)
          AND org_id = (SELECT org_id FROM sales WHERE id = NEW.sale_id);

        -- Log movement
        INSERT INTO inventory_logs (
            org_id, outlet_id, product_id,
            change_type, quantity_change, reference_id
        )
        SELECT
            s.org_id,
            s.outlet_id,
            NEW.product_id,
            'sale',
            -diff,
            NEW.sale_id
        FROM sales s
        WHERE s.id = NEW.sale_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `plan` varchar(50) NOT NULL,
  `allowed_verticals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`allowed_verticals`)),
  `max_outlets` int(11) NOT NULL DEFAULT 1,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`features`)),
  `starts_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `status` enum('ACTIVE','EXPIRED','SUSPENDED') DEFAULT 'ACTIVE',
  `created_at` datetime DEFAULT current_timestamp(),
  `vertical` varchar(50) NOT NULL,
  `razorpay_order_id` varchar(255) DEFAULT NULL,
  `razorpay_payment_id` varchar(255) DEFAULT NULL,
  `razorpay_signature` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `org_id`, `plan`, `allowed_verticals`, `max_outlets`, `features`, `starts_at`, `expires_at`, `status`, `created_at`, `vertical`, `razorpay_order_id`, `razorpay_payment_id`, `razorpay_signature`) VALUES
(1, 2, 'free', '[\"retail\"]', 1, '[]', '2025-09-01 06:52:42', '2025-09-08 06:52:42', 'EXPIRED', '2025-09-01 10:22:42', '', NULL, NULL, NULL),
(2, 3, 'free', '[\"Supermarket\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-01 07:54:13', '2025-09-08 07:54:13', 'EXPIRED', '2025-09-01 11:24:13', '', NULL, NULL, NULL),
(3, 4, 'free', '[\"supermarket\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-01 13:40:15', '2025-09-08 13:40:15', 'EXPIRED', '2025-09-01 17:10:15', '', NULL, NULL, NULL),
(4, 5, 'free', '[\"supermarket\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-02 08:45:43', '2025-09-09 08:45:43', 'EXPIRED', '2025-09-02 12:15:43', '', NULL, NULL, NULL),
(5, 6, 'free', '[\"retail\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-03 09:02:20', '2025-09-10 09:02:20', 'EXPIRED', '2025-09-03 12:32:20', '', NULL, NULL, NULL),
(6, 9, 'free', '[\"generic\"]', 2, '[\"loyalty_points\",\"barcode\",\"expiry_date\"]', '2025-12-10 06:36:05', '2025-12-17 06:36:05', 'ACTIVE', '2025-12-10 11:06:05', '', NULL, NULL, NULL),
(7, 10, 'annual', '[\"retail\"]', 0, '[\"loyalty_points\",\"barcode\"]', '2025-12-16 10:51:47', '2026-12-16 10:51:47', 'ACTIVE', '2025-12-16 10:51:47', 'retail', NULL, NULL, NULL),
(8, 11, 'annual', '[\"retail\"]', 0, '[\"loyalty_points\",\"barcode\"]', '2025-12-16 11:08:41', '2026-12-16 11:08:41', 'ACTIVE', '2025-12-16 11:08:41', 'retail', NULL, NULL, NULL),
(9, 15, 'annual', '[\"Retail\"]', 1, '[\"loayalty_points\"]', '2025-12-18 15:07:17', '2026-12-18 15:07:17', 'ACTIVE', '2025-12-18 15:07:17', 'Retail', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sub_categories`
--

CREATE TABLE `sub_categories` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `status` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sub_categories`
--

INSERT INTO `sub_categories` (`id`, `category_id`, `name`, `status`, `created_at`) VALUES
(1, 1, 'Green Tea', 1, '2025-12-19 11:01:29'),
(2, 1, 'Tea', 1, '2025-12-19 11:06:05'),
(4, 5, 'Tea', 1, '2025-12-20 02:35:15'),
(5, 5, 'Coffee', 1, '2025-12-20 02:35:16'),
(6, 5, 'Essentials', 1, '2025-12-20 02:35:16'),
(7, 6, 'Milk', 1, '2025-12-20 02:35:16'),
(8, 6, 'Curd', 1, '2025-12-20 02:35:16'),
(9, 6, 'Butter', 1, '2025-12-20 02:35:16'),
(10, 7, 'Bread', 1, '2025-12-20 02:35:16'),
(11, 7, 'Cake', 1, '2025-12-20 02:35:16'),
(12, 8, 'Hair Care', 1, '2025-12-20 02:35:16'),
(13, 8, 'Bath Soap', 1, '2025-12-20 02:35:16'),
(14, 8, 'Oral Care', 1, '2025-12-20 02:35:16'),
(15, 8, 'Skin Care', 1, '2025-12-20 02:35:16');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `org_id`, `outlet_id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(3, 3, NULL, 'qads', 'qads@gmail.com', '$2y$10$sRq98iCBGHAvEbpzxvgwXO33euJy3.TydoRjakU3AjWnh6JBmXIoi', 'admin', '2025-09-01 11:26:31'),
(4, 4, NULL, 'admin user', 'adminsupermarket@example.com', '$2y$10$IQfuFjVa1jBXJ5Jl8vkx1uuvQiWqEKjvaV.6o6l0Tf6pwpxYJnQl2', 'admin', '2025-09-01 17:11:46'),
(6, 5, NULL, 'qads corporation', 'abc@gmail.com', '$2y$10$4KF0s8hrzuvqJcYuMRielue6zjw2IJ9n8KxE3CZ1GElVVD3M/aAOq', 'admin', '2025-09-02 12:24:43'),
(7, 6, NULL, 'sriram_demo', 'sridemo@gmail.com', '$2y$10$KEBsnpH4UwekncwvKK/ZVelquFgYRqX/iQIkHata2ecq08B6EyXFq', 'admin', '2025-09-03 12:33:37'),
(8, 9, 6, 'try pandren', 'try@example.com', '$2y$10$j5qtoG26le159sSF/x47iemM/JgjLVNDce65u8wxSRZCxurbL1P6O', 'admin', '2025-12-10 11:31:50'),
(9, 9, 7, 'KK Nagar Admin', 'try2@example.com', '$2y$10$QuSecn6ast3xSus0TsOpzeDdrKTTZkzZaoc1tR1MZ2KJd1/mkNYfa', 'staff', '2025-12-10 11:34:44'),
(10, 10, 8, 'my test org', 'orgtest@example.com', '$2y$10$U9zeugDDnpFpZv5b2hdc9ujsTOBWTiPNW2Gx4GdfkRxUgGaRMrEMq', 'admin', '2025-12-16 10:38:49'),
(11, 10, 9, 'Nagar Admin', 'branch2@example.com', '$2y$10$DuKDVzG9WLGaUcXKN7AguORj.Cffl3uZSA0spiPDWLlHWxxkQI6S2', 'staff', '2025-12-16 10:53:30'),
(12, 10, 10, '1Nagar Admin', 'branch3@example.com', '$2y$10$.CdQeI.aDzvsJ3xzvHk3p.Tx9wHzqF1IYbpaB80/JcnmuzeWLbqiO', 'staff', '2025-12-16 10:54:05'),
(13, 10, 11, '2Nagar Admin', 'branch4@example.com', '$2y$10$rrs0d3l.DHE24K4UrMG1JudMy/N3V44zpDzjS5rvJ/OIXleSTTlla', 'staff', '2025-12-16 10:55:05'),
(14, 10, 12, '3Nagar Admin', 'branch5@example.com', '$2y$10$I.ijfgmOKLNso.QZwVKGQuYNcemQpfWq/TRFmbhQVwR0wZ7x3Cwua', 'staff', '2025-12-16 10:55:22'),
(15, 10, 13, '4Nagar Admin', 'branch6@example.com', '$2y$10$4eXWggvIcx.XKxsqZ6VyxOS6k6f70BHukUfoh73d8/XkNFszUon/i', 'staff', '2025-12-16 10:55:33'),
(16, 10, 14, '5Nagar Admin', 'branch7@example.com', '$2y$10$eM2ZBV/XYyYQuVQa6a6JHOcsn/MVp3iQ7AhdjwuSbA3nupmqt82Ry', 'staff', '2025-12-16 10:55:43'),
(17, 10, 15, '6Nagar Admin', 'branch8@example.com', '$2y$10$YR0OUhFJPr3waJLr44hnJ.yJxg1FaAiNDKpnAu4FrFZ1BhXr5/ycG', 'staff', '2025-12-16 10:56:11'),
(18, 10, 16, '7Nagar Admin', 'branch9@example.com', '$2y$10$okdzeXAecwVrM.CSaBsYN.0yPIIJrjzXbWen6oSCXWqRKUbjsj0R.', 'staff', '2025-12-16 10:56:25'),
(19, 11, 17, 'my test org1', 'orgtest1@example.com', '$2y$10$spuoQff7LNzvZT9UkyD5Weefe2Jl2mInEkoqOvpInOWCHUROKM5C.', 'admin', '2025-12-16 11:06:30'),
(20, 13, 18, 'my test org3', 'orgtest3@example.com', '$2y$10$82To8NmEb8uiP3ySg8NAcO298oaN4aVFXGAewF3Q2b1IthIAHwWgW', 'admin', '2025-12-18 12:48:13'),
(21, 13, 18, 'my test org4', 'orgtest4@example.com', '$2y$10$VD0oVn/clH3KOklp07OCTelJXAondtEQl//iku6KGLuWMIeMOImOm', 'admin', '2025-12-18 12:51:56'),
(22, 15, 19, 'my test org5', 'orgtest5@example.com', '$2y$10$pd7xoGpJuh3RCZw5q41PTu30IIq9cGJR37R324MHp3cHAQIelpaWq', 'admin', '2025-12-18 12:56:47');

-- --------------------------------------------------------

--
-- Table structure for table `vertical_features`
--

CREATE TABLE `vertical_features` (
  `id` int(11) NOT NULL,
  `vertical` varchar(50) NOT NULL,
  `feature_id` int(11) NOT NULL,
  `is_required` tinyint(1) DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `extra_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extra_config`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vertical_features`
--

INSERT INTO `vertical_features` (`id`, `vertical`, `feature_id`, `is_required`, `enabled`, `extra_config`) VALUES
(11, 'textile', 1, 1, 1, NULL),
(12, 'textile', 2, 1, 1, NULL),
(13, 'retail', 1, 1, 1, NULL),
(14, 'retail', 2, 1, 1, NULL),
(15, 'supermarket', 1, 1, 1, NULL),
(16, 'supermarket', 2, 1, 1, NULL),
(17, 'pharmacy', 1, 1, 1, NULL),
(18, 'pharmacy', 2, 1, 1, NULL),
(19, 'pharmacy', 3, 1, 1, NULL),
(20, 'generic', 1, 0, 0, NULL),
(21, 'generic', 2, 0, 0, NULL),
(22, 'generic', 3, 0, 0, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_org_category` (`org_id`,`name`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_org_phone` (`org_id`,`phone`),
  ADD KEY `fk_customers_outlet` (`outlet_id`);

--
-- Indexes for table `features`
--
ALTER TABLE `features`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inventory` (`org_id`,`outlet_id`,`product_id`,`variant_id`),
  ADD KEY `org_id` (`org_id`),
  ADD KEY `outlet_id` (`outlet_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- Indexes for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`),
  ADD KEY `outlet_id` (`outlet_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `variant_id` (`variant_id`);

--
-- Indexes for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `fk_loyalty_outlet` (`outlet_id`);

--
-- Indexes for table `numbering_schemes`
--
ALTER TABLE `numbering_schemes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `org_id` (`org_id`);

--
-- Indexes for table `orgs`
--
ALTER TABLE `orgs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `outlets`
--
ALTER TABLE `outlets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `org_id` (`org_id`),
  ADD KEY `outlet_id` (`outlet_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`),
  ADD KEY `fk_products_outlet` (`outlet_id`),
  ADD KEY `fk_product_category` (`category_id`),
  ADD KEY `fk_product_sub_category` (`sub_category_id`);

--
-- Indexes for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`),
  ADD KEY `outlet_id` (`outlet_id`),
  ADD KEY `idx_sales_customer_id` (`customer_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`);

--
-- Indexes for table `sub_categories`
--
ALTER TABLE `sub_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_cat_sub` (`category_id`,`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `org_id` (`org_id`),
  ADD KEY `outlet_id` (`outlet_id`);

--
-- Indexes for table `vertical_features`
--
ALTER TABLE `vertical_features`
  ADD PRIMARY KEY (`id`),
  ADD KEY `feature_id` (`feature_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `features`
--
ALTER TABLE `features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `numbering_schemes`
--
ALTER TABLE `numbering_schemes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `orgs`
--
ALTER TABLE `orgs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `outlets`
--
ALTER TABLE `outlets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sub_categories`
--
ALTER TABLE `sub_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `vertical_features`
--
ALTER TABLE `vertical_features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_customers_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`);

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`),
  ADD CONSTRAINT `inventory_ibfk_2` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  ADD CONSTRAINT `inventory_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `inventory_ibfk_4` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD CONSTRAINT `inventory_logs_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`),
  ADD CONSTRAINT `inventory_logs_ibfk_2` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`),
  ADD CONSTRAINT `inventory_logs_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  ADD CONSTRAINT `inventory_logs_ibfk_4` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`);

--
-- Constraints for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  ADD CONSTRAINT `fk_loyalty_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `loyalty_points_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `loyalty_points_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `numbering_schemes`
--
ALTER TABLE `numbering_schemes`
  ADD CONSTRAINT `fk_numbering_org` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `outlets`
--
ALTER TABLE `outlets`
  ADD CONSTRAINT `outlets_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_product_sub_category` FOREIGN KEY (`sub_category_id`) REFERENCES `sub_categories` (`id`),
  ADD CONSTRAINT `fk_products_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`);

--
-- Constraints for table `product_variants`
--
ALTER TABLE `product_variants`
  ADD CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_customer_clean` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sub_categories`
--
ALTER TABLE `sub_categories`
  ADD CONSTRAINT `fk_sub_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vertical_features`
--
ALTER TABLE `vertical_features`
  ADD CONSTRAINT `vertical_features_ibfk_1` FOREIGN KEY (`feature_id`) REFERENCES `features` (`id`);

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `ev_expire_subscriptions` ON SCHEDULE EVERY 1 HOUR STARTS '2025-12-13 12:05:56' ON COMPLETION NOT PRESERVE ENABLE DO BEGIN
    UPDATE subscriptions
    SET status = 'EXPIRED'
    WHERE status = 'ACTIVE'
      AND expires_at IS NOT NULL
      AND expires_at < NOW();
END$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
