<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "Tables:\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    print_r($tables);

    echo "\nProducts Schema:\n";
    $cols = $pdo->query("DESCRIBE products")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>