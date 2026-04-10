<?php
require_once __DIR__ . '/config/db.php';

try {
    // Connect to MySQL server without selecting a database first (to create it)
    $pdo_root = new PDO("mysql:host=$host;charset=$charset", $user, $pass, $options);

    // Read SQL file
    $sql_file = __DIR__ . '/database_foodcorp.sql';
    if (!file_exists($sql_file)) {
        die("Error: SQL file not found at $sql_file");
    }

    $sql = file_get_contents($sql_file);

    // Execute SQL
    $pdo_root->exec($sql);

    echo "¡Éxito! La base de datos 'foodcorp_system' ha sido creada e inicializada correctamente con Alitas y Hamburguesas.";

} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}
?>