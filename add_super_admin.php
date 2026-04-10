<?php
require_once __DIR__ . '/config/db.php';

try {
    // Add column is_super_admin to users table if not exists
    $sql = "
    SET @dbname = DATABASE();
    SET @tablename = 'users';
    SET @columnname = 'is_super_admin';
    SET @preparedStatement = (SELECT IF(
      (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
          (table_name = @tablename)
          AND (table_schema = @dbname)
          AND (column_name = @columnname)
      ) > 0,
      'SELECT 1',
      'ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) DEFAULT 0;'
    ));
    PREPARE alterIfNotExists FROM @preparedStatement;
    EXECUTE alterIfNotExists;
    DEALLOCATE PREPARE alterIfNotExists;
    ";

    $pdo->exec($sql);
    echo "Column is_super_admin added successfully (or already existed).";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>