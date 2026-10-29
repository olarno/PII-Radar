-- MySQL dump 10.13  Distrib 8.0.36, for Linux (x86_64)
--
-- Host: localhost    Database: boutique
-- ------------------------------------------------------

/*!40101 SET NAMES utf8mb4 */;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
CREATE TABLE IF NOT EXISTS `boutique`.`clients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL COMMENT 'Prénom, tel que saisi (forme libre)',
  `email` varchar(255) DEFAULT NULL,
  `statut` enum('actif','inactif') NOT NULL DEFAULT 'actif',
  `solde` decimal(8,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_name` (`first_name`),
  CONSTRAINT `fk_x` FOREIGN KEY (`id`) REFERENCES `comptes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Clients (B2C)';

INSERT INTO `clients` VALUES (1,'Jeanne','jeanne@example.test','actif',0.00);
