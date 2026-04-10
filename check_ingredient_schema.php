<?php
require_once __DIR__ . '/config/db.php';
try {
    $cols = $pdo->query("DESCRIBE ingredients")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>