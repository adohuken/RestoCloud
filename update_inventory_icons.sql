-- Add icon column to ingredients table

SET FOREIGN_KEY_CHECKS = 0;

-- Check if column exists, if not add it
SET @dbname = DATABASE();
SET @tablename = "ingredients";
SET @columnname = "icon";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE ingredients ADD COLUMN icon VARCHAR(50) DEFAULT '📦';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update existing ingredients with icons
UPDATE ingredients SET icon = '🥩' WHERE name LIKE '%Carne%' OR name LIKE '%Tocino%';
UPDATE ingredients SET icon = '🍔' WHERE name LIKE '%Pan%';
UPDATE ingredients SET icon = '🧀' WHERE name LIKE '%Queso%';
UPDATE ingredients SET icon = '🍗' WHERE name LIKE '%Alitas%';
UPDATE ingredients SET icon = '🥫' WHERE name LIKE '%Salsa%';
UPDATE ingredients SET icon = '🥔' WHERE name LIKE '%Papa%';
UPDATE ingredients SET icon = '🛢️' WHERE name LIKE '%Aceite%';
UPDATE ingredients SET icon = '🍅' WHERE name LIKE '%Tomate%';
UPDATE ingredients SET icon = '🥬' WHERE name LIKE '%Lechuga%';
UPDATE ingredients SET icon = '🧅' WHERE name LIKE '%Cebolla%';

SET FOREIGN_KEY_CHECKS = 1;
