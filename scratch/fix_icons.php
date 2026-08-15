<?php
require_once __DIR__ . '/../config/db.php';

// Fix category name "LÃ¡¡cteos/Huevos"
$stmt = $pdo->query("SELECT id, name FROM ingredient_categories");
$cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($cats as $cat) {
    if (strpos($cat['name'], 'L') !== false && strpos($cat['name'], 'cteos') !== false) {
        $pdo->exec("UPDATE ingredient_categories SET name = 'Lácteos/Huevos' WHERE id = " . $cat['id']);
        echo "Fixed ingredient category: " . $cat['name'] . "\n";
    }
}

// Fix "AcciÃ“n" in configuracion.php
$content = file_get_contents(__DIR__ . '/../configuracion.php');
$content = str_replace('ACCIÃ“N', 'ACCIÓN', $content);
file_put_contents(__DIR__ . '/../configuracion.php', $content);
echo "Fixed ACCIÓN in configuracion.php\n";

// Remove broken icons from ingredients
$stmt = $pdo->query("SELECT id, icon FROM ingredients WHERE icon IS NOT NULL AND icon != ''");
$ings = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($ings as $ing) {
    // If it contains strange characters
    if (strpos($ing['icon'], 'ðŸ') !== false || strlen($ing['icon']) > 5) {
        $pdo->exec("UPDATE ingredients SET icon = '' WHERE id = " . $ing['id']);
        echo "Removed broken icon for ingredient ID " . $ing['id'] . "\n";
    }
}

echo "All fixed.";
