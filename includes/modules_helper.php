<?php
/**
 * Modules Helper Functions
 * Provides functions for module-based access control
 */

/**
 * Get all modules assigned to a specific role
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @return array List of modules
 */
function getUserModules($pdo, $role_id)
{
    try {
        $stmt = $pdo->prepare('
            SELECT m.* 
            FROM modules m
            INNER JOIN role_modules rm ON m.id = rm.module_id
            WHERE rm.role_id = ? AND m.is_active = 1
            ORDER BY m.display_order ASC
        ');
        $stmt->execute([$role_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if a role has access to a specific module
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @param string $module_key Module key (e.g., 'dashboard', 'inventory')
 * @return bool True if has access, false otherwise
 */
function hasModuleAccess($pdo, $role_id, $module_key)
{
    // Always allow Admin (1) and SuperAdmin (5)
    if ($role_id == 1 || $role_id == 5) {
        return true;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count
            FROM modules m
            INNER JOIN role_modules rm ON m.id = rm.module_id
            WHERE rm.role_id = ? AND m.module_key = ? AND m.is_active = 1
        ');
        $stmt->execute([$role_id, $module_key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get all available modules
 * @param PDO $pdo Database connection
 * @return array List of all modules
 */
function getAllModules($pdo)
{
    try {
        $stmt = $pdo->query('SELECT * FROM modules WHERE is_active = 1 ORDER BY display_order ASC');
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
    try {
        $roles = $pdo->query('SELECT * FROM roles ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

        foreach ($roles as &$role) {
            $stmt = $pdo->prepare('
                SELECT module_id FROM role_modules WHERE role_id = ?
            ');
            $stmt->execute([$role['id']]);
            $role['modules'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
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
    try {
        $pdo->beginTransaction();

        // Remove all current assignments for the role
        $stmt = $pdo->prepare('DELETE FROM role_modules WHERE role_id = ?');
        $stmt->execute([$role_id]);

        // Insert new assignments
        if (!empty($module_ids)) {
            $stmt = $pdo->prepare('INSERT INTO role_modules (role_id, module_id) VALUES (?, ?)');
            foreach ($module_ids as $module_id) {
                $stmt->execute([$role_id, $module_id]);
            }
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}

/**
 * Check if modules table exists (for graceful degradation)
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
 * Check access and redirect if not authorized
 * @param PDO $pdo Database connection
 * @param int $role_id Role ID
 * @param string $module_key Module key
 * @param string $redirect_url URL to redirect to if not authorized (optional)
 */
function checkModuleAccess($pdo, $role_id, $module_key, $redirect_url = null)
{
    // SuperAdmin always has access (check by session flag)
    if (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1) {
        return true;
    }

    // If modules table doesn't exist yet, allow access (for migration period)
    if (!modulesTableExists($pdo)) {
        return true;
    }

    if (!hasModuleAccess($pdo, $role_id, $module_key)) {
        // Find the first accessible module for this user (excluding config sub-modules)
        if ($redirect_url === null) {
            $user_modules = getUserModules($pdo, $role_id);
            foreach ($user_modules as $module) {
                // Skip config sub-modules
                if (strpos($module['module_key'], 'config_') === 0) {
                    continue;
                }
                $redirect_url = $module['file_path'];
                break;
            }
            // If no modules found, go to "no access" page (prevents infinite loop)
            if ($redirect_url === null) {
                $redirect_url = 'sin_acceso.php';
            }
        }
        header('Location: ' . $redirect_url . '?error=no_access');
        exit();
    }

    return true;
}
