CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(512) DEFAULT NULL,
  `user_url` varchar(512) DEFAULT NULL,
  `email` varchar(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `redirect_uris` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `redirect_uri` varchar(512) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `logins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `date` datetime DEFAULT NULL,
  `client_id` varchar(512) DEFAULT NULL,
  `redirect_uri` varchar(512) DEFAULT NULL,
  `authn_provider` varchar(20) DEFAULT NULL,
  `authn_profile` varchar(512) DEFAULT NULL,
  `me_entered` varchar(512) DEFAULT NULL,
  `me_resolved` varchar(512) DEFAULT NULL,
  `complete` tinyint(4) NOT NULL DEFAULT '0',
  `date_complete` datetime DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
