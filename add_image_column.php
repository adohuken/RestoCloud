<?php
require_once __DIR__ . '/config/db.php';

try {
    // Add column image_url to products table if not exists
    $sql = "
    SET @dbname = DATABASE();
    SET @tablename = 'products';
    SET @columnname = 'image_url';
    SET @preparedStatement = (SELECT IF(
      (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
          (table_name = @tablename)
          AND (table_schema = @dbname)
          AND (column_name = @columnname)
      ) > 0,
      'SELECT 1',
      'ALTER TABLE products ADD COLUMN image_url VARCHAR(255) DEFAULT NULL;'
    ));
    PREPARE alterIfNotExists FROM @preparedStatement;
    EXECUTE alterIfNotExists;
    DEALLOCATE PREPARE alterIfNotExists;
    ";

    $pdo->exec($sql);
    echo "Column image_url added successfully (or already existed).";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>