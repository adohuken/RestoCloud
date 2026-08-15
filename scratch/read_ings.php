<?php
require_once __DIR__ . '/../config/db.php';
$stmt = $pdo->query("SELECT * FROM ingredients");
$ings = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($ings);
