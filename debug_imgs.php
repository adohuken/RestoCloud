<?php
require 'config/db.php';
$stmt = $pdo->query('SELECT id, name, image_url FROM products LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
