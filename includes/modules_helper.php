<?php
/**
 * Modules Helper Functions
 * Provides robust modular access control and dynamic sidebar support
 */

/**
 * Check if the given role is an Admin / SuperAdmin
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @return bool True if Admin/SuperAdmin
 */
function isRoleAdmin($pdo, $role_id)
{
    // Check session super admin flag if set
    if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1) {
        return true;
    }

    // Role ID 1 is always the master Admin
    if ($role_id == 1) {
        return true;
    }

    // Check by role name in database
    try {
        $stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $stmt->execute([$role_id]);
        $name = strtolower(trim($stmt->fetchColumn() ?: ''));
        if ($name === 'admin' || $name === 'administrador' || $name === 'superadmin') {
            return true;
        }
    } catch (Exception $e) {
        // Fallback
    }

    return false;
}

/**
 * Ensure modules and role_modules tables exist and contain default definitions
 * @param PDO $pdo Database connection
 */
function ensureModulesSystem($pdo)
{
    static $ensured = false;
    if ($ensured) return;

    try {
        // Create modules table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS modules (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Check if is_sidebar column exists, add if not
        $checkCol = $pdo->query("SHOW COLUMNS FROM modules LIKE 'is_sidebar'");
        if ($checkCol->rowCount() == 0) {
            $pdo->exec("ALTER TABLE modules ADD COLUMN is_sidebar TINYINT(1) DEFAULT 1 AFTER display_order");
        }

        // Check if category column exists, add if not
        $checkCat = $pdo->query("SHOW COLUMNS FROM modules LIKE 'category'");
        if ($checkCat->rowCount() == 0) {
            $pdo->exec("ALTER TABLE modules ADD COLUMN category VARCHAR(50) DEFAULT 'General' AFTER is_sidebar");
        }

        // Create role_modules table if not exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_modules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            module_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_role_module (role_id, module_id),
            FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
            FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Default Modules Definitions
        $default_modules = [
            // --- TOP LEVEL SIDEBAR MODULES (13 Modules matching Sidebar) ---
            ['key' => 'dashboard',     'name' => 'Dashboard',      'icon' => 'bx bxs-dashboard',     'path' => 'inicio.php',             'order' => 1,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'menu',          'name' => 'Menú',           'icon' => 'bx bx-package',        'path' => 'productos.php',          'order' => 2,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'insumos',       'name' => 'Insumos',        'icon' => 'bx bx-layer',          'path' => 'inventario_insumos.php', 'order' => 3,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'recetas',       'name' => 'Recetas/Costos', 'icon' => 'bx bx-food-menu',      'path' => 'gestion_recetas.php',    'order' => 4,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'tables',        'name' => 'Mesas',          'icon' => 'bx bx-chair',          'path' => 'mesas.php',              'order' => 5,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'barra',         'name' => 'Barra',          'icon' => 'bx bx-shopping-bag',   'path' => 'mesas.php?tab=barra',    'order' => 6,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'kitchen',       'name' => 'Cocina',         'icon' => 'bx bx-restaurant',     'path' => 'cocina.php',             'order' => 7,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'cashier',       'name' => 'Caja',           'icon' => 'bx bx-dollar-circle',  'path' => 'caja.php',               'order' => 8,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'cuentas',       'name' => 'Cuentas',        'icon' => 'bx bx-receipt',        'path' => 'cuentas.php',            'order' => 9,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'pedidosya',     'name' => 'PedidosYa',      'icon' => 'bx bx-cycling',        'path' => 'pedidosya.php',          'order' => 10,  'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'reports',       'name' => 'Reportes',       'icon' => 'bx bx-bar-chart-alt-2','path' => 'reportes.php',          'order' => 11, 'is_sidebar' => 1, 'category' => 'Barra Lateral'],
            ['key' => 'users',         'name' => 'Usuarios',       'icon' => 'bx bx-user',           'path' => 'usuarios.php',           'order' => 12, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'settings',      'name' => 'Configuración',  'icon' => 'bx bx-cog',            'path' => 'configuracion.php',      'order' => 13, 'is_sidebar' => 1, 'category' => 'Barra Lateral'],

            // --- SPECIAL ACTIONS & SUB-PERMISSIONS ---
            ['key' => 'pedido_libre',          'name' => 'Pedido Libre (POS)',     'icon' => 'bx bx-cart',         'path' => 'mesas.php',                  'order' => 15, 'is_sidebar' => 0, 'category' => 'Operaciones'],
            ['key' => 'ver_asientos_barra',    'name' => 'Ver Asientos en Barra',  'icon' => 'bx bx-show',         'path' => 'mesas.php?tab=barra',        'order' => 15, 'is_sidebar' => 0, 'category' => 'Operaciones'],
            ['key' => 'inventory_edit',        'name' => 'Editar Productos',       'icon' => 'bx bx-edit',         'path' => 'productos.php',              'order' => 16, 'is_sidebar' => 0, 'category' => 'Menú'],
            ['key' => 'inventory_delete',      'name' => 'Eliminar Productos',     'icon' => 'bx bx-trash',        'path' => 'productos.php',              'order' => 17, 'is_sidebar' => 0, 'category' => 'Menú'],
            ['key' => 'config_invoices_manage','name' => 'Gestionar Facturas',     'icon' => 'bx bx-history',      'path' => 'gestion_facturas.php',       'order' => 18, 'is_sidebar' => 0, 'category' => 'Facturación'],
            ['key' => 'config_general',        'name' => 'Config. General',        'icon' => 'bx bx-slider',       'path' => 'configuracion.php#general',  'order' => 20, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_backup',         'name' => 'Respaldo BD',            'icon' => 'bx bx-cloud-download','path' => 'configuracion.php#backup',  'order' => 21, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_restore',        'name' => 'Restaurar BD',           'icon' => 'bx bx-cloud-upload',  'path' => 'configuracion.php#restore', 'order' => 22, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_menu_init',      'name' => 'Inicialización Menú',    'icon' => 'bx bx-rocket',        'path' => 'configuracion.php#menu',    'order' => 23, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_tables',         'name' => 'Gestión de Mesas',       'icon' => 'bx bx-chair',         'path' => 'configuracion.php#tables',  'order' => 24, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_barra',          'name' => 'Gestión de Barra',       'icon' => 'bx bx-coffee-togo',   'path' => 'configuracion.php#barra',   'order' => 24, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_invoicing',      'name' => 'Config. Facturación',    'icon' => 'bx bx-receipt',       'path' => 'configuracion.php#invoicing','order' => 25, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_reset',          'name' => 'Restablecer Sistema',    'icon' => 'bx bx-reset',         'path' => 'configuracion.php#reset',    'order' => 26, 'is_sidebar' => 0, 'category' => 'Configuración'],
            ['key' => 'config_modules',        'name' => 'Gestión Roles y Permisos','icon' => 'bx bx-shield-quarter','path' => 'configuracion.php#roles',   'order' => 27, 'is_sidebar' => 0, 'category' => 'Configuración'],
        ];

        $stmtCheck = $pdo->prepare("SELECT id FROM modules WHERE module_key = ?");
        $stmtInsert = $pdo->prepare("INSERT INTO modules (module_key, name, icon, file_path, display_order, is_sidebar, category, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        $stmtUpdate = $pdo->prepare("UPDATE modules SET name = ?, icon = ?, file_path = ?, display_order = ?, is_sidebar = ?, category = ? WHERE module_key = ?");

        foreach ($default_modules as $mod) {
            $stmtCheck->execute([$mod['key']]);
            if ($row = $stmtCheck->fetch()) {
                $stmtUpdate->execute([$mod['name'], $mod['icon'], $mod['path'], $mod['order'], $mod['is_sidebar'], $mod['category'], $mod['key']]);
            } else {
                $stmtInsert->execute([$mod['key'], $mod['name'], $mod['icon'], $mod['path'], $mod['order'], $mod['is_sidebar'], $mod['category']]);
            }
        }

        // Auto-assign default permissions if role_modules is empty
        $countAssignments = $pdo->query("SELECT COUNT(*) FROM role_modules")->fetchColumn();
        if ($countAssignments == 0) {
            // Assign all to Admin roles
            $roles = $pdo->query("SELECT id, name FROM roles")->fetchAll(PDO::FETCH_ASSOC);
            $allModuleIds = $pdo->query("SELECT id FROM modules")->fetchAll(PDO::FETCH_COLUMN);

            $stmtRoleModule = $pdo->prepare("INSERT IGNORE INTO role_modules (role_id, module_id) VALUES (?, ?)");

            foreach ($roles as $role) {
                $rName = strtolower(trim($role['name']));
                $rId = $role['id'];

                if ($rName === 'admin' || $rName === 'administrador' || $rName === 'superadmin' || $rId == 1) {
                    foreach ($allModuleIds as $mId) {
                        $stmtRoleModule->execute([$rId, $mId]);
                    }
                } elseif ($rName === 'mesero' || $rId == 2) {
                    $modKeys = ['tables', 'cuentas', 'pedido_libre'];
                    $inKeys = "'" . implode("','", $modKeys) . "'";
                    $mIds = $pdo->query("SELECT id FROM modules WHERE module_key IN ($inKeys)")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($mIds as $mId) {
                        $stmtRoleModule->execute([$rId, $mId]);
                    }
                } elseif ($rName === 'cajero' || $rId == 3) {
                    $modKeys = ['cashier', 'cuentas', 'pedidosya', 'tables'];
                    $inKeys = "'" . implode("','", $modKeys) . "'";
                    $mIds = $pdo->query("SELECT id FROM modules WHERE module_key IN ($inKeys)")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($mIds as $mId) {
                        $stmtRoleModule->execute([$rId, $mId]);
                    }
                } elseif ($rName === 'cocina' || $rId == 4) {
                    $modKeys = ['kitchen'];
                    $inKeys = "'" . implode("','", $modKeys) . "'";
                    $mIds = $pdo->query("SELECT id FROM modules WHERE module_key IN ($inKeys)")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($mIds as $mId) {
                        $stmtRoleModule->execute([$rId, $mId]);
                    }
                }
            }
        }

        $ensured = true;
    } catch (Exception $e) {
        // Table creation / seeding error fallback
    }
}

/**
 * Check if modules table exists
 * @param PDO $pdo Database connection
 * @return bool True if exists
 */
function modulesTableExists($pdo)
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'modules'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Map alias keys to standard keys for backwards compatibility
 * @param string $key
 * @return array
 */
function getModuleKeyAliases($key)
{
    $map = [
        'inventory' => ['menu', 'inventory'],
        'menu' => ['menu', 'inventory'],
        'caja' => ['cashier', 'caja', 'cashier_panel'],
        'cashier' => ['cashier', 'caja', 'cashier_panel'],
        'cashier_panel' => ['cashier', 'caja', 'cashier_panel'],
        'mesas' => ['tables', 'mesas'],
        'tables' => ['tables', 'mesas'],
        'recetas' => ['recetas', 'recipes'],
        'recipes' => ['recetas', 'recipes'],
        'insumos' => ['insumos', 'supplies'],
        'supplies' => ['insumos', 'supplies'],
        'cuentas' => ['cuentas', 'accounts'],
        'accounts' => ['cuentas', 'accounts'],
        'users' => ['users', 'usuarios'],
        'usuarios' => ['users', 'usuarios'],
        'settings' => ['settings', 'configuracion'],
        'configuracion' => ['settings', 'configuracion'],
        'reports' => ['reports', 'reportes'],
        'reportes' => ['reports', 'reportes'],
    ];

    return $map[$key] ?? [$key];
}

/**
 * Check if a role has access to a specific module
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @param string $module_key Module key
 * @return bool True if has access, false otherwise
 */
function hasModuleAccess($pdo, $role_id, $module_key)
{
    // Always grant access to Admin role
    if (isRoleAdmin($pdo, $role_id)) {
        return true;
    }

    ensureModulesSystem($pdo);

    $keys = getModuleKeyAliases($module_key);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));

    try {
        $params = array_merge([$role_id], $keys);
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM modules m
            INNER JOIN role_modules rm ON m.id = rm.module_id
            WHERE rm.role_id = ? AND m.module_key IN ($placeholders) AND m.is_active = 1
        ");
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($result && $result['count'] > 0);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get all modules assigned to a specific role
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @param bool $sidebar_only If true, returns only modules meant for the sidebar
 * @return array List of modules
 */
function getUserModules($pdo, $role_id, $sidebar_only = true)
{
    ensureModulesSystem($pdo);

    $isAdmin = isRoleAdmin($pdo, $role_id);
    $sidebarFilter = $sidebar_only ? " AND is_sidebar = 1" : "";

    try {
        if ($isAdmin) {
            // Admin gets all active modules
            $stmt = $pdo->query("
                SELECT * 
                FROM modules 
                WHERE is_active = 1 $sidebarFilter
                ORDER BY display_order ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $pdo->prepare("
            SELECT m.* 
            FROM modules m
            INNER JOIN role_modules rm ON m.id = rm.module_id
            WHERE rm.role_id = ? AND m.is_active = 1 $sidebarFilter
            ORDER BY m.display_order ASC
        ");
        $stmt->execute([$role_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get all available modules
 * @param PDO $pdo Database connection
 * @return array List of all modules
 */
function getAllModules($pdo)
{
    ensureModulesSystem($pdo);

    try {
        $stmt = $pdo->query('SELECT * FROM modules WHERE is_active = 1 ORDER BY is_sidebar DESC, display_order ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get all roles with their assigned modules
 * @param PDO $pdo Database connection
 * @return array Roles with modules
 */
function getRolesWithModules($pdo)
{
    ensureModulesSystem($pdo);

    try {
        $roles = $pdo->query('SELECT * FROM roles ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $stmt = $pdo->prepare('SELECT module_id FROM role_modules WHERE role_id = ?');
            $stmt->execute([$role['id']]);
            $role['modules'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // User count for this role
            $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
            $stmtCount->execute([$role['id']]);
            $role['user_count'] = $stmtCount->fetchColumn();

            $role['is_admin'] = isRoleAdmin($pdo, $role['id']);
        }

        return $roles;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Update module assignments for a role
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @param array $module_ids Array of module IDs to assign
 * @return bool Success status
 */
function updateRoleModules($pdo, $role_id, $module_ids)
{
    ensureModulesSystem($pdo);

    try {
        $pdo->beginTransaction();

        // Remove all current assignments for the role
        $stmt = $pdo->prepare('DELETE FROM role_modules WHERE role_id = ?');
        $stmt->execute([$role_id]);

        // Insert new assignments
        if (!empty($module_ids)) {
            $stmt = $pdo->prepare('INSERT INTO role_modules (role_id, module_id) VALUES (?, ?)');
            foreach ($module_ids as $module_id) {
                $stmt->execute([$role_id, intval($module_id)]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/**
 * Check access and redirect if not authorized
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @param string $module_key Module key
 * @param string $redirect_url URL to redirect to if not authorized (optional)
 */
function checkModuleAccess($pdo, $role_id, $module_key, $redirect_url = null)
{
    // SuperAdmin or Admin always has access
    if (isRoleAdmin($pdo, $role_id)) {
        return true;
    }

    ensureModulesSystem($pdo);

    if (!hasModuleAccess($pdo, $role_id, $module_key)) {
        // Find first accessible sidebar module for this user
        if ($redirect_url === null) {
            $user_modules = getUserModules($pdo, $role_id, true);
            foreach ($user_modules as $module) {
                if (strpos($module['module_key'], 'config_') === 0) {
                    continue;
                }
                $redirect_url = $module['file_path'];
                break;
            }
            // If no module found, go to "no access" page
            if ($redirect_url === null) {
                $redirect_url = 'sin_acceso.php';
            }
        }
        header('Location: ' . $redirect_url . '?error=no_access');
        exit();
    }

    return true;
}
