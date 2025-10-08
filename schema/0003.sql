ALTER TABLE clients
ADD COLUMN date_created datetime DEFAULT NULL;

ALTER TABLE clients
ADD COLUMN date_last_used datetime DEFAULT NULL;
