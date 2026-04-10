-- Add Inventory and Recipe tables

SET FOREIGN_KEY_CHECKS = 0;

-- Ingredients table (Materia Prima)
DROP TABLE IF EXISTS ingredients;
CREATE TABLE IF NOT EXISTS ingredients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    unit ENUM('kg', 'g', 'l', 'ml', 'unidad') NOT NULL DEFAULT 'unidad',
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Cost per unit',
    stock DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    min_stock DECIMAL(10,3) DEFAULT 5.000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product Recipes table (Linking Products to Ingredients)
DROP TABLE IF EXISTS product_recipes;
CREATE TABLE IF NOT EXISTS product_recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    quantity_required DECIMAL(10,4) NOT NULL COMMENT 'Amount of ingredient needed for 1 unit of product',
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Insert some default ingredients for testing
INSERT INTO ingredients (name, unit, cost, stock) VALUES 
('Carne Molida', 'kg', 120.00, 10.000),
('Pan de Hamburguesa', 'unidad', 5.00, 100.000),
('Queso Cheddar', 'kg', 180.00, 5.000),
('Tocino', 'kg', 200.00, 5.000),
('Alitas Crudas', 'kg', 80.00, 20.000),
('Salsa BBQ', 'l', 150.00, 5.000),
('Salsa Buffalo', 'l', 160.00, 5.000),
('Papas Congeladas', 'kg', 40.00, 50.000),
('Aceite', 'l', 35.00, 20.000);
