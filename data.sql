CREATE TABLE `pagestatus` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `wiki` varchar(32) NOT NULL,
  `page` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `status` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `message` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `timestamp` varchar(14) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `query_sparql` tinytext COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `page` (`wiki`, `page`(100))
);
