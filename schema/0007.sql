/* Tables for the admin activity page, which counts each month once.

   Everything in them is derived from logins and can be counted again at any
   time: empty both tables and run bin/count-activity. They used to be kept in
   Redis, where losing the set of everyone seen without losing the months
   would have counted everyone as new from then on, and said nothing.

   This file is additive and can be run while the site is up. */

/* One row per month that has ended, written once */
CREATE TABLE IF NOT EXISTS `activity_months` (
  `month` char(7) NOT NULL,
  `signins` int(11) unsigned NOT NULL,
  `completed` int(11) unsigned NOT NULL,
  `people` int(11) unsigned NOT NULL,
  `new_people` int(11) unsigned NOT NULL,
  `clients` int(11) unsigned NOT NULL,
  /* JSON: sign-ins by authn_provider exactly as recorded, so that how they
     are grouped on the chart can change without counting again */
  `providers` text NOT NULL,
  `date_counted` datetime NOT NULL,
  PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

/* Everyone who has signed in, by the first 8 bytes of the SHA-1 of their
   me_resolved, and the month they were first seen. Whether someone is new is
   whether inserting them adds a row. */
CREATE TABLE IF NOT EXISTS `activity_people` (
  `person` binary(8) NOT NULL,
  `first_month` char(7) NOT NULL,
  PRIMARY KEY (`person`),
  KEY `first_month` (`first_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
