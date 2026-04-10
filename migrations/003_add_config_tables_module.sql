-- Add config_tables module for table management in configuration
-- Run this in phpMyAdmin

INSERT INTO modules (module_key, name, icon, file_path, display_order) VALUES 
('config_tables', 'Gestión de Mesas', '🪑', 'configuracion.php', 1);

-- Assign to SuperAdmin (role_id = 5) and Admin (role_id = 1)
INSERT INTO role_modules (role_id, module_id) 
SELECT 5, id FROM modules WHERE module_key = 'config_tables';

INSERT INTO role_modules (role_id, module_id) 
SELECT 1, id FROM modules WHERE module_key = 'config_tables';
