<?php
require_once __DIR__ . '/config/db.php';

try {
    // Read SQL file
    $sql_file = __DIR__ . '/update_product_icons.sql';
    if (!file_exists($sql_file)) {
        die("Error: SQL file not found at $sql_file");
    }

    // Execute SQL
    try {
        $pdo->exec("ALTER TABLE products ADD COLUMN icon VARCHAR(50) DEFAULT '🍽️'");
    } catch (PDOException $e) {
        // Ignore if column exists
    }

    // Update defaults
    $pdo->exec("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.icon = '🍗' WHERE c.name LIKE '%Alitas%' AND p.icon = '🍽️'");
    $pdo->exec("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.icon = '🍔' WHERE c.name LIKE '%Hamburguesas%' AND p.icon = '🍽️'");
    $pdo->exec("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.icon = '🥡' WHERE c.name LIKE '%Combos%' AND p.icon = '🍽️'");
    $pdo->exec("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.icon = '🥤' WHERE c.name LIKE '%Bebidas%' AND p.icon = '🍽️'");
    $pdo->exec("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.icon = '🍟' WHERE c.name LIKE '%Complementos%' AND p.icon = '🍽️'");
    $pdo->exec("UPDATE products p JOIN categories c ON p.category_id = c.id SET p.icon = '🍰' WHERE c.name LIKE '%Postres%' AND p.icon = '🍽️'");

    echo "¡Éxito! Columna de iconos agregada a productos.";

} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}
?>