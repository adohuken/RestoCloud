<?php
require_once __DIR__ . '/config/db.php';

try {
    // Check if role exists
    $stmt = $pdo->query("SELECT * FROM roles WHERE id = 5");
    $role = $stmt->fetch();

    if ($role) {
        echo "Role 'Superadmin' (ID 5) already exists.\n";
    } else {
        // Create role
        $pdo->exec("INSERT INTO roles (id, name) VALUES (5, 'Superadmin')");
        echo "Role 'Superadmin' (ID 5) created successfully.\n";
    }

    // Assign to user with is_super_admin = 1
    $stmt = $pdo->query("SELECT id FROM users WHERE is_super_admin = 1");
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $pdo->prepare("UPDATE users SET role_id = 5 WHERE id = ?");
        $stmt->execute([$user['id']]);
        echo "User ID " . $user['id'] . " assigned to role Superadmin.\n";
    } else {
        echo "No user found with is_super_admin = 1.\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
