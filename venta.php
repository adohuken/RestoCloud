<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'pos');

$table_id = $_GET['table'] ?? null;
$libre_order = $_GET['libre'] ?? null;
$is_libre = false;

// Handle libre (free) orders - orders without a table
if ($libre_order !== null) {
    $is_libre = true;

    // If libre is a number > 1, it's an existing order ID - verify it exists
    if ($libre_order > 1) {
        // Get the order and verify it's a libre order (from Barra table)
        $stmt = $pdo->prepare('
            SELECT o.*, t.name as table_name 
            FROM orders o 
            JOIN tables t ON o.table_id = t.id 
            WHERE o.id = ? AND t.name = "Barra"
        ');
        $stmt->execute([$libre_order]);
        $existing_libre = $stmt->fetch();
        if (!$existing_libre) {
            header('Location: mesas.php');
            exit();
        }
        $table_id = $existing_libre['table_id'];
        $table = ['id' => $table_id, 'name' => 'Pedido Libre', 'status' => 'available'];
    } else {
        // New libre order - will get/create Barra table later
        $table_id = 0;
        $table = ['id' => 0, 'name' => 'Pedido Libre', 'status' => 'available'];
    }
} else {
    if (!$table_id) {
        header('Location: mesas.php');
        exit();
    }

    // Get table info
    $stmt = $pdo->prepare('SELECT * FROM tables WHERE id = ?');
    $stmt->execute([$table_id]);
    $table = $stmt->fetch();

    if (!$table) {
        header('Location: mesas.php');
        exit();
    }
}

// For libre orders, we need a special "Barra" table to satisfy foreign key
$barra_table_id = null;
if ($is_libre) {
    // Check if "Barra" table exists, if not create it
    $stmt = $pdo->prepare('SELECT id FROM tables WHERE name = "Barra" LIMIT 1');
    $stmt->execute();
    $barra = $stmt->fetch();

    if (!$barra) {
        // Create "Barra" virtual table for libre orders
        $pdo->exec('INSERT INTO tables (name, status) VALUES ("Barra", "available")');
        $barra_table_id = $pdo->lastInsertId();
    } else {
        $barra_table_id = $barra['id'];
    }

    $table_id = $barra_table_id;
    $table = ['id' => $barra_table_id, 'name' => 'Pedido Libre', 'status' => 'available'];
}

// Handle AJAX requests
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'add_to_order') {
        $product_id = $_POST['product_id'];
        $quantity = $_POST['quantity'];

        // Get product details and effective stock
        $stmt = $pdo->prepare('
            SELECT p.*, 
                   (p.stock - COALESCE((SELECT SUM(od.quantity) 
                                        FROM order_details od 
                                        JOIN orders o ON od.order_id = o.id 
                                        WHERE od.product_id = p.id AND o.status IN ("pending", "ready")), 0)) as available_stock
            FROM products p 
            WHERE p.id = ?
        ');
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if ($product) {
            // Check stock
            if ($quantity > $product['available_stock']) {
                echo json_encode(['success' => false, 'message' => 'Stock insuficiente. Disponible: ' . $product['available_stock']]);
                exit();
            }

            try {
                $pdo->beginTransaction();

                // Check if there's an active order
                $order = false; // Default to no order found

                if ($is_libre) {
                    if ($libre_order > 1) {
                        // Resuming a SPECIFIC libre order
                        $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ? AND status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered") LIMIT 1 FOR UPDATE');
                        $stmt->execute([$libre_order]);
                        $order = $stmt->fetch();
                    }
                    // If libre_order is not set or <= 1, we DO NOT search for existing orders on table_id, 
                    // because "Barra" table has multiple independent orders.
                    // $order remains false -> triggering INSERT
                } else {
                    // Normal table: check for ANY active order on this table
                    $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE table_id = ? AND status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered") LIMIT 1 FOR UPDATE');
                    $stmt->execute([$table_id]);
                    $order = $stmt->fetch();
                }

                if (!$order) {
                    // Create new order (starts as draft - not sent to kitchen yet)
                    $stmt = $pdo->prepare('INSERT INTO orders (table_id, user_id, total, status) VALUES (?, ?, 0, "draft")');
                    $stmt->execute([$table_id, $_SESSION['user_id']]);
                    $order_id = $pdo->lastInsertId();
                    $order_status = 'draft';
                } else {
                    $order_id = $order['id'];
                    $order_status = $order['status'];
                }

                // Check if product already in order AND is pending
                $notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;
                
                if ($notes === null) {
                    $stmt = $pdo->prepare('SELECT id, quantity FROM order_details WHERE order_id = ? AND product_id = ? AND item_status = "draft" AND notes IS NULL');
                    $stmt->execute([$order_id, $product_id]);
                } else {
                    $stmt = $pdo->prepare('SELECT id, quantity FROM order_details WHERE order_id = ? AND product_id = ? AND item_status = "draft" AND notes = ?');
                    $stmt->execute([$order_id, $product_id, $notes]);
                }
                
                $existing = $stmt->fetch();

                if ($existing) {
                    // Update quantity of the PENDING item
                    $stmt = $pdo->prepare('UPDATE order_details SET quantity = quantity + ? WHERE id = ?');
                    $stmt->execute([$quantity, $existing['id']]);
                } else {
                    // Add new item (will be draft by default)
                    $stmt = $pdo->prepare('INSERT INTO order_details (order_id, product_id, quantity, price, notes, item_status) VALUES (?, ?, ?, ?, ?, "draft")');
                    $stmt->execute([$order_id, $product_id, $quantity, $product['price'], $notes]);
                }

                // Recalculate total
                $stmt = $pdo->prepare('SELECT SUM(quantity * price) FROM order_details WHERE order_id = ?');
                $stmt->execute([$order_id]);
                $new_total = $stmt->fetchColumn();

                // Update order total only (don't change status - user must click "Send to Kitchen")
                $stmt = $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?');
                $result = $stmt->execute([$new_total, $order_id]);

                // Debug log
                file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - Order $order_id updated. New Total: $new_total. Result: " . ($result ? 'OK' : 'FAIL') . "\n", FILE_APPEND);

                // Update table status
                $stmt = $pdo->prepare('UPDATE tables SET status = "occupied" WHERE id = ?');
                $stmt->execute([$table_id]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
                exit();
            }

        }

        echo json_encode(['success' => true, 'order_id' => $order_id]);
        exit();
    }

    if ($_GET['ajax'] === 'get_order') {
        // Get order status and total
        if ($is_libre && $libre_order > 1) {
            $stmt = $pdo->prepare('SELECT id, status, total FROM orders WHERE id = ?');
            $stmt->execute([$libre_order]);
        } else {
            $stmt = $pdo->prepare('SELECT id, status, total FROM orders WHERE table_id = ? AND status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered") LIMIT 1');
            $stmt->execute([$table_id]);
        }
        $order = $stmt->fetch();

        if ($is_libre && $libre_order > 1) {
            $stmt = $pdo->prepare('
                SELECT od.*, p.name as product_name, o.user_id, u.name as waiter_name
                FROM order_details od
                JOIN products p ON od.product_id = p.id
                JOIN orders o ON od.order_id = o.id
                JOIN users u ON o.user_id = u.id
                WHERE od.order_id = ?
            ');
            $stmt->execute([$libre_order]);
        } else {
            $stmt = $pdo->prepare('
                SELECT od.*, p.name as product_name, o.user_id, u.name as waiter_name
                FROM order_details od
                JOIN products p ON od.product_id = p.id
                JOIN orders o ON od.order_id = o.id
                JOIN users u ON o.user_id = u.id
                WHERE o.table_id = ? AND o.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
            ');
            $stmt->execute([$table_id]);
        }
        $items = $stmt->fetchAll();

        // Check if there are draft items that need to be sent to kitchen
        $has_pending_items = false;
        if ($order) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_details WHERE order_id = ? AND item_status = "draft"');
            $stmt->execute([$order['id']]);
            $has_pending_items = $stmt->fetchColumn() > 0;
        }

        echo json_encode([
            'items' => $items,
            'total' => $order['total'] ?? 0,
            'order_status' => $order['status'] ?? 'draft',
            'order_id' => $order['id'] ?? null,
            'has_pending_items' => $has_pending_items
        ]);
        exit();
    }

    // Send order to kitchen
    if ($_GET['ajax'] === 'send_to_kitchen') {
        // Get any active order for this table
        if ($is_libre && $libre_order > 1) {
            $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE id = ?');
            $stmt->execute([$libre_order]);
        } else {
            $stmt = $pdo->prepare('SELECT id, status FROM orders WHERE table_id = ? AND status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered") LIMIT 1');
            $stmt->execute([$table_id]);
        }
        $order = $stmt->fetch();

        if ($order) {
            // If order is draft OR has been processed (ready/picked_up/delivered) but has new items
            // We set it back to 'pending' so it shows up in Kitchen
            if (in_array($order['status'], ['draft', 'ready', 'picked_up', 'delivered'])) {
                $stmt = $pdo->prepare('UPDATE orders SET status = "pending", date_created = NOW() WHERE id = ?');
                $stmt->execute([$order['id']]);
            }

            // Mark all draft items as pending (this makes them visible to the kitchen)
            $stmt = $pdo->prepare('UPDATE order_details SET item_status = "pending" WHERE order_id = ? AND item_status = "draft"');
            $stmt->execute([$order['id']]);

            echo json_encode(['success' => true, 'message' => 'Pedido enviado a cocina']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No hay pedido para enviar']);
        }
        exit();
    }

    // Remove product from order
    if ($_GET['ajax'] === 'remove_from_order') {
        $detail_id = $_POST['detail_id'] ?? 0;

        if ($detail_id) {
            // Get the order_id before deleting
            $stmt = $pdo->prepare('SELECT order_id, product_id, quantity, price FROM order_details WHERE id = ?');
            $stmt->execute([$detail_id]);
            $detail = $stmt->fetch();

            if ($detail) {
                // Delete the order detail
                $stmt = $pdo->prepare('DELETE FROM order_details WHERE id = ? AND item_status = "draft"');
                $stmt->execute([$detail_id]);

                // Recalculate order total
                $stmt = $pdo->prepare('SELECT SUM(price * quantity) as new_total FROM order_details WHERE order_id = ?');
                $stmt->execute([$detail['order_id']]);
                $result = $stmt->fetch();
                $new_total = $result['new_total'] ?? 0;

                // Update order total
                $stmt = $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?');
                $stmt->execute([$new_total, $detail['order_id']]);

                // If no items left, delete order and set table to available
                if ($new_total == 0) {
                    $stmt = $pdo->prepare('DELETE FROM orders WHERE id = ?');
                    $stmt->execute([$detail['order_id']]);

                    $stmt = $pdo->prepare('UPDATE tables SET status = "available" WHERE id = ?');
                    $stmt->execute([$table_id]);
                }

                echo json_encode(['success' => true, 'new_total' => $new_total]);
                exit();
            }
        }

        echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el producto']);
        exit();
    }
}

// Get products with effective stock (Physical - Pending)
$products = $pdo->query('
    SELECT p.*, 
           (p.stock - COALESCE((SELECT SUM(od.quantity) 
                                FROM order_details od 
                                JOIN orders o ON od.order_id = o.id 
                                WHERE od.product_id = p.id AND o.status IN ("pending", "ready")), 0)) as available_stock
    FROM products p
    WHERE p.status = "active"
    HAVING available_stock > 0
    ORDER BY p.category_id, p.name
')->fetchAll();

// Get categories
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

// Get current order and its status
$stmt = $pdo->prepare('SELECT id, total, status FROM orders WHERE table_id = ? AND status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered") LIMIT 1');
$stmt->execute([$table_id]);
$current_order = $stmt->fetch();
$order_total = $current_order['total'] ?? 0;
$order_status = $current_order['status'] ?? null;

$stmt = $pdo->prepare('
    SELECT od.*, p.name as product_name
    FROM order_details od
    JOIN products p ON od.product_id = p.id
    JOIN orders o ON od.order_id = o.id
    WHERE o.table_id = ? AND o.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
');
$stmt->execute([$table_id]);
$order_items = $stmt->fetchAll();
?>
<?php // Check for clean mode (embedded)
$clean_mode = isset($_GET['clean']);

if (!$clean_mode) {
    include __DIR__ . '/includes/header.php';
} else {
    // Minimal header for clean mode
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>POS - ' . htmlspecialchars($table['name']) . '</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css?v=1.3">
        <link rel="stylesheet" href="assets/css/style.css?v=1.3">
        <link rel="stylesheet" href="assets/css/foodcorp-theme.css?v=1.1">
        <!-- SweetAlert2 -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            body { background: #f8fafc; padding: 20px; }
            .dashboard-wrapper { display: block !important; grid-template-columns: 1fr !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
            .page-header { display: none; }
        </style>
    </head>
    <body>';
}
?>

<div class="dashboard-wrapper">
    <?php if (!$clean_mode && in_array($_SESSION['role_id'], [1, 2, 5])): ?>
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>
 
    <main class="main-content" style="<?= $_SESSION['role_id'] == 2 ? 'margin-left: 0;' : '' ?>">
        <?php if ($_SESSION['role_id'] == 2 && !$clean_mode): ?>
            <div class="fc-card" style="margin-bottom: 25px; padding: 15px 25px; border-left: 5px solid var(--fc-primary);">
                <div class="fc-flex-between">
                    <h2 style="margin:0; font-size: 1.2em;"><i class='bx bxs-user-badge'></i> Terminal Mesero</h2>
                    <div style="display: flex; gap: 10px;">
                        <a href="mesas.php" class="fc-btn fc-btn-outline" style="padding: 8px 15px;"><i class='bx bx-grid-alt'></i> Mesas</a>
                        <a href="salir.php" class="fc-btn fc-btn-primary" style="padding: 8px 15px;"><i class='bx bx-log-out'></i></a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
 
        <div class="pos-header">
            <div>
                <h1><i class='bx bx-store-alt'></i> <?= htmlspecialchars($table['name']) ?></h1>
                <p><?= $is_libre ? 'Venta rápida / Barra' : 'Atención en salón' ?></p>
            </div>
            <div class="pos-header-actions">
                <a href="mesas.php" class="fc-btn fc-btn-outline" title="Volver a Mesas">
                    <i class='bx bx-arrow-back'></i> <span class="hide-mobile">Volver</span>
                </a>
                <button class="fc-btn fc-btn-primary" onclick="toggleMobileCart(); switchTab('tab-draft');">
                    <i class='bx bx-shopping-bag'></i> Pedido (<span id="top-cart-badge"><?= count($order_items) ?></span>)
                </button>
                <button class="fc-btn fc-btn-outline" onclick="toggleMobileCart(); switchTab('tab-kitchen');">
                    <i class='bx bx-time-five'></i> Cocina
                </button>
                <button class="fc-btn fc-btn-outline" onclick="toggleMobileCart(); switchTab('tab-billing');">
                    <i class='bx bx-receipt'></i> Cuenta
                </button>
            </div>
        </div>
 
        <div class="pos-modern-layout">
            <!-- Products Section -->
            <div class="products-section-modern">
                <div class="pos-toolbar-sticky">
                    <div class="search-container">
                        <i class='bx bx-search search-icon-overlay'></i>
                        <input type="text" id="productSearch" class="search-input" placeholder="Buscar por nombre o código..." onkeyup="filterProducts()">
                    </div>
 
                    <div class="categories-bar">
                        <button class="category-btn active" onclick="filterCategory('all', this)">Todos</button>
                        <?php foreach ($categories as $cat): ?>
                            <button class="category-btn" onclick="filterCategory(<?= $cat['id'] ?>, this)">
                                <?= htmlspecialchars($cat['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
 
                <div class="products-grid-modern" id="products-grid">
                    <?php foreach ($products as $product): ?>
                        <?php 
                            $category_id = $product['category_id'] ?? 0;
                            // Mapping categories to professional icons
                            $icon_map = [
                                1 => 'bx-pizza', 
                                2 => 'bx-dish', 
                                3 => 'bx-drink', 
                                4 => 'bx-coffee', 
                                5 => 'bx-wine'
                            ];
                            $icon = $icon_map[$category_id] ?? 'bx-package';
                        ?>
                        <div class="product-item fc-animate-fade-in" 
                             data-id="<?= $product['id'] ?>" 
                             data-category="<?= $category_id ?>"
                             onclick="addToOrder(<?= $product['id'] ?>)">
                            
                            <div class="product-image">
                                <?php if (!empty($product['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <div class="placeholder-image">
                                        <i class='bx <?= $icon ?>'></i>
                                    </div>
                                <?php endif; ?>
  
                                <div class="price-tag-overlay">
                                    C$<?= number_format($product['price'], 0) ?>
                                </div>
                                
                                <div class="product-actions-glass">
                                    <button type="button" class="glass-btn" onclick="event.stopPropagation(); promptNotes(<?= $product['id'] ?>, '<?= htmlspecialchars($product['name'], ENT_QUOTES) ?>')" title="Agregar Nota">
                                        <i class='bx bx-edit-alt'></i>
                                    </button>
                                    <button type="button" class="glass-btn primary">
                                        <i class='bx bx-plus'></i>
                                    </button>
                                </div>
                            </div>
  
                            <div class="product-info">
                                <div class="product-name">
                                    <?= htmlspecialchars($product['name']) ?>
                                </div>
                                <div class="product-stock-tag">
                                    <i class='bx bx-check-double'></i> Disponible
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Order Summary Sidebar -->
            <div class="order-summary" id="order-summary-panel">
                <div class="swipe-handle" id="swipe-handle">
                    <div class="handle-bar"></div>
                </div>
                <div class="summary-header" onclick="toggleOrderPanel()" style="padding: 20px 25px 15px; border-bottom: none;">
                    <div class="fc-flex-between" style="width:100%;">
                        <h3 style="margin:0;"><i class='bx bx-restaurant'></i> Seguimiento</h3>
                        <span class="fc-badge fc-badge-rose" id="item-count"><?= count($order_items) ?> ítems</span>
                    </div>
                </div>

                <div class="sidebar-tabs">
                    <button class="tab-btn active" onclick="switchTab('tab-draft')">
                        <i class='bx bx-plus-circle'></i> Nuevo
                    </button>
                    <button class="tab-btn" onclick="switchTab('tab-kitchen')">
                        <i class='bx bx-time-five'></i> Cocina
                    </button>
                    <button class="tab-btn" onclick="switchTab('tab-billing')">
                        <i class='bx bx-receipt'></i> Cuenta
                    </button>
                </div>

                <div class="pos-cart-items" style="padding: 0 20px 20px; flex: 1; overflow-y: auto;">
                    <!-- Tab: New Order (Draft) -->
                    <div id="tab-draft" class="tab-content active">
                        <div id="draft-items-container">
                            <!-- JS populated -->
                        </div>
                        <div id="draft-actions" style="margin-top: auto; padding-top: 20px;">
                            <button onclick="sendToKitchen()" class="fc-btn fc-btn-primary fc-w100" id="sendToKitchenBtn" style="height: 55px;">
                                <i class='bx bx-restaurant'></i> Enviar a Cocina
                            </button>
                        </div>
                    </div>

                    <!-- Tab: Kitchen Tracking -->
                    <div id="tab-kitchen" class="tab-content">
                        <div id="order-progress-wrapper"></div>
                        <div id="kitchen-items-container" style="margin-top: 15px;">
                            <!-- JS populated -->
                        </div>
                    </div>

                    <!-- Tab: Billing -->
                    <div id="tab-billing" class="tab-content">
                        <div class="billing-section" style="background: rgba(255,255,255,0.03); border: 1px solid var(--fc-border); border-radius: 15px; padding: 20px; margin-top: 10px;">
                            <div class="bill-row" style="display: flex; justify-content: space-between; margin-bottom: 12px; color: var(--fc-text-sec);">
                                <span>Subtotal Neto</span>
                                <span id="bill-subtotal">C$<?= number_format($order_total, 2) ?></span>
                            </div>
                            <div class="bill-row total" style="display: flex; justify-content: space-between; font-size: 1.6em; font-weight: 800; color: var(--fc-text-main); margin-top: 15px; padding-top: 15px; border-top: 1px dashed var(--fc-border);">
                                <span>Total</span>
                                <span id="order-total" style="color: var(--fc-primary);">C$<?= number_format($order_total, 2) ?></span>
                            </div>
                        </div>
                        <div style="margin-top: 25px;">
                            <a href="ver_pedido.php?table=<?= $table_id ?>&view=bill" target="_blank" class="fc-btn fc-btn-outline fc-w100" style="height: 55px; display: flex; align-items: center; justify-content: center; gap: 10px;">
                                <i class='bx bx-receipt'></i> Consultar Cuenta
                            </a>
                        </div>
                    </div>
                </div>
 
                <button class="pos-mobile-cart-btn" onclick="toggleMobileCart()" style="background: var(--fc-primary); box-shadow: 0 10px 30px rgba(225,29,72,0.4);">
                    <i class='bx bx-shopping-bag'></i>
                    <span class="badge" id="mobile-cart-badge"><?= count($order_items) ?></span>
                </button>
            </div>
            <div class="pos-cart-overlay" id="cartOverlay" onclick="toggleMobileCart()"></div>

        </div>
    </main>
</div>

<!-- Modern Confirmation Modal -->
<div class="modern-modal-overlay" id="confirmModal">
    <div class="modern-modal">
        <div class="modal-icon">
            <span>🗑️</span>
        </div>
        <h3 class="modal-title">¿Eliminar producto?</h3>
        <p class="modal-message">Este producto será removido del pedido actual.</p>
        <div class="modal-buttons">
            <button class="modal-btn modal-btn-cancel" onclick="closeConfirmModal()">
                Cancelar
            </button>
            <button class="modal-btn modal-btn-confirm" id="confirmModalBtn">
                Sí, eliminar
            </button>
        </div>
    </div>
</div>

<style>
    :root {
        --grid-gap: 20px;
        --card-radius: 16px;
        --btn-radius: 12px;
    }
 
    .pos-modern-layout {
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 30px;
        height: calc(100vh - 160px);
        overflow: hidden;
    }
 
    .products-section-modern {
        background: var(--fc-bg-dark);
        border-radius: var(--card-radius);
        position: relative;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid var(--fc-border);
    }
 
    .pos-toolbar-sticky {
        background: var(--fc-bg);
        padding: 20px;
        position: sticky;
        top: 0;
        z-index: 10;
        display: flex;
        flex-direction: column;
        gap: 20px;
        border-bottom: 1px solid var(--fc-border);
    }
 
    .search-input {
        width: 100%;
        padding: 14px 20px 14px 50px;
        border: 1px solid var(--fc-border);
        border-radius: 12px;
        background: rgba(255,255,255,0.03);
        color: var(--fc-text-main);
        font-size: 15px;
        transition: all 0.2s ease;
    }
 
    .search-input:focus {
        border-color: var(--fc-primary);
        background: rgba(255,255,255,0.05);
        outline: none;
    }
 
    .search-icon-overlay {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--fc-text-sec);
        font-size: 18px;
        pointer-events: none;
    }
 
    .categories-bar {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding-bottom: 5px;
        scrollbar-width: none;
    }
 
    .category-btn {
        padding: 10px 24px;
        background: rgba(255,255,255,0.05);
        border: 1px solid var(--fc-border);
        border-radius: 50px;
        color: var(--fc-text-sec);
        cursor: pointer;
        transition: all 0.2s ease;
        white-space: nowrap;
        font-weight: 600;
        font-size: 14px;
    }
 
    .category-btn.active {
        background: var(--fc-primary);
        color: white;
        border-color: var(--fc-primary);
        box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
    }
 
    .products-grid-modern {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 25px;
        padding: 25px;
        padding-bottom: 100px;
        align-content: start;
        overflow-y: auto;
        flex: 1; /* Take all available vertical space */
    }
 
    .product-item {
        background: var(--fc-card-bg);
        backdrop-filter: var(--fc-glass-blur);
        border-radius: 20px;
        overflow: hidden;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        border: 1px solid var(--fc-border);
        position: relative;
        min-height: 320px; /* Force a minimum height */
    }
 
    .product-item:hover {
        transform: translateY(-8px) scale(1.02);
        border-color: rgba(225, 29, 72, 0.4);
        box-shadow: 0 20px 40px rgba(0,0,0,0.4), 0 0 0 1px rgba(225, 29, 72, 0.1);
    }

    .product-item:hover .product-actions-glass {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }
 
    .product-image {
        width: 100%;
        height: 220px;
        min-height: 220px;
        background: rgba(30, 41, 59, 0.5);
        position: relative;
        overflow: hidden;
        border-bottom: 1px solid var(--fc-border);
    }
 
    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }

    .product-item:hover .product-image img {
        transform: scale(1.1);
    }
 
    .placeholder-image {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 80px;
        color: var(--fc-text-main);
        opacity: 0.15;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .product-item:hover .placeholder-image {
        opacity: 0.4;
        transform: scale(1.15) rotate(-5deg);
    }
 
    .price-tag-overlay {
        position: absolute;
        top: 15px;
        left: 15px;
        background: rgba(15, 23, 42, 0.8);
        backdrop-filter: blur(8px);
        color: white;
        padding: 6px 12px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 14px;
        border: 1px solid var(--fc-border);
        z-index: 2;
    }

    .product-actions-glass {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -40%) scale(0.9);
        display: flex;
        gap: 12px;
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        z-index: 3;
    }

    .glass-btn {
        width: 46px;
        height: 46px;
        border-radius: 14px;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 15px rgba(0,0,0,0.3);
    }

    .glass-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        border-color: rgba(255, 255, 255, 0.5);
        transform: scale(1.1) translateY(-2px);
    }

    .glass-btn.primary {
        background: var(--fc-primary);
        border-color: transparent;
        box-shadow: 0 8px 20px rgba(225, 29, 72, 0.4);
    }
 
    .product-info {
        padding: 18px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: linear-gradient(180deg, rgba(255,255,255,0.02) 0%, transparent 100%);
    }
 
    .product-name {
        font-weight: 700;
        color: var(--fc-text-main);
        font-size: 17px;
        margin-bottom: 10px;
        line-height: 1.4;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .product-stock-tag {
        font-size: 11px;
        color: var(--fc-success);
        display: flex;
        align-items: center;
        gap: 4px;
        font-weight: 600;
        opacity: 0.8;
    }
 
    .product-meta {
        display: none; /* Removed in favor of glass-actions */
    }
 
    .notes-btn {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: rgba(255,255,255,0.05);
        color: var(--fc-text-sec);
        border: 1px solid var(--fc-border);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        transition: all 0.2s;
    }
 
    .notes-btn:hover {
        background: rgba(225,29,72,0.1);
        color: var(--fc-primary);
    }
 
    .add-btn {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        background: var(--fc-primary);
        color: white;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        box-shadow: 0 4px 10px rgba(225,29,72,0.2);
    }

    .pos-modern-layout {
        display: grid;
        grid-template-columns: 1fr;
        gap: var(--grid-gap);
        height: 100%;
        overflow: hidden;
    }

    .order-summary {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.9);
        width: 500px;
        height: 650px; /* Altura fija para evitar saltos */
        max-width: 95vw;
        max-height: 90vh;
        background: #0f172a !important;
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 24px;
        box-shadow: 0 0 0 100vmax rgba(0,0,0,0.7), 0 25px 50px -12px rgba(0, 0, 0, 0.8);
        display: none;
        flex-direction: column;
        overflow: hidden;
        z-index: 1001;
        transition: all 0.2s ease-out;
        opacity: 0;
        color: white;
    }

    .order-summary.active {
        display: flex;
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
    }

    /* Overlay para oscurecer el fondo */
    .pos-cart-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.7);
        backdrop-filter: blur(4px);
        display: none;
        z-index: 1000;
    }

    .pos-cart-overlay.active {
        display: block;
    }
 
    .item-name {
        margin-right: 25px;
    }
 
    @media (max-width: 1200px) {
        .pos-modern-layout {
            grid-template-columns: 1fr;
            height: auto;
        }
 
        .order-summary {
            position: fixed;
            top: auto;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            max-width: none;
            height: 80vh;
            transform: translateY(100%);
            border-radius: 25px 25px 0 0;
            opacity: 1;
        }
 
        .order-summary.active {
            transform: translateY(0);
        }
 
        .swipe-handle {
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
 
        .handle-bar {
            width: 40px;
            height: 4px;
            background: var(--fc-border);
            border-radius: 2px;
        }
    }
</style>

<script>
    // System Helpers
    function getAjaxUrl(action) {
        let url = '?ajax=' + action + '&table=<?= $table_id ?>';
        const urlParams = new URLSearchParams(window.location.search);
        const libreId = urlParams.get('libre');
        if (libreId) { url += '&libre=' + libreId; }
        return url;
    }

    function showToast(message, type = 'success') {
        Swal.fire({
            text: message,
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true,
            background: type === 'success' ? 'var(--fc-bg-dark)' : '#7f1d1d',
            color: '#fff',
            icon: type,
            iconColor: type === 'success' ? 'var(--fc-primary)' : '#fff'
        });
    }

    // Product Logic
    function addToOrder(productId, notes = '', quantity = 1) {
        const url = getAjaxUrl('add_to_order');
        
        // Visual feedback - Pulse the mobile cart button
        const mobileCartBtn = document.querySelector('.pos-mobile-cart-btn');
        if(mobileCartBtn) {
            mobileCartBtn.classList.add('fc-animate-pulse');
            setTimeout(() => mobileCartBtn.classList.remove('fc-animate-pulse'), 300);
        }

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `product_id=${productId}&quantity=${quantity}&notes=${encodeURIComponent(notes)}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                updateOrder();
                switchTab('tab-draft');
                showToast('Añadido al pedido');
                if (data.order_id && <?= $is_libre ? 'true' : 'false' ?> && !window.location.search.includes('libre')) {
                    history.replaceState(null, '', '?libre=' + data.order_id);
                }
            } else {
                showToast(data.message || 'Error', 'error');
            }
        });
    }

    function promptNotes(productId, productName) {
        Swal.fire({
            title: 'Nota Especial',
            text: productName,
            input: 'text',
            inputPlaceholder: 'Sin cebolla, etc...',
            showCancelButton: true,
            confirmButtonText: 'Agregar',
            confirmButtonColor: 'var(--fc-primary)',
            background: 'var(--fc-bg-dark)',
            color: 'var(--fc-text-main)'
        }).then(result => {
            if (result.isConfirmed) {
                addToOrder(productId, result.value);
            }
        });
    }

    // UI Logic
    function filterCategory(catId, btn) {
        document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('productSearch').value = '';
        
        document.querySelectorAll('.product-item').forEach(item => {
            item.style.display = (catId === 'all' || item.dataset.category == catId) ? 'flex' : 'none';
        });
    }

    function filterProducts() {
        const term = document.getElementById('productSearch').value.toLowerCase();
        document.querySelectorAll('.product-item').forEach(item => {
            const name = item.querySelector('.product-name').textContent.toLowerCase();
            item.style.display = name.includes(term) ? 'flex' : 'none';
        });
        if(term) document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
    }

    function getTimeElapsed(dateStr) {
        if (!dateStr || dateStr === 'Enviado') return '';
        // Handle format YYYY-MM-DD HH:MM:SS by converting to ISO
        const start = new Date(dateStr.replace(' ', 'T'));
        const now = new Date();
        const diff = Math.floor((now - start) / 60000);
        return diff > 0 ? `${diff} min` : 'Recién';
    }

    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        const btn = document.querySelector(`button[onclick="switchTab('${tabId}')"]`);
        if(btn) btn.classList.add('active');
    }

    function updateOrder() {
        fetch(getAjaxUrl('get_order'))
        .then(res => res.json())
        .then(data => {
            const draftContainer = document.getElementById('draft-items-container');
            const kitchenContainer = document.getElementById('kitchen-items-container');
            const progressWrapper = document.getElementById('order-progress-wrapper');
            
            if (!data.items) data.items = [];

            document.getElementById('item-count').textContent = `${data.items.length} ítems`;
            document.getElementById('mobile-cart-badge').textContent = data.items.length;
            
            // Actualizar badge del header desktop si existe
            const topBadge = document.getElementById('top-cart-badge');
            if(topBadge) topBadge.textContent = data.items.length;
            
            const totalStr = 'C$' + parseFloat(data.total || 0).toFixed(2);
            const orderTotalElem = document.getElementById('order-total');
            if (orderTotalElem) orderTotalElem.textContent = totalStr;
            
            const billSubtotalElem = document.getElementById('bill-subtotal');
            if (billSubtotalElem) billSubtotalElem.textContent = totalStr;

            // Group items: Lógica limpia usando el nuevo estado 'draft'
            const newItems = data.items.filter(i => {
                const s = String(i.item_status || '').trim().toLowerCase();
                return s === 'draft';
            });
            
            const sentItems = data.items.filter(i => {
                const s = String(i.item_status || '').trim().toLowerCase();
                return s !== 'draft';
            });

            // Render Draft Tab
            draftContainer.innerHTML = ''; 
            if (newItems.length === 0) {
                draftContainer.innerHTML = `
                    <div class="empty-order" style="text-align: center; padding: 40px 10px;">
                        <i class='bx bx-shopping-bag' style="font-size: 48px; color: var(--fc-primary); opacity: 0.1;"></i>
                        <p style="color: var(--fc-text-sec); font-size: 14px;">No hay productos nuevos.</p>
                    </div>`;
                document.getElementById('draft-actions').style.display = 'none';
            } else {
                draftContainer.innerHTML = newItems.map(item => renderOrderItem(item, false)).join('');
                document.getElementById('draft-actions').style.display = 'block';
            }

            // Render Kitchen Tab
            if (sentItems.length === 0) {
                progressWrapper.innerHTML = '';
                kitchenContainer.innerHTML = `
                    <div class="empty-order" style="text-align: center; padding: 40px 10px;">
                        <i class='bx bx-restaurant' style="font-size: 48px; color: var(--fc-primary); opacity: 0.1;"></i>
                        <p style="color: var(--fc-text-sec); font-size: 14px;">Nada en cocina actualmente.</p>
                    </div>`;
            } else {
                // Progress
                const readyItems = data.items.filter(i => i.item_status === 'ready').length;
                const progress = (readyItems / data.items.length) * 100;
                
                progressWrapper.innerHTML = `
                    <div class="order-progress-container animate__animated animate__fadeIn">
                        <div class="progress-header">
                            <span style="font-size:12px; font-weight:700;">Progreso de Comanda</span>
                            <span class="fc-text-rose">${Math.round(progress)}%</span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width: ${progress}%"></div>
                        </div>
                    </div>`;
                
                kitchenContainer.innerHTML = sentItems.map(item => renderOrderItem(item, true)).join('');
            }
        });
    }

    function getTimeElapsed(timestamp) {
        if (!timestamp) return '';
        const created = new Date(timestamp);
        const now = new Date();
        const diffMs = now - created;
        const diffMins = Math.floor(diffMs / 60000);
        return diffMins + ' min';
    }

    function renderOrderItem(item, isSent) {
        const timeElapsed = isSent ? getTimeElapsed(item.created_at) : '';
        const statusMap = {
            'pending': { label: 'En Cola', class: 'status-label-pending', icon: 'bx-time' },
            'preparing': { label: 'Cocinando', class: 'status-label-preparing', icon: 'bx-loader-alt bx-spin' },
            'ready': { label: '¡Listo!', class: 'status-label-ready', icon: 'bx-check-double' }
        };
        const status = statusMap[item.item_status] || statusMap.pending;

        return `
            <div class="pos-order-item ${isSent ? 'sent' : ''}">
                <div class="pos-item-header">
                    <div class="pos-item-name">${item.product_name}</div>
                    ${!isSent ? `
                        <button class="fc-btn-icon" onclick="removeFromOrder(${item.id})" style="color: var(--fc-primary); opacity: 0.6;">
                            <i class='bx bx-trash' style="font-size: 18px;"></i>
                        </button>
                    ` : `
                        <span class="item-time">${timeElapsed}</span>
                    `}
                </div>
                ${item.notes ? `
                    <div class="fc-badge fc-badge-outline" style="font-size: 11px; margin-bottom: 8px; border-style: dashed; width: 100%; justify-content: flex-start; background: rgba(225, 29, 72, 0.05);">
                        <i class='bx bx-comment-detail'></i> ${item.notes}
                    </div>` : ''}
                <div class="pos-item-meta">
                    <div style="color: var(--fc-text-sec); font-weight: 500;">
                        <span style="color: var(--fc-primary);">${item.quantity}</span> × C$${parseFloat(item.price).toFixed(0)}
                    </div>
                    ${isSent ? `
                        <div class="pos-item-status ${status.class}">
                            <i class='bx ${status.icon}'></i> ${status.label}
                        </div>
                    ` : `
                        <div class="pos-item-total">
                            C$${(item.price * item.quantity).toFixed(2)}
                        </div>
                    `}
                </div>
            </div>
        `;
    }

    // Initial Load
    document.addEventListener('DOMContentLoaded', updateOrder);

    // Auto update timers every minute
    // Auto update timers every 15 seconds if panel is open or has kitchen items
    setInterval(() => {
        const hasKitchenItems = document.getElementById('kitchen-items-container')?.querySelectorAll('.pos-order-item').length > 0;
        if (hasKitchenItems || document.getElementById('order-summary-panel').classList.contains('active')) {
            updateOrder();
        }
    }, 15000);

    // Carga inicial
    updateOrder();

    function removeFromOrder(id) {
        Swal.fire({
            title: '¿Remover?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--fc-primary)',
            confirmButtonText: 'Sí, eliminar',
            background: 'var(--fc-bg-dark)',
            color: 'var(--fc-text-main)'
        }).then(result => {
            if (result.isConfirmed) {
                const formData = new FormData();
                formData.append('detail_id', id);
                fetch(getAjaxUrl('remove_from_order'), { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) { updateOrder(); showToast('Producto eliminado'); }
                });
            }
        });
    }

    function sendToKitchen() {
        const btn = document.getElementById('sendToKitchenBtn');
        if (!btn) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Enviando...';
        
        fetch(getAjaxUrl('send_to_kitchen'), { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast('Pedido enviado a cocina');
                updateOrder();
                switchTab('tab-kitchen');
                btn.disabled = false;
                btn.innerHTML = '<i class="bx bx-restaurant"></i> Enviar a Cocina';
            } else {
                showToast(data.message || 'Error al enviar a cocina', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bx bx-restaurant"></i> Enviar a Cocina';
            }
        })
        .catch(err => {
            console.error("Error en sendToKitchen:", err);
            showToast('Error de conexión', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="bx bx-restaurant"></i> Enviar a Cocina';
        });
    }

    function toggleMobileCart() {
        document.getElementById('order-summary-panel').classList.toggle('active');
        document.getElementById('cartOverlay').classList.toggle('active');
    }
</script>
<?php
if (!$clean_mode) {
    include __DIR__ . '/includes/footer.php';
} else {
    echo '</body></html>';
}
?>