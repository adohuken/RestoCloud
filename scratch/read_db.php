<?php
require_once __DIR__ . '/../config/db.php';

$stmt = $pdo->query("SELECT id, name, icon FROM ingredients WHERE name LIKE '%Aceite%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $pdo->query("SELECT id, name FROM categories");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

// And let's check ingredient categories
$stmt = $pdo->query("SELECT id, name FROM ingredient_categories");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
