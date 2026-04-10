-- Migration: Add configuration sub-modules
-- Run this script in phpMyAdmin after the initial modules migration

-- Insert configuration sub-modules
INSERT INTO modules (module_key, name, icon, file_path, display_order, is_active) VALUES
('config_general', 'Config. General', '🏢', 'configuracion.php#general', 20),
('config_backup', 'Respaldo BD', '💾', 'configuracion.php#backup', 21),
('config_restore', 'Restaurar BD', '📤', 'configuracion.php#restore', 22),
('config_menu_init', 'Inicialización Menú', '🚀', 'configuracion.php#menu', 23),
('config_invoicing', 'Config. Facturación', '💰', 'configuracion.php#invoicing', 24),
('config_reset', 'Restablecer Sistema', '⚠️', 'configuracion.php#reset', 25),
('config_modules', 'Gestión Módulos', '🔧', 'configuracion.php#modules', 26);

-- Assign all config sub-modules to SuperAdmin (id=1 based on your database)
INSERT INTO role_modules (role_id, module_id)
SELECT 1, id FROM modules WHERE module_key LIKE 'config_%';

-- Assign all config sub-modules to Admin (id=5 based on your database)
INSERT INTO role_modules (role_id, module_id)
SELECT 5, id FROM modules WHERE module_key LIKE 'config_%';
