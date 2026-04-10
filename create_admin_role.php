<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "<h2>Creando nuevo rol Admin...</h2>";
    
    // Primero verificar los roles actuales
    echo "<h3>Roles actuales:</h3>";
    $stmt = $pdo->query("SELECT * FROM roles ORDER BY id");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>ID</th><th>Nombre</th></tr>";
    foreach ($roles as $role) {
        echo "<tr><td>" . $role['id'] . "</td><td>" . htmlspecialchars($role['name']) . "</td></tr>";
    }
    echo "</table>";
    
    // Verificar si el rol Admin ya existe
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'Admin'");
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: orange;'>⚠️ El rol 'Admin' ya existe en la base de datos.</p>";
    } else {
        // Crear el nuevo rol Admin
        $stmt = $pdo->prepare("INSERT INTO roles (name) VALUES ('Admin')");
        $stmt->execute();
        $new_role_id = $pdo->lastInsertId();
        
        echo "<p style='color: green;'>✅ Nuevo rol 'Admin' creado exitosamente con ID: <strong>" . $new_role_id . "</strong></p>";
    }
    
    // Mostrar todos los roles actualizados
    echo "<h3>Roles actualizados:</h3>";
    $stmt = $pdo->query("SELECT * FROM roles ORDER BY id");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Nombre</th></tr>";
    foreach ($roles as $role) {
        $highlight = ($role['name'] == 'Admin' && $role['id'] != 1) ? " style='background-color: #c8e6c9;'" : "";
        echo "<tr$highlight><td>" . $role['id'] . "</td><td>" . htmlspecialchars($role['name']) . "</td></tr>";
    }
    echo "</table>";
    
    echo "<p><em>Nota: El nuevo rol 'Admin' está resaltado en verde.</em></p>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Error</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
