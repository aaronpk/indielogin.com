/* Developer accounts, so that client IDs can be self-registered.

   This file is additive and can be run while the site is up. It ends by
   moving the ownership data that lived in clients.user_url onto a real
   users table; the old columns are dropped separately in 0005.sql so the
   backfill can be checked first. */

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(512) NOT NULL,
  `email` varchar(512) DEFAULT NULL,
  `date_created` datetime DEFAULT NULL,
  `date_last_login` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url` (`url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/* The collation has to be stated explicitly. The existing tables are
   utf8mb3_general_ci, but that is no longer the server default for utf8mb3 on
   newer MariaDB, and a users.url created with the newer default cannot be
   joined against clients.user_url below. */

/* Which developer registered each client */
ALTER TABLE clients
ADD COLUMN user_id int(11) unsigned DEFAULT NULL,
ADD KEY `user_id` (`user_id`);

/* Set to 0 by hand to disable a client. An inactive client is refused at the
   authorization endpoint, and its owner sees a warning on their dashboard. */
ALTER TABLE clients
ADD COLUMN active TINYINT(4) NOT NULL DEFAULT 1;

/* Create one user per distinct owner recorded on the existing clients.

   Every user_url in production is already in the canonical form that
   IndieAuth\Client::normalizeMeURL produces (https://host/), which is also
   what the developer login will match on, so grouping on the raw column is
   safe here. Check that this still holds before running:

     SELECT DISTINCT user_url FROM clients WHERE NULLIF(user_url,'') IS NOT NULL;

   Where one person has several clients carrying different email addresses,
   MIN() keeps one of them arbitrarily. No such rows existed at the time this
   was written:

     SELECT user_url, COUNT(DISTINCT NULLIF(email,'')) c FROM clients
       WHERE NULLIF(user_url,'') IS NOT NULL GROUP BY user_url HAVING c > 1; */
INSERT INTO users (url, email, date_created)
SELECT user_url, MIN(NULLIF(email, '')), NOW()
  FROM clients
 WHERE NULLIF(user_url, '') IS NOT NULL
 GROUP BY user_url;

UPDATE clients c
  JOIN users u ON u.url = c.user_url
   SET c.user_id = u.id;

/* Two people must not be able to register the same client ID. This will fail
   if duplicates already exist; there were none at the time this was written:

     SELECT client_id, COUNT(*) n FROM clients GROUP BY client_id HAVING n > 1; */
ALTER TABLE clients
ADD UNIQUE KEY `client_id_unique` (`client_id`(191));

/* The unique key above serves every lookup the old non-unique index did */
ALTER TABLE clients
DROP KEY `client_id`;
