-- Esquema de la base de datos actas_tic (referencia). El sistema crea estas tablas solo; no hace falta importarlo.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ajustes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ajustes` (
  `clave` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `colaboradores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `colaboradores` (
  `n` int NOT NULL AUTO_INCREMENT,
  `codigo` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `nombre` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `cedula` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `puesto` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `departamento` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `correo` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `usuario` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`n`),
  KEY `codigo` (`codigo`),
  KEY `cedula` (`cedula`),
  KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipos` (
  `n` int NOT NULL AUTO_INCREMENT,
  `hostname` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `usuario` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `nombre` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `codigo` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `departamento` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `correo` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `observacion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `so` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `modelo` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `serie` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `perifericos` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'monitor, teléfono, mouse, teclado y UPS (JSON)',
  PRIMARY KEY (`n`),
  KEY `hostname` (`hostname`),
  KEY `codigo` (`codigo`),
  KEY `serie` (`serie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `formularios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formularios` (
  `id` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `publicado` tinyint(1) NOT NULL DEFAULT '1',
  `autor` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `creado` int NOT NULL DEFAULT '0',
  `modificado` int NOT NULL DEFAULT '0',
  `modificado_por` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `def` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'definición del formulario (JSON)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `formularios_papelera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `formularios_papelera` (
  `clave` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `titulo` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `eliminado` int NOT NULL,
  `eliminado_por` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `def` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `intentos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `intentos` (
  `clave` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `n` int NOT NULL DEFAULT '0',
  `desde` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventario_pendiente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventario_pendiente` (
  `clave` char(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `datos` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `original` longblob,
  `creado` int NOT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventario_versiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventario_versiones` (
  `n` int NOT NULL AUTO_INCREMENT,
  `actualizado` int NOT NULL,
  `por` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `archivo` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `empleados` int NOT NULL DEFAULT '0',
  `equipos` int NOT NULL DEFAULT '0',
  `datos` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `original` longblob,
  `original_nombre` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`n`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pres_activos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pres_activos` (
  `id` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `codigo` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `tipo` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `descripcion` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `datos` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pres_pdf`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pres_pdf` (
  `id` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `doc` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pdf` longblob NOT NULL,
  `creado` int NOT NULL,
  PRIMARY KEY (`id`,`doc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pres_reservas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pres_reservas` (
  `id` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reservada',
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `departamento` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `inicio` int NOT NULL DEFAULT '0',
  `fin` int NOT NULL DEFAULT '0',
  `creado` int NOT NULL DEFAULT '0',
  `datos` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `estado` (`estado`),
  KEY `inicio` (`inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registros` (
  `form` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'acta_entrega, acta_devolucion, bitacora o f:<formulario>',
  `id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'número de registro',
  `nombre` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'colaborador o usuario principal',
  `fecha` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `datos` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'lo llenado (JSON), incluidas las firmas',
  `creado` int NOT NULL,
  `creado_por` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `modificado` int NOT NULL DEFAULT '0',
  `modificado_por` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `migrado` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`form`,`id`),
  KEY `form` (`form`,`creado`),
  KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registros_eliminados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `registros_eliminados` (
  `n` bigint NOT NULL AUTO_INCREMENT,
  `form` varchar(90) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `eliminado` int NOT NULL,
  `eliminado_por` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `doc` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`n`),
  KEY `form` (`form`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reset_tokens` (
  `token` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expira` int NOT NULL,
  PRIMARY KEY (`token`),
  KEY `usuario` (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tecnicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tecnicos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cedula` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `puesto` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `correo` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `usuario` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'cuenta del sistema (opcional)',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `orden` int NOT NULL DEFAULT '0',
  `creado` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `usuario` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `correo` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rol` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tecnico',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `datos` longtext COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'registro completo: hash de la contraseña, permisos, fechas',
  `actualizado` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `v_actas_devolucion`;
/*!50001 DROP VIEW IF EXISTS `v_actas_devolucion`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_actas_devolucion` AS SELECT 
 1 AS `numero`,
 1 AS `colaborador`,
 1 AS `cedula`,
 1 AS `dependencia`,
 1 AS `observaciones`,
 1 AS `fecha`,
 1 AS `funcionario_ti`,
 1 AS `creado_por`,
 1 AS `registrado`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_actas_entrega`;
/*!50001 DROP VIEW IF EXISTS `v_actas_entrega`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_actas_entrega` AS SELECT 
 1 AS `numero`,
 1 AS `colaborador`,
 1 AS `cedula`,
 1 AS `dependencia`,
 1 AS `cargo`,
 1 AS `tipo_equipo`,
 1 AS `fecha`,
 1 AS `funcionario_ti`,
 1 AS `creado_por`,
 1 AS `registrado`*/;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_prestamos`;
/*!50001 DROP VIEW IF EXISTS `v_prestamos`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `v_prestamos` AS SELECT 
 1 AS `numero`,
 1 AS `estado`,
 1 AS `solicitante`,
 1 AS `departamento`,
 1 AS `retiro`,
 1 AS `devolucion`,
 1 AS `reservado`*/;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `v_actas_devolucion`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`actas`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_actas_devolucion` AS select `registros`.`id` AS `numero`,`registros`.`nombre` AS `colaborador`,json_unquote(json_extract(`registros`.`datos`,'$.ced')) AS `cedula`,json_unquote(json_extract(`registros`.`datos`,'$.dep')) AS `dependencia`,json_unquote(json_extract(`registros`.`datos`,'$.obs')) AS `observaciones`,`registros`.`fecha` AS `fecha`,json_unquote(json_extract(`registros`.`datos`,'$.ent')) AS `funcionario_ti`,`registros`.`creado_por` AS `creado_por`,from_unixtime(`registros`.`creado`) AS `registrado` from `registros` where (`registros`.`form` = 'acta_devolucion') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_actas_entrega`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`actas`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_actas_entrega` AS select `registros`.`id` AS `numero`,`registros`.`nombre` AS `colaborador`,json_unquote(json_extract(`registros`.`datos`,'$.ced')) AS `cedula`,json_unquote(json_extract(`registros`.`datos`,'$.dep')) AS `dependencia`,json_unquote(json_extract(`registros`.`datos`,'$.cargo')) AS `cargo`,json_unquote(json_extract(`registros`.`datos`,'$.tipo')) AS `tipo_equipo`,`registros`.`fecha` AS `fecha`,json_unquote(json_extract(`registros`.`datos`,'$.ent')) AS `funcionario_ti`,`registros`.`creado_por` AS `creado_por`,from_unixtime(`registros`.`creado`) AS `registrado` from `registros` where (`registros`.`form` = 'acta_entrega') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_prestamos`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`actas`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_prestamos` AS select `pres_reservas`.`id` AS `numero`,`pres_reservas`.`estado` AS `estado`,`pres_reservas`.`nombre` AS `solicitante`,`pres_reservas`.`departamento` AS `departamento`,from_unixtime(`pres_reservas`.`inicio`) AS `retiro`,from_unixtime(`pres_reservas`.`fin`) AS `devolucion`,from_unixtime(`pres_reservas`.`creado`) AS `reservado` from `pres_reservas` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

