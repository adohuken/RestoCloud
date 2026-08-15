-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: restocloud
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
-- Table structure for table `cash_register`
--

DROP TABLE IF EXISTS `cash_register`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_register` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('open','close') NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','closed') DEFAULT 'active',
  `expected_amount` decimal(10,2) DEFAULT NULL,
  `difference` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `cash_register_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_register`
--

LOCK TABLES `cash_register` WRITE;
/*!40000 ALTER TABLE `cash_register` DISABLE KEYS */;
INSERT INTO `cash_register` VALUES (1,1,0.01,'open','2026-03-31 16:27:41','active',NULL,NULL);
/*!40000 ALTER TABLE `cash_register` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Alitas','2026-03-31 14:24:27'),(2,'Hamburguesas','2026-03-31 14:24:27'),(3,'Combos','2026-03-31 14:24:27'),(4,'Bebidas','2026-03-31 14:24:27'),(5,'Complementos','2026-03-31 14:24:27'),(6,'Postres','2026-03-31 14:24:27');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deleted_invoices_log`
--

DROP TABLE IF EXISTS `deleted_invoices_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deleted_invoices_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_invoice_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `deleted_by` int(11) NOT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reason` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deleted_invoices_log`
--

LOCK TABLES `deleted_invoices_log` WRITE;
/*!40000 ALTER TABLE `deleted_invoices_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `deleted_invoices_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredient_categories`
--

DROP TABLE IF EXISTS `ingredient_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredient_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredient_categories`
--

LOCK TABLES `ingredient_categories` WRITE;
/*!40000 ALTER TABLE `ingredient_categories` DISABLE KEYS */;
INSERT INTO `ingredient_categories` VALUES (1,'Carnes',NULL),(2,'Verduras',NULL),(3,'Frutas',NULL),(4,'LÃ¡cteos/Huevos',NULL),(5,'Granos/Pan',NULL),(6,'Bebidas',NULL),(7,'Condimentos',NULL),(8,'Otros',NULL);
/*!40000 ALTER TABLE `ingredient_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredient_movements`
--

DROP TABLE IF EXISTS `ingredient_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredient_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `movement_type` enum('deduction','restock','adjustment','waste') DEFAULT 'deduction',
  `quantity` decimal(10,2) NOT NULL,
  `stock_before` decimal(10,2) NOT NULL,
  `stock_after` decimal(10,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ingredient_id` (`ingredient_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `ingredient_movements_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `ingredient_movements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredient_movements`
--

LOCK TABLES `ingredient_movements` WRITE;
/*!40000 ALTER TABLE `ingredient_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `ingredient_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredients`
--

DROP TABLE IF EXISTS `ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `unit` enum('kg','g','l','ml','unidad') NOT NULL DEFAULT 'unidad',
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Cost per unit',
  `stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `min_stock` decimal(10,3) DEFAULT 5.000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `category_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredients`
--

LOCK TABLES `ingredients` WRITE;
/*!40000 ALTER TABLE `ingredients` DISABLE KEYS */;
INSERT INTO `ingredients` VALUES (1,'Carne Molida',NULL,NULL,'kg',120.00,10.000,5.000,'2026-03-31 15:00:49',8),(2,'Pan de Hamburguesa',NULL,NULL,'unidad',5.00,100.000,5.000,'2026-03-31 15:00:49',8),(3,'Queso Cheddar',NULL,NULL,'kg',180.00,5.000,5.000,'2026-03-31 15:00:49',8),(4,'Tocino',NULL,NULL,'kg',200.00,5.000,5.000,'2026-03-31 15:00:49',8),(5,'Alitas Crudas',NULL,NULL,'kg',80.00,20.000,5.000,'2026-03-31 15:00:49',8),(6,'Salsa BBQ',NULL,NULL,'l',150.00,5.000,5.000,'2026-03-31 15:00:49',8),(7,'Salsa Buffalo',NULL,NULL,'l',160.00,5.000,5.000,'2026-03-31 15:00:49',8),(8,'Papas Congeladas',NULL,NULL,'kg',40.00,50.000,5.000,'2026-03-31 15:00:49',8),(9,'Aceite','ðŸ¾',NULL,'',35.00,20.000,5.000,'2026-03-31 15:00:49',8);
/*!40000 ALTER TABLE `ingredients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoice_payments`
--

DROP TABLE IF EXISTS `invoice_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `invoice_payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoice_payments`
--

LOCK TABLES `invoice_payments` WRITE;
/*!40000 ALTER TABLE `invoice_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoice_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `table_name` varchar(50) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `iva_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `iva_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'paid',
  `split_number` int(11) DEFAULT NULL,
  `total_splits` int(11) DEFAULT NULL,
  `has_mixed_payments` tinyint(1) DEFAULT 0,
  `parent_invoice_id` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `tip_amount` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_details`
--

DROP TABLE IF EXISTS `order_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `item_status` enum('draft','pending','preparing','ready','served') NOT NULL DEFAULT 'draft',
  `price` decimal(10,2) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `order_details_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_details`
--

LOCK TABLES `order_details` WRITE;
/*!40000 ALTER TABLE `order_details` DISABLE KEYS */;
INSERT INTO `order_details` VALUES (1,1,2,1,'pending',120.00,NULL,'2026-04-08 02:16:50'),(3,3,2,4,'ready',120.00,NULL,'2026-04-08 02:16:50'),(4,3,2,1,'ready',120.00,'esto es un test de nota especial','2026-04-08 02:16:50'),(5,3,1,1,'ready',120.00,NULL,'2026-04-24 16:54:38'),(6,3,1,1,'ready',120.00,NULL,'2026-04-24 17:02:20'),(7,3,6,1,'ready',190.00,NULL,'2026-05-08 15:13:40'),(8,3,2,1,'ready',120.00,NULL,'2026-05-08 16:25:05'),(9,3,6,1,'ready',190.00,NULL,'2026-05-08 16:25:10'),(12,3,2,1,'ready',120.00,NULL,'2026-05-08 18:07:47'),(13,3,7,1,'ready',160.00,NULL,'2026-05-08 19:39:25'),(14,3,19,1,'ready',85.00,NULL,'2026-05-08 19:44:25'),(15,3,14,1,'ready',25.00,NULL,'2026-05-08 19:44:42'),(17,3,1,1,'ready',120.00,NULL,'2026-05-08 19:48:14'),(18,3,8,1,'ready',220.00,NULL,'2026-05-08 19:50:52'),(19,3,10,1,'ready',350.00,NULL,'2026-05-08 20:16:19'),(20,3,4,1,'ready',130.00,NULL,'2026-05-08 20:16:28'),(21,3,11,1,'ready',25.00,NULL,'2026-05-08 20:17:58'),(22,3,4,1,'ready',130.00,NULL,'2026-05-08 20:29:19'),(23,3,18,1,'ready',90.00,NULL,'2026-05-08 20:32:01'),(25,3,8,1,'ready',220.00,NULL,'2026-05-08 20:32:39'),(26,3,20,1,'ready',95.00,NULL,'2026-05-08 20:34:03'),(27,3,4,1,'ready',130.00,NULL,'2026-05-08 20:42:43'),(28,3,2,1,'ready',120.00,NULL,'2026-05-08 20:48:23'),(29,3,3,1,'ready',120.00,NULL,'2026-05-08 21:22:27'),(30,4,3,1,'ready',120.00,NULL,'2026-05-08 21:22:57'),(31,3,5,1,'ready',150.00,NULL,'2026-05-08 21:32:21'),(32,3,5,1,'ready',150.00,NULL,'2026-05-08 21:32:35'),(33,3,2,1,'ready',120.00,NULL,'2026-05-08 21:33:56'),(34,3,2,1,'ready',0.00,'','2026-05-08 21:44:05'),(35,3,2,1,'ready',0.00,'','2026-05-08 21:45:44'),(36,4,3,1,'ready',120.00,NULL,'2026-05-08 21:52:29'),(37,3,3,1,'ready',120.00,NULL,'2026-05-08 22:17:34'),(38,3,2,1,'ready',120.00,NULL,'2026-05-08 22:35:31'),(39,3,13,1,'ready',45.00,NULL,'2026-05-08 22:41:15');
/*!40000 ALTER TABLE `order_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','completed','cancelled','draft','preparing','ready','picked_up','delivered') DEFAULT 'draft',
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_requested` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `table_id` (`table_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`table_id`) REFERENCES `tables` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (1,9,1,120.00,'draft','2026-03-31 16:27:45',0),(3,1,1,4185.00,'delivered','2026-05-08 16:23:54',1),(4,2,1,240.00,'delivered','2026-05-08 21:22:59',0);
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `method` enum('cash','transfer','card') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `cash_register_id` int(11) DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `cash_register_id` (`cash_register_id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`cash_register_id`) REFERENCES `cash_register` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pedidosya_order_details`
--

DROP TABLE IF EXISTS `pedidosya_order_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedidosya_order_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedidosya_order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(150) NOT NULL COMMENT 'Nombre del producto al momento de la venta',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL COMMENT 'Precio unitario al momento de la venta',
  PRIMARY KEY (`id`),
  KEY `pedidosya_order_id` (`pedidosya_order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `pedidosya_order_details_ibfk_1` FOREIGN KEY (`pedidosya_order_id`) REFERENCES `pedidosya_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pedidosya_order_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pedidosya_order_details`
--

LOCK TABLES `pedidosya_order_details` WRITE;
/*!40000 ALTER TABLE `pedidosya_order_details` DISABLE KEYS */;
/*!40000 ALTER TABLE `pedidosya_order_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pedidosya_orders`
--

DROP TABLE IF EXISTS `pedidosya_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pedidosya_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `external_order_id` varchar(100) NOT NULL COMMENT 'N??????mero de pedido de PedidosYa',
  `customer_name` varchar(150) DEFAULT NULL COMMENT 'Nombre del cliente',
  `customer_phone` varchar(50) DEFAULT NULL COMMENT 'Tel?????fono del cliente',
  `customer_address` text DEFAULT NULL COMMENT 'Direcci??????n de entrega',
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `iva_percentage` decimal(5,2) DEFAULT 0.00,
  `iva_amount` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL COMMENT 'Notas adicionales del pedido',
  `status` enum('pending','completed','cancelled') DEFAULT 'completed',
  `created_by` int(11) NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `pedidosya_orders_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pedidosya_orders`
--

LOCK TABLES `pedidosya_orders` WRITE;
/*!40000 ALTER TABLE `pedidosya_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `pedidosya_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_recipes`
--

DROP TABLE IF EXISTS `product_recipes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_recipes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `quantity_required` decimal(10,4) NOT NULL COMMENT 'Amount of ingredient needed for 1 unit of product',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `ingredient_id` (`ingredient_id`),
  CONSTRAINT `product_recipes_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_recipes_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_recipes`
--

LOCK TABLES `product_recipes` WRITE;
/*!40000 ALTER TABLE `product_recipes` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_recipes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) DEFAULT 0,
  `category_id` int(11) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `image_url` varchar(255) DEFAULT NULL,
  `icon` varchar(50) DEFAULT '????',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'ALI001','Alitas Buffalo (6 pzas)','Alitas de pollo ba??????adas en salsa Buffalo picante',120.00,100,1,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ—'),(2,'ALI002','Alitas BBQ (6 pzas)','Alitas de pollo con salsa BBQ ahumada',120.00,100,1,NULL,'active','2026-03-31 14:24:27','uploads/products/1777051883_alitas-platillo-final.gif','ðŸ—'),(3,'ALI003','Alitas Lemon Pepper (6 pzas)','Alitas sazonadas con lim??????n y pimienta',120.00,100,1,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ—'),(4,'ALI004','Alitas Mango Habanero (6 pzas)','Alitas con salsa de mango y habanero',130.00,100,1,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ—'),(5,'BUR001','Hamburguesa ClÃ¡sica','Carne de res, queso, lechuga, tomate y cebolla',150.00,100,2,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ”'),(6,'BUR002','Cheeseburger Bacon','Doble carne, doble queso cheddar y tocino crujiente',190.00,100,2,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ”'),(7,'BUR003','Hamburguesa de Pollo','Pechuga de pollo empanizada, queso y aderezo ranch',160.00,100,2,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ”'),(8,'BUR004','Monster Burger','Triple carne, aros de cebolla, queso y BBQ',220.00,100,2,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ”'),(9,'COM001','Combo Individual','Hamburguesa Cl?????sica + Papas + Refresco',195.00,100,3,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ¥¡'),(10,'COM002','Combo Pareja','12 Alitas + 2 Ordenes de Papas + 2 Refrescos',350.00,100,3,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ¥¡'),(11,'BEB001','Coca-Cola 500ml','Refresco de cola',25.00,200,4,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ¥¤'),(12,'BEB002','Limonada Natural','Limonada fresca 500ml',30.00,100,4,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ¥¤'),(13,'BEB003','Cerveza Nacional','Cerveza bien fr?????a',45.00,150,4,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ¥¤'),(14,'BEB004','TÃ© Helado','T????? helado de lim??????n',25.00,100,4,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ¥¤'),(15,'CMP001','Papas Fritas','Orden de papas fritas',50.00,100,5,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸŸ'),(16,'CMP002','Papas Gajo','Papas sazonadas tipo gajo',60.00,100,5,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸŸ'),(17,'CMP003','Aros de Cebolla','10 aros de cebolla empanizados',70.00,100,5,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸŸ'),(18,'CMP004','Dedos de Queso','6 dedos de queso con salsa marinara',90.00,50,5,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸŸ'),(19,'POS001','Brownie con Helado','Brownie de chocolate con bola de vainilla',85.00,30,6,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ°'),(20,'POS002','Cheesecake','Rebanada de cheesecake con fresa',95.00,30,6,NULL,'active','2026-03-31 14:24:27',NULL,'ðŸ½ï¸');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Admin'),(3,'Cajero'),(4,'Cocina'),(2,'Mesero');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'iva_percentage','15','2026-03-31 14:24:27'),(2,'company_name','Wings House','2026-03-31 19:53:32'),(3,'enable_tips','0','2026-03-31 15:50:00'),(5,'theme_effects_enabled','0','2026-03-31 19:08:16'),(12,'company_logo','uploads/logo_1775597414.png','2026-04-24 17:03:53'),(18,'show_company_name','0','2026-04-24 17:11:19');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `type` enum('Sale','Entry','Waste','Adjustment') NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reference_id` int(11) DEFAULT NULL COMMENT 'Order ID or other reference',
  `notes` text DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ingredient_id` (`ingredient_id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `stock_movements_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tables`
--

DROP TABLE IF EXISTS `tables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tables` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `status` enum('available','occupied','reserved') DEFAULT 'available',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tables`
--

LOCK TABLES `tables` WRITE;
/*!40000 ALTER TABLE `tables` DISABLE KEYS */;
INSERT INTO `tables` VALUES (1,'Mesa 1','occupied'),(2,'Mesa 2','occupied'),(3,'Mesa 3','available'),(4,'Mesa 4','available'),(5,'Mesa 5','available'),(6,'Mesa 6','available'),(7,'Mesa 7','available'),(8,'Mesa 8','available'),(9,'Barra','occupied');
/*!40000 ALTER TABLE `tables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_super_admin` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `role_id` (`role_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Administrador','admin@restocloud.com','admin','$2y$10$ca4jG0DKhXwRTTr8aG56Ae/2Uv.UNpQbXQMSFyXx3spqOVTi44OCm',1,'active','2026-03-31 14:24:27',0),(2,'Juan Mesero','juan@restocloud.com','mesero','$2y$10$UNBbIfsSG0JE5bymjBgoHuLDHRIKTktGXr2R8ZLkUer18mUktWeUe',2,'active','2026-03-31 14:24:27',0),(3,'Ana Cajera','ana@restocloud.com','cajero','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',3,'active','2026-03-31 14:24:27',0),(4,'Carlos Cocina','carlos@restocloud.com','cocina','$2y$10$DscPGILEN6uBbDn5Eub2DOrNvo1cXqFIglcO3VOi61mamZ86853sO',4,'active','2026-03-31 14:24:27',0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-15  8:35:21

