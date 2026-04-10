-- Script to remove the temporary superadmin user and expiration column
-- Run this script in phpMyAdmin or MySQL command line

-- Delete the demo admin user if it exists
DELETE FROM users WHERE username = 'demoadmin';

-- Note: The expires_at column can be dropped if desired, but it's optional
-- ALTER TABLE users DROP COLUMN IF EXISTS expires_at;
