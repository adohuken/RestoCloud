<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access for settings (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'settings');

// Ensure settings table exists (Self-healing)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    // Ensure deleted_invoices_log table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS deleted_invoices_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_invoice_id INT NOT NULL,
        order_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(50),
        deleted_by INT NOT NULL,
        deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reason TEXT
    ) ENGINE=InnoDB");

    // Ensure default IVA setting
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'iva_percentage'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('iva_percentage', '0')");
    }

    // Ensure modules and roles permission system exists
    ensureModulesSystem($pdo);
} catch (Exception $e) {
    // Silent fail or log if needed
}

$success_msg = '';
$error_msg = '';

// Handle Backup
if (isset($_POST['backup'])) {
    // Security check: Only SuperAdmin can backup
    if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] != 1) {
        $error_msg = 'Acceso denegado. Solo el SuperAdmin puede realizar respaldos.';
    } else {
        $tables = [];
        $stmt = $pdo->query('SHOW TABLES');
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $sqlScript = "-- Backup de Base de Datos: RestoCloud_system\n";
        $sqlScript .= "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
        $sqlScript .= "-- Generado por RestoCloud System\n\n";
        $sqlScript .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $sqlScript .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sqlScript .= "SET time_zone = \"+00:00\";\n\n";

        foreach ($tables as $table) {
            // Add DROP TABLE IF EXISTS
            $sqlScript .= "-- --------------------------------------------------------\n";
            $sqlScript .= "-- Estructura de tabla para la tabla `$table`\n";
            $sqlScript .= "-- --------------------------------------------------------\n\n";
            $sqlScript .= "DROP TABLE IF EXISTS `$table`;\n";

            // Get CREATE TABLE statement
            $stmt = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $createTable = $row[1];

            // Replace CREATE TABLE with CREATE TABLE IF NOT EXISTS
            $createTable = str_replace('CREATE TABLE', 'CREATE TABLE IF NOT EXISTS', $createTable);
            $sqlScript .= $createTable . ";\n\n";

            // Get table data
            $stmt = $pdo->query('SELECT * FROM `' . $table . '`');
            $columnCount = $stmt->columnCount();
            $rowCount = 0;

            if ($columnCount > 0) {
                $sqlScript .= "-- Volcado de datos para la tabla `$table`\n\n";

                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $sqlScript .= "INSERT INTO `$table` VALUES (";
                    for ($j = 0; $j < $columnCount; $j++) {
                        if (isset($row[$j])) {
                            $sqlScript .= '"' . addslashes($row[$j]) . '"';
                        } else {
                            $sqlScript .= 'NULL';
                        }
                        if ($j < ($columnCount - 1)) {
                            $sqlScript .= ', ';
                        }
                    }
                    $sqlScript .= ");\n";
                    $rowCount++;
                }
                $sqlScript .= "\n";
            }
        }

        $sqlScript .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        if (!empty($sqlScript)) {
            // Save the SQL script to a backup file
            $backup_file_name = 'backup_RestoCloud_system_' . date('Y-m-d_H-i-s') . '.sql';

            header('Content-Type: application/octet-stream');
            header("Content-Transfer-Encoding: Binary");
            header("Content-disposition: attachment; filename=\"" . $backup_file_name . "\"");
            echo $sqlScript;
            exit;
        }
    }
}

// Handle Database Restore
if (isset($_POST['restore_db'])) {
    // Security check: Only SuperAdmin can restore
    if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] != 1) {
        $error_msg = 'Acceso denegado. Solo el SuperAdmin puede restaurar la base de datos.';
    } else {
        $super_admin_username = $_POST['super_admin_username'] ?? '';
        $super_admin_password = $_POST['super_admin_password'] ?? '';

        // Verify Super Admin credentials
        $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? AND is_super_admin = 1');
        $stmt->execute([$super_admin_username]);
        $super_admin = $stmt->fetch();

        if ($super_admin && password_verify($super_admin_password, $super_admin['password'])) {
            // Check if file was uploaded
            if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] == 0) {
                $file_tmp = $_FILES['sql_file']['tmp_name'];
                $file_name = $_FILES['sql_file']['name'];
                $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);

                // Validate file extension
                if (strtolower($file_ext) !== 'sql') {
                    $error_msg = 'Error: Solo se permiten archivos .sql';
                } else if ($_FILES['sql_file']['size'] > 50 * 1024 * 1024) { // 50MB limit
                    $error_msg = 'Error: El archivo es demasiado grande (máximo 50MB)';
                } else {
                    try {
                        // Read SQL file
                        $sql_content = file_get_contents($file_tmp);

                        if ($sql_content === false) {
                            $error_msg = 'Error: No se pudo leer el archivo SQL';
                        } else {
                            // Disable foreign key checks and autocommit
                            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                            $pdo->exec('SET AUTOCOMMIT = 0');

                            try {
                                // Split SQL into individual statements
                                $statements = array_filter(
                                    array_map(
                                        'trim',
                                        preg_split('/;\s*$/m', $sql_content)
                                    ),
                                    function ($stmt) {
                                        return !empty($stmt) &&
                                            !preg_match('/^\s*--/', $stmt) &&
                                            !preg_match('/^\s*\/\*/', $stmt);
                                    }
                                );

                                // Execute each statement
                                foreach ($statements as $statement) {
                                    if (!empty(trim($statement))) {
                                        // Convert INSERT to REPLACE to handle duplicates
                                        $modified_statement = preg_replace('/^INSERT INTO/i', 'REPLACE INTO', $statement);
                                        $pdo->exec($modified_statement);
                                    }
                                }

                                // Commit transaction
                                $pdo->exec('COMMIT');
                                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                                $pdo->exec('SET AUTOCOMMIT = 1');

                                $success_msg = 'Base de datos restaurada exitosamente desde el archivo: ' . htmlspecialchars($file_name);

                            } catch (Exception $e) {
                                // Rollback on error
                                try {
                                    $pdo->exec('ROLLBACK');
                                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                                    $pdo->exec('SET AUTOCOMMIT = 1');
                                } catch (Exception $ex) {
                                }

                                $error_msg = 'Error al restaurar la base de datos: ' . $e->getMessage();
                            }
                        }
                    } catch (Exception $e) {
                        $error_msg = 'Error al procesar el archivo: ' . $e->getMessage();
                    }
                }
            } else {
                $error_msg = 'Error: No se seleccionó ningún archivo o hubo un error en la carga.';
            }
        } else {
            $error_msg = 'Credenciales de SuperAdmin incorrectas. No se realizó la restauración.';
        }
    }
}


// Handle Reset
if (isset($_POST['reset_system'])) {
    // Security check: Only SuperAdmin can reset system
    if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] != 1) {
        $error_msg = 'Acceso denegado. Solo el SuperAdmin puede restablecer el sistema.';
    } else {
        $super_admin_username = $_POST['super_admin_username'] ?? '';
        $super_admin_password = $_POST['super_admin_password'] ?? '';

        // Verify Super Admin credentials
        $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? AND is_super_admin = 1');
        $stmt->execute([$super_admin_username]);
        $super_admin = $stmt->fetch();

        if ($super_admin && password_verify($super_admin_password, $super_admin['password'])) {
            try {
                // Disable foreign key checks
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

                // Truncate transactional tables (TRUNCATE auto-commits in MySQL)
                $pdo->exec('TRUNCATE TABLE order_details');
                $pdo->exec('TRUNCATE TABLE payments');
                $pdo->exec('TRUNCATE TABLE orders');

                // Check if invoices table exists before truncating
                $stmt = $pdo->query("SHOW TABLES LIKE 'invoices'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec('TRUNCATE TABLE invoices');
                }

                $pdo->exec('TRUNCATE TABLE cash_register');

                // Delete non-super-admin users (using DELETE instead of TRUNCATE to preserve Super Admin)
                $stmt = $pdo->prepare('DELETE FROM users WHERE is_super_admin != 1 OR is_super_admin IS NULL');
                $stmt->execute();

                // Reset other tables
                $pdo->exec('TRUNCATE TABLE products');
                $pdo->exec('TRUNCATE TABLE categories');
                $pdo->exec('TRUNCATE TABLE tables');

                // Re-enable foreign key checks
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

                $success_msg = 'El sistema ha sido restablecido correctamente.';
            } catch (Exception $e) {
                // Re-enable foreign keys in case of error
                try {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Exception $ex) {
                    // Ignore if this fails
                }
                $error_msg = 'Error al restablecer el sistema: ' . $e->getMessage();
            }
        } else {
            $error_msg = 'Credenciales de Super Admin incorrectas. No se realizaron cambios.';
        }
    }
}

// Handle IVA Update
if (isset($_POST['update_iva'])) {
    $iva_percentage = $_POST['iva_percentage'];
    $enable_tips = isset($_POST['enable_tips']) && $_POST['enable_tips'] === '1' ? '1' : '0';

    // Update or insert setting
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('iva_percentage', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$iva_percentage, $iva_percentage]);
    
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('enable_tips', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$enable_tips, $enable_tips]);

    $success_msg = 'Configuración de facturación actualizada correctamente.';
}

// Handle General Settings Update
if (isset($_POST['update_general'])) {
    $company_name = $_POST['company_name'];
    $theme_effects_enabled = isset($_POST['theme_effects_enabled']) && $_POST['theme_effects_enabled'] === '1' ? '1' : '0';
    $show_company_name = isset($_POST['show_company_name']) && $_POST['show_company_name'] === '1' ? '1' : '0';

    // Update Company Name
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$company_name, $company_name]);

    // Update Theme Effects
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('theme_effects_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$theme_effects_enabled, $theme_effects_enabled]);

    // Update Company Name Visibility
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('show_company_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$show_company_name, $show_company_name]);

    // Handle Logo Upload
    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $filename = $_FILES['company_logo']['name'];
        $filetype = $_FILES['company_logo']['type'];
        $filesize = $_FILES['company_logo']['size'];

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), $allowed)) {
            $error_msg = 'Formato de archivo no válido. Solo JPG, PNG, GIF y WEBP.';
        } elseif ($filesize > 5 * 1024 * 1024) {
            $error_msg = 'El archivo es demasiado grande. Máximo 5MB.';
        } else {
            // Create uploads directory if not exists
            if (!file_exists('uploads')) {
                mkdir('uploads', 0777, true);
            }

            $new_filename = 'logo_' . time() . '.' . $ext;
            $destination = 'uploads/' . $new_filename;

            if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $destination)) {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$destination, $destination]);
            } else {
                $error_msg = 'Error al subir el archivo.';
            }
        }
    }

    if (empty($error_msg)) {
        $success_msg = 'Configuración general actualizada correctamente.';
    }
}

// Handle Role & Module Management Actions
if (isset($_POST['update_role_modules'])) {
    if (isRoleAdmin($pdo, $_SESSION['role_id'])) {
        $role_id = intval($_POST['role_id'] ?? 0);
        $module_ids = isset($_POST['module_ids']) ? array_map('intval', $_POST['module_ids']) : [];

        // If trying to edit Admin role, enforce all modules
        if (isRoleAdmin($pdo, $role_id)) {
            $module_ids = $pdo->query("SELECT id FROM modules WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        }

        if (updateRoleModules($pdo, $role_id, $module_ids)) {
            $success_msg = 'Permisos de módulos actualizados exitosamente para el rol.';
        } else {
            $error_msg = 'Error al guardar los permisos de los módulos.';
        }
    } else {
        $error_msg = 'Acceso denegado. Solo administradores pueden gestionar permisos.';
    }
}

// Handle Create Role
if (isset($_POST['create_role'])) {
    if (isRoleAdmin($pdo, $_SESSION['role_id'])) {
        $role_name = trim($_POST['role_name'] ?? '');
        if (empty($role_name)) {
            $error_msg = 'Debe ingresar un nombre para el nuevo rol.';
        } else {
            try {
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE LOWER(name) = LOWER(?)");
                $stmtCheck->execute([$role_name]);
                if ($stmtCheck->fetchColumn() > 0) {
                    $error_msg = 'Ya existe un rol con la denominación "' . htmlspecialchars($role_name) . '".';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO roles (name) VALUES (?)");
                    $stmt->execute([$role_name]);
                    $new_role_id = $pdo->lastInsertId();

                    // Automatically assign default basic modules (e.g. Mesas) if selected
                    if (isset($_POST['module_ids']) && is_array($_POST['module_ids'])) {
                        $module_ids = array_map('intval', $_POST['module_ids']);
                        updateRoleModules($pdo, $new_role_id, $module_ids);
                    }

                    $success_msg = 'Rol "' . htmlspecialchars($role_name) . '" creado correctamente.';
                }
            } catch (Exception $e) {
                $error_msg = 'Error al crear el rol: ' . $e->getMessage();
            }
        }
    } else {
        $error_msg = 'Acceso denegado.';
    }
}

// Handle Update Role Name
if (isset($_POST['update_role_name'])) {
    if (isRoleAdmin($pdo, $_SESSION['role_id'])) {
        $role_id = intval($_POST['role_id'] ?? 0);
        $role_name = trim($_POST['role_name'] ?? '');

        if ($role_id == 1 || isRoleAdmin($pdo, $role_id)) {
            $error_msg = 'No se puede modificar la denominación del rol Administrador principal.';
        } elseif (empty($role_name)) {
            $error_msg = 'El nombre del rol no puede estar vacío.';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE roles SET name = ? WHERE id = ?");
                $stmt->execute([$role_name, $role_id]);
                $success_msg = 'Nombre del rol actualizado correctamente.';
            } catch (Exception $e) {
                $error_msg = 'Error al actualizar el nombre del rol.';
            }
        }
    } else {
        $error_msg = 'Acceso denegado.';
    }
}

// Handle Delete Role
if (isset($_POST['delete_role'])) {
    if (isRoleAdmin($pdo, $_SESSION['role_id'])) {
        $role_id = intval($_POST['role_id'] ?? 0);

        if ($role_id == 1 || isRoleAdmin($pdo, $role_id)) {
            $error_msg = 'No es posible eliminar el rol Administrador del sistema.';
        } else {
            try {
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
                $stmtCount->execute([$role_id]);
                $userCount = $stmtCount->fetchColumn();

                if ($userCount > 0) {
                    $error_msg = 'No se puede eliminar el rol porque tiene ' . $userCount . ' usuario(s) asignado(s). Reasigna los usuarios primero.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
                    $stmt->execute([$role_id]);
                    $success_msg = 'Rol eliminado correctamente del sistema.';
                }
            } catch (Exception $e) {
                $error_msg = 'Error al eliminar el rol: ' . $e->getMessage();
            }
        }
    } else {
        $error_msg = 'Acceso denegado.';
    }
}

// Handle Add Table
if (isset($_POST['add_table'])) {
    $table_name = trim($_POST['table_name'] ?? '');

    if (empty($table_name)) {
        $error_msg = 'Debe ingresar un nombre para la mesa.';
    } else {
        try {
            // Check if table name already exists
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM tables WHERE name = ?');
            $stmt->execute([$table_name]);
            if ($stmt->fetchColumn() > 0) {
                $error_msg = 'Ya existe una mesa con ese nombre.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO tables (name, status) VALUES (?, "available")');
                $stmt->execute([$table_name]);
                $success_msg = 'Mesa "' . htmlspecialchars($table_name) . '" agregada correctamente.';
            }
        } catch (Exception $e) {
            $error_msg = 'Error al agregar la mesa: ' . $e->getMessage();
        }
    }
}

// Handle Delete Table
if (isset($_POST['delete_table'])) {
    $table_id = intval($_POST['table_id'] ?? 0);

    if ($table_id <= 0) {
        $error_msg = 'Mesa no válida.';
    } else {
        try {
            // Check if table has active orders
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE table_id = ? AND status = "pending"');
            $stmt->execute([$table_id]);
            if ($stmt->fetchColumn() > 0) {
                $error_msg = 'No se puede eliminar la mesa porque tiene pedidos pendientes.';
            } else {
                // Delete the table
                $stmt = $pdo->prepare('DELETE FROM tables WHERE id = ?');
                $stmt->execute([$table_id]);

                if ($stmt->rowCount() > 0) {
                    $success_msg = 'Mesa eliminada correctamente.';
                } else {
                    $error_msg = 'No se encontró la mesa.';
                }
            }
        } catch (Exception $e) {
            $error_msg = 'Error al eliminar la mesa: ' . $e->getMessage();
        }
    }
}

// Get all tables for management (sorted naturally: Mesa 1, Mesa 2, ..., Mesa 10)
$all_tables = $pdo->query('SELECT * FROM tables ORDER BY LENGTH(name), name')->fetchAll();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-cog'></i> Configuración del Sistema</h1>
                <p>Administración global, seguridad y mantenimiento</p>
            </div>
            <div class="fc-header-right">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class='bx bx-check-circle'></i> <?= $success_msg ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><i class='bx bx-x-circle'></i> <?= $error_msg ?></div>
        <?php endif; ?>

          <div class="fc-tabs" style="margin-bottom: 30px; overflow-x: auto; padding-bottom: 10px;">
            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_general') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab active" onclick="switchTab('general')" data-tab="general">
                    <i class='bx bx-equalizer'></i> <span>General</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_backup') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_restore') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('backup')" data-tab="backup">
                    <i class='bx bx-cloud-download'></i> <span>Backup</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_menu_init') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('menu_init')" data-tab="menu_init">
                    <i class='bx bx-rocket'></i> <span>Menú</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_tables') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('tables')" data-tab="tables">
                    <i class='bx bx-chair'></i> <span>Mesas</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_invoicing') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('invoicing')" data-tab="invoicing">
                    <i class='bx bx-receipt'></i> <span>Facturación</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_reset') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('reset')" data-tab="reset">
                    <i class='bx bx-reset'></i> <span>Restablecer</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_modules') || isRoleAdmin($pdo, $_SESSION['role_id'])): ?>
                <button class="fc-tab" onclick="switchTab('roles')" data-tab="roles">
                    <i class='bx bx-shield-quarter'></i> <span>Roles y Permisos</span>
                </button>
            <?php endif; ?>
        </div>


        <!-- General Settings Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_general') || !modulesTableExists($pdo)): ?>
            <div id="general" class="tab-content active">
                <div class="fc-card" style="max-width: 800px;">
                    <div class="fc-modal-header" style="border-radius: 20px 20px 0 0;">
                        <h3><i class='bx bx-building'></i> Perfil de la Empresa</h3>
                    </div>
                    <div class="fc-modal-body">
                        <p style="text-align: center; color: var(--fc-text-sec); margin-bottom: 25px;">Personaliza la identidad visual de tu establecimiento.</p>
                        <?php
                        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_logo', 'theme_effects_enabled', 'show_company_name')");
                        $stmt->execute();
                        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        $current_name = $settings['company_name'] ?? 'RestoCloud System';
                        $current_logo = $settings['company_logo'] ?? '';
                        $effects_enabled = $settings['theme_effects_enabled'] ?? '0';
                        $show_company_name = $settings['show_company_name'] ?? '1'; // Default visible
                        ?>
                        <form method="POST" enctype="multipart/form-data" class="fc-form">
                            <div class="fc-form-group">
                                <label class="fc-label">Nombre del Negocio</label>
                                <input type="text" name="company_name" class="fc-input" value="<?= htmlspecialchars($current_name) ?>" required>
                            </div>
                            
                            <div class="fc-form-group">
                                <label class="fc-label">Logotipo Institucional</label>
                                <?php if ($current_logo): ?>
                                    <div style="margin-bottom: 15px; display: flex; justify-content: center;">
                                        <div style="padding: 10px; background: #f8fafc; border-radius: 12px; border: 1px solid var(--fc-border);">
                                            <img src="<?= htmlspecialchars($current_logo) ?>" alt="Logo" style="max-height: 60px;">
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="company_logo" class="fc-input" accept="image/*">
                                <small style="color: var(--fc-text-sec); margin-top: 5px; display: block;">Formatos aceptados: PNG, JPG, WEBP (Max: 5MB)</small>
                            </div>

                            <div style="background: #f8fafc; padding: 20px; border-radius: 16px; border: 1px solid var(--fc-border); margin-top: 20px;">
                                <div class="fc-form-group" style="margin-bottom: 15px;">
                                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                                        <input type="checkbox" name="show_company_name" value="1" <?= $show_company_name == '1' ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: var(--fc-primary); margin-top: 2px;">
                                        <div>
                                            <span style="font-weight: 700; color: var(--fc-text-main); display: block;"><i class='bx bx-text'></i> Mostrar nombre en menú</span>
                                            <p style="font-size: 13px; color: var(--fc-text-sec); margin-top: 4px;">Permite elegir si el nombre del negocio aparece debajo del logo en la barra lateral.</p>
                                        </div>
                                    </label>
                                </div>
                                <div class="fc-form-group" style="margin-bottom: 0;">
                                    <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                                        <input type="checkbox" name="theme_effects_enabled" value="1" <?= $effects_enabled == '1' ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: var(--fc-primary); margin-top: 2px;">
                                        <div>
                                            <span style="font-weight: 700; color: var(--fc-text-main); display: block;"><i class='bx bx-party'></i> Activar Efectos Visuales</span>
                                            <p style="font-size: 13px; color: var(--fc-text-sec); margin-top: 4px;">Habilita animaciones y elementos temáticos en todo el sistema.</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" name="update_general" class="fc-btn fc-btn-primary fc-w100" style="margin-top: 25px;">
                                <i class='bx bx-save'></i> Guardar Configuración
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Backup & Restore Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_backup') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_restore') || !modulesTableExists($pdo)): ?>
            <div id="backup" class="tab-content">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px;">
                    <!-- Backup Section -->
                    <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_backup') || !modulesTableExists($pdo)): ?>
                        <div class="fc-card">
                            <div class="fc-modal-header">
                                <h3><i class='bx bxs-data'></i> Respaldo SQL</h3>
                            </div>
                            <div class="fc-modal-body">
                                <p style="color: var(--fc-text-sec); margin-bottom: 20px;">Descarga una copia completa de la base de datos para auditorías o migraciones manuales.</p>
                                <form method="POST">
                                    <button type="submit" name="backup" class="fc-btn fc-btn-primary fc-w100">
                                        <i class='bx bx-download'></i> Generar Respaldo
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Restore Section -->
                    <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_restore') || !modulesTableExists($pdo)): ?>
                        <div class="fc-card">
                            <div class="fc-modal-header">
                                <h3><i class='bx bx-cloud-upload'></i> Restauración</h3>
                            </div>
                            <div class="fc-modal-body">
                                <p style="color: var(--fc-text-sec); margin-bottom: 20px;">Sube un archivo de respaldo .sql para restaurar el estado previo del sistema.</p>
                                <div style="padding: 12px; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); border-radius: 12px; margin-bottom: 20px;">
                                    <span style="color: #f59e0b; font-weight: 700; font-size: 13px;"><i class='bx bx-error'></i> PELIGRO:</span>
                                    <p style="font-size: 12px; color: var(--fc-text-sec); margin-top: 4px;">Esta acción sobrescribirá permanentemente todos los datos actuales.</p>
                                </div>
                                <button type="button" onclick="openRestoreModal()" class="fc-btn fc-btn-outline fc-w100">
                                    <i class='bx bx-upload'></i> Subir y Restaurar
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Menu Initialization Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_menu_init') || !modulesTableExists($pdo)): ?>
            <div id="menu_init" class="tab-content">
                <div class="fc-card" style="max-width: 800px;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-rocket'></i> Configuración Inicial</h3>
                    </div>
                    <div class="fc-modal-body" style="text-align: center;">
                        <div style="font-size: 50px; color: var(--fc-primary); margin-bottom: 20px;">
                            <i class='bx bx-archive-in'></i>
                        </div>
                        <p style="color: var(--fc-text-main); font-weight: 600; margin-bottom: 10px;">Carga Masiva de Menú</p>
                        <p style="color: var(--fc-text-sec); margin-bottom: 25px;">Utiliza nuestra herramienta de inicialización para cargar rápidamente categorías y productos desde una interfaz simplificada.</p>
                        
                        <a href="menu_init.php" class="fc-btn fc-btn-primary fc-w100" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 10px;">
                            <i class='bx bx-edit-alt'></i> Abrir Asistente de Menú
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Table Management Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_tables') || !modulesTableExists($pdo)): ?>
            <div id="tables" class="tab-content">
                <div class="fc-card">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-chair'></i> Gestión de Salón</h3>
                    </div>
                    <div class="fc-modal-body">
                        <p style="color: var(--fc-text-sec); margin-bottom: 25px;">Administra las mesas disponibles para el servicio en salón.</p>

                        <!-- Add New Table Form -->
                        <form method="POST" class="fc-form" style="background: #f8fafc; padding: 25px; border-radius: 20px; border: 1px dashed var(--fc-border); margin-bottom: 30px;">
                            <label class="fc-label">Nueva Mesa / Área</label>
                            <div style="display: flex; gap: 12px;">
                                <input type="text" name="table_name" class="fc-input" placeholder="Ej: Mesa 15, Terraza 2, VIP" required style="flex: 1;">
                                <button type="submit" name="add_table" class="fc-btn fc-btn-primary" style="padding: 0 25px; height: 48px;">
                                    <i class='bx bx-plus'></i> <span>Crear</span>
                                </button>
                            </div>
                        </form>

                        <!-- Existing Tables List -->
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 0 5px;">
                                <h4 style="margin: 0; color: var(--fc-text-main); font-size: 16px;">
                                    Distribución Actual 
                                    <span class="fc-badge fc-badge-outline" style="margin-left: 10px;"><?= count($all_tables) ?> Mesas</span>
                                </h4>
                            </div>

                            <?php if (empty($all_tables)): ?>
                                <div style="text-align: center; padding: 60px 20px; color: var(--fc-text-sec); background: #f8fafc; border-radius: 20px; border: 1px solid var(--fc-border);">
                                    <i class='bx bx-chair' style="font-size: 48px; opacity: 0.3; display: block; margin-bottom: 15px;"></i>
                                    <p>No se han configurado mesas aún.</p>
                                </div>
                            <?php else: ?>
                                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px;">
                                    <?php foreach ($all_tables as $table): ?>
                                        <div class="fc-card" style="margin: 0; padding: 15px; background: #ffffff; border: 1px solid var(--fc-border); display: flex; align-items: center; gap: 15px; position: relative;">
                                            <div style="width: 44px; height: 44px; background: rgba(225, 29, 72, 0.1); color: var(--fc-primary); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                                                <i class='bx bx-chair'></i>
                                            </div>
                                            <div style="flex: 1; min-width: 0;">
                                                <span style="display: block; font-weight: 700; color: var(--fc-text-main); font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                    <?= htmlspecialchars($table['name']) ?>
                                                </span>
                                                <span style="font-size: 11px; color: #10b981; display: flex; align-items: center; gap: 4px;">
                                                    <span style="width: 6px; height: 6px; border-radius: 50%; background: currentcolor;"></span> Operativa
                                                </span>
                                            </div>
                                            <div style="display: flex; gap: 5px;">
                                                <form method="POST" onsubmit="confirmDeleteTable(event, this, '<?= htmlspecialchars($table['name']) ?>')">
                                                    <input type="hidden" name="table_id" value="<?= $table['id'] ?>">
                                                    <input type="hidden" name="delete_table" value="1">
                                                    <button type="submit" class="fc-btn fc-btn-outline" style="width: 36px; height: 36px; padding: 0; color: var(--fc-rose); border-radius: 10px;">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- VAT Configuration Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_invoicing') || !modulesTableExists($pdo)): ?>
            <div id="invoicing" class="tab-content">
                <div class="fc-card" style="max-width: 800px;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-receipt'></i> Parámetros de Facturación</h3>
                    </div>
                    <div class="fc-modal-body">
                        <?php
                        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('iva_percentage', 'enable_tips')");
                        $stmt->execute();
                        $inv_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        $current_iva = $inv_settings['iva_percentage'] ?? 0;
                        $enable_tips = $inv_settings['enable_tips'] ?? '0';
                        ?>
                        <form method="POST" class="fc-form">
                            <div class="fc-form-group">
                                <label class="fc-label">Impuesto al Valor Agregado (IVA %)</label>
                                <input type="number" name="iva_percentage" class="fc-input" value="<?= $current_iva ?>" min="0" max="100" step="0.01" required>
                                <small style="color: var(--fc-text-sec); margin-top: 5px; display: block;">Este valor se aplicará automáticamente al subtotal de cada pedido.</small>
                            </div>
                            
                            <div class="fc-form-group" style="background: #f8fafc; padding: 20px; border-radius: 16px; border: 1px solid var(--fc-border); margin: 20px 0;">
                                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                                    <input type="checkbox" name="enable_tips" value="1" <?= $enable_tips == '1' ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: var(--fc-primary); margin-top: 2px;">
                                    <div>
                                        <span style="font-weight: 700; color: var(--fc-text-main); display: block;"><i class='bx bx-coin-stack'></i> Sugerir Propina (10%)</span>
                                        <p style="font-size: 13px; color: var(--fc-text-sec); margin-top: 4px;">Habilita el cálculo sugerido de propina en el cierre de cuenta.</p>
                                    </div>
                                </label>
                            </div>

                            <button type="submit" name="update_iva" class="fc-btn fc-btn-primary fc-w100">
                                <i class='bx bx-save'></i> Actualizar Configuración Fiscal
                            </button>
                        </form>

                        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_invoices_manage') || !modulesTableExists($pdo)): ?>
                            <div style="margin-top: 30px; padding-top: 25px; border-top: 1px solid var(--fc-border);">
                                <h4 style="color: var(--fc-text-main); font-size: 15px; margin-bottom: 15px;">Control de Auditoría</h4>
                                <a href="gestion_facturas.php" class="fc-btn fc-btn-outline fc-w100" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 10px; color: #f59e0b; border-color: rgba(245, 158, 11, 0.3);">
                                    <i class='bx bx-history'></i> Administrar Historial de Facturas
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Reset Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_reset') || !modulesTableExists($pdo)): ?>
            <div id="reset" class="tab-content">
                <div class="fc-card" style="max-width: 800px; border: 1px solid rgba(225, 29, 72, 0.3);">
                    <div class="fc-modal-header" style="background: rgba(225, 29, 72, 0.1);">
                        <h3 style="color: var(--fc-primary);"><i class='bx bx-error'></i> Zona de Peligro</h3>
                    </div>
                    <div class="fc-modal-body">
                        <div style="background: rgba(225, 29, 72, 0.05); padding: 20px; border-radius: 16px; border: 1px solid rgba(225, 29, 72, 0.1); margin-bottom: 25px;">
                            <p style="color: var(--fc-text-main); font-weight: 700; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                <i class='bx bx-info-circle'></i> ACCIÓN IRREVERSIBLE
                            </p>
                            <p style="color: var(--fc-text-sec); font-size: 14px; margin-bottom: 15px;">Esta acción restablecerá el sistema a su estado inicial, eliminando permanentemente:</p>
                            <ul style="color: var(--fc-text-sec); font-size: 13px; padding-left: 20px; gap: 8px; display: flex; flex-direction: column;">
                                <li><i class='bx bx-chevron-right'></i> Todos los productos, categorías e insumos.</li>
                                <li><i class='bx bx-chevron-right'></i> Historial completo de ventas, facturas y pagos.</li>
                                <li><i class='bx bx-chevron-right'></i> Configuración de mesas y pedidos activos.</li>
                                <li><i class='bx bx-chevron-right'></i> Todos los usuarios (excepto la sesión actual del sistema).</li>
                            </ul>
                        </div>
                        
                        <button onclick="openResetModal()" class="fc-btn fc-btn-primary fc-w100" style="height: 54px; letter-spacing: 1px;">
                            <i class='bx bx-trash'></i> REINICIAR SISTEMA COMPLETO
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Roles & Modules Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_modules') || isRoleAdmin($pdo, $_SESSION['role_id'])): ?>
            <div id="roles" class="tab-content">
                <div class="fc-card" style="margin-bottom: 25px;">
                    <div class="fc-modal-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                        <div>
                            <h3 style="margin: 0;"><i class='bx bx-shield-quarter'></i> Gestión de Roles y Permisos</h3>
                            <p style="color: var(--fc-text-sec); font-size: 13px; margin-top: 4px; margin-bottom: 0;">Administra los roles del sistema y asigna de manera modular qué elementos de la barra lateral y operaciones puede acceder cada uno.</p>
                        </div>
                        <div>
                            <button type="button" onclick="openNewRoleModal()" class="fc-btn fc-btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; padding: 10px 18px;">
                                <i class='bx bx-plus-circle'></i> Crear Nuevo Rol
                            </button>
                        </div>
                    </div>
                </div>

                <?php
                $all_modules = getAllModules($pdo);
                $sidebar_mods = array_filter($all_modules, fn($m) => $m['is_sidebar'] == 1);
                $special_mods = array_filter($all_modules, fn($m) => $m['is_sidebar'] == 0);
                $all_roles = getRolesWithModules($pdo);
                ?>

                <?php if (empty($all_roles)): ?>
                    <div class="fc-badge fc-badge-outline" style="width: 100%; justify-content: center; padding: 20px;">
                        <i class='bx bx-error-circle'></i> No hay roles configurados en la base de datos.
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(460px, 1fr)); gap: 25px; align-items: start;">
                        <?php foreach ($all_roles as $role): ?>
                            <?php 
                            $isAdminRole = $role['is_admin']; 
                            $roleId = $role['id'];
                            ?>
                            <div class="fc-card" style="margin: 0; background: #ffffff; border: 1px solid var(--fc-border); box-shadow: 0 4px 12px rgba(0,0,0,0.03); border-radius: 18px; overflow: hidden;">
                                <div style="background: #f8fafc; padding: 18px 22px; border-bottom: 1px solid var(--fc-border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div style="width: 38px; height: 38px; border-radius: 10px; background: <?= $isAdminRole ? 'linear-gradient(135deg, var(--fc-primary) 0%, #8b5cf6 100%)' : 'rgba(15,23,42,0.06)' ?>; display: flex; align-items: center; justify-content: center; color: <?= $isAdminRole ? '#ffffff' : 'var(--fc-primary)' ?>; font-size: 1.2rem;">
                                            <i class='bx <?= $isAdminRole ? 'bx-crown' : 'bx-user-pin' ?>'></i>
                                        </div>
                                        <div>
                                            <h4 style="margin: 0; color: var(--fc-text-main); font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                                                <?= htmlspecialchars($role['name']) ?>
                                                <?php if (!$isAdminRole): ?>
                                                    <button type="button" onclick="openEditRoleModal(<?= $role['id'] ?>, '<?= htmlspecialchars(addslashes($role['name'])) ?>')" style="background: none; border: none; cursor: pointer; color: var(--fc-text-sec); padding: 2px;" title="Editar nombre del rol">
                                                        <i class='bx bx-edit' style="font-size: 14px;"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </h4>
                                            <span style="font-size: 11px; color: var(--fc-text-sec);">ID Rol: #<?= $role['id'] ?></span>
                                        </div>
                                    </div>

                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <?php if ($isAdminRole): ?>
                                            <span class="fc-badge fc-badge-primary" style="font-size: 11px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                                                <i class='bx bx-shield-alt-2'></i> Acceso Total (Inmune)
                                            </span>
                                        <?php else: ?>
                                            <span class="fc-badge fc-badge-outline" style="font-size: 11px; padding: 4px 10px;">
                                                <i class='bx bx-user'></i> <?= $role['user_count'] ?> usuario(s)
                                            </span>
                                            <?php if ($role['user_count'] == 0): ?>
                                                <form method="POST" style="margin: 0; display: inline;" onsubmit="confirmDeleteRole(event, this, '<?= htmlspecialchars(addslashes($role['name'])) ?>')">
                                                    <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                                    <button type="submit" name="delete_role" class="fc-btn fc-btn-outline" style="padding: 5px 8px; width: 30px; height: 30px; min-width: auto; border-color: #ef4444; color: #ef4444;" title="Eliminar este rol">
                                                        <i class='bx bx-trash' style="font-size: 14px;"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div style="padding: 22px;">
                                    <?php if ($isAdminRole): ?>
                                        <div style="background: rgba(139, 92, 246, 0.07); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 12px; padding: 14px; margin-bottom: 20px; color: var(--fc-text-main); font-size: 12.5px; line-height: 1.5; display: flex; align-items: flex-start; gap: 10px;">
                                            <i class='bx bx-info-circle' style="color: #8b5cf6; font-size: 1.2rem; margin-top: 1px;"></i>
                                            <div>
                                                <strong>Acceso Maestro:</strong> El rol Administrador cuenta con permisos completos e incondicionales sobre toda la barra lateral y operaciones del sistema.
                                            </div>
                                        </div>

                                        <h5 style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fc-text-sec); margin-bottom: 12px;">
                                            <i class='bx bx-layout'></i> Módulos de la Barra Lateral (Acceso Habilitado)
                                        </h5>
                                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 20px;">
                                            <?php foreach ($sidebar_mods as $mod): ?>
                                                <div style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; opacity: 0.9;">
                                                    <i class='bx bx-check-circle' style="color: #10b981; font-size: 1.1rem;"></i>
                                                    <span style="font-size: 12.5px; font-weight: 600; color: var(--fc-text-main);"><?= htmlspecialchars($mod['name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" id="form_role_<?= $role['id'] ?>">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">

                                            <!-- Toolbar for Quick Selection -->
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; background: #f8fafc; padding: 8px 12px; border-radius: 10px; border: 1px solid #edf2f7;">
                                                <span style="font-size: 11.5px; font-weight: 600; color: var(--fc-text-sec);">Selección Rápida:</span>
                                                <div style="display: flex; gap: 6px;">
                                                    <button type="button" onclick="selectAllRoleMods(<?= $role['id'] ?>, true)" class="fc-btn fc-btn-outline" style="padding: 3px 8px; font-size: 11px; height: auto; min-width: auto;">
                                                        Todos
                                                    </button>
                                                    <button type="button" onclick="selectAllRoleMods(<?= $role['id'] ?>, false)" class="fc-btn fc-btn-outline" style="padding: 3px 8px; font-size: 11px; height: auto; min-width: auto;">
                                                        Ninguno
                                                    </button>
                                                    <button type="button" onclick="selectSidebarOnly(<?= $role['id'] ?>)" class="fc-btn fc-btn-outline" style="padding: 3px 8px; font-size: 11px; height: auto; min-width: auto; border-color: var(--fc-primary); color: var(--fc-primary);">
                                                        Solo Barra Lateral
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- 1. Sidebar Modules -->
                                            <h5 style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fc-text-sec); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                                                <i class='bx bx-dock-left' style="color: var(--fc-primary);"></i> Módulos de Barra Lateral
                                            </h5>
                                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 22px;">
                                                <?php foreach ($sidebar_mods as $module): ?>
                                                    <?php $isChecked = in_array($module['id'], $role['modules']); ?>
                                                    <label style="display: flex; align-items: center; gap: 8px; padding: 9px 12px; background: <?= $isChecked ? 'rgba(79, 70, 229, 0.05)' : '#f8fafc' ?>; border-radius: 10px; cursor: pointer; border: 1px solid <?= $isChecked ? 'rgba(79, 70, 229, 0.3)' : 'var(--fc-border)' ?>; transition: all 0.2s;">
                                                        <input type="checkbox" name="module_ids[]" value="<?= $module['id'] ?>" data-sidebar="1"
                                                            <?= $isChecked ? 'checked' : '' ?>
                                                            style="width: 16px; height: 16px; accent-color: var(--fc-primary); cursor: pointer;"
                                                            onchange="updateCheckboxCardStyle(this)">
                                                        <span style="font-size: 12.5px; font-weight: 600; color: var(--fc-text-main); display: flex; align-items: center; gap: 6px;">
                                                            <i class='bx <?= htmlspecialchars($module['icon']) ?>' style="font-size: 1.1rem; color: var(--fc-primary);"></i> <?= htmlspecialchars($module['name']) ?>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- 2. Special Permissions & Actions -->
                                            <?php if (!empty($special_mods)): ?>
                                                <h5 style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fc-text-sec); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                                                    <i class='bx bx-key' style="color: #f59e0b;"></i> Permisos y Acciones Especiales
                                                </h5>
                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 20px;">
                                                    <?php foreach ($special_mods as $module): ?>
                                                        <?php $isChecked = in_array($module['id'], $role['modules']); ?>
                                                        <label style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: <?= $isChecked ? 'rgba(245, 158, 11, 0.05)' : '#f8fafc' ?>; border-radius: 8px; cursor: pointer; border: 1px solid <?= $isChecked ? 'rgba(245, 158, 11, 0.3)' : 'var(--fc-border)' ?>; transition: all 0.2s;">
                                                            <input type="checkbox" name="module_ids[]" value="<?= $module['id'] ?>" data-sidebar="0"
                                                                <?= $isChecked ? 'checked' : '' ?>
                                                                style="width: 15px; height: 15px; accent-color: #f59e0b; cursor: pointer;"
                                                                onchange="updateCheckboxCardStyle(this)">
                                                            <span style="font-size: 11.5px; color: var(--fc-text-main); display: flex; align-items: center; gap: 5px;">
                                                                <i class='bx <?= htmlspecialchars($module['icon']) ?>' style="color: #f59e0b;"></i> <?= htmlspecialchars($module['name']) ?>
                                                            </span>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <button type="submit" name="update_role_modules" class="fc-btn fc-btn-primary fc-w100" style="font-size: 13px; height: 42px; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                                <i class='bx bx-save'></i> Guardar Permisos de <?= htmlspecialchars($role['name']) ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal: Crear Nuevo Rol -->
<div class="fc-modal-overlay" id="newRoleModal">
    <div class="fc-modal" style="max-width: 500px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-shield-plus'></i> Crear Nuevo Rol</h3>
            <button class="fc-modal-close" onclick="closeNewRoleModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" class="fc-form">
                <p style="color: var(--fc-text-sec); font-size: 13px; margin-bottom: 20px;">Define el nombre del nuevo rol y asigna sus módulos de acceso iniciales.</p>
                <div class="fc-form-group">
                    <label class="fc-label">Denominación del Rol</label>
                    <input type="text" name="role_name" class="fc-input" placeholder="Ej: Supervisor, Repartidor, Encargado" required autocomplete="off">
                </div>

                <div class="fc-form-group">
                    <label class="fc-label" style="margin-bottom: 10px;">Módulos Iniciales de Barra Lateral</label>
                    <div style="max-height: 220px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px; background: #f8fafc; border-radius: 12px; border: 1px solid var(--fc-border);">
                        <?php foreach ($sidebar_mods as $mod): ?>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer;">
                                <input type="checkbox" name="module_ids[]" value="<?= $mod['id'] ?>" style="accent-color: var(--fc-primary);">
                                <span><?= htmlspecialchars($mod['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeNewRoleModal()">Cancelar</button>
                    <button type="submit" name="create_role" class="fc-btn fc-btn-primary fc-w100"><i class='bx bx-save'></i> Crear Rol</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Editar Denominación de Rol -->
<div class="fc-modal-overlay" id="editRoleModal">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-edit'></i> Editar Nombre del Rol</h3>
            <button class="fc-modal-close" onclick="closeEditRoleModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" class="fc-form">
                <input type="hidden" name="role_id" id="edit_role_id_val">
                <div class="fc-form-group">
                    <label class="fc-label">Nombre del Rol</label>
                    <input type="text" name="role_name" id="edit_role_name_val" class="fc-input" required autocomplete="off">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeEditRoleModal()">Cancelar</button>
                    <button type="submit" name="update_role_name" class="fc-btn fc-btn-primary fc-w100"><i class='bx bx-save'></i> Actualizar Nombre</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Confirmation Modal -->
<div class="fc-modal-overlay" id="resetModal">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-lock-alt'></i> Confirmación requerida</h3>
            <button class="fc-modal-close" onclick="closeResetModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" class="fc-form">
                <p style="color: var(--fc-text-sec); margin-bottom: 20px; font-size: 14px;">Ingresa las credenciales de SuperAdmin para validar el restablecimiento del sistema.</p>
                <div class="fc-form-group">
                    <label class="fc-label">Usuario SuperAdmin</label>
                    <input type="text" name="super_admin_username" class="fc-input" required autocomplete="off">
                </div>
                <div class="fc-form-group">
                    <label class="fc-label">Contraseña</label>
                    <input type="password" name="super_admin_password" class="fc-input" required autocomplete="off">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeResetModal()">Cancelar</button>
                    <button type="submit" name="reset_system" class="fc-btn fc-btn-primary fc-w100">Confirmar Borrado</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div class="fc-modal-overlay" id="restoreModal">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-lock-alt'></i> Restauración Segura</h3>
            <button class="fc-modal-close" onclick="closeRestoreModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" enctype="multipart/form-data" class="fc-form">
                <div class="fc-form-group">
                    <label class="fc-label">Archivo SQL</label>
                    <input type="file" name="sql_file" class="fc-input" accept=".sql" required>
                </div>
                <div class="fc-form-group">
                    <label class="fc-label">Usuario SuperAdmin</label>
                    <input type="text" name="super_admin_username" class="fc-input" required autocomplete="off">
                </div>
                <div class="fc-form-group">
                    <label class="fc-label">Contraseña</label>
                    <input type="password" name="super_admin_password" class="fc-input" required autocomplete="off">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeRestoreModal()">Cancelar</button>
                    <button type="submit" name="restore_db" class="fc-btn fc-btn-primary fc-w100" style="background-color: #f59e0b; border-color: #f59e0b;">Restaurar Datos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function switchTab(tabId) {
        if (tabId === 'modules') tabId = 'roles';

        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.fc-tab').forEach(el => el.classList.remove('active'));

        const content = document.getElementById(tabId);
        if (content) content.classList.add('active');

        const btn = document.querySelector(`.fc-tab[data-tab="${tabId}"]`);
        if (btn) btn.classList.add('active');

        localStorage.setItem('configActiveTab', tabId);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const hash = window.location.hash.replace('#', '');
        let activeTab = hash || localStorage.getItem('configActiveTab') || 'general';
        if (activeTab === 'modules') activeTab = 'roles';

        if (document.getElementById(activeTab)) {
            switchTab(activeTab);
        } else {
            const firstTab = document.querySelector('.tab-content');
            if (firstTab) switchTab(firstTab.id);
        }
    });

    function openNewRoleModal() {
        const m = document.getElementById('newRoleModal');
        m.classList.add('show');
        m.style.display = 'flex';
    }
    function closeNewRoleModal() {
        const m = document.getElementById('newRoleModal');
        m.classList.remove('show');
        m.style.display = 'none';
    }

    function openEditRoleModal(id, name) {
        document.getElementById('edit_role_id_val').value = id;
        document.getElementById('edit_role_name_val').value = name;
        const m = document.getElementById('editRoleModal');
        m.classList.add('show');
        m.style.display = 'flex';
    }
    function closeEditRoleModal() {
        const m = document.getElementById('editRoleModal');
        m.classList.remove('show');
        m.style.display = 'none';
    }

    function selectAllRoleMods(roleId, selectAll) {
        const form = document.getElementById(`form_role_${roleId}`);
        if (!form) return;
        const checkboxes = form.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            cb.checked = selectAll;
            updateCheckboxCardStyle(cb);
        });
    }

    function selectSidebarOnly(roleId) {
        const form = document.getElementById(`form_role_${roleId}`);
        if (!form) return;
        const checkboxes = form.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(cb => {
            const isSidebar = cb.getAttribute('data-sidebar') === '1';
            cb.checked = isSidebar;
            updateCheckboxCardStyle(cb);
        });
    }

    function updateCheckboxCardStyle(checkbox) {
        const label = checkbox.closest('label');
        if (!label) return;
        const isSidebar = checkbox.getAttribute('data-sidebar') === '1';
        if (checkbox.checked) {
            label.style.background = isSidebar ? 'rgba(79, 70, 229, 0.05)' : 'rgba(245, 158, 11, 0.05)';
            label.style.borderColor = isSidebar ? 'rgba(79, 70, 229, 0.3)' : 'rgba(245, 158, 11, 0.3)';
        } else {
            label.style.background = '#f8fafc';
            label.style.borderColor = 'var(--fc-border)';
        }
    }

    function confirmDeleteRole(e, form, name) {
        e.preventDefault();
        Swal.fire({
            title: '¿Eliminar rol ' + name + '?',
            text: 'Esta acción removerá el rol y sus permisos.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'delete_role';
                hiddenInput.value = '1';
                form.appendChild(hiddenInput);
                form.submit();
            }
        });
    }

    function openResetModal() { document.getElementById('resetModal').classList.add('show'); document.getElementById('resetModal').style.display='flex'; }
    function closeResetModal() { document.getElementById('resetModal').classList.remove('show'); document.getElementById('resetModal').style.display='none'; }
    function openRestoreModal() { document.getElementById('restoreModal').classList.add('show'); document.getElementById('restoreModal').style.display='flex'; }
    function closeRestoreModal() { document.getElementById('restoreModal').classList.remove('show'); document.getElementById('restoreModal').style.display='none'; }

    function confirmDeleteTable(e, form, name) {
        e.preventDefault();
        Swal.fire({
            title: '¿Eliminar ' + name + '?',
            text: 'Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--fc-primary)',
            cancelButtonColor: 'var(--fc-bg-dark)',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            background: 'var(--fc-bg-dark)',
            color: 'var(--fc-text-main)'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    }

    <?php if ($success_msg): ?>
        Swal.fire({ icon: 'success', title: '¡Hecho!', text: '<?= addslashes($success_msg) ?>', confirmButtonColor: 'var(--fc-primary)' });
    <?php endif; ?>
    <?php if ($error_msg): ?>
        Swal.fire({ icon: 'error', title: 'Error', text: '<?= addslashes($error_msg) ?>', confirmButtonColor: 'var(--fc-primary)' });
    <?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

