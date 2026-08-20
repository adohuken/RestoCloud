<?php
/**
 * InventoryManager - Controlador central de stock y trazabilidad
 */
class InventoryManager {
    private static $pdo;

    private static function init() {
        if (!self::$pdo) {
            global $pdo;
            self::$pdo = $pdo;
        }
    }

    /**
     * Procesa la deducción de stock para un pedido completo
     */
    public static function processOrderStock($order_id, $user_id) {
        self::init();
        
        // 1. Obtener detalles del pedido que NO han sido descontados
        $stmt = self::$pdo->prepare("SELECT od.*, p.name as p_name FROM order_details od JOIN products p ON od.product_id = p.id WHERE od.order_id = ? AND od.stock_deducted = 0");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $qty_ordered = $item['quantity'];

            // 2. Verificar si el producto tiene receta vinculada
            $stmt = self::$pdo->prepare("SELECT pr.*, i.name as ing_name FROM product_recipes pr JOIN ingredients i ON pr.ingredient_id = i.id WHERE pr.product_id = ?");
            $stmt->execute([$product_id]);
            $recipe = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($recipe)) {
                // FLUJO A: Descontar Insumos (Receta)
                foreach ($recipe as $ingredient) {
                    $ing_id = $ingredient['ingredient_id'];
                    $qty_to_deduct = $ingredient['quantity_required'] * $qty_ordered;
                    
                    self::registerMovement($ing_id, -$qty_to_deduct, 'Sale', $user_id, $order_id, "Venta Pedido #$order_id - {$item['p_name']}");
                }
            } else {
                // FLUJO B: Descontar Stock del Producto Directamente (Simple)
                $stmt = self::$pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$qty_ordered, $product_id]);
                
                // Opcional: Registrar movimiento de producto final (sin ingredient_id)
                self::registerProductMovement($product_id, -$qty_ordered, 'Sale', $user_id, $order_id, "Venta Directa Pedido #$order_id");
            }
            // Marcar el item como descontado
            $stmt = self::$pdo->prepare("UPDATE order_details SET stock_deducted = 1 WHERE id = ?");
            $stmt->execute([$item['id']]);
        }
    }

    /**
     * Procesa la deducción de stock para un pedido de PedidosYa
     */
    public static function processPedidosYaStock($py_order_id, $user_id) {
        self::init();
        
        $stmt = self::$pdo->prepare("SELECT pod.*, p.name as p_name FROM pedidosya_order_details pod JOIN products p ON pod.product_id = p.id WHERE pod.pedidosya_order_id = ?");
        $stmt->execute([$py_order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $product_id = $item['product_id'];
            $qty_ordered = $item['quantity'];

            $stmt = self::$pdo->prepare("SELECT pr.*, i.name as ing_name FROM product_recipes pr JOIN ingredients i ON pr.ingredient_id = i.id WHERE pr.product_id = ?");
            $stmt->execute([$product_id]);
            $recipe = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($recipe)) {
                foreach ($recipe as $ingredient) {
                    $ing_id = $ingredient['ingredient_id'];
                    $qty_to_deduct = $ingredient['quantity_required'] * $qty_ordered;
                    self::registerMovement($ing_id, -$qty_to_deduct, 'Sale', $user_id, $py_order_id, "Venta PedidosYa #$py_order_id - {$item['p_name']}");
                }
            } else {
                $stmt = self::$pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->execute([$qty_ordered, $product_id]);
                self::registerProductMovement($product_id, -$qty_ordered, 'Sale', $user_id, $py_order_id, "Venta PedidosYa #$py_order_id");
            }
        }
    }

    /**
     * Registra un movimiento en la tabla stock_movements para Insumos
     */
    public static function registerMovement($ing_id, $qty, $type, $user_id, $ref_id = null, $notes = '') {
        self::init();

        try {
            // Actualizar stock real
            $stmt = self::$pdo->prepare("UPDATE ingredients SET stock = stock + ? WHERE id = ?");
            $stmt->execute([$qty, $ing_id]);

            // Insertar registro en Kárdex
            $stmt = self::$pdo->prepare("INSERT INTO stock_movements (ingredient_id, type, quantity, user_id, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$ing_id, $type, $qty, $user_id, $ref_id, $notes]);
            
            return true;
        } catch (Exception $e) {
            error_log("Error en InventoryManager: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra un movimiento para productos finales (sin ingredientes)
     */
    public static function registerProductMovement($product_id, $qty, $type, $user_id, $ref_id = null, $notes = '') {
        self::init();
        $stmt = self::$pdo->prepare("INSERT INTO stock_movements (product_id, type, quantity, user_id, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$product_id, $type, $qty, $user_id, $ref_id, $notes]);
    }
}
