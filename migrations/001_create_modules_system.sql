-- Migration: Add modules system for role-based access control
-- Run this script in phpMyAdmin or MySQL command line

-- Create modules table
CREATE TABLE IF NOT EXISTS modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_key VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(10) DEFAULT '',
    file_path VARCHAR(100) NOT NULL,
    display_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create role_modules table (many-to-many relationship)
CREATE TABLE IF NOT EXISTS role_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    module_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_role_module (role_id, module_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default modules
INSERT INTO modules (module_key, name, icon, file_path, display_order) VALUES
('dashboard', 'Dashboard', '📊', 'inicio.php', 1),
('inventory', 'Inventario', '📦', 'productos.php', 2),
('tables', 'Mesas', '🪑', 'mesas.php', 3),
('pos', 'POS', '💳', 'venta.php', 4),
('kitchen', 'Cocina', '👨‍🍳', 'cocina.php', 5),
('cashier', 'Caja', '💰', 'caja.php', 6),
('cashier_panel', 'Panel Cajero', '🧾', 'panel_cajero.php', 7),
('pedidosya', 'PedidosYa', '🛵', 'pedidosya.php', 8),
('reports', 'Reportes', '📈', 'reportes.php', 9),
('users', 'Usuarios', '👥', 'usuarios.php', 10),
('settings', 'Configuración', '⚙️', 'configuracion.php', 11);

-- Assign all modules to Admin (role_id = 1) and SuperAdmin (role_id = 5)
INSERT INTO role_modules (role_id, module_id)
SELECT 1, id FROM modules WHERE is_active = 1;

INSERT INTO role_modules (role_id, module_id)
SELECT 5, id FROM modules WHERE is_active = 1;

-- Assign Mesas module to Mesero (role_id = 2)
INSERT INTO role_modules (role_id, module_id)
SELECT 2, id FROM modules WHERE module_key = 'tables';

-- Assign Cashier Panel and Caja modules to Cajero (role_id = 3)
INSERT INTO role_modules (role_id, module_id)
SELECT 3, id FROM modules WHERE module_key IN ('cashier', 'cashier_panel', 'pedidosya');

-- Assign Cocina module to Cocina (role_id = 4)
INSERT INTO role_modules (role_id, module_id)
SELECT 4, id FROM modules WHERE module_key = 'kitchen';
