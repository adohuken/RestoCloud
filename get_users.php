<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>Usuarios Activos del Sistema</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Usuario</th><th>Nombre</th><th>Role ID</th><th>Contraseña (hash)</th></tr>";

$stmt = $pdo->query('SELECT id, username, name, role_id, password FROM users WHERE status = "active" ORDER BY id');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['username']) . "</td>";
    echo "<td>" . htmlspecialchars($row['name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['role_id']) . "</td>";
    echo "<td style='font-size: 10px;'>" . htmlspecialchars(substr($row['password'], 0, 40)) . "...</td>";
    echo "</tr>";
}
echo "</table>";

echo "<hr>";
echo "<h3>Roles:</h3>";
echo "<ul>";
$stmt = $pdo->query('SELECT id, name FROM roles ORDER BY id');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<li>Role ID " . $row['id'] . ": " . htmlspecialchars($row['name']) . "</li>";
}
echo "</ul>";

echo "<p><strong>Nota:</strong> Las contraseñas están encriptadas. Si no sabes la contraseña de algún usuario, necesitarás resetearla.</p>";
?>
