<?php
require_once __DIR__ . '/config/db.php';

try {
    // Nueva contraseña para el admin
    $new_password = 'admin123';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Actualizar la contraseña del usuario admin (ID 1)
    $stmt = $pdo->prepare("UPDATE users SET password = :password WHERE id = 1");
    $stmt->execute(['password' => $hashed_password]);
    
    echo "<h2>✅ Contraseña actualizada exitosamente</h2>";
    echo "<p><strong>Usuario:</strong> admin</p>";
    echo "<p><strong>Nueva contraseña:</strong> admin123</p>";
    echo "<p>Ahora puedes iniciar sesión en: <a href='index.php'>http://localhost/System_pizza/</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Error al actualizar la contraseña</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
