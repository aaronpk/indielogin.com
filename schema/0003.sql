ALTER TABLE clients
ADD COLUMN date_created datetime DEFAULT NULL;

ALTER TABLE clients
ADD COLUMN date_last_used datetime DEFAULT NULL;


/* New clients will require PKCE */
ALTER TABLE clients
ADD COLUMN pkce_required TINYINT(4) NOT NULL DEFAULT 1;
/* Set pkce_required = 0 for all existing clients */
UPDATE clients SET pkce_required = 0;

