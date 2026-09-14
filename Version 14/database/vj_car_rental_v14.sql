-- VJ Car Rental Version 14 — Unified Refund & Security Deposit Settlement database schema and safe reference data
-- Import this file once with phpMyAdmin. Version 14 preserves Version 13 reference data; refunds, settlements, modifications, cancellations, drafts, and operational customer data remain empty.

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: vj_car_rental_v14
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `vj_car_rental_v14`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `vj_car_rental_v14` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `vj_car_rental_v14`;

--
-- Table structure for table `addons`
--

DROP TABLE IF EXISTS `addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `addons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `addon_key` varchar(80) NOT NULL,
  `name` varchar(140) NOT NULL,
  `price` int(11) NOT NULL,
  `billing` varchar(20) NOT NULL DEFAULT 'day',
  `icon` varchar(80) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `addon_key` (`addon_key`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(80) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `ip_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_addons`
--

DROP TABLE IF EXISTS `booking_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_addons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `addon_id` bigint(20) unsigned NOT NULL,
  `addon_name` varchar(140) NOT NULL,
  `unit_price` int(11) NOT NULL,
  `billing` varchar(20) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `line_total` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `addon_id` (`addon_id`),
  CONSTRAINT `booking_addons_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_addons_ibfk_2` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `vehicle_id` bigint(20) unsigned NOT NULL,
  `pickup_at` datetime NOT NULL,
  `return_at` datetime NOT NULL,
  `original_return_at` datetime DEFAULT NULL,
  `pickup_method` varchar(40) NOT NULL,
  `pickup_location` varchar(140) NOT NULL,
  `delivery_address` varchar(255) DEFAULT '',
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `promo_code` varchar(40) DEFAULT '',
  `subtotal` int(11) NOT NULL,
  `addons_total` int(11) NOT NULL DEFAULT 0,
  `delivery_fee` int(11) NOT NULL DEFAULT 0,
  `discount` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL,
  `deposit` int(11) NOT NULL,
  `special_requests` longtext DEFAULT NULL,
  `admin_notes` longtext DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `ready_at` datetime DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `no_show_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `idx_bookings_vehicle_dates` (`vehicle_id`,`pickup_at`,`return_at`,`status`),
  KEY `idx_bookings_user` (`user_id`,`created_at`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_modification_requests`
--

DROP TABLE IF EXISTS `booking_modification_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_modification_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `request_type` varchar(30) NOT NULL DEFAULT 'pre_pickup',
  `original_data` longtext NOT NULL,
  `requested_data` longtext NOT NULL,
  `requested_vehicle_id` bigint(20) unsigned DEFAULT NULL,
  `price_difference` int(11) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `customer_reason` longtext DEFAULT NULL,
  `admin_note` longtext DEFAULT NULL,
  `requested_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_modification_booking_status` (`booking_id`,`status`),
  KEY `idx_booking_modification_user` (`user_id`,`requested_at`),
  KEY `requested_vehicle_id` (`requested_vehicle_id`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `booking_modification_requests_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_modification_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_modification_requests_ibfk_3` FOREIGN KEY (`requested_vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `booking_modification_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_cancellation_requests`
--

DROP TABLE IF EXISTS `booking_cancellation_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_cancellation_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `reason` longtext NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `refundable_amount` int(11) NOT NULL DEFAULT 0,
  `non_refundable_amount` int(11) NOT NULL DEFAULT 0,
  `admin_note` longtext DEFAULT NULL,
  `requested_at` datetime NOT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_booking_cancellation_booking_status` (`booking_id`,`status`),
  KEY `idx_booking_cancellation_user` (`user_id`,`requested_at`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `booking_cancellation_requests_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_cancellation_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_cancellation_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT '',
  `subject` varchar(180) NOT NULL,
  `message` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_documents`
--

DROP TABLE IF EXISTS `customer_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `document_type` varchar(40) NOT NULL,
  `document_number` varchar(120) DEFAULT '',
  `expiry_date` date DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `admin_notes` longtext DEFAULT NULL,
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`document_type`),
  KEY `verified_by` (`verified_by`),
  KEY `idx_documents_user_status` (`user_id`,`status`),
  CONSTRAINT `customer_documents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_documents_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `favorites`
--

DROP TABLE IF EXISTS `favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `favorites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `vehicle_id` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`vehicle_id`),
  KEY `vehicle_id` (`vehicle_id`),
  CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identity_hash` varchar(255) NOT NULL,
  `succeeded` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts` (`identity_hash`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `maintenance_records`
--

DROP TABLE IF EXISTS `maintenance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `maintenance_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `description` longtext NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'scheduled',
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `cost` int(11) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_maintenance_vehicle_dates` (`vehicle_id`,`starts_at`,`ends_at`,`status`),
  CONSTRAINT `maintenance_records_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `maintenance_records_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `maintenance_records_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'system',
  `title` varchar(160) NOT NULL,
  `message` longtext NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `idx_notifications_user_read` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rental_adjustment_requests`
--

DROP TABLE IF EXISTS `rental_adjustment_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_adjustment_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `request_type` varchar(30) NOT NULL,
  `original_return_at` datetime NOT NULL,
  `requested_return_at` datetime NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `price_difference` int(11) NOT NULL DEFAULT 0,
  `customer_reason` varchar(1500) DEFAULT '',
  `admin_note` varchar(1500) DEFAULT '',
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_adjustment_booking_status` (`booking_id`,`status`),
  KEY `idx_adjustment_user_status` (`user_id`,`status`,`created_at`),
  KEY `idx_adjustment_type_status` (`request_type`,`status`,`requested_return_at`),
  KEY `reviewed_by` (`reviewed_by`),
  CONSTRAINT `rental_adjustments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rental_adjustments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rental_adjustments_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rental_settlements`
--

DROP TABLE IF EXISTS `rental_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `deposit_paid` int(11) NOT NULL DEFAULT 0,
  `damage_charges` int(11) NOT NULL DEFAULT 0,
  `fuel_charges` int(11) NOT NULL DEFAULT 0,
  `late_charges` int(11) NOT NULL DEFAULT 0,
  `other_charges` int(11) NOT NULL DEFAULT 0,
  `total_deductions` int(11) NOT NULL DEFAULT 0,
  `deposit_refund_amount` int(11) NOT NULL DEFAULT 0,
  `outstanding_balance` int(11) NOT NULL DEFAULT 0,
  `status` varchar(30) NOT NULL DEFAULT 'finalized',
  `finalized_at` datetime NOT NULL,
  `finalized_by` bigint(20) unsigned NOT NULL,
  `notes` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_id` (`booking_id`),
  KEY `idx_settlements_status` (`status`,`finalized_at`),
  KEY `finalized_by` (`finalized_by`),
  CONSTRAINT `rental_settlements_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rental_settlements_ibfk_2` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `rental_adjustment_id` bigint(20) unsigned DEFAULT NULL,
  `booking_modification_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `payment_type` varchar(30) NOT NULL,
  `method` varchar(40) NOT NULL,
  `amount` int(11) NOT NULL,
  `transaction_reference` varchar(120) DEFAULT '',
  `proof_filename` varchar(255) DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `notes` longtext DEFAULT NULL,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `recorded_by` (`recorded_by`),
  KEY `idx_payments_booking_status` (`booking_id`,`status`),
  KEY `idx_payments_adjustment` (`rental_adjustment_id`,`status`),
  KEY `idx_payments_modification` (`booking_modification_id`,`status`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_adjustment` FOREIGN KEY (`rental_adjustment_id`) REFERENCES `rental_adjustment_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_modification` FOREIGN KEY (`booking_modification_id`) REFERENCES `booking_modification_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_refunds`
--

DROP TABLE IF EXISTS `payment_refunds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_refunds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `booking_id` bigint(20) unsigned NOT NULL,
  `refund_type` varchar(30) NOT NULL DEFAULT 'payment_correction',
  `cancellation_request_id` bigint(20) unsigned DEFAULT NULL,
  `booking_modification_id` bigint(20) unsigned DEFAULT NULL,
  `rental_settlement_id` bigint(20) unsigned DEFAULT NULL,
  `amount` int(11) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `reason` longtext NOT NULL,
  `requested_at` datetime NOT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `processed_at` datetime DEFAULT NULL,
  `reference_number` varchar(120) DEFAULT '',
  `customer_note` longtext DEFAULT NULL,
  `admin_note` longtext DEFAULT NULL,
  `notes` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_refunds_booking_status` (`booking_id`,`status`),
  KEY `idx_refunds_payment_status` (`payment_id`,`status`),
  KEY `idx_refunds_type_status` (`refund_type`,`status`),
  KEY `cancellation_request_id` (`cancellation_request_id`),
  KEY `booking_modification_id` (`booking_modification_id`),
  KEY `rental_settlement_id` (`rental_settlement_id`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `payment_refunds_ibfk_1` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_refunds_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_refunds_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_refunds_ibfk_4` FOREIGN KEY (`cancellation_request_id`) REFERENCES `booking_cancellation_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_refunds_ibfk_5` FOREIGN KEY (`booking_modification_id`) REFERENCES `booking_modification_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_refunds_ibfk_6` FOREIGN KEY (`rental_settlement_id`) REFERENCES `rental_settlements` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `promos`
--

DROP TABLE IF EXISTS `promos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `description` varchar(255) NOT NULL,
  `discount_type` varchar(20) NOT NULL,
  `discount_value` int(11) NOT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `max_uses` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rental_inspections`
--

DROP TABLE IF EXISTS `rental_inspections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rental_inspections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `inspection_type` varchar(20) NOT NULL,
  `odometer` int(11) NOT NULL DEFAULT 0,
  `fuel_percent` int(11) NOT NULL DEFAULT 100,
  `body_condition` varchar(40) NOT NULL DEFAULT 'good',
  `notes` longtext DEFAULT NULL,
  `damage_notes` longtext DEFAULT NULL,
  `extra_charges` int(11) NOT NULL DEFAULT 0,
  `recorded_by` bigint(20) unsigned NOT NULL,
  `inspected_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_id` (`booking_id`,`inspection_type`),
  KEY `recorded_by` (`recorded_by`),
  CONSTRAINT `rental_inspections_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rental_inspections_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `review_photos`
--

DROP TABLE IF EXISTS `review_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `review_photos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `review_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `review_id` (`review_id`),
  CONSTRAINT `review_photos_ibfk_1` FOREIGN KEY (`review_id`) REFERENCES `reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reviews`
--

DROP TABLE IF EXISTS `reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `vehicle_id` bigint(20) unsigned NOT NULL,
  `overall` int(11) NOT NULL,
  `cleanliness` int(11) NOT NULL,
  `comfort` int(11) NOT NULL,
  `vehicle_condition` int(11) NOT NULL,
  `pickup_experience` int(11) NOT NULL,
  `customer_support` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_id` (`booking_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_reviews_vehicle` (`vehicle_id`,`status`),
  CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`),
  CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `label` varchar(80) NOT NULL,
  `description` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT '',
  `password_hash` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `vehicles`
--

DROP TABLE IF EXISTS `vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(140) NOT NULL,
  `name` varchar(180) NOT NULL,
  `brand` varchar(100) NOT NULL,
  `category` varchar(40) NOT NULL,
  `image` varchar(255) NOT NULL,
  `price` int(11) NOT NULL,
  `deposit` int(11) NOT NULL DEFAULT 5000,
  `seats` int(11) NOT NULL,
  `doors` int(11) NOT NULL,
  `luggage` varchar(80) NOT NULL,
  `transmission` varchar(40) NOT NULL,
  `fuel` varchar(40) NOT NULL,
  `daily_km` int(11) NOT NULL DEFAULT 200,
  `overview_title` varchar(255) NOT NULL,
  `overview` longtext NOT NULL,
  `description` longtext NOT NULL,
  `inclusions` longtext NOT NULL,
  `features` longtext NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `availability_status` varchar(30) NOT NULL DEFAULT 'available',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_vehicles_category` (`category`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_drafts`
--

DROP TABLE IF EXISTS `booking_drafts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_drafts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `vehicle_id` bigint(20) unsigned NOT NULL,
  `pickup_at` datetime NOT NULL,
  `return_at` datetime NOT NULL,
  `pickup_method` varchar(40) NOT NULL,
  `pickup_location` varchar(140) NOT NULL,
  `delivery_address` varchar(255) DEFAULT '',
  `promo_code` varchar(40) DEFAULT '',
  `estimated_subtotal` int(11) NOT NULL DEFAULT 0,
  `estimated_addons_total` int(11) NOT NULL DEFAULT 0,
  `estimated_delivery_fee` int(11) NOT NULL DEFAULT 0,
  `estimated_discount` int(11) NOT NULL DEFAULT 0,
  `estimated_total` int(11) NOT NULL DEFAULT 0,
  `estimated_deposit` int(11) NOT NULL DEFAULT 0,
  `special_requests` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `expires_at` datetime NOT NULL,
  `last_saved_at` datetime NOT NULL,
  `converted_booking_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `idx_booking_drafts_user_status` (`user_id`,`status`,`updated_at`),
  KEY `idx_booking_drafts_vehicle` (`vehicle_id`),
  KEY `idx_booking_drafts_expires` (`status`,`expires_at`),
  KEY `converted_booking_id` (`converted_booking_id`),
  CONSTRAINT `booking_drafts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_drafts_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `booking_drafts_ibfk_3` FOREIGN KEY (`converted_booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_draft_addons`
--

DROP TABLE IF EXISTS `booking_draft_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_draft_addons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draft_id` bigint(20) unsigned NOT NULL,
  `addon_id` bigint(20) unsigned NOT NULL,
  `addon_name` varchar(140) NOT NULL,
  `unit_price` int(11) NOT NULL,
  `billing` varchar(20) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `line_total` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `draft_addon_unique` (`draft_id`,`addon_id`),
  KEY `addon_id` (`addon_id`),
  CONSTRAINT `booking_draft_addons_ibfk_1` FOREIGN KEY (`draft_id`) REFERENCES `booking_drafts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_draft_addons_ibfk_2` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'vj_car_rental_v14'
--

--
-- Dumping routines for database 'vj_car_rental_v14'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-09  7:15:23


-- Safe reference data: roles, vehicles, rental add-ons, and promotions only.

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: vj_car_rental_v14
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','Administrator','Full access to management, reporting, and account administration.','2026-09-01 11:31:54','2026-09-01 11:31:54'),(2,'customer','Customer','Customer access to reservations, favorites, profile, and verified reviews.','2026-09-01 11:31:54','2026-09-01 11:31:54');

-- Development administrator inherited from Version 8. Change this password after first sign-in.
INSERT INTO `users` (`id`,`role_id`,`name`,`email`,`phone`,`password_hash`,`status`,`last_login_at`,`created_at`,`updated_at`) VALUES
(1,1,'VJ Administrator','admin@vjcarrental.local','','$2y$12$jMyhHWmEKvlSNTEoDbF5Zuu/Rr7fNCZCg7BuOy3q.GY2oZh9mA.Uq','active',NULL,'2026-09-11 00:00:00','2026-09-11 00:00:00');

/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `vehicles`
--

LOCK TABLES `vehicles` WRITE;
/*!40000 ALTER TABLE `vehicles` DISABLE KEYS */;
INSERT INTO `vehicles` VALUES 
(1,'porsche-911','Porsche 911 Carrera S','Porsche','Luxury','porsche-911.png',35000,15000,4,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Porsche 911 Carrera S is a 4-seat luxury selected for milestone celebrations and premium city drives. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Porsche 911 Carrera S when your trip calls for milestone celebrations and premium city drives. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 21:37:04'),(2,'mclaren-720s','McLaren 720S','McLaren','Luxury','mclaren-720s.png',27000,15000,2,2,'1 bag','Automatic','Gasoline',150,'A premium choice with memorable presence','The McLaren 720S is a 2-seat luxury selected for two-person performance getaways. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the McLaren 720S when your trip calls for two-person performance getaways. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"2-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(3,'rolls-royce-ghost','Rolls-Royce Ghost','Rolls-Royce','Luxury','rolls-royce-ghost.png',25000,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Rolls-Royce Ghost is a 5-seat luxury selected for executive transfers and formal occasions. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Rolls-Royce Ghost when your trip calls for executive transfers and formal occasions. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(4,'lamborghini-urus','Lamborghini Urus','Lamborghini','Luxury','lamborghini-urus.png',20000,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Lamborghini Urus is a 5-seat luxury selected for high-impact arrivals with SUV practicality. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Lamborghini Urus when your trip calls for high-impact arrivals with SUV practicality. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(5,'ferrari-roma','Ferrari Roma','Ferrari','Luxury','ferrari-roma.png',18000,15000,4,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Ferrari Roma is a 4-seat luxury selected for stylish date nights and weekend escapes. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Ferrari Roma when your trip calls for stylish date nights and weekend escapes. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(6,'ford-everest','Ford Everest','Ford','SUV','ford-everest.png',6500,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Ford Everest is a 7-seat suv selected for family road trips and out-of-town itineraries. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Ford Everest when your trip calls for family road trips and out-of-town itineraries. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(7,'nissan-terra','Nissan Terra','Nissan','SUV','nissan-terra.png',5500,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Nissan Terra is a 7-seat suv selected for seven-seat travel with dependable diesel range. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Nissan Terra when your trip calls for seven-seat travel with dependable diesel range. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(8,'mitsubishi-montero','Mitsubishi Montero Sport','Mitsubishi','SUV','mitsubishi-montero.png',5800,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Mitsubishi Montero Sport is a 7-seat suv selected for comfortable group trips across mixed city and highway routes. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mitsubishi Montero Sport when your trip calls for comfortable group trips across mixed city and highway routes. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(9,'toyota-fortuner','Toyota Fortuner','Toyota','SUV','toyota-fortuner.png',6200,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Toyota Fortuner is a 7-seat suv selected for family holidays with flexible seating. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Fortuner when your trip calls for family holidays with flexible seating. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(10,'toyota-prado','Toyota Land Cruiser Prado','Toyota','SUV','toyota-prado.png',8500,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Toyota Land Cruiser Prado is a 7-seat suv selected for premium provincial drives and important family occasions. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Land Cruiser Prado when your trip calls for premium provincial drives and important family occasions. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(11,'nissan-navara','Nissan Navara','Nissan','Pickup','nissan-navara.png',3500,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Nissan Navara is a 5-seat pickup selected for daily utility trips with five-seat comfort. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Nissan Navara when your trip calls for daily utility trips with five-seat comfort. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(12,'ford-ranger-raptor','Ford Ranger Raptor','Ford','Pickup','ford-ranger-raptor.png',5500,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Ford Ranger Raptor is a 5-seat pickup selected for adventure weekends and premium pickup capability. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Ford Ranger Raptor when your trip calls for adventure weekends and premium pickup capability. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(13,'isuzu-dmax','Isuzu D-Max','Isuzu','Pickup','isuzu-dmax.png',3400,5000,5,4,'Cargo bed','Manual','Diesel',200,'Practical capability for work and weekends','The Isuzu D-Max is a 5-seat pickup selected for practical hauling with driver-controlled manual transmission. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Isuzu D-Max when your trip calls for practical hauling with driver-controlled manual transmission. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(14,'toyota-hilux','Toyota Hilux','Toyota','Pickup','toyota-hilux.png',4500,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Toyota Hilux is a 5-seat pickup selected for work-and-leisure trips requiring a versatile bed. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Hilux when your trip calls for work-and-leisure trips requiring a versatile bed. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(15,'toyota-hiace','Toyota Hiace GL Grandia','Toyota','Van','toyota-hiace.png',5500,5000,10,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Toyota Hiace GL Grandia is a 10-seat van selected for comfortable ten-seat family and business transport. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Hiace GL Grandia when your trip calls for comfortable ten-seat family and business transport. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"10-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(16,'tesla-model-3','Tesla Model 3','Tesla','EV','tesla-model-3.png',6500,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Tesla Model 3 is a 5-seat ev selected for quiet city travel and technology-focused daily driving. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Tesla Model 3 when your trip calls for quiet city travel and technology-focused daily driving. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(17,'mercedes-s-class','Mercedes-Benz S-Class','Mercedes-Benz','Luxury','mercedes-s-class.png',22000,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Mercedes-Benz S-Class is a 5-seat luxury selected for business travel with first-class comfort. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mercedes-Benz S-Class when your trip calls for business travel with first-class comfort. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(18,'bmw-7-series','BMW 7 Series','BMW','Luxury','bmw-7-series.png',21000,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The BMW 7 Series is a 5-seat luxury selected for executive itineraries with generous rear-seat space. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the BMW 7 Series when your trip calls for executive itineraries with generous rear-seat space. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(19,'audi-a8','Audi A8','Audi','Luxury','audi-a8.png',19500,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Audi A8 is a 5-seat luxury selected for quiet corporate travel and formal events. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Audi A8 when your trip calls for quiet corporate travel and formal events. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(20,'lexus-ls-500','Lexus LS 500','Lexus','Luxury','lexus-ls-500.png',18000,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Lexus LS 500 is a 5-seat luxury selected for refined long-distance drives with calm cabin comfort. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Lexus LS 500 when your trip calls for refined long-distance drives with calm cabin comfort. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(21,'bentley-continental-gt','Bentley Continental GT','Bentley','Luxury','bentley-continental-gt.png',32000,15000,4,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Bentley Continental GT is a 4-seat luxury selected for grand touring and special celebrations. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Bentley Continental GT when your trip calls for grand touring and special celebrations. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(22,'maserati-quattroporte','Maserati Quattroporte','Maserati','Luxury','maserati-quattroporte.png',23000,15000,5,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Maserati Quattroporte is a 5-seat luxury selected for luxury group arrivals with dramatic road presence. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Maserati Quattroporte when your trip calls for luxury group arrivals with dramatic road presence. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(23,'porsche-panamera','Porsche Panamera','Porsche','Luxury','porsche-panamera.png',26000,15000,4,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The Porsche Panamera is a 4-seat luxury selected for chauffeur-style executive schedules. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Porsche Panamera when your trip calls for chauffeur-style executive schedules. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(24,'jaguar-f-type','Jaguar F-Type','Jaguar','Luxury','jaguar-f-type.png',20500,15000,2,2,'1 bag','Automatic','Gasoline',150,'A premium choice with memorable presence','The Jaguar F-Type is a 2-seat luxury selected for sporty four-seat touring and premium weekends. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Jaguar F-Type when your trip calls for sporty four-seat touring and premium weekends. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"2-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(25,'bmw-m4-competition','BMW M4 Competition','BMW','Luxury','bmw-m4-competition.png',24000,15000,4,4,'3 bags','Automatic','Gasoline',150,'A premium choice with memorable presence','The BMW M4 Competition is a 4-seat luxury selected for focused two-seat coastal drives. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the BMW M4 Competition when your trip calls for focused two-seat coastal drives. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(26,'mercedes-amg-gt','Mercedes-AMG GT','Mercedes-Benz','Luxury','mercedes-amg-gt.png',28500,15000,2,2,'1 bag','Automatic','Gasoline',150,'A premium choice with memorable presence','The Mercedes-AMG GT is a 2-seat luxury selected for performance-oriented celebrations and photo-ready arrivals. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mercedes-AMG GT when your trip calls for performance-oriented celebrations and photo-ready arrivals. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"150 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Premium vehicle orientation\",\"Priority reservation support\"]','[\"2-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(27,'toyota-land-cruiser-300','Toyota Land Cruiser 300','Toyota','SUV','toyota-land-cruiser-300.png',9800,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Toyota Land Cruiser 300 is a 7-seat suv selected for large-group travel with eight-seat capacity. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Land Cruiser 300 when your trip calls for large-group travel with eight-seat capacity. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(28,'nissan-patrol','Nissan Patrol','Nissan','SUV','nissan-patrol.png',9500,5000,8,4,'4 bags','Automatic','Gasoline',200,'Flexible space for confident group travel','The Nissan Patrol is a 8-seat suv selected for daily city use with crossover convenience. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Nissan Patrol when your trip calls for daily city use with crossover convenience. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"8-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(29,'ford-territory','Ford Territory','Ford','SUV','ford-territory.png',4800,5000,5,4,'3 bags','Automatic','Gasoline',200,'Flexible space for confident group travel','The Ford Territory is a 5-seat suv selected for eight-seat family transport and airport runs. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Ford Territory when your trip calls for eight-seat family transport and airport runs. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(30,'hyundai-palisade','Hyundai Palisade','Hyundai','SUV','hyundai-palisade.png',7200,5000,8,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Hyundai Palisade is a 8-seat suv selected for balanced seven-seat touring and weekend travel. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Hyundai Palisade when your trip calls for balanced seven-seat touring and weekend travel. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"8-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(31,'kia-sorento','Kia Sorento','Kia','SUV','kia-sorento.png',6100,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Kia Sorento is a 7-seat suv selected for comfortable family drives with practical access. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Kia Sorento when your trip calls for comfortable family drives with practical access. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(32,'honda-cr-v','Honda CR-V','Honda','SUV','honda-cr-v.png',5200,5000,7,4,'4 bags','Automatic','Gasoline',200,'Flexible space for confident group travel','The Honda CR-V is a 7-seat suv selected for smooth group touring with generous cabin space. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Honda CR-V when your trip calls for smooth group touring with generous cabin space. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(33,'mazda-cx-9','Mazda CX-9','Mazda','SUV','mazda-cx-9.png',6700,5000,7,4,'4 bags','Automatic','Gasoline',200,'Flexible space for confident group travel','The Mazda CX-9 is a 7-seat suv selected for five-seat adventure trips and all-weather confidence. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mazda CX-9 when your trip calls for five-seat adventure trips and all-weather confidence. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(34,'subaru-forester','Subaru Forester','Subaru','SUV','subaru-forester.png',5000,5000,5,4,'3 bags','Automatic','Gasoline',200,'Flexible space for confident group travel','The Subaru Forester is a 5-seat suv selected for seven-seat travel with a durable diesel setup. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Subaru Forester when your trip calls for seven-seat travel with a durable diesel setup. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(35,'chevrolet-trailblazer','Chevrolet Trailblazer','Chevrolet','SUV','chevrolet-trailblazer.png',5700,5000,7,4,'4 bags','Automatic','Diesel',200,'Flexible space for confident group travel','The Chevrolet Trailblazer is a 7-seat suv selected for compact adventures and easy urban parking. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Chevrolet Trailblazer when your trip calls for compact adventures and easy urban parking. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(36,'suzuki-jimny','Suzuki Jimny','Suzuki','SUV','suzuki-jimny.png',3900,5000,4,4,'3 bags','Automatic','Gasoline',200,'Flexible space for confident group travel','The Suzuki Jimny is a 4-seat suv selected for business and family trips that need dependable cargo room. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Suzuki Jimny when your trip calls for business and family trips that need dependable cargo room. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Emergency roadside kit\",\"Flexible passenger configuration\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(37,'mitsubishi-triton','Mitsubishi Triton','Mitsubishi','Pickup','mitsubishi-triton.png',4300,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Mitsubishi Triton is a 5-seat pickup selected for modern double-cab travel with balanced comfort. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mitsubishi Triton when your trip calls for modern double-cab travel with balanced comfort. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(38,'mazda-bt-50','Mazda BT-50','Mazda','Pickup','mazda-bt-50.png',4400,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Mazda BT-50 is a 5-seat pickup selected for business hauling and weekend recreation. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mazda BT-50 when your trip calls for business hauling and weekend recreation. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(39,'chevrolet-colorado','Chevrolet Colorado','Chevrolet','Pickup','chevrolet-colorado.png',4600,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Chevrolet Colorado is a 5-seat pickup selected for worksite support with refined road manners. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Chevrolet Colorado when your trip calls for worksite support with refined road manners. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(40,'nissan-frontier-pro-4x','Nissan Frontier Pro-4X','Nissan','Pickup','nissan-frontier-pro-4x.png',5200,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Nissan Frontier Pro-4X is a 5-seat pickup selected for outdoor travel and confident provincial routes. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Nissan Frontier Pro-4X when your trip calls for outdoor travel and confident provincial routes. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(41,'ford-ranger-wildtrak','Ford Ranger Wildtrak','Ford','Pickup','ford-ranger-wildtrak.png',4900,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Ford Ranger Wildtrak is a 5-seat pickup selected for premium utility trips with a well-equipped cabin. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Ford Ranger Wildtrak when your trip calls for premium utility trips with a well-equipped cabin. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(42,'toyota-hilux-gr-sport','Toyota Hilux GR Sport','Toyota','Pickup','toyota-hilux-gr-sport.png',5400,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Toyota Hilux GR Sport is a 5-seat pickup selected for sport-inspired pickup travel and weekend hauling. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Hilux GR Sport when your trip calls for sport-inspired pickup travel and weekend hauling. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(43,'isuzu-dmax-x-terrain','Isuzu D-Max X-Terrain','Isuzu','Pickup','isuzu-dmax-x-terrain.png',5000,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Isuzu D-Max X-Terrain is a 5-seat pickup selected for durable diesel assignments with modern convenience. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Isuzu D-Max X-Terrain when your trip calls for durable diesel assignments with modern convenience. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(44,'mitsubishi-strada-athlete','Mitsubishi Strada Athlete','Mitsubishi','Pickup','mitsubishi-strada-athlete.png',4700,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The Mitsubishi Strada Athlete is a 5-seat pickup selected for active-lifestyle hauling and daily driving. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mitsubishi Strada Athlete when your trip calls for active-lifestyle hauling and daily driving. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(45,'jac-t9','JAC T9','JAC','Pickup','jac-t9.png',3900,5000,5,4,'Cargo bed','Automatic','Diesel',200,'Practical capability for work and weekends','The JAC T9 is a 5-seat pickup selected for value-focused business transport. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the JAC T9 when your trip calls for value-focused business transport. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(46,'foton-thunder','Foton Thunder','Foton','Pickup','foton-thunder.png',3600,5000,5,4,'Cargo bed','Manual','Diesel',200,'Practical capability for work and weekends','The Foton Thunder is a 5-seat pickup selected for straightforward manual utility work. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Foton Thunder when your trip calls for straightforward manual utility work. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(47,'ram-1500','RAM 1500','RAM','Pickup','ram-1500.png',7200,5000,5,4,'Cargo bed','Automatic','Gasoline',200,'Practical capability for work and weekends','The RAM 1500 is a 5-seat pickup selected for large-cabin pickup comfort and substantial road presence. Its automatic transmission and gasoline powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the RAM 1500 when your trip calls for large-cabin pickup comfort and substantial road presence. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Cargo-bed inspection\",\"Tie-down point orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(48,'hyundai-staria','Hyundai Staria','Hyundai','Van','hyundai-staria.png',6800,5000,11,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Hyundai Staria is a 11-seat van selected for airport transfers and premium group travel. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Hyundai Staria when your trip calls for airport transfers and premium group travel. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"11-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(49,'toyota-hiace-commuter','Toyota Hiace Commuter','Toyota','Van','toyota-hiace-commuter.png',5900,5000,15,5,'4 bags','Manual','Diesel',250,'Passenger-focused comfort for bigger journeys','The Toyota Hiace Commuter is a 15-seat van selected for large-group shuttle schedules with fifteen-seat capacity. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Hiace Commuter when your trip calls for large-group shuttle schedules with fifteen-seat capacity.
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"15-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(50,'nissan-urvan-premium','Nissan Urvan Premium','Nissan','Van','nissan-urvan-premium.png',5700,5000,15,5,'4 bags','Manual','Diesel',250,'Passenger-focused comfort for bigger journeys','The Nissan Urvan Premium is a 15-seat van selected for reliable passenger transport for events and tours. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Nissan Urvan Premium when your trip calls for reliable passenger transport for events and tours. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"15-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(51,'kia-carnival','Kia Carnival','Kia','Van','kia-carnival.png',7000,5000,8,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Kia Carnival is a 8-seat van selected for eight-seat family travel with upscale cabin comfort. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Kia Carnival when your trip calls for eight-seat family travel with upscale cabin comfort. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"8-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(52,'maxus-g10','Maxus G10','Maxus','Van','maxus-g10.png',5100,5000,9,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Maxus G10 is a 9-seat van selected for nine-seat team trips and practical city transfers. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Maxus G10 when your trip calls for nine-seat team trips and practical city transfers. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"9-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(53,'foton-toano','Foton Toano','Foton','Van','foton-toano.png',5300,5000,15,5,'4 bags','Manual','Diesel',250,'Passenger-focused comfort for bigger journeys','The Foton Toano is a 15-seat van selected for group tours that need maximum passenger capacity. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Foton Toano when your trip calls for group tours that need maximum passenger capacity. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"15-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(54,'mercedes-v-class','Mercedes-Benz V-Class','Mercedes-Benz','Van','mercedes-v-class.png',12000,5000,7,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Mercedes-Benz V-Class is a 7-seat van selected for executive group transfers and VIP schedules. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mercedes-Benz V-Class when your trip calls for executive group transfers and VIP schedules. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(55,'volkswagen-multivan','Volkswagen Multivan','Volkswagen','Van','volkswagen-multivan.png',8500,5000,7,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Volkswagen Multivan is a 7-seat van selected for seven-seat premium touring with flexible cabin use. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Volkswagen Multivan when your trip calls for seven-seat premium touring with flexible cabin use. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"7-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(56,'peugeot-traveller','Peugeot Traveller','Peugeot','Van','peugeot-traveller.png',7600,5000,8,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Peugeot Traveller is a 8-seat van selected for eight-seat long-distance passenger travel. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Peugeot Traveller when your trip calls for eight-seat long-distance passenger travel. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"8-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(57,'hyundai-h100','Hyundai H-100','Hyundai','Van','hyundai-h100.png',4200,5000,12,5,'4 bags','Manual','Diesel',250,'Passenger-focused comfort for bigger journeys','The Hyundai H-100 is a 12-seat van selected for twelve-seat utility and crew transport. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Hyundai H-100 when your trip calls for twelve-seat utility and crew transport. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"12-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(58,'maxus-v80','Maxus V80','Maxus','Van','maxus-v80.png',4800,5000,12,5,'4 bags','Manual','Diesel',250,'Passenger-focused comfort for bigger journeys','The Maxus V80 is a 12-seat van selected for twelve-seat shuttle and business assignments. Its manual transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Maxus V80 when your trip calls for twelve-seat shuttle and business assignments. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"12-seat configuration\",\"Manual transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(59,'ford-transit','Ford Transit','Ford','Van','ford-transit.png',6100,5000,15,5,'4 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Ford Transit is a 15-seat van selected for fifteen-seat group movement with automatic convenience. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Ford Transit when your trip calls for fifteen-seat group movement with automatic convenience. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"15-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(60,'renault-trafic','Renault Trafic','Renault','Van','renault-trafic.png',6500,5000,9,5,'6 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Renault Trafic is a 9-seat van selected for nine-seat touring and event transport. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Renault Trafic when your trip calls for nine-seat touring and event transport. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"9-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(61,'mercedes-sprinter','Mercedes-Benz Sprinter','Mercedes-Benz','Van','mercedes-sprinter.png',9800,5000,15,5,'4 bags','Automatic','Diesel',250,'Passenger-focused comfort for bigger journeys','The Mercedes-Benz Sprinter is a 15-seat van selected for high-capacity premium shuttle requirements. Its automatic transmission and diesel powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mercedes-Benz Sprinter when your trip calls for high-capacity premium shuttle requirements. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"250 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Passenger seating orientation\",\"Pre-trip cabin inspection\"]','[\"15-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Flexible passenger seating\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(62,'hyundai-ioniq-5','Hyundai Ioniq 5','Hyundai','EV','hyundai-ioniq-5.png',7200,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Hyundai Ioniq 5 is a 5-seat ev selected for modern electric road trips with spacious seating. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Hyundai Ioniq 5 when your trip calls for modern electric road trips with spacious seating. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(63,'kia-ev6','Kia EV6','Kia','EV','kia-ev6.png',7400,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Kia EV6 is a 5-seat ev selected for sporty electric touring and premium city schedules. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Kia EV6 when your trip calls for sporty electric touring and premium city schedules. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(64,'byd-atto-3','BYD Atto 3','BYD','EV','byd-atto-3.png',5600,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The BYD Atto 3 is a 5-seat ev selected for efficient crossover travel with everyday practicality. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the BYD Atto 3 when your trip calls for efficient crossover travel with everyday practicality. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(65,'nissan-leaf','Nissan Leaf','Nissan','EV','nissan-leaf.png',4800,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Nissan Leaf is a 5-seat ev selected for simple urban mobility with zero tailpipe emissions. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Nissan Leaf when your trip calls for simple urban mobility with zero tailpipe emissions. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(66,'bmw-i4','BMW i4','BMW','EV','bmw-i4.png',10500,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The BMW i4 is a 5-seat ev selected for premium electric business travel. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the BMW i4 when your trip calls for premium electric business travel. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(67,'mercedes-eqe','Mercedes-Benz EQE','Mercedes-Benz','EV','mercedes-eqe.png',11800,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Mercedes-Benz EQE is a 5-seat ev selected for executive electric journeys with refined comfort. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Mercedes-Benz EQE when your trip calls for executive electric journeys with refined comfort. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(68,'porsche-taycan','Porsche Taycan','Porsche','EV','porsche-taycan.png',17000,8000,4,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Porsche Taycan is a 4-seat ev selected for performance-focused electric celebrations. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Porsche Taycan when your trip calls for performance-focused electric celebrations. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"4-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(69,'audi-e-tron-gt','Audi e-tron GT','Audi','EV','audi-e-tron-gt.png',15500,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Audi e-tron GT is a 5-seat ev selected for grand-touring electric travel and special occasions. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Audi e-tron GT when your trip calls for grand-touring electric travel and special occasions. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(70,'volvo-ex30','Volvo EX30','Volvo','EV','volvo-ex30.png',6800,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Volvo EX30 is a 5-seat ev selected for compact electric city trips and easy parking. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Volvo EX30 when your trip calls for compact electric city trips and easy parking. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(71,'mg4-ev','MG4 EV','MG4','EV','mg4-ev.png',5200,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The MG4 EV is a 5-seat ev selected for accessible electric mobility for daily schedules. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the MG4 EV when your trip calls for accessible electric mobility for daily schedules. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(72,'honda-cr-v-hybrid','Honda CR-V Hybrid','Honda','EV','honda-cr-v-hybrid.png',5900,8000,5,4,'3 bags','Automatic','Hybrid',200,'Modern efficiency with smooth, quiet performance','The Honda CR-V Hybrid is a 5-seat ev selected for efficient family travel with hybrid flexibility. Its automatic transmission and hybrid powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Honda CR-V Hybrid when your trip calls for efficient family travel with hybrid flexibility. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Hybrid driving orientation\",\"Emergency roadside kit\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(73,'toyota-corolla-cross-hybrid','Toyota Corolla Cross Hybrid','Toyota','EV','toyota-corolla-cross-hybrid.png',5700,8000,5,4,'3 bags','Automatic','Hybrid',200,'Modern efficiency with smooth, quiet performance','The Toyota Corolla Cross Hybrid is a 5-seat ev selected for family crossover trips with fuel-saving hybrid power. Its automatic transmission and hybrid powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Toyota Corolla Cross Hybrid when your trip calls for family crossover trips with fuel-saving hybrid power. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Hybrid driving orientation\",\"Emergency roadside kit\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(74,'tesla-model-y','Tesla Model Y','Tesla','EV','tesla-model-y.png',7800,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The Tesla Model Y is a 5-seat ev selected for five-seat electric family travel. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the Tesla Model Y when your trip calls for five-seat electric family travel. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54'),(75,'byd-seal','BYD Seal','BYD','EV','byd-seal.png',6900,8000,5,4,'3 bags','Automatic','Electric',200,'Modern efficiency with smooth, quiet performance','The BYD Seal is a 5-seat ev selected for sleek electric touring with a spacious sedan layout. Its automatic transmission and electric powertrain make it a practical match for customers who value a clean, prepared vehicle and a clear rental experience.','Choose the BYD Seal when your trip calls for sleek electric touring with a spacious sedan layout. 
This VJ Car Rental unit is released after inspection and sanitation, with date-based availability, transparent daily pricing, and support details shown before confirmation.','[\"200 km daily mileage allowance\",\"Standard rental protection\",\"Sanitized interior before release\",\"24/7 roadside assistance\",\"Portable charging cable\",\"Charging and range orientation\"]','[\"5-seat configuration\",\"Automatic transmission\",\"Air-conditioned cabin\",\"Bluetooth audio\",\"Rear parking assistance\",\"Charging cable supplied\"]',1,'available','2026-09-01 11:31:54','2026-09-01 11:31:54');
/*!40000 ALTER TABLE `vehicles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `addons`
--

LOCK TABLES `addons` WRITE;
/*!40000 ALTER TABLE `addons` DISABLE KEYS */;
INSERT INTO `addons` VALUES (1,'child-seat','Child safety seat',250,'day','bi-person-hearts',1,'2026-09-01 11:31:54','2026-09-01 11:31:54'),(2,'additional-driver','Additional driver',350,'day','bi-person-plus',1,'2026-09-01 11:31:54','2026-09-01 11:31:54'),(3,'gps','GPS navigation unit',150,'day','bi-map',1,'2026-09-01 11:31:54','2026-09-01 11:31:54'),(4,'wifi','Mobile Wi-Fi',250,'day','bi-wifi',1,'2026-09-01 11:31:54','2026-09-01 11:31:54'),(5,'enhanced-protection','Enhanced protection',800,'day','bi-shield-plus',1,'2026-09-01 11:31:54','2026-09-01 11:31:54');
/*!40000 ALTER TABLE `addons` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `promos`
--

LOCK TABLES `promos` WRITE;
/*!40000 ALTER TABLE `promos` DISABLE KEYS */;
INSERT INTO `promos` VALUES (1,'VJ10','Ten percent off the rental subtotal','percent',10,NULL,NULL,1000,0,1,'2026-09-01 11:31:54','2026-09-01 11:31:54');
/*!40000 ALTER TABLE `promos` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-09  7:15:23
