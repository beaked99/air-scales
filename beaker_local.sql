-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: air_scales2
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
-- Table structure for table `axle_group`
--

DROP TABLE IF EXISTS `axle_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `axle_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `axle_group`
--

LOCK TABLES `axle_group` WRITE;
/*!40000 ALTER TABLE `axle_group` DISABLE KEYS */;
INSERT INTO `axle_group` VALUES (1,'steer','Steer Axle'),(2,'drive','Drive Axle'),(3,'Trailer','Trailer Main Axle'),(4,'Jeep','Jeep Trailer'),(5,'booster','Booster Axles'),(8,'jeep','Jeep Axle Group'),(9,'booster','Booster Axle Group'),(10,'dolly','Converter Dolly Axle'),(11,'tag','Tag Axle'),(12,'pusher','Pusher Axle'),(13,'lift','Lift Axle'),(14,'midlift','Midlift Axle'),(15,'lazy','Lazy Axle (non-driven, non-lifted)');
/*!40000 ALTER TABLE `axle_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `calibration`
--

DROP TABLE IF EXISTS `calibration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calibration` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `created_by_id` int(11) NOT NULL,
  `updated_by_id` int(11) DEFAULT NULL,
  `air_pressure` double NOT NULL,
  `ambient_air_pressure` double NOT NULL,
  `air_temperature` double NOT NULL,
  `elevation` double NOT NULL,
  `scale_weight` double NOT NULL,
  `comment` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_FCC2B41394A4C7D4` (`device_id`),
  KEY `IDX_FCC2B413B03A8386` (`created_by_id`),
  KEY `IDX_FCC2B413896DBBDE` (`updated_by_id`),
  CONSTRAINT `FK_FCC2B413896DBBDE` FOREIGN KEY (`updated_by_id`) REFERENCES `user` (`id`),
  CONSTRAINT `FK_FCC2B41394A4C7D4` FOREIGN KEY (`device_id`) REFERENCES `device` (`id`),
  CONSTRAINT `FK_FCC2B413B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `calibration`
--

LOCK TABLES `calibration` WRITE;
/*!40000 ALTER TABLE `calibration` DISABLE KEYS */;
INSERT INTO `calibration` VALUES (1,1,1,1,1013.25,1013.2,22.5,150,100,'Initial calibration device 1','2024-01-15 08:30:00','2024-01-15 08:30:00'),(2,2,2,2,1012.8,1012.75,21.8,200.5,250.5,'Standard calibration check','2024-01-15 09:15:00','2024-01-15 09:15:00'),(3,3,3,3,1014.1,1014.05,23.2,175.2,500,'Post-maintenance calibration','2024-01-15 10:00:00','2024-01-15 10:00:00'),(4,4,4,4,1011.9,1011.85,20.9,300.8,750.25,'Weekly calibration','2024-01-16 08:45:00','2024-01-16 08:45:00'),(5,5,5,5,1013.6,1013.55,24.1,125.3,1000,'Monthly precision check','2024-01-16 11:20:00','2024-01-16 11:20:00'),(6,6,6,6,1012.4,1012.35,22,180.7,50.75,'Light load calibration','2024-01-17 07:30:00','2024-01-17 07:30:00'),(7,7,7,7,1015.2,1015.15,25.5,90.2,1500,'Heavy load test','2024-01-17 14:15:00','2024-01-17 14:15:00'),(9,9,2,2,1013.9,1013.85,23.8,220.4,300.6,'Routine maintenance','2024-01-18 13:45:00','2024-01-18 13:45:00'),(10,10,3,3,1014.5,1014.45,21.3,160.9,800.4,'Accuracy verification','2024-01-19 08:15:00','2024-01-19 08:15:00'),(11,1,4,4,1011.3,1011.25,26.2,350.6,1200.8,'Temperature compensation','2024-01-19 16:30:00','2024-01-19 16:30:00'),(12,2,5,5,1012.7,1012.65,22.7,185.1,450.15,'Drift check','2024-01-20 10:30:00','2024-01-20 10:30:00'),(13,3,6,6,1013.4,1013.35,24.4,140.8,650.9,'Linearity test','2024-01-20 15:00:00','2024-01-20 15:00:00'),(14,4,7,7,1014.8,1014.75,20.1,275.3,350.25,'Pressure sensitivity','2024-01-21 09:45:00','2024-01-21 09:45:00'),(15,5,1,1,1010.2,1010.15,27.8,80.7,900.5,'High temperature test','2024-01-21 14:20:00','2024-01-21 14:20:00'),(16,6,2,2,1013,1012.95,21.9,195.4,150.75,'Repeatability check','2024-01-22 08:00:00','2024-01-22 08:00:00'),(17,7,3,3,1012.1,1012.05,23.6,165.2,550.3,'Stability test','2024-01-22 12:30:00','2024-01-22 12:30:00'),(19,9,5,5,1011.7,1011.65,25.1,210.6,400.85,'Mid-range calibration','2024-01-23 11:00:00','2024-01-23 11:00:00'),(20,10,6,6,1013.8,1013.75,22.4,155.7,700.2,'Quarterly review','2024-01-24 09:30:00','2024-01-24 09:30:00'),(21,1,7,7,1012.5,1012.45,24.7,130.5,250.95,'Precision validation','2024-01-24 14:45:00','2024-01-24 14:45:00'),(22,2,1,1,1014.3,1014.25,21.6,245.8,850.6,'Load cell verification','2024-01-25 08:20:00','2024-01-25 08:20:00'),(23,3,2,2,1010.9,1010.85,26.9,95.3,1300.75,'Full scale test','2024-01-25 15:10:00','2024-01-25 15:10:00'),(24,4,3,3,1013.7,1013.65,20.8,175.9,175.4,'Zero point check','2024-01-26 10:15:00','2024-01-26 10:15:00'),(25,5,4,4,1012.2,1012.15,23.3,190.2,600.8,'Span adjustment','2024-01-26 13:00:00','2024-01-26 13:00:00'),(26,6,5,5,1015,1014.95,22.1,110.4,75.25,'Minimum load test','2024-01-27 07:45:00','2024-01-27 07:45:00'),(27,7,6,6,1011.4,1011.35,25.8,260.7,950.15,'Maximum load test','2024-01-27 16:20:00','2024-01-27 16:20:00'),(29,9,1,1,1012.6,1012.55,24,225.6,325.9,'Creep test','2024-01-28 12:40:00','2024-01-28 12:40:00'),(30,10,2,2,1014.7,1014.65,19.7,305.3,775.35,'Environmental stability','2024-01-29 08:35:00','2024-01-29 08:35:00'),(31,1,3,3,1010.6,1010.55,27.2,85.8,1150.7,'Thermal drift test','2024-01-29 14:50:00','2024-01-29 14:50:00'),(32,2,4,4,1013.5,1013.45,22.8,170.4,290.45,'Sensitivity check','2024-01-30 10:25:00','2024-01-30 10:25:00'),(33,3,5,5,1012.8,1012.75,23.9,200.7,520.8,'Response time test','2024-01-30 15:30:00','2024-01-30 15:30:00'),(34,4,6,6,1015.4,1015.35,20.4,120.9,125.6,'Low-end accuracy','2024-01-31 07:20:00','2024-01-31 07:20:00'),(35,5,7,7,1011.1,1011.05,26.5,280.3,1400.9,'High-end accuracy','2024-01-31 11:40:00','2024-01-31 11:40:00'),(36,6,1,1,1013.3,1013.25,21.7,155.6,375.25,'Mid-point verification','2024-02-01 08:50:00','2024-02-01 08:50:00'),(37,7,2,2,1012.9,1012.85,24.3,195.8,675.8,'Stability recheck','2024-02-01 14:15:00','2024-02-01 14:15:00'),(39,9,4,4,1011.8,1011.75,28.1,75.4,1250.6,'Hot weather test','2024-02-02 15:45:00','2024-02-02 15:45:00'),(40,10,5,5,1013.6,1013.55,22.9,205.7,225.3,'Pressure variance test','2024-02-03 09:20:00','2024-02-03 09:20:00'),(41,1,6,6,1012.3,1012.25,25.4,165.9,575.85,'Elevation compensation','2024-02-03 13:10:00','2024-02-03 13:10:00'),(42,2,7,7,1014.9,1014.85,19.8,290.5,425.75,'Barometric adjustment','2024-02-04 08:40:00','2024-02-04 08:40:00'),(43,3,1,1,1010.7,1010.65,26.7,115.2,1050.2,'Final verification','2024-02-04 12:25:00','2024-02-04 12:25:00'),(44,4,2,2,1013.85,1013.8,23.1,185.3,325.65,'Quality assurance','2024-02-05 10:00:00','2024-02-05 10:00:00'),(45,5,3,3,1012.45,1012.4,21.4,235.8,750.4,'Performance validation','2024-02-05 16:30:00','2024-02-05 16:30:00'),(46,6,4,4,1015.1,1015.05,24.8,125.7,150.95,'Compliance check','2024-02-06 09:15:00','2024-02-06 09:15:00'),(47,7,5,5,1011.55,1011.5,27.3,310.4,875.3,'Certification prep','2024-02-06 14:00:00','2024-02-06 14:00:00'),(49,9,7,7,1012.65,1012.6,25,215.6,625.8,'System integration','2024-02-07 13:50:00','2024-02-07 13:50:00'),(50,10,1,1,1014.35,1014.3,22.3,145.9,925.15,'Final acceptance test','2024-02-08 11:30:00','2024-02-08 11:30:00'),(52,8,1,NULL,468843,101325,30,0,13812,'loaded at san pedro','2025-06-29 23:22:14','2025-06-29 23:22:14'),(53,8,1,NULL,125000,100000,38,100,4000,'empty headed to san pedro','2025-06-29 23:23:37','2025-06-29 23:23:37'),(54,8,1,NULL,150000,98000,25,250,6000,'dummy data','2025-06-29 23:29:29','2025-06-29 23:29:29'),(55,8,1,NULL,175000,99000,29,301,8000,NULL,'2025-06-30 12:01:37','2025-06-30 12:01:37'),(56,1,1,NULL,1013,1012,27,120,980,'stuff','2025-06-30 16:06:37','2025-06-30 16:06:37');
/*!40000 ALTER TABLE `calibration` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device`
--

DROP TABLE IF EXISTS `device`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(11) DEFAULT NULL,
  `sold_to_id` int(11) DEFAULT NULL,
  `serial_number` varchar(64) DEFAULT NULL,
  `mac_address` varchar(17) DEFAULT NULL,
  `device_type` varchar(64) DEFAULT NULL,
  `firmware_version` varchar(64) DEFAULT NULL,
  `order_date` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `ship_date` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `tracking_id` varchar(64) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `regression_intercept` double DEFAULT NULL,
  `regression_air_pressure_coeff` double DEFAULT NULL,
  `regression_ambient_pressure_coeff` double DEFAULT NULL,
  `regression_air_temp_coeff` double DEFAULT NULL,
  `regression_rsq` double DEFAULT NULL,
  `regression_rmse` double DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_92FB68E545317D1` (`vehicle_id`),
  KEY `IDX_92FB68E8E5CC2C0` (`sold_to_id`),
  CONSTRAINT `FK_92FB68E545317D1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicle` (`id`),
  CONSTRAINT `FK_92FB68E8E5CC2C0` FOREIGN KEY (`sold_to_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device`
--

LOCK TABLES `device` WRITE;
/*!40000 ALTER TABLE `device` DISABLE KEYS */;
INSERT INTO `device` VALUES (1,1,2,'AS001-2024','AA:BB:CC:DD:EE:FF','Air Scale Pro','v2.1.3','2024-01-10 09:00:00','2024-01-12 14:30:00','TRK001234567','Primary device for red Camry','2024-01-15 10:35:00','2025-06-30 16:06:38',0.030273898742962,-138.11104911951,132.45802573656,253.29555060307,0.8251277739475,179.76951982225),(2,1,NULL,'AS002-2024','00:1B:44:11:3A:B8','Air Scale Pro','v2.1.3','2024-01-10 09:00:00','2024-01-12 14:30:00','TRK001234568','Backup device for red Camry','2024-01-15 10:40:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(3,2,NULL,'AS003-2024','00:1B:44:11:3A:B9','Air Scale Heavy','v2.2.1','2024-01-28 11:15:00','2024-01-30 16:20:00','TRK001234569','Heavy duty for work truck','2024-02-01 09:20:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(4,2,NULL,'AS004-2024','00:1B:44:11:3A:C0','Air Scale Heavy','v2.2.1','2024-01-28 11:15:00','2024-01-30 16:20:00','TRK001234570','Secondary heavy duty device','2024-02-01 09:25:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(5,3,NULL,'AS005-2024','00:1B:44:11:3A:C1','Air Scale Compact','v2.0.5','2024-01-18 13:45:00','2024-01-20 10:15:00','TRK001234571','Compact device for Honda','2024-01-20 11:50:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(6,3,NULL,'AS006-2024','00:1B:44:11:3A:C2','Air Scale Compact','v2.0.5','2024-01-18 13:45:00','2024-01-20 10:15:00','TRK001234572','Spare compact device','2024-01-20 11:55:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(7,4,NULL,'AS007-2024','00:1B:44:11:3A:C3','Air Scale Heavy','v2.2.0','2024-03-05 14:20:00','2024-03-08 09:40:00','TRK001234573','Heavy duty for Silverado','2024-03-10 08:25:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(8,5,NULL,'AS008-2024','00:1B:44:11:3A:C4','Air Scale Pro','v2.1.4','2024-01-22 16:30:00','2024-01-25 11:45:00','TRK001234574','Standard device for Altima','2024-01-25 14:15:00','2025-06-30 12:01:37',-0.41536139503751,0.024154543566289,0.070633306334077,-151.76707847558,0.96144379166829,719.92827916223),(9,6,NULL,'AS009-2024','00:1B:44:11:3A:C5','Air Scale Heavy','v2.2.1','2024-02-12 10:10:00','2024-02-14 15:25:00','TRK001234575','Heavy duty for Ram','2024-02-15 16:45:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL),(10,7,NULL,'AS010-2024','00:1B:44:11:3A:C6','Air Scale All-Terrain','v2.3.0','2024-03-01 12:50:00','2024-03-04 08:15:00','TRK001234576','All-terrain for Subaru','2024-03-05 12:30:00',NULL,NULL,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `device` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_access`
--

DROP TABLE IF EXISTS `device_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `first_seen_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `last_connected_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_D5795A4FA76ED395` (`user_id`),
  KEY `IDX_D5795A4F94A4C7D4` (`device_id`),
  CONSTRAINT `FK_D5795A4F94A4C7D4` FOREIGN KEY (`device_id`) REFERENCES `device` (`id`),
  CONSTRAINT `FK_D5795A4FA76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_access`
--

LOCK TABLES `device_access` WRITE;
/*!40000 ALTER TABLE `device_access` DISABLE KEYS */;
INSERT INTO `device_access` VALUES (1,1,1,'2025-06-16 16:59:52','2025-06-26 15:59:52',1),(2,2,2,'2025-06-06 16:59:52','2025-06-26 14:59:52',1),(3,3,3,'2025-06-21 16:59:52','2025-06-26 16:29:52',1),(4,4,4,'2025-06-11 16:59:52','2025-06-26 13:59:52',1),(5,5,5,'2025-06-25 16:59:52','2025-06-26 16:49:52',1),(6,6,6,'2025-06-23 16:59:52','2025-06-26 16:54:52',1),(7,7,7,'2025-06-19 16:59:52','2025-06-26 14:59:52',1),(8,1,8,'2025-06-14 16:59:52','2025-06-26 10:59:52',1),(9,2,9,'2025-06-24 16:59:52','2025-06-26 15:59:52',1);
/*!40000 ALTER TABLE `device_access` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctrine_migration_versions`
--

DROP TABLE IF EXISTS `doctrine_migration_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctrine_migration_versions` (
  `version` varchar(191) NOT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctrine_migration_versions`
--

LOCK TABLES `doctrine_migration_versions` WRITE;
/*!40000 ALTER TABLE `doctrine_migration_versions` DISABLE KEYS */;
INSERT INTO `doctrine_migration_versions` VALUES ('DoctrineMigrations\\Version20250624194636','2025-06-24 21:46:41',709),('DoctrineMigrations\\Version20250625044404','2025-06-25 06:44:37',114),('DoctrineMigrations\\Version20250625053840','2025-06-25 07:38:54',8),('DoctrineMigrations\\Version20250626062301','2025-06-26 08:23:17',157),('DoctrineMigrations\\Version20250626141709','2025-06-26 16:17:22',90),('DoctrineMigrations\\Version20250627045600','2025-06-27 06:56:11',135),('DoctrineMigrations\\Version20250628054601','2025-06-28 07:46:14',268),('DoctrineMigrations\\Version20250628064528','2025-06-28 08:45:35',229),('DoctrineMigrations\\Version20250630052432','2025-06-30 07:24:44',50);
/*!40000 ALTER TABLE `doctrine_migration_versions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `messenger_messages`
--

DROP TABLE IF EXISTS `messenger_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messenger_messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `body` longtext NOT NULL,
  `headers` longtext NOT NULL,
  `queue_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `available_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `delivered_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  KEY `IDX_75EA56E0FB7336F0` (`queue_name`),
  KEY `IDX_75EA56E0E3BD61CE` (`available_at`),
  KEY `IDX_75EA56E016BA31DB` (`delivered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `messenger_messages`
--

LOCK TABLES `messenger_messages` WRITE;
/*!40000 ALTER TABLE `messenger_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `messenger_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `micro_data`
--

DROP TABLE IF EXISTS `micro_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `micro_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `main_air_pressure` double NOT NULL,
  `atmospheric_pressure` double NOT NULL,
  `temperature` double NOT NULL,
  `elevation` double NOT NULL,
  `gps_lat` double NOT NULL,
  `gps_lng` double NOT NULL,
  `mac_address` varchar(255) NOT NULL,
  `timestamp` datetime NOT NULL,
  `weight` double NOT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_DD967FF994A4C7D4` (`device_id`),
  CONSTRAINT `FK_DD967FF994A4C7D4` FOREIGN KEY (`device_id`) REFERENCES `device` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `micro_data`
--

LOCK TABLES `micro_data` WRITE;
/*!40000 ALTER TABLE `micro_data` DISABLE KEYS */;
INSERT INTO `micro_data` VALUES (1,1,1023.4,1012.1,22.5,250,49.2827,-123.1207,'AA:BB:CC:DD:EE:FF','2025-06-26 17:07:30',1250),(2,1,1023.2,1011.9,22.8,250.3,49.2828,-123.1208,'AA:BB:CC:DD:EE:FF','2025-06-26 18:07:30',1248.6),(3,1,1023.1,1011.8,22.7,250.1,49.2829,-123.121,'AA:BB:CC:DD:EE:FF','2025-06-26 19:07:30',1251.1),(4,2,1022.5,1010.3,22.1,251,49.283,-123.1212,'00:1B:44:11:3A:B8','2025-06-26 18:07:30',1249),(5,2,1022.3,1010,22.3,251.2,49.2831,-123.1213,'00:1B:44:11:3A:B8','2025-06-26 19:07:30',1247.8),(6,3,1019.9,1008.4,19.8,600.1,51.045,-114.0719,'00:1B:44:11:3A:B9','2025-06-26 17:07:30',2450),(7,3,1019.7,1008.2,20,600.4,51.0451,-114.072,'00:1B:44:11:3A:B9','2025-06-26 18:07:30',2448.3),(8,4,1019,1007.5,19.6,602,51.0452,-114.0721,'00:1B:44:11:3A:C0','2025-06-26 19:07:30',2449.2),(9,5,1020.4,1009.1,21.3,280,48.4284,-123.3656,'00:1B:44:11:3A:C1','2025-06-26 18:07:30',1200),(10,6,1020,1008.8,21.5,280.5,48.4286,-123.3658,'00:1B:44:11:3A:C2','2025-06-26 19:07:30',1202.4),(11,7,1018.2,1007.1,19.1,300,47.6062,-122.3321,'00:1B:44:11:3A:C3','2025-06-26 19:07:30',2300.8),(12,8,1021,1009.5,22.9,265,49.8951,-97.1384,'00:1B:44:11:3A:C4','2025-06-30 19:07:30',1275.3),(13,9,1017.5,1006,18.5,320,45.5017,-73.5673,'00:1B:44:11:3A:C5','2025-06-26 19:07:30',2650.6),(14,10,1016.8,1005.2,18.9,310,43.6532,-79.3832,'00:1B:44:11:3A:C6','2025-06-26 19:07:30',2100.2);
/*!40000 ALTER TABLE `micro_data` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user`
--

DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(180) NOT NULL,
  `roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '(DC2Type:json)' CHECK (json_valid(`roles`)),
  `password` varchar(255) NOT NULL,
  `first_name` varchar(64) NOT NULL,
  `last_name` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `last_login` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `stripe_customer_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `UNIQ_IDENTIFIER_EMAIL` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user`
--

LOCK TABLES `user` WRITE;
/*!40000 ALTER TABLE `user` DISABLE KEYS */;
INSERT INTO `user` VALUES (1,'kevin@beaker.ca','[\"ROLE_ADMIN\"]','$2y$13$nM.QX786SvUhxMLJF/NApODDqVHaM6bhh4bPTfrcF8B5SG9vXlfjG','kevin','wiebe','2025-06-09 22:35:45',NULL,NULL,NULL),(2,'test@test.ca','{\"1\":\"ROLE_USER\"}','$2y$13$4/GIBoDI/nUcnTUppkwoW.XkIG.MLj4aXvZxJosyYXGT01sFI6aC.','test','dummies','2025-06-09 22:35:45',NULL,NULL,NULL),(3,'testing@tested.ca','[\"ROLE_USER\"]','$2y$13$.IBulki40UBmv6GzimrCguevP5xsBgDThKvsGPkeTKvFFvbjSvYxu','tested','for alcohol','2025-06-09 22:35:45',NULL,NULL,NULL),(4,'random@random.ca','[\"ROLE_USER\"]','$2y$13$RGz6uJNXH8vZ25WWZtg3wOQ1bdQ3lCS5mldkhZ/vVbyZDYPobBySO','losing','randomness','2025-06-09 22:35:46',NULL,NULL,NULL),(5,'more@test.ca','[]','$2y$13$gnR/xEQOyaXsVHRnz792wONl0dTSJezjhh7eUuQG6rRRS1PZXhviq','more','test','2025-06-09 23:35:47',NULL,NULL,NULL),(6,'stuff@stuff.com','[]','$2y$13$I0dzTxNFLaF/OqsMUGGCE.mvqzFuwKSVkQ2syKMS0ogMHpXsQvz7.','stuff','stuff','2025-06-22 22:56:33',NULL,NULL,NULL),(7,'frick@me.com','[]','$2y$13$WAX3R.BFaGe7lU.LR0blpu9LZRlHQDTaCX8VFERl6C7EsMb3zxvzW','frick','me','2025-06-24 01:18:55',NULL,NULL,NULL),(8,'look@me.com','[]','$2y$13$C0MAvQvVbWidxbqqYUrjz.24mDSD26x4eKEeEvaSJyHEyzIRiMGwm','look','at','2025-06-24 10:42:11',NULL,NULL,NULL),(9,'slore@sloreing.com','[]','$2y$13$hF8N/ox8Ofu2PqRTcEWYfezOvoCpZcr1mfw5cMCj0alkJ8fobbCHC','slore','slore','2025-06-24 10:46:02',NULL,NULL,NULL),(10,'slore@test.ca','[]','$2y$13$Jjjz5OGYOPz.hDsPEvEglO5scBa2RiH8zaC/fu/WICa1n7wTtfGpy','slore','slore','2025-06-24 02:03:37',NULL,NULL,NULL),(11,'blank@blank.ca','[]','$2y$13$cQi2xeZnfUbsDtUR4le8VeGNEMmTRp4EFwDjlnmUEJbvCJcln1nSu','123','123','2025-06-24 02:09:42',NULL,NULL,NULL);
/*!40000 ALTER TABLE `user` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_connected_vehicle`
--

DROP TABLE IF EXISTS `user_connected_vehicle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_connected_vehicle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `is_connected` tinyint(1) NOT NULL,
  `last_changed_at` datetime NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_vehicle_unique` (`user_id`,`vehicle_id`),
  KEY `IDX_706F1854A76ED395` (`user_id`),
  KEY `IDX_706F1854545317D1` (`vehicle_id`),
  CONSTRAINT `FK_706F1854545317D1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicle` (`id`),
  CONSTRAINT `FK_706F1854A76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_connected_vehicle`
--

LOCK TABLES `user_connected_vehicle` WRITE;
/*!40000 ALTER TABLE `user_connected_vehicle` DISABLE KEYS */;
INSERT INTO `user_connected_vehicle` VALUES (1,1,5,1,'2025-06-28 09:36:22'),(2,1,1,0,'2025-06-28 09:36:33');
/*!40000 ALTER TABLE `user_connected_vehicle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_vehicle_order`
--

DROP TABLE IF EXISTS `user_vehicle_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_vehicle_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `position` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_7908BE24A76ED395` (`user_id`),
  KEY `IDX_7908BE24545317D1` (`vehicle_id`),
  CONSTRAINT `FK_7908BE24545317D1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicle` (`id`),
  CONSTRAINT `FK_7908BE24A76ED395` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_vehicle_order`
--

LOCK TABLES `user_vehicle_order` WRITE;
/*!40000 ALTER TABLE `user_vehicle_order` DISABLE KEYS */;
INSERT INTO `user_vehicle_order` VALUES (1,1,5,0),(2,1,1,1);
/*!40000 ALTER TABLE `user_vehicle_order` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vehicle`
--

DROP TABLE IF EXISTS `vehicle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_by_id` int(11) DEFAULT NULL,
  `updated_by_id` int(11) DEFAULT NULL,
  `year` int(11) NOT NULL,
  `make` varchar(64) DEFAULT NULL,
  `model` varchar(64) DEFAULT NULL,
  `nickname` varchar(64) DEFAULT NULL,
  `vin` varchar(17) DEFAULT NULL,
  `license_plate` varchar(20) DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `created_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `updated_at` datetime DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
  `axle_group_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `IDX_1B80E486B03A8386` (`created_by_id`),
  KEY `IDX_1B80E486896DBBDE` (`updated_by_id`),
  KEY `IDX_1B80E486A5CAF111` (`axle_group_id`),
  CONSTRAINT `FK_1B80E486896DBBDE` FOREIGN KEY (`updated_by_id`) REFERENCES `user` (`id`),
  CONSTRAINT `FK_1B80E486A5CAF111` FOREIGN KEY (`axle_group_id`) REFERENCES `axle_group` (`id`),
  CONSTRAINT `FK_1B80E486B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vehicle`
--

LOCK TABLES `vehicle` WRITE;
/*!40000 ALTER TABLE `vehicle` DISABLE KEYS */;
INSERT INTO `vehicle` VALUES (1,2,NULL,2023,'Toyota','Camry','Red Camry','1HGBH41JXMN109186','ABC123','2024-06-20 14:22:00','2024-01-15 10:30:00','2025-06-26 22:52:51',2),(2,NULL,NULL,2022,'Ford','F-150','Work Truck','1FTFW1ET5DFC12345','XYZ789','2024-06-22 16:45:00','2024-02-01 09:15:00',NULL,NULL),(3,NULL,NULL,2021,'Honda','Civic','Blue Honda','2HGFC2F59MH123456','DEF456','2024-06-21 13:30:00','2024-01-20 11:45:00',NULL,NULL),(4,NULL,NULL,2020,'Chevrolet','Silverado','Big Silver','1GCRYSE70LZ123456','GHI789','2024-06-23 12:15:00','2024-03-10 08:20:00',NULL,NULL),(5,NULL,NULL,2019,'Nissan','Altima','Daily Driver','1N4AL3AP8KC123456','JKL012','2024-06-24 09:30:00','2024-01-25 14:10:00',NULL,NULL),(6,NULL,NULL,2018,'Ram','1500','The Beast','1C6RR7LT4JS123456','MNO345','2024-06-19 15:20:00','2024-02-15 16:40:00',NULL,NULL),(7,NULL,NULL,2017,'Subaru','Outback','Adventure Car','4S4BSANC5H3123456','PQR678','2024-06-18 11:10:00','2024-03-05 12:25:00',NULL,NULL),(8,2,NULL,2001,'Peterbilt','379','Project 350',NULL,NULL,NULL,'2025-06-26 20:26:05','2025-06-26 20:26:05',NULL);
/*!40000 ALTER TABLE `vehicle` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-06-30 22:06:07
