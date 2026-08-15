<?php
require_once __DIR__ . '/../config/db.php';

// Fix category again
$pdo->exec("UPDATE ingredient_categories SET name = 'Lácteos/Huevos' WHERE name LIKE '%cteos%'");
$pdo->exec("UPDATE categories SET name = 'Lácteos/Huevos' WHERE name LIKE '%cteos%'");

// Wipe all icons in ingredients
$pdo->exec("UPDATE ingredients SET icon = ''");

// Fix AcciÃ“n in configuracion.php
$content = file_get_contents(__DIR__ . '/../configuracion.php');
$content = str_replace('ACCIÃ“N', 'ACCIÓN', $content);
file_put_contents(__DIR__ . '/../configuracion.php', $content);

echo "Fixed everything.";
