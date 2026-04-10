<?php
require_once __DIR__ . '/config/db.php';

try {
    // 1. Ensure column exists
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0");
    } catch (PDOException $e) {
        // Ignore "Duplicate column name" error
    }

    // 2. Truncate users table (Reset)
    $pdo->exec("TRUNCATE TABLE users");

    // 3. Create Default Roles if they don't exist (safety check)
    $pdo->exec("INSERT IGNORE INTO roles (id, name) VALUES (1, 'Administrador'), (2, 'Gerente'), (3, 'Cajero'), (4, 'Cocina')");

    // 4. Create Admin User
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role_id, is_super_admin, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute(['admin', $password, 'Administrador Principal', 1, 1, 'active']);

    // 5. Create Kitchen User
    $pass_kitchen = password_hash('cocina123', PASSWORD_DEFAULT);
    $stmt->execute(['cocina', $pass_kitchen, 'Personal de Cocina', 4, 0, 'active']);

    echo "✅ Usuarios reiniciados correctamente.\n";
    echo "------------------------------------------------\n";
    echo "Usuario: admin\nPassword: admin123\n";
    echo "------------------------------------------------\n";
    echo "Usuario: cocina\nPassword: cocina123\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>