<?php
$_SERVER['HTTP_HOST'] = 'localhost';
include __DIR__ . '/../config/db.php';
$stmt = $pdo->query('SELECT * FROM roles');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
