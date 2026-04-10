<?php
require_once __DIR__ . '/config/db.php';

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM ingredients LIKE 'unit'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    print_r($col);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>