-- Add Superadmin Role
INSERT INTO roles (id, name) VALUES (5, 'Superadmin') ON DUPLICATE KEY UPDATE name = 'Superadmin';

-- Assign Superadmin role to the user with is_super_admin = 1
UPDATE users SET role_id = 5 WHERE is_super_admin = 1;
