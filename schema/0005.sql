/* Removes the denormalized ownership columns that 0004.sql migrated onto the
   users table. This one is destructive and cannot be undone, so run it only
   after confirming the backfill in 0004.sql landed:

     SELECT COUNT(*) FROM clients WHERE NULLIF(user_url,'') IS NOT NULL AND user_id IS NULL;

   That has to return 0. It is also worth checking that the number of users
   matches the number of distinct owners you started with:

     SELECT COUNT(*) FROM users;
     SELECT COUNT(DISTINCT user_url) FROM clients WHERE NULLIF(user_url,'') IS NOT NULL; */

ALTER TABLE clients
DROP COLUMN user_url;

/* clients.email is deliberately kept.

   Contact addresses for developers who have a user record now live on
   users.email, but 11 clients carry an email address with no user_url beside
   it, so no user row could be created for them. That column is the only
   remaining record of who owns those clients, and dropping it would destroy
   it. Nothing reads the column; treat it as legacy contact data, and migrate
   a row by hand if its owner is ever identified:

     SELECT id, client_id, email FROM clients
      WHERE NULLIF(email,'') IS NOT NULL AND user_id IS NULL; */
