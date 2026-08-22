<?php
require_once __DIR__ . '/config/db.php';

// Initialize settings table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('kitchen_workflow', 'pantalla')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('kitchen_printer_ip', '192.168.1.100')");
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('kitchen_printer_port', '9100')");
} catch (PDOException $e) {
    // silently fail if user doesn't have create table perm
}

require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Handle AJAX Test Print
if (isset($_GET['ajax']) && $_GET['ajax'] === 'test_printer') {
    $ip = $_POST['test_ip'] ?? '';
    $port = $_POST['test_port'] ?? '9100';

    if (empty($ip)) {
        echo json_encode(['success' => false, 'message' => 'IP vacía']);
        exit();
    }

    require_once __DIR__ . '/includes/printer_helper.php';

    // Create dummy items for the test
    $dummy_items = [
        ['quantity' => 1, 'product_name' => 'Hamburguesa Clásica', 'notes' => 'Sin cebolla'],
        ['quantity' => 2, 'product_name' => 'Cerveza Artesanal', 'notes' => '']
    ];

    $result = sendToKitchenPrinter($ip, $port, 'Mesa de Prueba', $_SESSION['name'] ?? 'Admin', $dummy_items);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No se pudo conectar a la impresora. Verifica la IP y que esté encendida.']);
    }
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

// --- USER MANAGEMENT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
    switch ($_POST['user_action']) {
        case 'create':
            $name = $_POST['name'];
            $email = $_POST['email'];
            $username = $_POST['username'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $role_id = $_POST['role_id'];

            try {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, username, password, role_id, status) VALUES (?, ?, ?, ?, ?, "active")');
                $stmt->execute([$name, $email, $username, $password, $role_id]);
                $success_msg = 'Usuario creado exitosamente';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error_msg = 'Error: El email o nombre de usuario ya existe en el sistema.';
                } else {
                    $error_msg = 'Error al crear usuario: ' . $e->getMessage();
                }
            }
            break;

        case 'update':
            $user_id = $_POST['user_id'];
            $name = $_POST['name'];
            $email = $_POST['email'];
            $role_id = $_POST['role_id'];
            $status = $_POST['status'];

            $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role_id = ?, status = ? WHERE id = ?');
            $stmt->execute([$name, $email, $role_id, $status, $user_id]);
            $success_msg = 'Usuario actualizado exitosamente';
            break;

        case 'reset_password':
            $user_id = $_POST['user_id'];
            $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
            $stmt->execute([$new_password, $user_id]);
            $success_msg = 'Contraseña actualizada exitosamente';
            break;

        case 'delete':
            $user_id = $_POST['user_id'];
            if ($user_id == 1) {
                $error_msg = 'No se puede eliminar al Superadmin.';
                break;
            }
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$user_id]);
            $success_msg = 'Usuario eliminado exitosamente';
            break;
    }
}
// -----------------------------

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
    // Security check is done by verifying credentials below
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

                // Use DELETE FROM instead of TRUNCATE because InnoDB prevents TRUNCATE on tables with foreign keys even if FOREIGN_KEY_CHECKS=0
                $tables_to_clear = [
                    'order_details',
                    'payments',
                    'orders',
                    'cash_register',
                    'deleted_invoices_log',
                    'ingredient_movements',
                    'invoice_payments',
                    'pedidosya_order_details',
                    'pedidosya_orders',
                    'stock_movements'
                ];
                
                if (isset($_POST['hard_reset']) && $_POST['hard_reset'] == '1') {
                    $tables_to_clear = array_merge($tables_to_clear, [
                        'products',
                        'product_recipes',
                        'tables'
                    ]);
                }

                foreach ($tables_to_clear as $table) {
                    $pdo->exec("DELETE FROM $table");
                    $pdo->exec("ALTER TABLE $table AUTO_INCREMENT = 1");
                }
                
                // Reset physical tables to available
                $pdo->exec("UPDATE tables SET status = 'available'");

                // Check if invoices table exists before clearing
                $stmt = $pdo->query("SHOW TABLES LIKE 'invoices'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("DELETE FROM invoices");
                    $pdo->exec("ALTER TABLE invoices AUTO_INCREMENT = 1");
                }

                // Delete non-super-admin users
                $stmt = $pdo->prepare('DELETE FROM users WHERE is_super_admin != 1 OR is_super_admin IS NULL');
                $stmt->execute();
                $pdo->exec("ALTER TABLE users AUTO_INCREMENT = 1");

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

    // Kitchen Workflow Settings
    $kitchen_workflow = $_POST['kitchen_workflow'] ?? 'pantalla';
    $kitchen_printer_ip = $_POST['kitchen_printer_ip'] ?? '192.168.1.100';
    $kitchen_printer_port = $_POST['kitchen_printer_port'] ?? '9100';

    // Update Company Name
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('company_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$company_name, $company_name]);

    // Update Theme Effects
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('theme_effects_enabled', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$theme_effects_enabled, $theme_effects_enabled]);

    // Update Company Name Visibility
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('show_company_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$show_company_name, $show_company_name]);

    // Update Kitchen Settings
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('kitchen_workflow', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$kitchen_workflow, $kitchen_workflow]);

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('kitchen_printer_ip', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$kitchen_printer_ip, $kitchen_printer_ip]);

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('kitchen_printer_port', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$kitchen_printer_port, $kitchen_printer_port]);

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

// Handle Add Bar Seat
if (isset($_POST['add_bar_seat'])) {
    try {
        // Find highest existing seat number
        $stmt = $pdo->query('SELECT name FROM tables WHERE name LIKE "Barra - Asiento %"');
        $seats = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $max_num = 0;
        foreach ($seats as $seat_name) {
            $num = (int) str_replace('Barra - Asiento ', '', $seat_name);
            if ($num > $max_num)
                $max_num = $num;
        }

        $new_seat_num = $max_num + 1;
        $table_name = "Barra - Asiento " . $new_seat_num;

        $stmt = $pdo->prepare('INSERT INTO tables (name, status) VALUES (?, "available")');
        $stmt->execute([$table_name]);
        $success_msg = 'Asiento de barra agregado correctamente.';
    } catch (Exception $e) {
        $error_msg = 'Error al agregar asiento: ' . $e->getMessage();
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

// Get all tables for management, separated by type
$salon_tables = $pdo->query('SELECT * FROM tables WHERE name != "Barra" AND name NOT LIKE "Barra - %" ORDER BY LENGTH(name), name')->fetchAll();
$barra_seats = $pdo->query('SELECT * FROM tables WHERE name LIKE "Barra - %" ORDER BY LENGTH(name), name')->fetchAll();

// Get users and roles
$users = $pdo->query('
    SELECT u.*, r.name as role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
')->fetchAll();
$roles = $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <style>
            .tab-content {
                max-width: 1150px !important;
                margin: 0 auto !important;
                align-items: flex-start !important;
            }

            .role-list-item {
                padding: 14px 18px;
                border-radius: 12px;
                cursor: pointer;
                transition: all 0.2s ease;
                border: 1px solid #f1f5f9;
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 10px;
                background: #ffffff;
            }

            .role-list-item:hover {
                background: #f8fafc;
                border-color: #e2e8f0;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.03);
            }

            .role-list-item.active {
                background: rgba(79, 70, 229, 0.04);
                border-color: rgba(79, 70, 229, 0.3);
                box-shadow: 0 4px 12px rgba(79, 70, 229, 0.08);
            }

            .role-detail-view {
                display: none;
                height: 100%;
            }

            .role-detail-view.active {
                display: flex;
                flex-direction: column;
                animation: fadeIn 0.3s ease;
            }
        </style>
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-cog'></i> Configuración del Sistema</h1>
                <p>Administración global, seguridad y mantenimiento</p>
            </div>
            <div class="fc-header-right">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></span>
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

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_backup') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_restore') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_menu_init') || hasModuleAccess($pdo, $_SESSION['role_id'], 'config_reset') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('sistema')" data-tab="sistema">
                    <i class='bx bx-data'></i> <span>Sistema & Datos</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_tables') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('tables')" data-tab="tables">
                    <i class='bx bx-chair'></i> <span>Mesas</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_barra') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('barra')" data-tab="barra">
                    <i class='bx bx-coffee-togo'></i> <span>Barra</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_invoicing') || !modulesTableExists($pdo)): ?>
                <button class="fc-tab" onclick="switchTab('invoicing')" data-tab="invoicing">
                    <i class='bx bx-receipt'></i> <span>Facturación</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_modules') || isRoleAdmin($pdo, $_SESSION['role_id'])): ?>
                <button class="fc-tab" onclick="switchTab('roles')" data-tab="roles">
                    <i class='bx bx-shield-quarter'></i> <span>Roles y Permisos</span>
                </button>
            <?php endif; ?>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'users') || isRoleAdmin($pdo, $_SESSION['role_id'])): ?>
                <button class="fc-tab" onclick="switchTab('users')" data-tab="users">
                    <i class='bx bx-user'></i> <span>Usuarios</span>
                </button>
            <?php endif; ?>
        </div>


        <!-- General Settings Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_general') || !modulesTableExists($pdo)): ?>
            <div id="general" class="tab-content active">

        <!-- Premium Settings & Crisp Modern Styles -->
        <style>
            /* Force the gradient background on the whole view */
            body, .dashboard-wrapper, .fc-main-content, .main-content {
                background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 50%, #f3e8ff 100%) !important;
                background-attachment: fixed !important;
            }
            
            /* Changed from Glassmorphism to Crisp Premium White Cards to avoid "opaco" look */
            .glass-card { 
                background: #ffffff !important; 
                border: 1px solid #e2e8f0 !important; 
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05), 0 4px 6px rgba(0, 0, 0, 0.02) !important; 
                border-radius: 20px; 
                padding: 30px; 
                transition: transform 0.3s ease, box-shadow 0.3s ease; 
                position: relative; 
                overflow: hidden; 
            }
            
            .glass-card:hover { 
                transform: translateY(-4px); 
                box-shadow: 0 20px 35px rgba(79, 70, 229, 0.1), 0 8px 15px rgba(0, 0, 0, 0.03) !important; 
                border-color: #c7d2fe !important; 
            }
            
            /* Aggressive Text Contrast Fixes (Pure Black and Very Dark Greys) */
            .glass-card, 
            .glass-card p, 
            .glass-card span, 
            .glass-card div, 
            .glass-card label, 
            .glass-card td, 
            .glass-card th {
                color: #000000 !important; /* Pure black for maximum contrast */
                text-shadow: none !important;
            }
            
            .glass-card .fc-text-sec, 
            .glass-card p[style*="fc-text-sec"], 
            .glass-card span[style*="fc-text-sec"], 
            .glass-card div[style*="fc-text-sec"],
            .glass-card small {
                color: #1e293b !important; /* Very dark slate for secondary text */
                font-weight: 600 !important;
            }

            .glass-card h3, .glass-card h4 { 
                margin-top: 0; margin-bottom: 25px; font-size: 19px; font-weight: 900 !important; color: #000000 !important; display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; 
            }
            
            .p-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; transition: all 0.3s ease; }
            .glass-card:hover .p-icon { transform: scale(1.1) rotate(5deg); }
            .p-icon-1 { background: #e0e7ff; color: #4f46e5 !important; border: 1px solid #c7d2fe; }
            .p-icon-2 { background: #fef3c7; color: #d97706 !important; border: 1px solid #fde68a; }
            .p-icon-3 { background: #ffe4e6; color: #e11d48 !important; border: 1px solid #fecdd3; }
            
            /* Custom Toggle Switch (iOS Style) */
            .fc-switch { position: relative; display: inline-block; width: 48px; height: 26px; flex-shrink: 0; }
            .fc-switch input { opacity: 0; width: 0; height: 0; }
            .fc-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 34px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1); }
            .fc-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
            .fc-switch input:checked + .fc-slider { background-color: #4f46e5; }
            .fc-switch input:checked + .fc-slider:before { transform: translateX(22px); }
            
            /* Inputs and Tables overriding background for contrast */
            .fc-input { 
                background: #f8fafc !important; 
                border: 2px solid #cbd5e1 !important; 
                color: #000000 !important; 
                font-weight: 700 !important; 
                border-radius: 10px !important;
            }
            .fc-input::placeholder { color: #475569 !important; font-weight: 500 !important; }
            .fc-input:focus { border-color: #4f46e5 !important; background: #ffffff !important; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15) !important; }
            
            /* Table inside glass card */
            .glass-card table { color: #000000 !important; border-collapse: separate; border-spacing: 0; }
            .glass-card th { background: #f1f5f9 !important; color: #000000 !important; font-weight: 800 !important; border-bottom: 2px solid #cbd5e1 !important; padding: 12px !important; }
            .glass-card td { border-bottom: 1px solid #e2e8f0 !important; font-weight: 600 !important; padding: 12px !important; }
            
            /* Custom File Upload */
            .custom-file-upload { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 20px; cursor: pointer; background: #e0e7ff !important; color: #4f46e5 !important; border-radius: 10px; font-weight: 800 !important; font-size: 14px; transition: all 0.2s; border: 2px dashed #818cf8 !important; width: 100%; text-align: center; }
            .custom-file-upload:hover { background: #c7d2fe !important; border-color: #4f46e5 !important; }
            .file-input-hidden { display: none; }
            
            /* Option Row Hover */
            .option-row { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 20px; background: #f8fafc; border-radius: 14px; border: 2px solid #e2e8f0; transition: all 0.2s; cursor: pointer; }
            .option-row:hover { border-color: #4f46e5; background: #ffffff; box-shadow: 0 6px 15px rgba(79, 70, 229, 0.1); transform: translateY(-2px); }
        </style>

                <div style="margin-bottom: 35px; display: flex; justify-content: space-between; align-items: flex-end;">
                    <div>
                        <h2 style="font-size: 26px; font-weight: 700; color: var(--fc-text-main); margin-bottom: 8px;">
                            Configuración General</h2>
                        <p style="color: var(--fc-text-sec); font-size: 15px;">Administra la identidad y el comportamiento
                            principal de tu sistema RestoCloud.</p>
                    </div>
                </div>

                <?php
                $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_logo', 'theme_effects_enabled', 'show_company_name', 'kitchen_workflow', 'kitchen_printer_ip', 'kitchen_printer_port')");
                $stmt->execute();
                $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $current_name = $settings['company_name'] ?? 'RestoCloud System';
                $current_logo = $settings['company_logo'] ?? '';
                $effects_enabled = $settings['theme_effects_enabled'] ?? '0';
                $show_company_name = $settings['show_company_name'] ?? '1';
                $kitchen_workflow = $settings['kitchen_workflow'] ?? 'pantalla';
                $kitchen_printer_ip = $settings['kitchen_printer_ip'] ?? '192.168.1.100';
                $kitchen_printer_port = $settings['kitchen_printer_port'] ?? '9100';
                ?>

                <form method="POST" enctype="multipart/form-data">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 30px;">

                        <!-- Tarjeta Identidad -->
                        <div class="glass-card">
                            <h4>
                                <div class="p-icon p-icon-1"><i class='bx bx-store-alt'></i></div>
                                Identidad del Negocio
                            </h4>

                            <div class="fc-form-group">
                                <label class="fc-label" style="font-weight: 600; margin-bottom: 8px;">Nombre
                                    Comercial</label>
                                <input type="text" name="company_name" class="fc-input"
                                    value="<?= htmlspecialchars($current_name) ?>" required
                                    style="padding: 14px; border-radius: 12px; font-size: 15px;">
                            </div>

                            <div class="fc-form-group" style="margin-bottom: 0;">
                                <label class="fc-label" style="font-weight: 600; margin-bottom: 8px;">Logotipo
                                    Institucional</label>
                                <?php if ($current_logo): ?>
                                    <div style="margin-bottom: 20px; text-align: center;">
                                        <div
                                            style="padding: 20px; background: #f8fafc; border-radius: 16px; border: 1px dashed #cbd5e1; display: inline-flex; align-items: center; justify-content: center; min-width: 120px;">
                                            <img src="<?= htmlspecialchars($current_logo) ?>" alt="Logo"
                                                style="max-height: 70px; display: block; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <label class="custom-file-upload">
                                    <i class='bx bx-cloud-upload' style="font-size: 20px;"></i>
                                    <span id="file-chosen">Subir Nuevo Logo</span>
                                    <input type="file" name="company_logo" class="file-input-hidden" accept="image/*"
                                        onchange="document.getElementById('file-chosen').textContent = this.files[0] ? this.files[0].name : 'Subir Nuevo Logo'">
                                </label>
                                <small
                                    style="color: var(--fc-text-sec); margin-top: 10px; display: block; text-align: center; font-size: 13px;">Formatos
                                    aceptados: PNG, JPG, WEBP (Max: 5MB)</small>
                            </div>
                        </div>

                        <!-- Tarjeta Interfaz -->
                        <div class="glass-card">
                            <h4>
                                <div class="p-icon p-icon-2"><i class='bx bx-palette'></i></div>
                                Personalización Visual
                            </h4>

                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                <!-- Toggle 1 -->
                                <label class="option-row">
                                    <div style="display: flex; gap: 15px; align-items: center;">
                                        <div
                                            style="width: 40px; height: 40px; border-radius: 10px; background: white; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--fc-text-main);">
                                            <i class='bx bx-text'></i>
                                        </div>
                                        <div>
                                            <span
                                                style="font-weight: 700; color: var(--fc-text-main); font-size: 15px; display: block;">Mostrar
                                                nombre en menú</span>
                                            <span style="font-size: 13px; color: var(--fc-text-sec);">Visualiza el texto
                                                debajo del logo.</span>
                                        </div>
                                    </div>
                                    <div class="fc-switch">
                                        <input type="checkbox" name="show_company_name" value="1" <?= $show_company_name == '1' ? 'checked' : '' ?>>
                                        <span class="fc-slider"></span>
                                    </div>
                                </label>

                                <!-- Toggle 2 -->
                                <label class="option-row">
                                    <div style="display: flex; gap: 15px; align-items: center;">
                                        <div
                                            style="width: 40px; height: 40px; border-radius: 10px; background: white; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 20px; color: var(--fc-text-main);">
                                            <i class='bx bx-party'></i>
                                        </div>
                                        <div>
                                            <span
                                                style="font-weight: 700; color: var(--fc-text-main); font-size: 15px; display: block;">Efectos
                                                Visuales</span>
                                            <span style="font-size: 13px; color: var(--fc-text-sec);">Animaciones dinámicas
                                                en el sistema.</span>
                                        </div>
                                    </div>
                                    <div class="fc-switch">
                                        <input type="checkbox" name="theme_effects_enabled" value="1"
                                            <?= $effects_enabled == '1' ? 'checked' : '' ?>>
                                        <span class="fc-slider"></span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Tarjeta Operativa -->
                        <div class="glass-card" style="grid-column: 1 / -1;">
                            <h4>
                                <div class="p-icon p-icon-3"><i class='bx bx-restaurant'></i></div>
                                Operativa de Cocina
                            </h4>

                            <div class="fc-form-group" style="margin-bottom: 25px;">
                                <label class="fc-label" style="font-weight: 600; margin-bottom: 10px;">Flujo de Trabajo
                                    Principal</label>
                                <div style="position: relative;">
                                    <select name="kitchen_workflow" class="fc-input"
                                        onchange="document.getElementById('printer_settings').style.display = this.value === 'comandera' ? 'block' : 'none'"
                                        style="cursor: pointer; font-weight: 600; padding: 16px; border-radius: 12px; font-size: 15px; background: #f8fafc; border: 1px solid #cbd5e1; appearance: none;">
                                        <option value="pantalla" <?= $kitchen_workflow === 'pantalla' ? 'selected' : '' ?>>💻
                                            Modo Pantalla Interactiva (Flujo Completo con Tablet)</option>
                                        <option value="comandera" <?= $kitchen_workflow === 'comandera' ? 'selected' : '' ?>>
                                            🖨️ Modo Comandera Física (Impresión Directa por Red)</option>
                                    </select>
                                    <i class='bx bx-chevron-down'
                                        style="position: absolute; right: 15px; top: 18px; font-size: 20px; color: var(--fc-text-sec); pointer-events: none;"></i>
                                </div>
                            </div>

                            <div id="printer_settings"
                                style="display: <?= $kitchen_workflow === 'comandera' ? 'block' : 'none' ?>; background: #f8fafc; padding: 25px; border-radius: 16px; border: 1px solid var(--fc-border);">
                                <h5
                                    style="margin-top:0; margin-bottom: 20px; font-size: 15px; font-weight: 700; color: var(--fc-text-main); display: flex; align-items: center; gap: 8px;">
                                    <i class='bx bx-wifi' style="color: var(--fc-primary); font-size: 20px;"></i>
                                    Ajustes de Impresora de Red Térmica (ESC/POS)
                                </h5>

                                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                                    <div class="fc-form-group" style="margin-bottom: 0;">
                                        <label class="fc-label" style="font-weight: 600;">Dirección IP (Red Local)</label>
                                        <div style="position: relative;">
                                            <i class='bx bx-network-chart'
                                                style="position: absolute; left: 16px; top: 15px; color: var(--fc-primary); font-size: 20px;"></i>
                                            <input type="text" name="kitchen_printer_ip" class="fc-input"
                                                value="<?= htmlspecialchars($kitchen_printer_ip) ?>"
                                                placeholder="Ej: 192.168.1.100"
                                                style="padding: 14px 14px 14px 45px; border-radius: 10px; font-family: monospace; font-size: 15px;">
                                        </div>
                                    </div>
                                    <div class="fc-form-group" style="margin-bottom: 0;">
                                        <label class="fc-label" style="font-weight: 600;">Puerto Socket</label>
                                        <div style="position: relative;">
                                            <i class='bx bx-plug'
                                                style="position: absolute; left: 16px; top: 15px; color: var(--fc-primary); font-size: 20px;"></i>
                                            <input type="number" name="kitchen_printer_port" class="fc-input"
                                                value="<?= htmlspecialchars($kitchen_printer_port) ?>" placeholder="9100"
                                                style="padding: 14px 14px 14px 45px; border-radius: 10px; font-family: monospace; font-size: 15px;">
                                        </div>
                                    </div>
                                </div>

                                <div style="margin-top: 20px; display: flex; gap: 15px;">
                                    <div
                                        style="flex: 1; background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.2); padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 12px;">
                                        <div
                                            style="width: 32px; height: 32px; border-radius: 50%; background: #fef3c7; color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                            <i class='bx bx-info-circle'></i></div>
                                        <span
                                            style="font-size: 13px; color: #92400e; font-weight: 500; line-height: 1.4;">El
                                            servidor web (PHP) debe tener acceso de red a esta IP local.</span>
                                    </div>

                                    <button type="button" onclick="testKitchenPrinter()" class="fc-btn"
                                        style="flex-shrink: 0; background: white; color: var(--fc-primary); border: 2px solid var(--fc-primary); padding: 0 25px; border-radius: 12px; font-weight: 700; transition: all 0.2s;">
                                        <i class='bx bx-printer' style="font-size: 20px; margin-right: 6px;"></i> Ticket de
                                        Prueba
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Bottom Sticky Action Bar -->
                    <div
                        style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--fc-border); display: flex; justify-content: flex-end;">
                        <button type="submit" name="update_general" class="fc-btn fc-btn-primary"
                            style="padding: 16px 40px; font-size: 16px; font-weight: 700; border-radius: 14px; box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3); transition: all 0.3s; transform: translateY(0);">
                            <i class='bx bx-save' style="font-size: 22px; margin-right: 8px;"></i> Guardar Configuración
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Sistema & Datos Tab Included Here -->
        <?php include 'includes/config_sistema_tab.php'; ?>

        <!-- Table Management Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_tables') || !modulesTableExists($pdo)): ?>
            <div id="tables" class="tab-content">
                <div class="glass-card" style="max-width: 900px; margin: 0 auto;">
                    <div class="fc-modal-header" style="border-bottom: none; padding-bottom: 0;">
                        <h3><i class='bx bx-chair'
                                style="color: var(--fc-primary); background: rgba(79, 70, 229, 0.1); padding: 8px; border-radius: 12px; margin-right: 8px;"></i>
                            Gestión de Salón</h3>
                    </div>
                    <div class="fc-modal-body">
                        <p style="color: var(--fc-text-sec); margin-bottom: 25px;">Administra las mesas disponibles para el
                            servicio en el salón principal y terraza.</p>

                        <!-- Add New Table Form -->
                        <form method="POST" class="fc-form"
                            style="background: rgba(255,255,255,0.5); padding: 25px; border-radius: 20px; border: 1px dashed rgba(79, 70, 229, 0.3); margin-bottom: 30px; transition: all 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.02);"
                            onmouseover="this.style.background='rgba(255,255,255,0.8)'; this.style.borderColor='rgba(79, 70, 229, 0.6)';"
                            onmouseout="this.style.background='rgba(255,255,255,0.5)'; this.style.borderColor='rgba(79, 70, 229, 0.3)';">
                            <label class="fc-label" style="font-weight: 600; color: var(--fc-primary);">Añadir Nueva Mesa /
                                Área</label>
                            <div style="display: flex; gap: 12px;">
                                <input type="text" name="table_name" class="fc-input"
                                    placeholder="Ej: Mesa 15, Terraza 2, VIP" required
                                    style="flex: 1; border-radius: 14px; background: rgba(255,255,255,0.7);">
                                <button type="submit" name="add_table" class="fc-btn fc-btn-primary"
                                    style="padding: 0 30px; height: 50px; border-radius: 14px; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.2);">
                                    <i class='bx bx-plus' style="font-size: 20px;"></i> <span>Crear Mesa</span>
                                </button>
                            </div>
                        </form>

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--fc-border);">
                            <h4
                                style="margin: 0; color: var(--fc-text-main); font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class='bx bx-restaurant' style="color: var(--fc-primary);"></i> Salón
                                <span class="fc-badge fc-badge-outline"
                                    style="margin-left: 10px;"><?= count($salon_tables) ?> Mesas</span>
                            </h4>
                        </div>

                        <?php if (empty($salon_tables)): ?>
                            <div
                                style="text-align: center; padding: 40px 20px; color: var(--fc-text-sec); background: #f8fafc; border-radius: 20px; border: 1px dashed var(--fc-border);">
                                <i class='bx bx-chair'
                                    style="font-size: 40px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                                <p style="font-size: 13px;">No hay mesas de salón.</p>
                            </div>
                        <?php else: ?>
                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
                                <?php foreach ($salon_tables as $table): ?>
                                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; display: flex; align-items: center; justify-content: space-between; transition: all 0.2s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.02);"
                                        onmouseover="this.style.borderColor='#cbd5e1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(0,0,0,0.05)';"
                                        onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.02)';">
                                        <div style="display: flex; align-items: center; gap: 12px; overflow: hidden;">
                                            <div
                                                style="width: 42px; height: 42px; background: rgba(225, 29, 72, 0.08); color: var(--fc-primary); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                                                <i class='bx bx-chair'></i>
                                            </div>
                                            <span
                                                style="font-weight: 700; color: var(--fc-text-main); font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?= htmlspecialchars($table['name']) ?>
                                            </span>
                                        </div>
                                        <form method="POST"
                                            onsubmit="confirmDeleteTable(event, this, '<?= htmlspecialchars($table['name']) ?>')"
                                            style="margin: 0; flex-shrink: 0;">
                                            <input type="hidden" name="table_id" value="<?= $table['id'] ?>">
                                            <input type="hidden" name="delete_table" value="1">
                                            <button type="submit"
                                                style="background: #fff1f2; border: 1px solid #ffe4e6; width: 34px; height: 34px; border-radius: 10px; color: #f43f5e; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;"
                                                onmouseover="this.style.background='#ffe4e6'; this.style.color='#e11d48';"
                                                onmouseout="this.style.background='#fff1f2'; this.style.color='#f43f5e';"
                                                title="Eliminar">
                                                <i class='bx bx-trash' style="font-size: 16px;"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Barra Management Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_barra') || !modulesTableExists($pdo)): ?>
            <div id="barra" class="tab-content">
                <div class="glass-card" style="max-width: 900px; margin: 0 auto;">
                    <div class="fc-modal-header" style="border-bottom: none; padding-bottom: 0;">
                        <h3><i class='bx bx-coffee-togo'
                                style="color: #f59e0b; background: rgba(245, 158, 11, 0.1); padding: 8px; border-radius: 12px; margin-right: 8px;"></i>
                            Gestión de Barra</h3>
                    </div>
                    <div class="fc-modal-body">
                        <p style="color: var(--fc-text-sec); margin-bottom: 25px;">Administra los asientos disponibles en la
                            barra o mostrador para atención rápida.</p>

                        <div
                            style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid var(--fc-border);">
                            <h4
                                style="margin: 0; color: var(--fc-text-main); font-size: 16px; display: flex; align-items: center; gap: 8px;">
                                <i class='bx bx-coffee-togo' style="color: var(--fc-primary);"></i> Barra
                                <span class="fc-badge fc-badge-outline"
                                    style="margin-left: 10px;"><?= count($barra_seats) ?> Asientos</span>
                            </h4>
                            <form method="POST" style="margin: 0;">
                                <button type="submit" name="add_bar_seat" class="fc-btn fc-btn-primary"
                                    style="padding: 10px 20px; font-size: 13px; font-weight: 600; border-radius: 12px; height: auto; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.2);">
                                    <i class='bx bx-plus' style="font-size: 16px;"></i> Añadir Asiento
                                </button>
                            </form>
                        </div>

                        <?php if (empty($barra_seats)): ?>
                            <div
                                style="text-align: center; padding: 40px 20px; color: var(--fc-text-sec); background: #f8fafc; border-radius: 20px; border: 1px dashed var(--fc-border);">
                                <i class='bx bx-user'
                                    style="font-size: 40px; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                                <p style="font-size: 13px;">No hay asientos en barra.</p>
                            </div>
                        <?php else: ?>
                            <div
                                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px;">
                                <?php foreach ($barra_seats as $seat): ?>
                                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 16px; display: flex; align-items: center; justify-content: space-between; transition: all 0.2s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.02);"
                                        onmouseover="this.style.borderColor='#cbd5e1'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 12px rgba(0,0,0,0.05)';"
                                        onmouseout="this.style.borderColor='#e2e8f0'; this.style.transform='none'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.02)';">
                                        <div style="display: flex; align-items: center; gap: 12px; overflow: hidden;">
                                            <div
                                                style="width: 42px; height: 42px; background: rgba(79, 70, 229, 0.08); color: #4f46e5; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;">
                                                <i class='bx bx-user'></i>
                                            </div>
                                            <span
                                                style="font-weight: 700; color: var(--fc-text-main); font-size: 15px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?= htmlspecialchars(str_replace('Barra - ', '', $seat['name'])) ?>
                                            </span>
                                        </div>
                                        <form method="POST"
                                            onsubmit="confirmDeleteTable(event, this, '<?= htmlspecialchars($seat['name']) ?>')"
                                            style="margin: 0; flex-shrink: 0;">
                                            <input type="hidden" name="table_id" value="<?= $seat['id'] ?>">
                                            <input type="hidden" name="delete_table" value="1">
                                            <button type="submit"
                                                style="background: #fff1f2; border: 1px solid #ffe4e6; width: 34px; height: 34px; border-radius: 10px; color: #f43f5e; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;"
                                                onmouseover="this.style.background='#ffe4e6'; this.style.color='#e11d48';"
                                                onmouseout="this.style.background='#fff1f2'; this.style.color='#f43f5e';"
                                                title="Eliminar">
                                                <i class='bx bx-trash' style="font-size: 16px;"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- VAT Configuration Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_invoicing') || !modulesTableExists($pdo)): ?>
            <div id="invoicing" class="tab-content">
                <div class="glass-card" style="max-width: 900px; margin: 0 auto;">
                    <div class="fc-modal-header" style="border-bottom: none; padding-bottom: 0;">
                        <h3><i class='bx bx-receipt'
                                style="color: #10b981; background: rgba(16, 185, 129, 0.1); padding: 8px; border-radius: 12px; margin-right: 8px;"></i>
                            Parámetros de Facturación</h3>
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
                                <input type="number" name="iva_percentage" class="fc-input" value="<?= $current_iva ?>"
                                    min="0" max="100" step="0.01" required>
                                <small style="color: var(--fc-text-sec); margin-top: 5px; display: block;">Este valor se
                                    aplicará automáticamente al subtotal de cada pedido.</small>
                            </div>

                            <div class="fc-form-group"
                                style="background: rgba(255,255,255,0.5); padding: 20px; border-radius: 16px; border: 1px solid rgba(16, 185, 129, 0.2); margin: 25px 0; transition: all 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.02);"
                                onmouseover="this.style.background='rgba(255,255,255,0.8)'; this.style.borderColor='rgba(16, 185, 129, 0.5)';"
                                onmouseout="this.style.background='rgba(255,255,255,0.5)'; this.style.borderColor='rgba(16, 185, 129, 0.2)';">
                                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer;">
                                    <input type="checkbox" name="enable_tips" value="1" <?= $enable_tips == '1' ? 'checked' : '' ?> style="width: 22px; height: 22px; accent-color: #10b981; margin-top: 2px;">
                                    <div>
                                        <span
                                            style="font-weight: 700; color: var(--fc-text-main); display: block; font-size: 15px;"><i
                                                class='bx bx-coin-stack' style="color: #10b981;"></i> Sugerir Propina
                                            (10%)</span>
                                        <p
                                            style="font-size: 13px; color: var(--fc-text-sec); margin-top: 6px; line-height: 1.5;">
                                            Habilita el
                                            cálculo sugerido de propina en el cierre de cuenta.</p>
                                    </div>
                                </label>
                            </div>

                            <button type="submit" name="update_iva" class="fc-btn fc-btn-primary fc-w100">
                                <i class='bx bx-save'></i> Actualizar Configuración Fiscal
                            </button>
                        </form>

                        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_invoices_manage') || !modulesTableExists($pdo)): ?>
                            <div style="margin-top: 30px; padding-top: 25px; border-top: 1px solid var(--fc-border);">
                                <h4 style="color: var(--fc-text-main); font-size: 15px; margin-bottom: 15px;">Control de
                                    Auditoría</h4>
                                <a href="gestion_facturas.php" class="fc-btn fc-btn-outline fc-w100"
                                    style="text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 10px; color: #f59e0b; border-color: #f59e0b; background: rgba(245, 158, 11, 0.05); font-weight: 600; padding: 12px; border-radius: 12px; transition: all 0.2s ease;"
                                    onmouseover="this.style.background='rgba(245, 158, 11, 0.1)'; this.style.transform='translateY(-1px)';"
                                    onmouseout="this.style.background='rgba(245, 158, 11, 0.05)'; this.style.transform='translateY(0)';">
                                    <i class='bx bx-history' style="font-size: 18px;"></i> Administrar Historial de Facturas
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Removed Reset Tab (now in sistema) -->

        <!-- Roles & Modules Tab -->
        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'config_modules') || isRoleAdmin($pdo, $_SESSION['role_id'])): ?>
            <div id="roles" class="tab-content">
                <div
                    style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid rgba(255,255,255,0.3); padding-bottom: 15px;">
                    <div>
                        <h3
                            style="margin: 0; font-size: 26px; font-weight: 700; color: var(--fc-text-main); display: flex; align-items: center; gap: 12px;">
                            <i class='bx bx-shield-quarter'
                                style="color: var(--fc-primary); background: rgba(79, 70, 229, 0.1); padding: 10px; border-radius: 14px;"></i>
                            Gestión de Roles y Permisos
                        </h3>
                        <p style="color: var(--fc-text-sec); font-size: 15px; margin-top: 10px; margin-bottom: 0;">
                            Administra los roles del sistema y asigna de manera modular a qué elementos puede acceder cada
                            uno.
                        </p>
                    </div>
                    <div>
                        <button type="button" onclick="openNewRoleModal()" class="fc-btn fc-btn-primary"
                            style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; padding: 10px 18px; border-radius: 12px; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);">
                            <i class='bx bx-plus-circle'></i> Crear Nuevo Rol
                        </button>
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
                    <!-- Selector de Roles Superior -->
                    <div class="glass-card"
                        style="margin-bottom: 25px; padding: 24px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
                        <div style="display: flex; align-items: center; gap: 18px; flex: 1; min-width: 300px;">
                            <div
                                style="width: 54px; height: 54px; border-radius: 14px; background: rgba(79,70,229,0.08); display: flex; align-items: center; justify-content: center; color: var(--fc-primary); font-size: 1.8rem;">
                                <i class='bx bx-user-circle'></i>
                            </div>
                            <div style="flex: 1;">
                                <label
                                    style="display: block; font-size: 13px; font-weight: 800; color: var(--fc-text-sec); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Seleccionar
                                    Rol a Configurar</label>
                                <div style="position: relative; max-width: 400px;">
                                    <select id="roleSelectMenu" onchange="switchRoleView(this.value)"
                                        style="width: 100%; padding: 14px 20px; font-size: 16px; font-weight: 700; color: var(--fc-text-main); border: 2px solid #e2e8f0; border-radius: 12px; appearance: none; background: #f8fafc; cursor: pointer; outline: none; transition: border-color 0.2s;"
                                        onfocus="this.style.borderColor='var(--fc-primary)'"
                                        onblur="this.style.borderColor='#e2e8f0'">
                                        <?php foreach ($all_roles as $role): ?>
                                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?>
                                                <?= $role['is_admin'] ? '(Administrador Maestro)' : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class='bx bx-chevron-down'
                                        style="position: absolute; right: 18px; top: 50%; transform: translateY(-50%); font-size: 24px; color: #94a3b8; pointer-events: none;"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Vistas de los Roles -->
                    <div id="rolesViewsContainer">
                        <?php foreach ($all_roles as $index => $role): ?>
                            <?php
                            $isAdminRole = $role['is_admin'];
                            $isActive = $index === 0;
                            ?>
                            <div id="role_view_<?= $role['id'] ?>" class="role-view-panel glass-card"
                                style="display: <?= $isActive ? 'block' : 'none' ?>; padding: 0; animation: fadeIn 0.3s ease-in-out;">
                                <!-- Cabecera del Panel del Rol -->
                                <div
                                    style="padding: 24px 30px; border-bottom: 1px solid rgba(255,255,255,0.4); display: flex; justify-content: space-between; align-items: center; background: <?= $isAdminRole ? 'linear-gradient(to right, rgba(139,92,246,0.1), rgba(255,255,255,0.5))' : 'rgba(255,255,255,0.5)' ?>; flex-wrap: wrap; gap: 15px;">
                                    <div>
                                        <h4
                                            style="margin: 0; font-size: 20px; color: var(--fc-text-main); display: flex; align-items: center; gap: 10px; font-weight: 800;">
                                            <i class='bx <?= $isAdminRole ? 'bx-crown' : 'bx-user-pin' ?>'
                                                style="color: <?= $isAdminRole ? '#8b5cf6' : 'var(--fc-primary)' ?>; font-size: 1.4rem;"></i>
                                            <?= htmlspecialchars($role['name']) ?>
                                            <?php if (!$isAdminRole): ?>
                                                <button type="button"
                                                    onclick="openEditRoleModal(<?= $role['id'] ?>, '<?= htmlspecialchars(addslashes($role['name'])) ?>')"
                                                    style="background: none; border: none; cursor: pointer; color: #94a3b8; padding: 4px; display: flex; align-items: center; transition: color 0.2s;"
                                                    title="Editar nombre del rol" onmouseover="this.style.color='var(--fc-primary)'"
                                                    onmouseout="this.style.color='#94a3b8'">
                                                    <i class='bx bx-edit' style="font-size: 20px;"></i>
                                                </button>
                                            <?php endif; ?>
                                        </h4>
                                        <span
                                            style="font-size: 13.5px; color: var(--fc-text-sec); margin-top: 6px; display: block;">
                                            <i class='bx bx-hash'></i> ID Rol: <?= $role['id'] ?> <span
                                                style="opacity: 0.5; margin: 0 6px;">•</span>
                                            <i class='bx bx-user'></i>
                                            <?= $isAdminRole ? 'Acceso Maestro Inmune' : $role['user_count'] . ' usuario(s) asignados' ?>
                                        </span>
                                    </div>
                                    <?php if (!$isAdminRole && $role['user_count'] == 0): ?>
                                        <form method="POST" style="margin: 0;"
                                            onsubmit="confirmDeleteRole(event, this, '<?= htmlspecialchars(addslashes($role['name'])) ?>')">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                            <button type="submit" name="delete_role" class="fc-btn fc-btn-outline"
                                                style="border-color: #ef4444; color: #ef4444; display: flex; align-items: center; gap: 6px;">
                                                <i class='bx bx-trash' style="font-size: 16px;"></i> Eliminar Rol
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- Contenido de Permisos -->
                                <div style="padding: 30px;">
                                    <?php if ($isAdminRole): ?>
                                        <div
                                            style="background: rgba(139, 92, 246, 0.05); border: 1px solid rgba(139, 92, 246, 0.2); border-radius: 16px; padding: 20px; margin-bottom: 25px; color: var(--fc-text-main); font-size: 14px; line-height: 1.6; display: flex; align-items: flex-start; gap: 15px;">
                                            <i class='bx bx-info-circle'
                                                style="color: #8b5cf6; font-size: 1.5rem; margin-top: 2px;"></i>
                                            <div>
                                                <strong
                                                    style="color: #7c3aed; display: block; margin-bottom: 4px; font-size: 15px;">Acceso
                                                    Maestro</strong>
                                                El rol Administrador cuenta con permisos completos e incondicionales sobre toda la barra
                                                lateral y operaciones del sistema. No se puede restringir.
                                            </div>
                                        </div>

                                        <h5
                                            style="font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fc-text-sec); margin-bottom: 15px;">
                                            <i class='bx bx-layout'></i> Módulos de la Barra Lateral Habilitados
                                        </h5>
                                        <div
                                            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-bottom: 25px;">
                                            <?php foreach ($sidebar_mods as $mod): ?>
                                                <div
                                                    style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                                                    <i class='bx bx-check-circle' style="color: #10b981; font-size: 1.3rem;"></i>
                                                    <span
                                                        style="font-size: 14px; font-weight: 600; color: var(--fc-text-main);"><?= htmlspecialchars($mod['name']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" id="form_role_<?= $role['id'] ?>">
                                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">

                                            <!-- Barra de Selección Rápida -->
                                            <div
                                                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; background: #f8fafc; padding: 12px 16px; border-radius: 12px; border: 1px solid #edf2f7;">
                                                <span style="font-size: 13px; font-weight: 700; color: var(--fc-text-sec);"><i
                                                        class='bx bx-pointer'></i> Selección Rápida:</span>
                                                <div style="display: flex; gap: 8px;">
                                                    <button type="button" onclick="selectAllRoleMods(<?= $role['id'] ?>, true)"
                                                        class="fc-btn fc-btn-outline"
                                                        style="padding: 6px 14px; font-size: 12px; height: auto;">
                                                        Todos
                                                    </button>
                                                    <button type="button" onclick="selectAllRoleMods(<?= $role['id'] ?>, false)"
                                                        class="fc-btn fc-btn-outline"
                                                        style="padding: 6px 14px; font-size: 12px; height: auto;">
                                                        Ninguno
                                                    </button>
                                                    <button type="button" onclick="selectSidebarOnly(<?= $role['id'] ?>)"
                                                        class="fc-btn fc-btn-outline"
                                                        style="padding: 6px 14px; font-size: 12px; height: auto; border-color: var(--fc-primary); color: var(--fc-primary);">
                                                        Solo Barra Lateral
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Módulos de Barra Lateral -->
                                            <h5
                                                style="font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fc-text-sec); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                                <i class='bx bx-dock-left' style="color: var(--fc-primary); font-size: 16px;"></i>
                                                Módulos de Barra Lateral
                                            </h5>
                                            <div
                                                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-bottom: 30px;">
                                                <?php foreach ($sidebar_mods as $module): ?>
                                                    <?php $isChecked = in_array($module['id'], $role['modules']); ?>
                                                    <label
                                                        style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: <?= $isChecked ? 'rgba(79, 70, 229, 0.05)' : '#f8fafc' ?>; border-radius: 12px; cursor: pointer; border: 1px solid <?= $isChecked ? 'rgba(79, 70, 229, 0.3)' : '#e2e8f0' ?>; transition: all 0.2s;">
                                                        <span
                                                            style="font-size: 14px; font-weight: 600; color: var(--fc-text-main); display: flex; align-items: center; gap: 10px;">
                                                            <i class='bx <?= htmlspecialchars($module['icon']) ?>'
                                                                style="font-size: 1.3rem; color: <?= $isChecked ? 'var(--fc-primary)' : '#94a3b8' ?>;"></i>
                                                            <?= htmlspecialchars($module['name']) ?>
                                                        </span>
                                                        <div class="custom-switch">
                                                            <input type="checkbox" name="module_ids[]" value="<?= $module['id'] ?>"
                                                                data-sidebar="1" <?= $isChecked ? 'checked' : '' ?>
                                                                onchange="updateCheckboxCardStyle(this)" class="switch-checkbox">
                                                            <div class="switch-slider"></div>
                                                        </div>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>

                                            <!-- Permisos Especiales -->
                                            <?php if (!empty($special_mods)): ?>
                                                <h5
                                                    style="font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: var(--fc-text-sec); margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
                                                    <i class='bx bx-key' style="color: #f59e0b; font-size: 16px;"></i> Permisos y Acciones
                                                    Especiales
                                                </h5>
                                                <div
                                                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-bottom: 30px;">
                                                    <?php foreach ($special_mods as $module): ?>
                                                        <?php $isChecked = in_array($module['id'], $role['modules']); ?>
                                                        <label
                                                            style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: <?= $isChecked ? 'rgba(245, 158, 11, 0.05)' : '#f8fafc' ?>; border-radius: 12px; cursor: pointer; border: 1px solid <?= $isChecked ? 'rgba(245, 158, 11, 0.3)' : '#e2e8f0' ?>; transition: all 0.2s;">
                                                            <span
                                                                style="font-size: 14px; font-weight: 600; color: var(--fc-text-main); display: flex; align-items: center; gap: 10px;">
                                                                <i class='bx <?= htmlspecialchars($module['icon']) ?>'
                                                                    style="font-size: 1.3rem; color: <?= $isChecked ? '#f59e0b' : '#94a3b8' ?>;"></i>
                                                                <?= htmlspecialchars($module['name']) ?>
                                                            </span>
                                                            <div class="custom-switch switch-warning">
                                                                <input type="checkbox" name="module_ids[]" value="<?= $module['id'] ?>"
                                                                    data-sidebar="0" <?= $isChecked ? 'checked' : '' ?>
                                                                    onchange="updateCheckboxCardStyle(this)" class="switch-checkbox">
                                                                <div class="switch-slider"></div>
                                                            </div>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <div style="padding-top: 15px; border-top: 1px solid #e2e8f0;">
                                                <button type="submit" name="update_role_modules" class="fc-btn fc-btn-primary fc-w100"
                                                    style="font-size: 15px; height: 54px; display: flex; align-items: center; justify-content: center; gap: 10px; border-radius: 12px;">
                                                    <i class='bx bx-save' style="font-size: 20px;"></i> Guardar Permisos de
                                                    <?= htmlspecialchars($role['name']) ?>
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Users Management Tab -->
            <?php require_once __DIR__ . '/includes/config_usuarios_tab.php'; ?>

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
                <p style="color: var(--fc-text-sec); font-size: 13px; margin-bottom: 20px;">Define el nombre del nuevo
                    rol y asigna sus módulos de acceso iniciales.</p>
                <div class="fc-form-group">
                    <label class="fc-label">Denominación del Rol</label>
                    <input type="text" name="role_name" class="fc-input"
                        placeholder="Ej: Supervisor, Repartidor, Encargado" required autocomplete="off">
                </div>

                <div class="fc-form-group">
                    <label class="fc-label" style="margin-bottom: 10px;">Módulos Iniciales de Barra Lateral</label>
                    <div
                        style="max-height: 220px; overflow-y: auto; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px; background: #f8fafc; border-radius: 12px; border: 1px solid var(--fc-border);">
                        <?php foreach ($sidebar_mods as $mod): ?>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer;">
                                <input type="checkbox" name="module_ids[]" value="<?= $mod['id'] ?>"
                                    style="accent-color: var(--fc-primary);">
                                <span><?= htmlspecialchars($mod['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100"
                        onclick="closeNewRoleModal()">Cancelar</button>
                    <button type="submit" name="create_role" class="fc-btn fc-btn-primary fc-w100"><i
                            class='bx bx-save'></i> Crear Rol</button>
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
                    <input type="text" name="role_name" id="edit_role_name_val" class="fc-input" required
                        autocomplete="off">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100"
                        onclick="closeEditRoleModal()">Cancelar</button>
                    <button type="submit" name="update_role_name" class="fc-btn fc-btn-primary fc-w100"><i
                            class='bx bx-save'></i> Actualizar Nombre</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Confirmation Modal -->
<div class="fc-modal-overlay" id="systemResetModal">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-lock-alt'></i> Confirmación requerida</h3>
            <button type="button" class="fc-modal-close" onclick="closeSystemResetModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" class="fc-form">
                <p style="color: var(--fc-text-sec); margin-bottom: 20px; font-size: 14px;">Ingresa las credenciales de
                    SuperAdmin para validar el restablecimiento del sistema.</p>
                <div class="fc-form-group">
                    <label class="fc-label">Usuario SuperAdmin</label>
                    <input type="text" name="super_admin_username" class="fc-input" required autocomplete="off">
                </div>
                <div class="fc-form-group">
                    <label class="fc-label">Contraseña</label>
                    <input type="password" name="super_admin_password" class="fc-input" required autocomplete="off">
                </div>
                <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#e11d48; margin-top: 15px; font-weight:600; cursor:pointer;">
                    <input type="checkbox" name="hard_reset" value="1" style="margin-top:2px;">
                    <span>Borrar también mis platos, recetas y mesas físicas.<br><small>(Tus insumos, unidades y categorías quedarán a salvo siempre).</small></span>
                </label>
                <div style="display: flex; gap: 10px; margin-top: 25px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100"
                        onclick="closeSystemResetModal()">Cancelar</button>
                    <button type="submit" name="reset_system" class="fc-btn fc-btn-primary fc-w100">Confirmar
                        Borrado</button>
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
            <button type="button" class="fc-modal-close" onclick="closeRestoreModal()">&times;</button>
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
                    <button type="button" class="fc-btn fc-btn-outline fc-w100"
                        onclick="closeRestoreModal()">Cancelar</button>
                    <button type="submit" name="restore_db" class="fc-btn fc-btn-primary fc-w100"
                        style="background-color: #f59e0b; border-color: #f59e0b;">Restaurar Datos</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    /* Estilos para los interruptores tipo iOS */
    .custom-switch {
        position: relative;
        display: inline-block;
        width: 44px;
        height: 24px;
        flex-shrink: 0;
    }

    .custom-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .switch-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #cbd5e1;
        transition: .3s;
        border-radius: 24px;
    }

    .switch-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .3s;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    input:checked+.switch-slider {
        background-color: var(--fc-primary);
    }

    .switch-warning input:checked+.switch-slider {
        background-color: #f59e0b;
    }

    input:checked+.switch-slider:before {
        transform: translateX(20px);
    }
</style>

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

    function switchRoleView(roleId) {
        document.querySelectorAll('.role-view-panel').forEach(el => {
            el.style.display = 'none';
        });
        const selectedView = document.getElementById('role_view_' + roleId);
        if (selectedView) {
            selectedView.style.display = 'block';
        }
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

    function openSystemResetModal() { document.getElementById('systemResetModal').classList.add('show'); document.getElementById('systemResetModal').style.display = 'flex'; }
    function closeSystemResetModal() { document.getElementById('systemResetModal').classList.remove('show'); document.getElementById('systemResetModal').style.display = 'none'; }
    function openRestoreModal() { document.getElementById('restoreModal').classList.add('show'); document.getElementById('restoreModal').style.display = 'flex'; }
    function closeRestoreModal() { document.getElementById('restoreModal').classList.remove('show'); document.getElementById('restoreModal').style.display = 'none'; }

    // Close modals when clicking outside
    window.addEventListener('click', function(e) {
        if (e.target.classList.contains('fc-modal-overlay')) {
            e.target.classList.remove('show');
            e.target.style.display = 'none';
        }
    });

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

    function testKitchenPrinter() {
        const ip = document.querySelector('input[name="kitchen_printer_ip"]').value;
        const port = document.querySelector('input[name="kitchen_printer_port"]').value || '9100';

        if (!ip) {
            Swal.fire({ icon: 'warning', title: 'IP Inválida', text: 'Por favor ingresa la IP de la impresora.' });
            return;
        }

        Swal.fire({
            title: 'Imprimiendo...',
            text: 'Enviando ticket de prueba a ' + ip,
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        const formData = new FormData();
        formData.append('test_ip', ip);
        formData.append('test_port', port);

        fetch('configuracion.php?ajax=test_printer', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({ icon: 'success', title: '¡Éxito!', text: 'El ticket de prueba ha sido enviado. Revisa tu impresora térmica.' });
                } else {
                    Swal.fire({ icon: 'error', title: 'Fallo de Conexión', text: data.message });
                }
            })
            .catch(e => {
                Swal.fire({ icon: 'error', title: 'Error de Red', text: 'No se pudo contactar al servidor para hacer la prueba.' });
            });
    }

    <?php if ($success_msg): ?>
        Swal.fire({ icon: 'success', title: '¡Hecho!', text: '<?= addslashes($success_msg) ?>', confirmButtonColor: 'var(--fc-primary)' });
    <?php endif; ?>
    <?php if ($error_msg): ?>
        Swal.fire({ icon: 'error', title: 'Error', text: '<?= addslashes($error_msg) ?>', confirmButtonColor: 'var(--fc-primary)' });
    <?php endif; ?>

    // Prevent Form Resubmission Prompt on Page Refresh
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>