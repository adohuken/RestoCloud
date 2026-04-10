<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "<h2>Actualizando a SuperAdmin...</h2>";
    
    // 1. Actualizar el nombre del rol de "Admin" a "SuperAdmin"
    echo "<p>1. Actualizando nombre del rol...</p>";
    $stmt = $pdo->prepare("UPDATE roles SET name = 'SuperAdmin' WHERE id = 1");
    $stmt->execute();
    echo "<p>✅ Rol actualizado: Admin → SuperAdmin</p>";
    
    // 2. Actualizar el nombre del usuario
    echo "<p>2. Actualizando nombre del usuario...</p>";
    $stmt = $pdo->prepare("UPDATE users SET name = 'SuperAdmin' WHERE id = 1");
    $stmt->execute();
    echo "<p>✅ Nombre de usuario actualizado: Administrador → SuperAdmin</p>";
    
    // 3. Actualizar el username
    echo "<p>3. Actualizando username...</p>";
    $stmt = $pdo->prepare("UPDATE users SET username = 'superadmin' WHERE id = 1");
    $stmt->execute();
    echo "<p>✅ Username actualizado: admin → superadmin</p>";
    
    // 4. Actualizar contraseña
    $new_password = 'superadmin123';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = 1");
    $stmt->execute(['password' => $hashed_password]);
    echo "<p>✅ Contraseña actualizada: superadmin123</p>";
    
    echo "<hr>";
    echo "<h3>✅ Actualización completada exitosamente</h3>";
    echo "<div style='background: #e8f5e9; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<p><strong>Nuevas credenciales de SuperAdmin:</strong></p>";
    echo "<p>👤 <strong>Usuario:</strong> superadmin</p>";
    echo "<p>🔑 <strong>Contraseña:</strong> superadmin123</p>";
    echo "<p>👥 <strong>Rol:</strong> SuperAdmin</p>";
    echo "</div>";
    
    echo "<p>Ahora puedes iniciar sesión en: <a href='index.php'>http://localhost/System_pizza/</a></p>";
    
    // Mostrar información actualizada
    echo "<hr>";
    echo "<h3>Información del usuario actualizado:</h3>";
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.name, u.email, r.name as role_name 
        FROM users u 
        JOIN roles r ON u.role_id = r.id 
        WHERE u.id = 1
    ");
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Nombre</th><th>Email</th><th>Rol</th></tr>";
    echo "<tr>";
    echo "<td>" . $user['id'] . "</td>";
    echo "<td>" . htmlspecialchars($user['username']) . "</td>";
    echo "<td>" . htmlspecialchars($user['name']) . "</td>";
    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
    echo "<td>" . htmlspecialchars($user['role_name']) . "</td>";
    echo "</tr>";
    echo "</table>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Error al actualizar</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
