-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 27, 2025 at 10:39 AM
-- Server version: 8.0.42
-- PHP Version: 8.4.11

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vxjtgclw_security`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int NOT NULL,
  `operator_id` int DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `operator_id`, `activity_type`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:09:46'),
(2, 1, 'visitor_registration', 'Registered new visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:25:36'),
(3, 1, 'print_card', 'Printed card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:25:41'),
(4, 1, 'gate_scan', 'QR scan check_in for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:26:29'),
(5, 1, 'gate_scan', 'QR scan check_out for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:27:31'),
(6, 1, 'print_card', 'Printed card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:35:49'),
(7, 1, 'print_card', 'Printed professional card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:56:04'),
(8, 1, 'print_card', 'Printed professional card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 20:58:21'),
(9, 1, 'print_card', 'Printed professional card for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-21 21:02:04'),
(10, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-21 22:18:14'),
(12, NULL, 'failed_login', 'Failed login attempt for code: jasper64@gmxxail.com', '128.90.145.244', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15', '2025-09-21 23:53:18'),
(13, NULL, 'failed_login', 'Failed login attempt for code: hugolehmann92@outlook.com', '128.90.145.244', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15', '2025-09-21 23:53:19'),
(14, NULL, 'failed_login', 'Failed login attempt for code: jasper64@gmxxail.com', '128.90.145.244', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15', '2025-09-21 23:53:20'),
(15, NULL, 'failed_login', 'Failed login attempt for code: jasper64@gmxxail.com', '128.90.145.244', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15', '2025-09-21 23:53:21'),
(16, NULL, 'failed_login', 'Failed login attempt for code: jasper_hoek90@tf-info.com', '128.90.145.244', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1', '2025-09-21 23:53:29'),
(17, NULL, 'failed_login', 'Failed login attempt for code: isobel72@hotmail.com', '128.90.145.244', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1', '2025-09-21 23:53:30'),
(18, NULL, 'failed_login', 'Failed login attempt for code: hugolehmann92@outlook.com', '128.90.145.244', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15', '2025-09-21 23:53:30'),
(19, NULL, 'failed_login', 'Failed login attempt for code: jasper_hoek90@tf-info.com', '128.90.145.244', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1', '2025-09-21 23:53:31'),
(20, NULL, 'failed_login', 'Failed login attempt for code: isobel72@hotmail.com', '128.90.145.244', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1', '2025-09-21 23:53:32'),
(21, NULL, 'failed_login', 'Failed login attempt for code: daron.larson6@gmail.com', '128.90.145.244', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.0 Mobile/15E148 Safari/604.1', '2025-09-21 23:53:40'),
(22, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 03:00:02'),
(23, 1, 'print_card', 'Printed professional card for visitor: Dylan Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 03:00:17'),
(24, 1, 'photo_upload', 'Uploaded photo for visitor: VIS68D07AA8B9638', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 03:08:33'),
(25, 1, 'print_card', 'Printed professional card for visitor: Dylan Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 03:08:39'),
(26, 1, 'photo_upload', 'Uploaded photo for visitor: VIS68D05F4001FAC', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 03:23:03'),
(27, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 03:25:17'),
(28, 1, 'gate_scan', 'QR scan check_in for visitor: Dennis Mwangi', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 03:26:05'),
(29, 1, 'gate_scan', 'QR scan check_in for visitor: Dylan Mwangi', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 04:08:02'),
(30, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 06:33:57'),
(31, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 06:38:29'),
(32, 1, 'print_card', 'Printed professional card for visitor: Dylan Mwangi', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 06:44:13'),
(33, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 07:05:39'),
(34, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 07:06:45'),
(35, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 08:11:33'),
(36, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 08:14:28'),
(37, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 08:14:48'),
(38, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 08:15:33'),
(39, 1, 'gate_scan', 'QR scan check_out for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 08:15:50'),
(40, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 08:17:23'),
(41, 1, 'settings_update', 'Updated system settings', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 08:19:15'),
(42, 1, 'print_card', 'Printed professional card for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 08:27:57'),
(43, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 08:46:57'),
(44, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 09:54:17'),
(45, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:32:21'),
(46, 1, 'print_card', 'Printed professional card for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:37:52'),
(47, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-22 10:51:41'),
(48, 1, 'login', 'Operator logged in', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:56:56'),
(49, 1, 'gate_scan', 'QR scan check_in for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:57:17'),
(50, 1, 'gate_scan', 'QR scan check_out for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:57:42'),
(51, 1, 'gate_scan', 'QR scan check_in for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:58:41'),
(52, 1, 'gate_scan', 'QR scan check_out for visitor: Dylan Mwangi', '154.159.252.156', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 10:59:43'),
(53, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 15:31:33'),
(54, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-22 17:09:23'),
(55, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 06:42:58'),
(56, 1, 'vehicle_registration', 'Registered new vehicle: KDA 001A', '41.209.3.54', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/603.1.30 (KHTML, like Gecko) Version/17.5 Mobile/15A5370a Safari/602.1', '2025-09-23 08:58:26'),
(57, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 09:30:19'),
(58, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:33:01'),
(59, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 09:36:48'),
(60, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:37:40'),
(61, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 09:38:03'),
(62, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:39:01'),
(63, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 09:39:21'),
(64, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:39:33'),
(65, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 09:42:08'),
(66, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:43:04'),
(67, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-23 09:43:51'),
(68, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:44:19'),
(69, 1, 'gate_scan', 'QR scan check_in for visitor: Dylan Mwangi', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-23 09:44:34'),
(70, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-24 10:27:04'),
(71, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-24 10:46:39'),
(72, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-25 08:20:46'),
(73, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-26 06:32:19'),
(74, 1, 'vehicle_check_in', 'Check in for vehicle: KDA 001A', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-26 07:28:53'),
(75, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-27 07:18:58'),
(76, 1, 'vehicle_check_out', 'Check out for vehicle: KDA 001A', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-27 07:19:44'),
(77, 1, 'export_report', 'Exported activity report for 2025-08-28 to 2025-09-27', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-27 07:21:48'),
(78, 1, 'gate_scan', 'QR scan check_out for visitor: Dylan Mwangi', '41.209.3.54', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36', '2025-09-27 07:23:22'),
(79, 1, 'login', 'Operator logged in', '41.209.3.54', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '2025-09-27 07:34:35');

-- --------------------------------------------------------

--
-- Table structure for table `card_print_logs`
--

CREATE TABLE `card_print_logs` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `template_id` int DEFAULT NULL,
  `printed_by` int NOT NULL,
  `print_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `print_quality` enum('draft','normal','high','photo') DEFAULT 'normal',
  `copies_printed` int DEFAULT '1',
  `printer_used` varchar(100) DEFAULT NULL,
  `notes` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `card_print_logs`
--

INSERT INTO `card_print_logs` (`id`, `visitor_id`, `template_id`, `printed_by`, `print_timestamp`, `print_quality`, `copies_printed`, `printer_used`, `notes`) VALUES
(1, 'VIS68D05F4001FAC', NULL, 1, '2025-09-21 20:56:04', 'high', 1, NULL, NULL),
(2, 'VIS68D05F4001FAC', NULL, 1, '2025-09-21 20:58:21', 'high', 1, NULL, NULL),
(3, 'VIS68D05F4001FAC', NULL, 1, '2025-09-21 21:02:04', 'high', 1, NULL, NULL),
(6, 'VIS68D07AA8B9638', NULL, 1, '2025-09-22 03:00:17', 'high', 1, NULL, NULL),
(7, 'VIS68D07AA8B9638', NULL, 1, '2025-09-22 03:08:39', 'high', 1, NULL, NULL),
(8, 'VIS68D07AA8B9638', NULL, 1, '2025-09-22 06:44:13', 'high', 1, NULL, NULL),
(9, 'VIS68D07AA8B9638', NULL, 1, '2025-09-22 08:27:57', 'high', 1, NULL, NULL),
(10, 'VIS68D07AA8B9638', NULL, 1, '2025-09-22 10:37:52', 'high', 1, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `card_templates`
--

CREATE TABLE `card_templates` (
  `id` int NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `background_color` varchar(7) DEFAULT '#ffffff',
  `header_color` varchar(7) DEFAULT '#2563eb',
  `text_color` varchar(7) DEFAULT '#000000',
  `logo_position` enum('top-left','top-right','center','bottom') DEFAULT 'top-right',
  `show_photo` tinyint(1) DEFAULT '1',
  `show_qr_front` tinyint(1) DEFAULT '0',
  `show_qr_back` tinyint(1) DEFAULT '1',
  `security_features` json DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `card_templates`
--

INSERT INTO `card_templates` (`id`, `template_name`, `background_color`, `header_color`, `text_color`, `logo_position`, `show_photo`, `show_qr_front`, `show_qr_back`, `security_features`, `is_default`, `is_active`, `created_at`) VALUES
(1, 'Professional Default', '#ffffff', '#2563eb', '#000000', 'top-right', 1, 0, 1, '{\"hologram\": true, \"watermark\": false, \"security_strip\": true}', 1, 1, '2025-09-21 20:34:47'),
(2, 'Professional Default', '#ffffff', '#2563eb', '#000000', 'top-right', 1, 0, 1, '{\"hologram\": true, \"watermark\": false, \"security_strip\": true}', 1, 1, '2025-09-21 20:37:13');

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `address` text,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `company_name`, `logo_path`, `contact_person`, `contact_email`, `contact_phone`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Acme Corporation', NULL, 'John Manager', 'contact@acme.com', '+1234567890', NULL, 1, '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(2, 'Tech Solutions Ltd', NULL, 'Jane Director', 'info@techsolutions.com', '+0987654321', NULL, 1, '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(3, 'Global Industries', NULL, 'Mike CEO', 'admin@global.com', '+1122334455', NULL, 1, '2025-09-21 20:34:47', '2025-09-21 20:34:47');

-- --------------------------------------------------------

--
-- Stand-in structure for view `daily_statistics_by_location`
-- (See below for the actual view)
--
CREATE TABLE `daily_statistics_by_location` (
`delivery_arrivals` bigint
,`delivery_departures` bigint
,`location_id` int
,`location_name` varchar(100)
,`log_date` date
,`unique_vehicles` bigint
,`unique_visitors` bigint
,`vehicle_check_ins` bigint
,`vehicle_check_outs` bigint
,`visitor_check_ins` bigint
,`visitor_check_outs` bigint
);

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` int NOT NULL,
  `delivery_id` varchar(20) NOT NULL,
  `vehicle_id` varchar(20) DEFAULT NULL,
  `visitor_id` varchar(20) DEFAULT NULL,
  `location_id` int NOT NULL,
  `delivery_type` enum('pickup','delivery','service','maintenance') NOT NULL,
  `delivery_company` varchar(100) NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `sender_name` varchar(100) DEFAULT NULL,
  `sender_phone` varchar(20) DEFAULT NULL,
  `receiver_name` varchar(100) DEFAULT NULL,
  `receiver_phone` varchar(20) DEFAULT NULL,
  `receiver_department` varchar(100) DEFAULT NULL,
  `package_description` text,
  `package_count` int DEFAULT '1',
  `special_instructions` text,
  `scheduled_time` datetime DEFAULT NULL,
  `arrived_time` datetime DEFAULT NULL,
  `completed_time` datetime DEFAULT NULL,
  `status` enum('scheduled','arrived','in_progress','completed','cancelled') DEFAULT 'scheduled',
  `proof_photos` json DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `description` text,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `department_name`, `description`, `contact_person`, `contact_phone`, `contact_email`, `is_active`, `created_at`) VALUES
(1, 'Administration', NULL, 'Admin Officer', NULL, NULL, 1, '2025-09-21 16:53:07'),
(2, 'Security', NULL, 'Security Manager', NULL, NULL, 1, '2025-09-21 16:53:07'),
(3, 'HR', NULL, 'HR Manager', NULL, NULL, 1, '2025-09-21 16:53:07'),
(4, 'IT', NULL, 'IT Support', NULL, NULL, 1, '2025-09-21 16:53:07'),
(5, 'Maintenance', NULL, 'Maintenance Supervisor', NULL, NULL, 1, '2025-09-21 16:53:07');

-- --------------------------------------------------------

--
-- Table structure for table `gates`
--

CREATE TABLE `gates` (
  `id` int NOT NULL,
  `location_id` int NOT NULL,
  `gate_name` varchar(100) NOT NULL,
  `gate_code` varchar(20) NOT NULL,
  `gate_type` enum('entry','exit','both') DEFAULT 'both',
  `supports_vehicles` tinyint(1) DEFAULT '1',
  `supports_pedestrians` tinyint(1) DEFAULT '1',
  `scanner_device_id` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `gates`
--

INSERT INTO `gates` (`id`, `location_id`, `gate_name`, `gate_code`, `gate_type`, `supports_vehicles`, `supports_pedestrians`, `scanner_device_id`, `is_active`, `created_at`) VALUES
(1, 1, 'Main Gate', 'MAIN_G1', 'both', 1, 1, NULL, 1, '2025-09-22 09:39:35'),
(2, 1, 'Pedestrian Gate', 'MAIN_G2', 'both', 0, 1, NULL, 1, '2025-09-22 09:39:35'),
(3, 1, 'Vehicle Gate', 'MAIN_G3', 'both', 1, 0, NULL, 1, '2025-09-22 09:39:35'),
(4, 2, 'Branch Main Gate', 'BRANCH_G1', 'both', 1, 1, NULL, 1, '2025-09-22 09:39:35'),
(5, 3, 'Warehouse Gate A', 'WARE_G1', 'both', 1, 1, NULL, 1, '2025-09-22 09:39:35'),
(6, 3, 'Warehouse Gate B', 'WARE_G2', 'both', 1, 0, NULL, 1, '2025-09-22 09:39:35');

-- --------------------------------------------------------

--
-- Table structure for table `gate_logs`
--

CREATE TABLE `gate_logs` (
  `id` int NOT NULL,
  `location_id` int DEFAULT NULL,
  `gate_id` int DEFAULT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `vehicle_id` varchar(20) DEFAULT NULL,
  `log_type` enum('check_in','check_out') NOT NULL,
  `entry_type` enum('visitor','vehicle','delivery','service','staff') DEFAULT 'visitor',
  `delivery_type` enum('pickup','delivery','service','maintenance') DEFAULT NULL,
  `delivery_company` varchar(100) DEFAULT NULL,
  `delivery_reference` varchar(100) DEFAULT NULL,
  `expected_duration` int DEFAULT NULL COMMENT 'Expected duration in minutes',
  `actual_duration` int DEFAULT NULL COMMENT 'Actual duration in minutes',
  `verification_method` enum('qr','rfid','manual','license_plate') DEFAULT 'qr',
  `temperature_check` decimal(4,2) DEFAULT NULL,
  `health_declaration` tinyint(1) DEFAULT NULL,
  `gate_location` varchar(100) DEFAULT NULL,
  `operator_id` int NOT NULL,
  `purpose_of_visit` text,
  `host_name` varchar(100) DEFAULT NULL,
  `host_department` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `notes` text,
  `log_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `gate_logs`
--

INSERT INTO `gate_logs` (`id`, `location_id`, `gate_id`, `visitor_id`, `vehicle_id`, `log_type`, `entry_type`, `delivery_type`, `delivery_company`, `delivery_reference`, `expected_duration`, `actual_duration`, `verification_method`, `temperature_check`, `health_declaration`, `gate_location`, `operator_id`, `purpose_of_visit`, `host_name`, `host_department`, `vehicle_number`, `notes`, `log_timestamp`) VALUES
(1, NULL, NULL, 'VIS68D05F4001FAC', NULL, 'check_in', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, 'Main Gate', 1, '', '', '', 'KDQ 123J', '', '2025-09-21 20:26:29'),
(2, NULL, NULL, 'VIS68D05F4001FAC', NULL, 'check_out', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, 'Main Gate', 1, '', '', '', 'KDQ 123J', '', '2025-09-21 20:27:31'),
(3, NULL, NULL, 'VIS68D05F4001FAC', NULL, 'check_in', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, 'Main Gate', 1, '', '', '', 'KDQ 123J', '', '2025-09-22 03:26:05'),
(4, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_in', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, 'Main Gate', 1, '', '', '', 'KDS 442T', '', '2025-09-22 04:08:02'),
(5, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_out', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, 'Main Gate', 1, '', '', '', 'KDS 442T', '', '2025-09-22 08:15:50'),
(6, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_in', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, NULL, 1, '', '', '', 'KDS 442T', '', '2025-09-22 10:57:17'),
(7, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_out', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, NULL, 1, '', '', '', 'KDS 442T', '', '2025-09-22 10:57:42'),
(8, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_in', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, NULL, 1, '', '', '', 'KDS 442T', '', '2025-09-22 10:58:41'),
(9, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_out', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, NULL, 1, '', '', '', 'KDS 442T', '', '2025-09-22 10:59:43'),
(10, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_in', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, NULL, 1, '', '', '', 'KDS 442T', '', '2025-09-23 09:44:34'),
(11, NULL, NULL, 'VIS68D07AA8B9638', NULL, 'check_out', 'visitor', NULL, NULL, NULL, NULL, NULL, 'qr', NULL, NULL, NULL, 1, '', '', '', 'KDS 442T', '', '2025-09-27 07:23:22');

-- --------------------------------------------------------

--
-- Table structure for table `gate_operators`
--

CREATE TABLE `gate_operators` (
  `id` int NOT NULL,
  `operator_name` varchar(100) NOT NULL,
  `operator_code` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','operator') DEFAULT 'operator',
  `is_active` tinyint(1) DEFAULT '1',
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `gate_operators`
--

INSERT INTO `gate_operators` (`id`, `operator_name`, `operator_code`, `password_hash`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'ADMIN001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, '2025-09-27 07:34:35', '2025-09-21 16:53:06', '2025-09-27 07:34:35');

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `id` int NOT NULL,
  `location_name` varchar(100) NOT NULL,
  `location_code` varchar(20) NOT NULL,
  `address` text,
  `contact_person` varchar(100) DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `contact_email` varchar(100) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT 'Africa/Nairobi',
  `operating_hours_from` time DEFAULT '06:00:00',
  `operating_hours_to` time DEFAULT '22:00:00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`id`, `location_name`, `location_code`, `address`, `contact_person`, `contact_phone`, `contact_email`, `timezone`, `operating_hours_from`, `operating_hours_to`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Main Campus', 'MAIN', '123 Main Street, Nairobi', 'John Security', '+254700000001', NULL, 'Africa/Nairobi', '06:00:00', '22:00:00', 1, '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(2, 'Branch Office', 'BRANCH', '456 Branch Road, Nairobi', 'Jane Guard', '+254700000002', NULL, 'Africa/Nairobi', '06:00:00', '22:00:00', 1, '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(3, 'Warehouse', 'WAREHOUSE', '789 Industrial Area, Nairobi', 'Mike Supervisor', '+254700000003', NULL, 'Africa/Nairobi', '06:00:00', '22:00:00', 1, '2025-09-22 09:39:35', '2025-09-22 09:39:35');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `type` enum('check_in','check_out','pre_registration','alert') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `visitor_id` varchar(20) DEFAULT NULL,
  `operator_id` int DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `title`, `message`, `visitor_id`, `operator_id`, `is_read`, `created_at`) VALUES
(1, 'check_in', 'Check in', 'Visitor Dennis Mwangi has checked in', 'VIS68D05F4001FAC', 1, 0, '2025-09-22 03:26:05'),
(2, 'check_in', 'Check in', 'Visitor Dylan Mwangi has checked in', 'VIS68D07AA8B9638', 1, 0, '2025-09-22 04:08:02'),
(3, 'check_out', 'Check out', 'Visitor Dylan Mwangi has checked out', 'VIS68D07AA8B9638', 1, 0, '2025-09-22 08:15:50'),
(4, 'check_in', 'Check in', 'Visitor Dylan Mwangi has checked in', 'VIS68D07AA8B9638', 1, 0, '2025-09-22 10:57:17'),
(5, 'check_out', 'Check out', 'Visitor Dylan Mwangi has checked out', 'VIS68D07AA8B9638', 1, 0, '2025-09-22 10:57:42'),
(6, 'check_in', 'Check in', 'Visitor Dylan Mwangi has checked in', 'VIS68D07AA8B9638', 1, 0, '2025-09-22 10:58:41'),
(7, 'check_out', 'Check out', 'Visitor Dylan Mwangi has checked out', 'VIS68D07AA8B9638', 1, 0, '2025-09-22 10:59:43'),
(8, 'check_in', 'Check in', 'Visitor Dylan Mwangi has checked in', 'VIS68D07AA8B9638', 1, 0, '2025-09-23 09:44:34'),
(9, 'check_out', 'Check out', 'Visitor Dylan Mwangi has checked out', 'VIS68D07AA8B9638', 1, 0, '2025-09-27 07:23:22');

-- --------------------------------------------------------

--
-- Table structure for table `operator_locations`
--

CREATE TABLE `operator_locations` (
  `id` int NOT NULL,
  `operator_id` int NOT NULL,
  `location_id` int NOT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `operator_locations`
--

INSERT INTO `operator_locations` (`id`, `operator_id`, `location_id`, `is_primary`, `assigned_at`, `assigned_by`) VALUES
(1, 1, 1, 1, '2025-09-22 09:39:35', NULL),
(2, 1, 2, 0, '2025-09-22 09:39:35', NULL),
(3, 1, 3, 0, '2025-09-22 09:39:35', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `operator_sessions`
--

CREATE TABLE `operator_sessions` (
  `id` int NOT NULL,
  `operator_id` int NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `operator_sessions`
--

INSERT INTO `operator_sessions` (`id`, `operator_id`, `session_token`, `expires_at`, `created_at`) VALUES
(39, 1, '206376ec0ec02551827801340031e336ce9724e98d7b3f9c43519aeaf1e07a90', '2025-09-27 08:34:35', '2025-09-27 07:34:35');

-- --------------------------------------------------------

--
-- Table structure for table `pre_registrations`
--

CREATE TABLE `pre_registrations` (
  `id` int NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `purpose_of_visit` text,
  `host_name` varchar(100) DEFAULT NULL,
  `host_department` varchar(100) DEFAULT NULL,
  `visit_date` date NOT NULL,
  `visit_time_from` time DEFAULT NULL,
  `visit_time_to` time DEFAULT NULL,
  `status` enum('pending','approved','rejected','used') DEFAULT 'pending',
  `qr_code` varchar(255) DEFAULT NULL,
  `created_by_operator` int DEFAULT NULL,
  `approved_by_operator` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `location_id` int DEFAULT NULL,
  `setting_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `location_id`, `setting_value`, `created_at`, `updated_at`) VALUES
(1, 'system_name', NULL, 'Gate Management System', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(2, 'primary_color', NULL, '#2563eb', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(3, 'secondary_color', NULL, '#1f2937', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(4, 'accent_color', NULL, '#10b981', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(5, 'email_notifications', NULL, 'false', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(6, 'smtp_host', NULL, '', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(7, 'smtp_port', NULL, '', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(8, 'smtp_username', NULL, '', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(9, 'smtp_password', NULL, '', '2025-09-21 16:53:06', '2025-09-21 16:53:06'),
(10, 'session_timeout', NULL, '3600', '2025-09-21 16:53:06', '2025-09-22 08:19:15'),
(11, 'card_logo_path', NULL, '', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(12, 'card_background_image', NULL, '', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(13, 'card_expiry_days', NULL, '30', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(14, 'card_security_features', NULL, 'true', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(15, 'card_double_sided', NULL, 'true', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(16, 'print_resolution', NULL, 'high', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(17, 'card_paper_size', NULL, 'cr80', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(18, 'organization_name', NULL, 'Gate Management System', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(19, 'security_contact', NULL, '+1-800-SECURITY', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(20, 'enable_photo_capture', NULL, 'true', '2025-09-21 20:34:47', '2025-09-21 20:34:47'),
(22, 'system_name', 1, 'Main Campus Gate System', '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(23, 'system_name', 2, 'Branch Office Gate System', '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(24, 'system_name', 3, 'Warehouse Gate System', '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(25, 'max_vehicle_duration', 1, '240', '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(26, 'max_vehicle_duration', 2, '180', '2025-09-22 09:39:35', '2025-09-22 09:39:35'),
(27, 'max_vehicle_duration', 3, '480', '2025-09-22 09:39:35', '2025-09-22 09:39:35');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int NOT NULL,
  `vehicle_id` varchar(20) NOT NULL,
  `license_plate` varchar(20) NOT NULL,
  `vehicle_type_id` int DEFAULT NULL,
  `make` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `year` year DEFAULT NULL,
  `color` varchar(30) DEFAULT NULL,
  `owner_name` varchar(100) DEFAULT NULL,
  `owner_phone` varchar(20) DEFAULT NULL,
  `owner_company` varchar(100) DEFAULT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `driver_license` varchar(50) DEFAULT NULL,
  `vehicle_photo_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `rfid_tag` varchar(100) DEFAULT NULL,
  `is_company_vehicle` tinyint(1) DEFAULT '0',
  `is_delivery_vehicle` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive','blocked','maintenance') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `vehicle_id`, `license_plate`, `vehicle_type_id`, `make`, `model`, `year`, `color`, `owner_name`, `owner_phone`, `owner_company`, `driver_name`, `driver_phone`, `driver_license`, `vehicle_photo_path`, `qr_code`, `rfid_tag`, `is_company_vehicle`, `is_delivery_vehicle`, `status`, `created_at`, `updated_at`) VALUES
(1, 'VEH68D2613210484', 'KDA 001A', 2, 'Isuzu', 'FVZ', '2016', 'White', 'Daniel Kiragu', '+254710256330', 'Dessra', 'Daniel Kiragu', '+254710256330', '123456987', NULL, '47de2ad10fa8a2b2c5320736f6323fe109f41eea5abefded444abac8fe887c4b', NULL, 0, 1, 'active', '2025-09-23 08:58:26', '2025-09-23 08:58:26');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_logs`
--

CREATE TABLE `vehicle_logs` (
  `id` int NOT NULL,
  `vehicle_id` varchar(20) NOT NULL,
  `log_type` enum('check_in','check_out') NOT NULL,
  `location_id` int NOT NULL,
  `gate_id` int DEFAULT NULL,
  `entry_purpose` enum('delivery','pickup','service','maintenance','visitor','staff') NOT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `driver_phone` varchar(20) DEFAULT NULL,
  `driver_license` varchar(50) DEFAULT NULL,
  `passenger_count` int DEFAULT '0',
  `cargo_description` text,
  `delivery_company` varchar(100) DEFAULT NULL,
  `delivery_reference` varchar(100) DEFAULT NULL,
  `destination_department` varchar(100) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `expected_duration` int DEFAULT NULL,
  `operator_id` int NOT NULL,
  `verification_method` enum('qr','rfid','manual','license_plate') DEFAULT 'qr',
  `photos` json DEFAULT NULL COMMENT 'Array of photo paths',
  `notes` text,
  `log_timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vehicle_logs`
--

INSERT INTO `vehicle_logs` (`id`, `vehicle_id`, `log_type`, `location_id`, `gate_id`, `entry_purpose`, `driver_name`, `driver_phone`, `driver_license`, `passenger_count`, `cargo_description`, `delivery_company`, `delivery_reference`, `destination_department`, `contact_person`, `expected_duration`, `operator_id`, `verification_method`, `photos`, `notes`, `log_timestamp`) VALUES
(2, 'VEH68D2613210484', 'check_in', 1, NULL, '', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'qr', NULL, NULL, '2025-09-26 07:28:53'),
(4, 'VEH68D2613210484', 'check_out', 1, NULL, '', NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, 1, 'qr', NULL, NULL, '2025-09-27 07:19:44');

-- --------------------------------------------------------

--
-- Stand-in structure for view `vehicle_status_by_location`
-- (See below for the actual view)
--
CREATE TABLE `vehicle_status_by_location` (
`current_status` varchar(13)
,`entry_purpose` enum('delivery','pickup','service','maintenance','visitor','staff')
,`last_activity` timestamp
,`last_gate` varchar(100)
,`last_operator` varchar(100)
,`license_plate` varchar(20)
,`location_id` int
,`location_name` varchar(100)
,`make` varchar(50)
,`model` varchar(50)
,`owner_company` varchar(100)
,`owner_name` varchar(100)
,`vehicle_id` varchar(20)
);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_types`
--

CREATE TABLE `vehicle_types` (
  `id` int NOT NULL,
  `type_name` varchar(50) NOT NULL,
  `description` text,
  `icon_path` varchar(255) DEFAULT NULL,
  `default_duration` int DEFAULT '60' COMMENT 'Default expected duration in minutes',
  `requires_escort` tinyint(1) DEFAULT '0',
  `restricted_areas` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vehicle_types`
--

INSERT INTO `vehicle_types` (`id`, `type_name`, `description`, `icon_path`, `default_duration`, `requires_escort`, `restricted_areas`, `is_active`, `created_at`) VALUES
(1, 'Car', 'Personal or company cars', NULL, 30, 0, NULL, 1, '2025-09-21 16:53:07'),
(2, 'Truck', 'Delivery trucks and heavy vehicles', NULL, 60, 1, NULL, 1, '2025-09-21 16:53:07'),
(3, 'Motorcycle', 'Motorcycles and scooters', NULL, 60, 0, NULL, 1, '2025-09-21 16:53:07'),
(4, 'Van', 'Vans and mini buses', NULL, 60, 0, NULL, 1, '2025-09-21 16:53:07'),
(5, 'Bicycle', 'Bicycles', NULL, 60, 0, NULL, 1, '2025-09-21 16:53:07'),
(6, 'Other', 'Other vehicle types', NULL, 60, 0, NULL, 1, '2025-09-21 16:53:07');

-- --------------------------------------------------------

--
-- Table structure for table `visitors`
--

CREATE TABLE `visitors` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `company` varchar(100) DEFAULT NULL,
  `vehicle_number` varchar(20) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `is_pre_registered` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive','blocked') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `visitors`
--

INSERT INTO `visitors` (`id`, `visitor_id`, `full_name`, `phone`, `email`, `id_number`, `company`, `vehicle_number`, `photo_path`, `qr_code`, `is_pre_registered`, `status`, `created_at`, `updated_at`) VALUES
(1, 'VIS68D05F4001FAC', 'Dennis Mwangi', '+254758256440', 'mwangidennis546@gmail.com', '123456789', 'Zurihub', 'KDQ 123J', 'uploads/photos/visitors/VIS68D05F4001FAC_1758511383.png', '22f7f2b15367991d7820a2cdc7018b913a073a4e1eaf5ae6f213ca76d5e49c01', 0, 'active', '2025-09-21 20:25:36', '2025-09-22 03:23:03'),
(2, 'VIS68D07AA8B9638', 'Dylan Mwangi', '+254710896330', 'info@zurihub.co.ke', '1234567890123', 'Zurihub', 'KDS 442T', 'uploads/photos/visitors/VIS68D07AA8B9638_1758510513.png', '35ab8c6c703b4d6c381f77df15a0d7c52a4c5857ec7d5d8d07701bcac908bbbc', 0, 'active', '2025-09-21 22:22:32', '2025-09-22 03:08:33');

-- --------------------------------------------------------

--
-- Table structure for table `visitor_photos`
--

CREATE TABLE `visitor_photos` (
  `id` int NOT NULL,
  `visitor_id` varchar(20) NOT NULL,
  `photo_path` varchar(255) NOT NULL,
  `file_size` int DEFAULT NULL,
  `mime_type` varchar(50) DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `visitor_photos`
--

INSERT INTO `visitor_photos` (`id`, `visitor_id`, `photo_path`, `file_size`, `mime_type`, `uploaded_by`, `upload_date`, `is_active`) VALUES
(5, 'VIS68D07AA8B9638', 'uploads/photos/visitors/VIS68D07AA8B9638_1758510513.png', 71999, 'image/png', 1, '2025-09-22 03:08:33', 1),
(6, 'VIS68D05F4001FAC', 'uploads/photos/visitors/VIS68D05F4001FAC_1758511383.png', 156541, 'image/png', 1, '2025-09-22 03:23:03', 1);

-- --------------------------------------------------------

--
-- Stand-in structure for view `visitor_status_by_location`
-- (See below for the actual view)
--
CREATE TABLE `visitor_status_by_location` (
`company` varchar(100)
,`current_status` varchar(13)
,`full_name` varchar(100)
,`last_activity` timestamp
,`last_gate` varchar(100)
,`last_operator` varchar(100)
,`location_id` int
,`location_name` varchar(100)
,`phone` varchar(20)
,`vehicle_number` varchar(20)
,`visitor_id` varchar(20)
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `idx_activity_logs_type_time` (`activity_type`,`created_at`);

--
-- Indexes for table `card_print_logs`
--
ALTER TABLE `card_print_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `template_id` (`template_id`),
  ADD KEY `printed_by` (`printed_by`);

--
-- Indexes for table `card_templates`
--
ALTER TABLE `card_templates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_card_templates_default` (`is_default`,`is_active`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `company_name` (`company_name`),
  ADD KEY `idx_companies_active` (`is_active`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `delivery_id` (`delivery_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `updated_by` (`updated_by`),
  ADD KEY `idx_delivery_status` (`status`),
  ADD KEY `idx_delivery_schedule` (`scheduled_time`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `department_name` (`department_name`);

--
-- Indexes for table `gates`
--
ALTER TABLE `gates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `gate_code` (`gate_code`),
  ADD KEY `location_id` (`location_id`);

--
-- Indexes for table `gate_logs`
--
ALTER TABLE `gate_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_visitor_id` (`visitor_id`),
  ADD KEY `idx_log_timestamp` (`log_timestamp`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `idx_gate_logs_visitor_timestamp` (`visitor_id`,`log_timestamp`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `gate_id` (`gate_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `idx_entry_type` (`entry_type`),
  ADD KEY `idx_gate_logs_location_timestamp` (`location_id`,`log_timestamp`),
  ADD KEY `idx_gate_logs_entry_type_timestamp` (`entry_type`,`log_timestamp`),
  ADD KEY `idx_gate_logs_visitor_vehicle` (`visitor_id`,`vehicle_id`);

--
-- Indexes for table `gate_operators`
--
ALTER TABLE `gate_operators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `operator_code` (`operator_code`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `location_code` (`location_code`),
  ADD UNIQUE KEY `location_name` (`location_name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `operator_id` (`operator_id`);

--
-- Indexes for table `operator_locations`
--
ALTER TABLE `operator_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `operator_location` (`operator_id`,`location_id`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `operator_sessions`
--
ALTER TABLE `operator_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `operator_id` (`operator_id`);

--
-- Indexes for table `pre_registrations`
--
ALTER TABLE `pre_registrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_visit_date` (`visit_date`),
  ADD KEY `created_by_operator` (`created_by_operator`),
  ADD KEY `approved_by_operator` (`approved_by_operator`),
  ADD KEY `idx_pre_reg_status_date` (`status`,`visit_date`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_location` (`setting_key`,`location_id`),
  ADD KEY `location_id` (`location_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `vehicle_id` (`vehicle_id`),
  ADD UNIQUE KEY `license_plate` (`license_plate`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `vehicle_type_id` (`vehicle_type_id`),
  ADD KEY `idx_vehicles_license` (`license_plate`),
  ADD KEY `idx_vehicles_owner` (`owner_name`),
  ADD KEY `idx_vehicles_status` (`status`),
  ADD KEY `idx_vehicles_status_type` (`status`,`vehicle_type_id`);

--
-- Indexes for table `vehicle_logs`
--
ALTER TABLE `vehicle_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `gate_id` (`gate_id`),
  ADD KEY `operator_id` (`operator_id`),
  ADD KEY `idx_vehicle_logs_timestamp` (`log_timestamp`),
  ADD KEY `idx_vehicle_purpose` (`entry_purpose`),
  ADD KEY `idx_vehicle_logs_location_timestamp` (`location_id`,`log_timestamp`);

--
-- Indexes for table `vehicle_types`
--
ALTER TABLE `vehicle_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type_name` (`type_name`);

--
-- Indexes for table `visitors`
--
ALTER TABLE `visitors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `visitor_id` (`visitor_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `idx_visitors_qr_code` (`qr_code`),
  ADD KEY `idx_visitors_phone` (`phone`),
  ADD KEY `idx_visitors_company` (`company`),
  ADD KEY `idx_visitors_photo` (`photo_path`);

--
-- Indexes for table `visitor_photos`
--
ALTER TABLE `visitor_photos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visitor_id` (`visitor_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=80;

--
-- AUTO_INCREMENT for table `card_print_logs`
--
ALTER TABLE `card_print_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `card_templates`
--
ALTER TABLE `card_templates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `gates`
--
ALTER TABLE `gates`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `gate_logs`
--
ALTER TABLE `gate_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `gate_operators`
--
ALTER TABLE `gate_operators`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `operator_locations`
--
ALTER TABLE `operator_locations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `operator_sessions`
--
ALTER TABLE `operator_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `pre_registrations`
--
ALTER TABLE `pre_registrations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `vehicle_logs`
--
ALTER TABLE `vehicle_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `vehicle_types`
--
ALTER TABLE `vehicle_types`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `visitors`
--
ALTER TABLE `visitors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `visitor_photos`
--
ALTER TABLE `visitor_photos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

-- --------------------------------------------------------

--
-- Structure for view `daily_statistics_by_location`
--
DROP TABLE IF EXISTS `daily_statistics_by_location`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `daily_statistics_by_location`  AS SELECT cast(`gl`.`log_timestamp` as date) AS `log_date`, `l`.`location_name` AS `location_name`, `l`.`id` AS `location_id`, count((case when ((`gl`.`log_type` = 'check_in') and (`gl`.`entry_type` = 'visitor')) then 1 end)) AS `visitor_check_ins`, count((case when ((`gl`.`log_type` = 'check_out') and (`gl`.`entry_type` = 'visitor')) then 1 end)) AS `visitor_check_outs`, count((case when ((`gl`.`log_type` = 'check_in') and (`gl`.`entry_type` = 'vehicle')) then 1 end)) AS `vehicle_check_ins`, count((case when ((`gl`.`log_type` = 'check_out') and (`gl`.`entry_type` = 'vehicle')) then 1 end)) AS `vehicle_check_outs`, count((case when ((`gl`.`log_type` = 'check_in') and (`gl`.`entry_type` = 'delivery')) then 1 end)) AS `delivery_arrivals`, count((case when ((`gl`.`log_type` = 'check_out') and (`gl`.`entry_type` = 'delivery')) then 1 end)) AS `delivery_departures`, count(distinct `gl`.`visitor_id`) AS `unique_visitors`, count(distinct `gl`.`vehicle_id`) AS `unique_vehicles` FROM (`gate_logs` `gl` join `locations` `l` on((`gl`.`location_id` = `l`.`id`))) GROUP BY cast(`gl`.`log_timestamp` as date), `l`.`id` ORDER BY `log_date` DESC, `l`.`location_name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `vehicle_status_by_location`
--
DROP TABLE IF EXISTS `vehicle_status_by_location`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `vehicle_status_by_location`  AS SELECT `veh`.`vehicle_id` AS `vehicle_id`, `veh`.`license_plate` AS `license_plate`, `veh`.`make` AS `make`, `veh`.`model` AS `model`, `veh`.`owner_name` AS `owner_name`, `veh`.`owner_company` AS `owner_company`, `l`.`location_name` AS `location_name`, `l`.`id` AS `location_id`, (case when (`latest_log`.`log_type` = 'check_in') then 'Inside' when (`latest_log`.`log_type` = 'check_out') then 'Outside' else 'Never Visited' end) AS `current_status`, `latest_log`.`entry_purpose` AS `entry_purpose`, `latest_log`.`log_timestamp` AS `last_activity`, `latest_log`.`operator_name` AS `last_operator`, `latest_log`.`gate_name` AS `last_gate` FROM ((`vehicles` `veh` left join (select `vl`.`vehicle_id` AS `vehicle_id`,`vl`.`log_type` AS `log_type`,`vl`.`log_timestamp` AS `log_timestamp`,`vl`.`location_id` AS `location_id`,`vl`.`entry_purpose` AS `entry_purpose`,`go`.`operator_name` AS `operator_name`,`g`.`gate_name` AS `gate_name`,row_number() OVER (PARTITION BY `vl`.`vehicle_id` ORDER BY `vl`.`log_timestamp` desc )  AS `rn` from ((`vehicle_logs` `vl` join `gate_operators` `go` on((`vl`.`operator_id` = `go`.`id`))) left join `gates` `g` on((`vl`.`gate_id` = `g`.`id`)))) `latest_log` on(((`veh`.`vehicle_id` = `latest_log`.`vehicle_id`) and (`latest_log`.`rn` = 1)))) left join `locations` `l` on((`latest_log`.`location_id` = `l`.`id`))) ;

-- --------------------------------------------------------

--
-- Structure for view `visitor_status_by_location`
--
DROP TABLE IF EXISTS `visitor_status_by_location`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `visitor_status_by_location`  AS SELECT `v`.`visitor_id` AS `visitor_id`, `v`.`full_name` AS `full_name`, `v`.`phone` AS `phone`, `v`.`vehicle_number` AS `vehicle_number`, `v`.`company` AS `company`, `l`.`location_name` AS `location_name`, `l`.`id` AS `location_id`, (case when (`latest_log`.`log_type` = 'check_in') then 'Inside' when (`latest_log`.`log_type` = 'check_out') then 'Outside' else 'Never Visited' end) AS `current_status`, `latest_log`.`log_timestamp` AS `last_activity`, `latest_log`.`operator_name` AS `last_operator`, `latest_log`.`gate_name` AS `last_gate` FROM ((`visitors` `v` left join (select `gl`.`visitor_id` AS `visitor_id`,`gl`.`log_type` AS `log_type`,`gl`.`log_timestamp` AS `log_timestamp`,`gl`.`location_id` AS `location_id`,`go`.`operator_name` AS `operator_name`,`g`.`gate_name` AS `gate_name`,row_number() OVER (PARTITION BY `gl`.`visitor_id` ORDER BY `gl`.`log_timestamp` desc )  AS `rn` from ((`gate_logs` `gl` join `gate_operators` `go` on((`gl`.`operator_id` = `go`.`id`))) left join `gates` `g` on((`gl`.`gate_id` = `g`.`id`)))) `latest_log` on(((`v`.`visitor_id` = `latest_log`.`visitor_id`) and (`latest_log`.`rn` = 1)))) left join `locations` `l` on((`latest_log`.`location_id` = `l`.`id`))) ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `card_print_logs`
--
ALTER TABLE `card_print_logs`
  ADD CONSTRAINT `card_print_logs_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `card_print_logs_ibfk_2` FOREIGN KEY (`template_id`) REFERENCES `card_templates` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `card_print_logs_ibfk_3` FOREIGN KEY (`printed_by`) REFERENCES `gate_operators` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `deliveries_ibfk_2` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `deliveries_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `deliveries_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `deliveries_ibfk_5` FOREIGN KEY (`updated_by`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `gates`
--
ALTER TABLE `gates`
  ADD CONSTRAINT `gates_ibfk_1` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `gate_logs`
--
ALTER TABLE `gate_logs`
  ADD CONSTRAINT `gate_logs_gate_fk` FOREIGN KEY (`gate_id`) REFERENCES `gates` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `gate_logs_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `gate_logs_ibfk_2` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `gate_logs_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `gate_logs_vehicle_fk` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `operator_locations`
--
ALTER TABLE `operator_locations`
  ADD CONSTRAINT `operator_locations_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `operator_locations_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `operator_locations_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `operator_sessions`
--
ALTER TABLE `operator_sessions`
  ADD CONSTRAINT `operator_sessions_ibfk_1` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pre_registrations`
--
ALTER TABLE `pre_registrations`
  ADD CONSTRAINT `pre_registrations_ibfk_1` FOREIGN KEY (`created_by_operator`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pre_registrations_ibfk_2` FOREIGN KEY (`approved_by_operator`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `settings_location_fk` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`vehicle_type_id`) REFERENCES `vehicle_types` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_logs`
--
ALTER TABLE `vehicle_logs`
  ADD CONSTRAINT `vehicle_logs_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `vehicle_logs_ibfk_2` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `vehicle_logs_ibfk_3` FOREIGN KEY (`gate_id`) REFERENCES `gates` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `vehicle_logs_ibfk_4` FOREIGN KEY (`operator_id`) REFERENCES `gate_operators` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `visitor_photos`
--
ALTER TABLE `visitor_photos`
  ADD CONSTRAINT `visitor_photos_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `visitor_photos_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `gate_operators` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
