<?php
require_once __DIR__ . '/../config/db.php';

// Wipe all icons in products to fall back to '🍽️'
$pdo->exec("UPDATE products SET icon = ''");

echo "Fixed products.";
