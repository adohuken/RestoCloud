-- ====================================================================
-- SCRIPT DE DATOS DE PRUEBA (NUEVOS PRODUCTOS E INSUMOS)
-- ====================================================================
-- Instrucciones para el Hosting:
-- 1. Ingrese a cPanel / phpMyAdmin.
-- 2. Seleccione su base de datos (ej: if0_42662715_RestoCloud).
-- 3. Vaya a la pestaña "Importar", seleccione este archivo y ejecútelo.
-- 4. Suba las 7 imágenes desde su carpeta local 'uploads/products/'
--    hacia la carpeta 'uploads/products/' del hosting utilizando FTP o el Administrador de Archivos.
-- ====================================================================

-- 1. Insertar 7 nuevos productos en el Menú
INSERT INTO products (code, name, price, stock, category_id, status, image_url)
VALUES 
('PROD-TACOS', 'Tacos de Asada', 85.00, 100, 6, 'active', 'uploads/products/tacos.jpg'),
('PROD-ALITAS', 'Alitas BBQ', 90.00, 100, 6, 'active', 'uploads/products/alitas.jpg'),
('PROD-PAPASSUP', 'Papas Fritas Suprema', 65.00, 100, 6, 'active', 'uploads/products/papas_suprema.jpg'),
('PROD-SUSHITEMP', 'Sushi Roll Tempura', 110.00, 100, 6, 'active', 'uploads/products/sushi.jpg'),
('PROD-MOJITO', 'Mojito Clásico', 45.00, 100, 1, 'active', 'uploads/products/mojito.jpg'),
('PROD-HELADOMIX', 'Copa de Helado Mixta', 40.00, 100, 3, 'active', 'uploads/products/helado.jpg'),
('PROD-CLUBSAND', 'Club Sandwich', 80.00, 100, 2, 'active', 'uploads/products/club_sandwich.jpg')
ON DUPLICATE KEY UPDATE 
price = VALUES(price),
image_url = VALUES(image_url),
status = 'active';

-- 2. Insertar 7 nuevos insumos en la Maestra de Insumos
INSERT INTO ingredients (name, cost, stock, min_stock, unit, category_id, icon)
VALUES
('Tortillas de Maíz', 0.15, 500, 50, 'und', 15, '🌽'),
('Alitas de Pollo (Crudas)', 3.20, 50, 10, 'kg', 11, '🍗'),
('Papas Fritas Congeladas', 2.50, 80, 15, 'kg', 15, '🍟'),
('Arroz para Sushi', 1.10, 30, 5, 'kg', 15, '🍚'),
('Hierbabuena / Menta', 0.50, 5, 1, 'kg', 14, '🌿'),
('Helado de Vainilla', 4.20, 12, 2, 'kg', 13, '🍨'),
('Tocino en Rebanadas', 8.50, 10, 3, 'kg', 11, '🥓')
ON DUPLICATE KEY UPDATE
cost = VALUES(cost),
stock = VALUES(stock),
min_stock = VALUES(min_stock),
icon = VALUES(icon);
