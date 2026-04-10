<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "Starting Inventory Migration...<br>";

    // 1. Create ingredient_categories table
    $sql = "CREATE TABLE IF NOT EXISTS ingredient_categories (
        id INT AUTO_INCREMENT PRIMARY_KEY,
        name VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // Fix: PRIMARY KEY syntax error in previous line logic concept 
    // Correct SQL:
    $sql = "CREATE TABLE IF NOT EXISTS ingredient_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);
    echo "Table 'ingredient_categories' checked/created.<br>";

    // 2. Populate default categories (matching the JS icons we had)
    $categories = [
        'Carnes' => '🥩',
        'Verduras' => '🥦',
        'Frutas' => '🍎',
        'Lácteos/Huevos' => '🥛',
        'Granos/Pan' => '🍞',
        'Bebidas' => '🥤',
        'Condimentos' => '🧂',
        'Otros' => '📦'
    ];

    foreach ($categories as $catName => $icon) {
        // Check if exists
        $stmt = $pdo->prepare("SELECT id FROM ingredient_categories WHERE name = ?");
        $stmt->execute([$catName]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO ingredient_categories (name) VALUES (?)");
            $stmt->execute([$catName]);
            echo "Added category: $catName<br>";
        }
    }

    // 3. Add category_id to ingredients
    $stmt = $pdo->query("SHOW COLUMNS FROM ingredients LIKE 'category_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN category_id INT DEFAULT NULL");
        echo "Column 'category_id' added to ingredients.<br>";
    }

    // 4. Migrate existing ingredients based on icons
    // We'll use a mapping similar to the JS one to auto-assign
    $migrationMap = [
        'Carnes' => ['🥩', '🍗', '🍖', '🥓', '🍔', '🌭'],
        'Verduras' => ['🥦', '🥕', '🌽', '🥬', '🍅', '🍆', '🥔', '🧅', '🧄', '🥒', '🍄'],
        'Frutas' => ['🍎', '🍌', '🍇', '🍊', '🍋', '🍍', '🥭', '🍑', '🍒', '🍓', '🥑'],
        'Lácteos/Huevos' => ['🥛', '🧀', '🥚', '🧈', '🍦'],
        'Granos/Pan' => ['🍞', '🥐', '🥖', '🥨', '🥞', '🌾', '🍚', '🍝', '🍜'],
        'Bebidas' => ['🥤', '🧃', '☕', '🍵', '🍺', '🍷', '🍹', '🍾'],
        'Condimentos' => ['🧂', '🌶️', '🌿', '🍯', '🍫', '🍬', '🥥', '🥜']
    ];

    foreach ($migrationMap as $catName => $icons) {
        // Get Cat ID
        $stmt = $pdo->prepare("SELECT id FROM ingredient_categories WHERE name = ?");
        $stmt->execute([$catName]);
        $catId = $stmt->fetchColumn();

        if ($catId) {
            foreach ($icons as $icon) {
                $stmt = $pdo->prepare("UPDATE ingredients SET category_id = ? WHERE icon = ? AND category_id IS NULL");
                $stmt->execute([$catId, $icon]);
            }
        }
    }

    // Set default category 'Otros' (or 1) for any remaining NULLs
    $stmt = $pdo->prepare("SELECT id FROM ingredient_categories WHERE name = 'Otros'");
    $stmt->execute();
    $otherId = $stmt->fetchColumn();
    if ($otherId) {
        $pdo->exec("UPDATE ingredients SET category_id = $otherId WHERE category_id IS NULL");
        echo "Assigned remaining items to 'Otros'.<br>";
    }

    echo "Migration Complete!";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>