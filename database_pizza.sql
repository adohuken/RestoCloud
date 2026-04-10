  -- Database: pizza_system
  CREATE DATABASE IF NOT EXISTS pizza_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  USE pizza_system;

  SET FOREIGN_KEY_CHECKS = 0;

  -- Roles table
  DROP TABLE IF EXISTS roles;
  CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE
  ) ENGINE=InnoDB;

  -- Users table
  DROP TABLE IF EXISTS users;
  CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role_id INT NOT NULL,
    status ENUM('active','inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE CASCADE
  ) ENGINE=InnoDB;

  -- Categories table
  DROP TABLE IF EXISTS categories;
  CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB;

  -- Products table
  DROP TABLE IF EXISTS products;
  CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    stock INT DEFAULT 0,
    category_id INT,
    image VARCHAR(255),
    status ENUM('active','inactive') DEFAULT 'active',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL ON UPDATE CASCADE
  ) ENGINE=InnoDB;

  -- Tables (restaurant tables) table
  DROP TABLE IF EXISTS tables;
  CREATE TABLE IF NOT EXISTS tables (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    status ENUM('available','occupied','reserved') DEFAULT 'available'
  ) ENGINE=InnoDB;

  -- Orders table
  DROP TABLE IF EXISTS orders;
  CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_id INT NOT NULL,
    user_id INT NOT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('pending','completed','cancelled') DEFAULT 'pending',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (table_id) REFERENCES tables(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
  ) ENGINE=InnoDB;

  -- Order details table
  DROP TABLE IF EXISTS order_details;
  CREATE TABLE IF NOT EXISTS order_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT ON UPDATE CASCADE
  ) ENGINE=InnoDB;

  -- Cash register table
  DROP TABLE IF EXISTS cash_register;
  CREATE TABLE IF NOT EXISTS cash_register (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    type ENUM('open','close') NOT NULL,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','closed') DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE
  ) ENGINE=InnoDB;

  -- Payments table
  DROP TABLE IF EXISTS payments;
  CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    method ENUM('cash','transfer','card') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    cash_register_id INT,
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (cash_register_id) REFERENCES cash_register(id)
  ) ENGINE=InnoDB;

  -- Insert default roles
  INSERT INTO roles (name) VALUES ('Admin'), ('Mesero'), ('Cajero'), ('Cocina');

  -- Insert default admin user (username: admin, password: admin123)
  INSERT INTO users (name, email, username, password, role_id) VALUES (
      'Administrador',
      'admin@pizzeria.com',
      'admin',
      '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
      1
  );

  -- Insert other users
  INSERT INTO users (name, email, username, password, role_id) VALUES 
  ('Juan Mesero', 'juan@pizzeria.com', 'mesero', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2),
  ('Ana Cajera', 'ana@pizzeria.com', 'cajero', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 3),
  ('Carlos Cocina', 'carlos@pizzeria.com', 'cocina', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 4);

  -- Insert default tables
  INSERT INTO tables (name, status) VALUES 
  ('Mesa 1', 'available'), ('Mesa 2', 'available'), ('Mesa 3', 'available'),
  ('Mesa 4', 'available'), ('Mesa 5', 'available'), ('Mesa 6', 'available'),
  ('Mesa 7', 'available'), ('Mesa 8', 'available');

  -- Insert default categories
  INSERT INTO categories (name) VALUES 
  ('Pizzas Clásicas'),
  ('Pizzas Especiales'),
  ('Bebidas'),
  ('Entradas'),
  ('Postres');

  -- Insert default products
  INSERT INTO products (code, name, description, price, stock, category_id, status) VALUES 
  -- Pizzas Clásicas
  ('PIZ001', 'Pizza Pepperoni', 'Salsa de tomate, queso mozzarella y pepperoni', 180.00, 100, 1, 'active'),
  ('PIZ002', 'Pizza Margarita', 'Salsa de tomate, queso mozzarella y albahaca', 150.00, 100, 1, 'active'),
  ('PIZ003', 'Pizza Jamón y Queso', 'Salsa de tomate, queso mozzarella y jamón', 170.00, 100, 1, 'active'),

  -- Pizzas Especiales
  ('PIZ004', 'Pizza Hawaiana', 'Salsa de tomate, queso, jamón y piña', 190.00, 100, 2, 'active'),
  ('PIZ005', 'Pizza Carnívora', 'Pepperoni, jamón, carne molida y tocino', 220.00, 100, 2, 'active'),
  ('PIZ006', 'Pizza Vegetariana', 'Pimientos, cebolla, champiñones y aceitunas', 200.00, 100, 2, 'active'),

  -- Bebidas
  ('BEB001', 'Coca-Cola 500ml', 'Refresco de cola', 25.00, 200, 3, 'active'),
  ('BEB002', 'Limonada Natural', 'Limonada fresca', 30.00, 100, 3, 'active'),
  ('BEB003', 'Cerveza Nacional', 'Cerveza bien fría', 45.00, 150, 3, 'active'),

  -- Entradas
  ('ENT001', 'Pan de Ajo', '4 rebanadas de pan con mantequilla de ajo', 60.00, 50, 4, 'active'),
  ('ENT002', 'Dedos de Queso', '6 dedos de queso empanizados', 90.00, 50, 4, 'active'),

  -- Postres
  ('POS001', 'Brownie con Helado', 'Brownie de chocolate con bola de vainilla', 85.00, 30, 5, 'active');

  -- PedidosYa orders table
  DROP TABLE IF EXISTS pedidosya_orders;
  CREATE TABLE IF NOT EXISTS pedidosya_orders (
      id INT AUTO_INCREMENT PRIMARY KEY,
      external_order_id VARCHAR(100) NOT NULL COMMENT 'Número de pedido de PedidosYa',
      customer_name VARCHAR(150) DEFAULT NULL COMMENT 'Nombre del cliente',
      customer_phone VARCHAR(50) DEFAULT NULL COMMENT 'Teléfono del cliente',
      customer_address TEXT DEFAULT NULL COMMENT 'Dirección de entrega',
      subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
      iva_percentage DECIMAL(5,2) DEFAULT 0,
      iva_amount DECIMAL(10,2) DEFAULT 0,
      total DECIMAL(10,2) NOT NULL DEFAULT 0,
      notes TEXT COMMENT 'Notas adicionales del pedido',
      status ENUM('pending','completed','cancelled') DEFAULT 'completed',
      created_by INT NOT NULL,
      date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- PedidosYa order details table
  DROP TABLE IF EXISTS pedidosya_order_details;
  CREATE TABLE IF NOT EXISTS pedidosya_order_details (
      id INT AUTO_INCREMENT PRIMARY KEY,
      pedidosya_order_id INT NOT NULL,
      product_id INT NOT NULL,
      product_name VARCHAR(150) NOT NULL COMMENT 'Nombre del producto al momento de la venta',
      quantity INT NOT NULL DEFAULT 1,
      price DECIMAL(10,2) NOT NULL COMMENT 'Precio unitario al momento de la venta',
      FOREIGN KEY (pedidosya_order_id) REFERENCES pedidosya_orders(id) ON DELETE CASCADE,
      FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- Invoices table
  DROP TABLE IF EXISTS invoices;
  CREATE TABLE IF NOT EXISTS invoices (
      id INT AUTO_INCREMENT PRIMARY KEY,
      order_id INT NOT NULL,
      table_name VARCHAR(50),
      subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
      iva_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
      iva_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
      total DECIMAL(10,2) NOT NULL DEFAULT 0,
      payment_method VARCHAR(50) DEFAULT NULL,
      status VARCHAR(20) DEFAULT 'paid',
      split_number INT DEFAULT NULL,
      total_splits INT DEFAULT NULL,
      has_mixed_payments TINYINT(1) DEFAULT 0,
      parent_invoice_id INT DEFAULT NULL,
      date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- Invoice Payments table (for splits and mixed payments tracking)
  DROP TABLE IF EXISTS invoice_payments;
  CREATE TABLE IF NOT EXISTS invoice_payments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      invoice_id INT NOT NULL,
      payment_method VARCHAR(50) NOT NULL,
      amount DECIMAL(10,2) NOT NULL,
      date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- Settings table
  DROP TABLE IF EXISTS settings;
  CREATE TABLE IF NOT EXISTS settings (
      id INT AUTO_INCREMENT PRIMARY KEY,
      setting_key VARCHAR(50) UNIQUE NOT NULL,
      setting_value TEXT,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB;

  -- Insert default IVA setting if not exists
  INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('iva_percentage', '15');

  SET FOREIGN_KEY_CHECKS = 1;
