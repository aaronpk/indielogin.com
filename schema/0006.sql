/* Indexes for the admin section.

   logins has had only its primary key. The admin overview counts recent
   sign-ins by date, and its pages look up sign-ins by client and by the
   person's URL; without these, each of those reads the whole table.

   This file is additive and can be run while the site is up. Adding an index
   to a large table takes a while, though MariaDB builds it without blocking
   the inserts the sign-in flow makes. To see how long to expect:

     SELECT COUNT(*) FROM logins; */

ALTER TABLE logins
ADD KEY `date` (`date`),
ADD KEY `client_id` (`client_id`(191)),
ADD KEY `me_resolved` (`me_resolved`(191));
