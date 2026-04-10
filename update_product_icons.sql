-- Add icon column to products table

SET FOREIGN_KEY_CHECKS = 0;

-- Check if column exists, if not add it
SET @dbname = DATABASE();
SET @tablename = "products";
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
  "ALTER TABLE products ADD COLUMN icon VARCHAR(50) DEFAULT '🍽️';"
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- Update existing products with category-based icons as default
UPDATE products p 
JOIN categories c ON p.category_id = c.id 
SET p.icon = CASE 
    WHEN c.name LIKE '%Alitas%' THEN '🍗'
    WHEN c.name LIKE '%Hamburguesas%' THEN '🍔'
    WHEN c.name LIKE '%Combos%' THEN '🥡'
    WHEN c.name LIKE '%Bebidas%' THEN '🥤'
    WHEN c.name LIKE '%Complementos%' THEN '🍟'
    WHEN c.name LIKE '%Postres%' THEN '🍰'
    ELSE '🍽️'
END
WHERE p.icon = '🍽️';

SET FOREIGN_KEY_CHECKS = 1;
