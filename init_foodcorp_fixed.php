<?php
// Manually define connection params to avoid connecting to the non-existent DB
$host = 'localhost';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

try {
    // Connect to MySQL server without selecting a database
    $pdo_root = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Read SQL file
    $sql_file = __DIR__ . '/database_foodcorp.sql';
    if (!file_exists($sql_file)) {
        die("Error: SQL file not found at $sql_file");
    }

    $sql = file_get_contents($sql_file);

    // Execute SQL
    $pdo_root->exec($sql);

    echo "¡Éxito! La base de datos 'foodcorp_system' ha sido creada e inicializada correctamente.";

} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}
?>