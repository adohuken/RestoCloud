-- Migration 004: Modular Sidebar and Role Permissions System
-- restocloud database

CREATE TABLE IF NOT EXISTS modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(50) DEFAULT '',
    file_path VARCHAR(100) NOT NULL,
    display_order INT DEFAULT 0,
    is_sidebar TINYINT(1) DEFAULT 1,
    category VARCHAR(50) DEFAULT 'General',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    module_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_module (role_id, module_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert or Update default modules
INSERT INTO modules (module_key, name, icon, file_path, display_order, is_sidebar, category, is_active) VALUES
('dashboard', 'Dashboard', 'bx bxs-dashboard', 'inicio.php', 1, 1, 'Barra Lateral', 1),
('menu', 'Menú', 'bx bx-package', 'productos.php', 2, 1, 'Barra Lateral', 1),
('insumos', 'Insumos', 'bx bx-layer', 'inventario_insumos.php', 3, 1, 'Barra Lateral', 1),
('recetas', 'Recetas/Costos', 'bx bx-food-menu', 'gestion_recetas.php', 4, 1, 'Barra Lateral', 1),
('tables', 'Mesas', 'bx bx-chair', 'mesas.php', 5, 1, 'Barra Lateral', 1),
('kitchen', 'Cocina', 'bx bx-restaurant', 'cocina.php', 6, 1, 'Barra Lateral', 1),
('cashier', 'Caja', 'bx bx-dollar-circle', 'caja.php', 7, 1, 'Barra Lateral', 1),
('cuentas', 'Cuentas', 'bx bx-receipt', 'cuentas.php', 8, 1, 'Barra Lateral', 1),
('pedidosya', 'PedidosYa', 'bx bx-cycling', 'pedidosya.php', 9, 1, 'Barra Lateral', 1),
('reports', 'Reportes', 'bx bx-bar-chart-alt-2', 'reportes.php', 10, 1, 'Barra Lateral', 1),
('users', 'Usuarios', 'bx bx-user', 'usuarios.php', 11, 1, 'Barra Lateral', 1),
('settings', 'Configuración', 'bx bx-cog', 'configuracion.php', 12, 1, 'Barra Lateral', 1),
('pedido_libre', 'Pedido Libre (POS)', 'bx bx-cart', 'mesas.php', 15, 0, 'Operaciones', 1),
('inventory_edit', 'Editar Productos', 'bx bx-edit', 'productos.php', 16, 0, 'Menú', 1),
('inventory_delete', 'Eliminar Productos', 'bx bx-trash', 'productos.php', 17, 0, 'Menú', 1),
('config_invoices_manage', 'Gestionar Facturas', 'bx bx-history', 'gestion_facturas.php', 18, 0, 'Facturación', 1),
('config_general', 'Config. General', 'bx bx-slider', 'configuracion.php#general', 20, 0, 'Configuración', 1),
('config_backup', 'Respaldo BD', 'bx bx-cloud-download', 'configuracion.php#backup', 21, 0, 'Configuración', 1),
('config_restore', 'Restaurar BD', 'bx bx-cloud-upload', 'configuracion.php#restore', 22, 0, 'Configuración', 1),
('config_menu_init', 'Inicialización Menú', 'bx bx-rocket', 'configuracion.php#menu', 23, 0, 'Configuración', 1),
('config_tables', 'Gestión de Mesas', 'bx bx-chair', 'configuracion.php#tables', 24, 0, 'Configuración', 1),
('config_invoicing', 'Config. Facturación', 'bx bx-receipt', 'configuracion.php#invoicing', 25, 0, 'Configuración', 1),
('config_reset', 'Restablecer Sistema', 'bx bx-reset', 'configuracion.php#reset', 26, 0, 'Configuración', 1),
('config_modules', 'Gestión Roles y Permisos', 'bx bx-shield-quarter', 'configuracion.php#roles', 27, 0, 'Configuración', 1)
ON DUPLICATE KEY UPDATE 
    name = VALUES(name),
    icon = VALUES(icon),
    file_path = VALUES(file_path),
    display_order = VALUES(display_order),
    is_sidebar = VALUES(is_sidebar),
    category = VALUES(category),
    is_active = VALUES(is_active);

-- Admin & SuperAdmin full access
INSERT IGNORE INTO role_modules (role_id, module_id)
SELECT 1, id FROM modules;

-- Mesero
INSERT IGNORE INTO role_modules (role_id, module_id)
SELECT 2, id FROM modules WHERE module_key IN ('tables', 'cuentas', 'pedido_libre');

-- Cajero
INSERT IGNORE INTO role_modules (role_id, module_id)
SELECT 3, id FROM modules WHERE module_key IN ('cashier', 'cuentas', 'pedidosya', 'tables');

-- Cocina
INSERT IGNORE INTO role_modules (role_id, module_id)
SELECT 4, id FROM modules WHERE module_key IN ('kitchen');
