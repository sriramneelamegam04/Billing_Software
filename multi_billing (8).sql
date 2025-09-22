-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 04, 2025 at 09:21 AM
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
(3, 6, 5, 'ragul', '99999999999', '2025-09-03 12:40:46', '2025-09-03 12:40:46');

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
(4, 6, 5, 3, 30, 1.40, 0.00, '2025-09-03 12:52:39');

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
(38, 6, 6, '2025-09-03 07:16:04', '2025-09-03 08:12:11');

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
(8, 'gani', 'gani@gmail.com', '2025-09-04 11:43:46', '9965727766', NULL, 'restarant', 0, '432b2708bb181b8c1aa247e634b64eaa', '33GANI1010A1Z5', 'CGST_SGST', 18.00);

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
(5, 6, 'Sriram_demo', '123 Main Street,kkdi', 'retail', NULL, '2025-09-03 12:35:37');

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
(5, 30, 6, 5, 140.00, 'upi', '\"{\\\"original_amount\\\":140,\\\"redeem_points\\\":0,\\\"redeem_value\\\":0,\\\"gst\\\":{\\\"cgst\\\":\\\"9.00\\\",\\\"sgst\\\":\\\"9.00\\\",\\\"igst\\\":\\\"0.00\\\"}}\"', '2025-09-03 12:59:38');

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
  `category` varchar(100) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `org_id`, `outlet_id`, `name`, `price`, `category`, `meta`, `created_at`) VALUES
(1, 3, 2, 'Pepsi 500ml', NULL, NULL, NULL, '2025-09-01 12:41:51'),
(2, 3, 2, 'Pepsi 1L', NULL, NULL, NULL, '2025-09-01 12:42:50'),
(3, 4, 3, 'Pepsi 1.5L', NULL, NULL, NULL, '2025-09-01 17:26:01'),
(14, 5, 4, 'Coca Cola', 40.00, '', '{\"brand\":\"Coca Cola\",\"size\":\"500ml\"}', '2025-09-02 13:02:35'),
(16, 5, 4, 'lays', 10.00, 'snacks', '{\"colour\":\"green\",\"size\":\"10rs pack\"}', '2025-09-02 13:09:03'),
(17, 6, 5, 'Dairy Milk Chocolate', 50.00, 'Snacks', '{\"barcode\":\"8900060001721\"}', '2025-09-03 12:38:50');

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

CREATE TABLE `product_variants` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`id`, `product_id`, `name`, `price`, `created_at`) VALUES
(1, 17, 'Small Pack', 20.00, '2025-09-03 07:08:50'),
(2, 17, 'Family Pack', 100.00, '2025-09-03 07:08:50');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `org_id` int(11) NOT NULL,
  `outlet_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `cgst` decimal(5,2) DEFAULT 0.00,
  `sgst` decimal(5,2) DEFAULT 0.00,
  `igst` decimal(5,2) DEFAULT 0.00,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `org_id`, `outlet_id`, `customer_id`, `total_amount`, `discount`, `cgst`, `sgst`, `igst`, `meta`, `created_at`) VALUES
(9, 3, 2, NULL, 140.00, 10.00, 0.00, 0.00, 0.00, NULL, '2025-09-01 13:24:19'),
(16, 4, 3, NULL, 285.00, 10.00, 0.00, 0.00, 0.00, NULL, '2025-09-01 17:36:50'),
(17, 4, 3, NULL, 285.00, 10.00, 0.00, 0.00, 0.00, NULL, '2025-09-01 17:38:04'),
(28, 5, 4, NULL, 110.00, 10.00, 0.00, 0.00, 0.00, NULL, '2025-09-02 13:32:55'),
(29, 6, 5, NULL, 140.00, 10.00, 9.00, 9.00, 0.00, NULL, '2025-09-03 12:46:04'),
(30, 6, 5, 3, 140.00, 10.00, 9.00, 9.00, 0.00, NULL, '2025-09-03 12:52:39');

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
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `variant_id`, `quantity`, `rate`, `amount`, `meta`) VALUES
(1, 9, 1, NULL, 2, 35.00, 70.00, NULL),
(2, 9, 2, NULL, 1, 70.00, 70.00, NULL),
(3, 16, 3, NULL, 3, 95.00, 285.00, NULL),
(4, 17, 3, NULL, 3, 95.00, 285.00, NULL),
(5, 28, 14, NULL, 2, 80.00, 160.00, NULL),
(6, 28, 16, NULL, 3, 30.00, 90.00, NULL),
(7, 29, 17, NULL, 2, 50.00, 100.00, NULL),
(8, 30, 17, NULL, 2, 50.00, 100.00, NULL);

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
(1, 2, 'free', '[\"retail\"]', 1, '[]', '2025-09-01 06:52:42', '2025-09-08 06:52:42', 'ACTIVE', '2025-09-01 10:22:42', '', NULL, NULL, NULL),
(2, 3, 'free', '[\"Supermarket\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-01 07:54:13', '2025-09-08 07:54:13', 'ACTIVE', '2025-09-01 11:24:13', '', NULL, NULL, NULL),
(3, 4, 'free', '[\"supermarket\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-01 13:40:15', '2025-09-08 13:40:15', 'ACTIVE', '2025-09-01 17:10:15', '', NULL, NULL, NULL),
(4, 5, 'free', '[\"supermarket\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-02 08:45:43', '2025-09-09 08:45:43', 'ACTIVE', '2025-09-02 12:15:43', '', NULL, NULL, NULL),
(5, 6, 'free', '[\"retail\"]', 1, '[\"loyalty_points\",\"barcode\"]', '2025-09-03 09:02:20', '2025-09-10 09:02:20', 'ACTIVE', '2025-09-03 12:32:20', '', NULL, NULL, NULL);

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
(7, 6, NULL, 'sriram_demo', 'sridemo@gmail.com', '$2y$10$KEBsnpH4UwekncwvKK/ZVelquFgYRqX/iQIkHata2ecq08B6EyXFq', 'admin', '2025-09-03 12:33:37');

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
  ADD KEY `fk_products_outlet` (`outlet_id`);

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
  ADD KEY `fk_sales_customer` (`customer_id`);

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
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `features`
--
ALTER TABLE `features`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `loyalty_points`
--
ALTER TABLE `loyalty_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `numbering_schemes`
--
ALTER TABLE `numbering_schemes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `orgs`
--
ALTER TABLE `orgs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `outlets`
--
ALTER TABLE `outlets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `product_variants`
--
ALTER TABLE `product_variants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
  ADD CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`org_id`) REFERENCES `orgs` (`id`),
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`outlet_id`) REFERENCES `outlets` (`id`);

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
