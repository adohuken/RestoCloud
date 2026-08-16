<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/inventory_helper.php';
require_once __DIR__ . '/includes/modules_helper.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access
checkModuleAccess($pdo, $_SESSION['role_id'], 'pedidosya');

// Ensure tables exist
try {
    $pdo->query("SELECT 1 FROM pedidosya_orders LIMIT 1");
} catch (Exception $e) {
    header('Location: setup_pedidosya.php');
    exit();
}

// Get IVA percentage from settings
$iva_percentage = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'iva_percentage'");
    $stmt->execute();
    $iva_percentage = floatval($stmt->fetchColumn()) ?: 0;
} catch (Exception $e) {
}

$success_msg = '';
$error_msg = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $external_order_id = trim($_POST['external_order_id'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $products = $_POST['products'] ?? [];
    $quantities = $_POST['quantities'] ?? [];

    if (empty($external_order_id)) {
        $error_msg = 'El número de orden de PedidosYa es obligatorio.';
    } elseif (empty($products) || !array_filter($quantities)) {
        $error_msg = 'Debe agregar al menos un producto.';
    } else {
        try {
            $pdo->beginTransaction();

            // Calculate totals
            $subtotal = 0;
            $valid_items = [];

            for ($i = 0; $i < count($products); $i++) {
                $product_id = intval($products[$i]);
                $quantity = intval($quantities[$i]);

                if ($product_id > 0 && $quantity > 0) {
                    // Get product info
                    $stmt = $pdo->prepare('SELECT id, name, price, stock FROM products WHERE id = ?');
                    $stmt->execute([$product_id]);
                    $product = $stmt->fetch();

                    if ($product) {
                        // Check stock
                        if ($product['stock'] < $quantity) {
                            throw new Exception("Stock insuficiente para '{$product['name']}'. Disponible: {$product['stock']}");
                        }

                        $item_total = $product['price'] * $quantity;
                        $subtotal += $item_total;

                        $valid_items[] = [
                            'product_id' => $product_id,
                            'product_name' => $product['name'],
                            'price' => $product['price'],
                            'quantity' => $quantity
                        ];
                    }
                }
            }

            if (empty($valid_items)) {
                throw new Exception('No se encontraron productos válidos.');
            }

            $iva_amount = $subtotal * ($iva_percentage / 100);
            $total = $subtotal + $iva_amount;

            // Insert order
            $stmt = $pdo->prepare('
                INSERT INTO pedidosya_orders 
                (external_order_id, customer_name, customer_phone, customer_address, subtotal, iva_percentage, iva_amount, total, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $external_order_id,
                $customer_name ?: null,
                $customer_phone ?: null,
                $customer_address ?: null,
                $subtotal,
                $iva_percentage,
                $iva_amount,
                $total,
                $notes ?: null,
                $_SESSION['user_id']
            ]);
            $order_id = $pdo->lastInsertId();

            // Insert order details and update stock
            foreach ($valid_items as $item) {
                $stmt = $pdo->prepare('
                    INSERT INTO pedidosya_order_details 
                    (pedidosya_order_id, product_id, product_name, quantity, price) 
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $order_id,
                    $item['product_id'],
                    $item['product_name'],
                    $item['quantity'],
                    $item['price']
                ]);

            // Update stock (Using New Enterprise Inventory Manager)
            InventoryManager::processPedidosYaStock($order_id, $_SESSION['user_id']);
            }

            $pdo->commit();
            header('Location: factura_pedidosya.php?id=' . $order_id);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
}

// Get products for selection
$products_list = $pdo->query('SELECT id, code, name, price, stock FROM products WHERE status = "active" ORDER BY name')->fetchAll();

// Get recent PedidosYa orders
$recent_orders = $pdo->query('
    SELECT po.*, u.name as created_by_name,
           (SELECT COUNT(*) FROM pedidosya_order_details WHERE pedidosya_order_id = po.id) as item_count
    FROM pedidosya_orders po
    JOIN users u ON po.created_by = u.id
    ORDER BY po.date_created DESC
    LIMIT 20
')->fetchAll();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php
// Fetch company settings for sidebar
$sidebar_company_name = 'Sistema Pizzería';
$sidebar_company_logo = '';

try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_logo')");
    $stmt->execute();
    $sidebar_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($sidebar_settings['company_name'])) {
        $sidebar_company_name = $sidebar_settings['company_name'];
    }
    if (!empty($sidebar_settings['company_logo'])) {
        $sidebar_company_logo = $sidebar_settings['company_logo'];
    }
} catch (Exception $e) {
}
?>
<?php
// Check for clean mode (embedded)
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
        <title>PedidosYa</title>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css?v=1.3">
        <link rel="stylesheet" href="assets/css/restocloud-theme.css?v=1.0">
        <style>
            body { background: var(--fc-bg); padding: 20px; color: var(--fc-text-main); }
            .dashboard-wrapper { display: block !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
        </style>
    </head>
    <body>';
}
?>

<div class="dashboard-wrapper">
    <?php if (!$clean_mode): ?>
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class='bx bxs-truck'></i> PedidosYa</h1>
                <p>Centro de recepción y control de delivery externo</p>
            </div>
            <div class="user-profile-header">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                    <span class="user-role"><?= htmlspecialchars($user_role_name) ?></span>
                </div>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="fc-alert fc-alert-rose">
                <i class='bx bx-check-circle'></i> <?= $success_msg ?>
            </div>
        <?php endif; ?>
 
        <?php if ($error_msg): ?>
            <div class="fc-alert fc-alert-slate">
                <i class='bx bx-x-circle'></i> <?= htmlspecialchars($error_msg) ?>
            </div>
        <?php endif; ?>       <div class="pedidosya-layout">
            <!-- Form Section -->
            <div class="fc-card" style="padding:0;">
                <div class="fc-card-header">
                    <h3 style="margin:0;"><i class='bx bx-plus-circle'></i> Nuevo Pedido Delivery</h3>
                </div>
                <form method="POST" id="pedidosyaForm" style="padding: 25px;">
                    <input type="hidden" name="create_order" value="1">
 
                    <div style="margin-bottom: 30px;">
                        <h4 style="margin-bottom: 20px; color: var(--fc-primary); font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">
                            <i class='bx bx-info-circle'></i> Datos del Cliente
                        </h4>
                        <div class="fc-flex-between" style="gap: 20px; margin-bottom: 20px;">
                            <div class="fc-input-group" style="flex: 1;">
                                <label class="fc-label">Orden PedidosYa *</label>
                                <input type="text" name="external_order_id" class="fc-input" placeholder="Ej: PY-12345" required
                                    value="<?= htmlspecialchars($_POST['external_order_id'] ?? '') ?>">
                            </div>
                            <div class="fc-input-group" style="flex: 1;">
                                <label class="fc-label">Teléfono</label>
                                <input type="text" name="customer_phone" class="fc-input" placeholder="8888-8888"
                                    value="<?= htmlspecialchars($_POST['customer_phone'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="fc-input-group" style="margin-bottom: 20px;">
                            <label class="fc-label">Nombre del Cliente</label>
                            <input type="text" name="customer_name" class="fc-input" placeholder="Nombre completo"
                                value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>">
                        </div>
                        <div class="fc-input-group">
                            <label class="fc-label">Dirección Completa</label>
                            <textarea name="customer_address" class="fc-input" rows="2" style="height: auto; padding: 12px;"
                                placeholder="Punto de entrega"><?= htmlspecialchars($_POST['customer_address'] ?? '') ?></textarea>
                        </div>
                    </div>
 
                    <div style="margin-bottom: 30px;">
                        <h4 style="margin-bottom: 20px; color: var(--fc-primary); font-size: 14px; text-transform: uppercase;">
                            <i class='bx bx-list-ul'></i> Canasta de Productos
                        </h4>
                        <div id="products-container">
                            <div class="product-row" style="background: rgba(255,255,255,0.02); border: 1px solid var(--fc-border); padding: 15px; border-radius: 12px; display: flex; gap: 15px; align-items: center; margin-bottom: 15px;">
                                <div style="flex: 1; display: flex; gap: 10px;">
                                    <input type="hidden" name="products[]" class="product-id">
                                    <input type="text" class="fc-input product-name" readonly
                                        placeholder="Toca para buscar..." onclick="openProductModal(this)"
                                        style="cursor: pointer; background: rgba(0,0,0,0.2);">
                                    <button type="button" class="fc-btn fc-btn-outline" style="width: 48px; padding: 0;"
                                        onclick="openProductModal(this)"><i class='bx bx-search-alt'></i></button>
                                </div>
                                <div style="width: 90px;">
                                    <input type="number" name="quantities[]" class="fc-input quantity-input" min="1"
                                        value="1" placeholder="Cant." required style="text-align: center;">
                                </div>
                                <div style="width: 120px; font-weight: 700; color: var(--fc-primary); text-align: right;" class="item-subtotal">C$0.00</div>
                                <button type="button" class="fc-btn fc-btn-outline remove-product"
                                    style="display:none; width: 48px; border-color: rgba(225,29,72,0.3); color: var(--fc-primary); padding:0;">
                                    <i class='bx bx-trash'></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" id="addProductBtn" class="fc-btn fc-btn-outline" style="width: 100%; border-style: dashed; border-width: 2px;">
                            <i class='bx bx-plus'></i> Agregar otro ítem
                        </button>
                    </div>
                    <!-- Product Selection Modal -->
                    <div id="productModal" class="fc-modal-overlay">
                        <div class="fc-modal" style="max-width: 600px;">
                            <div class="fc-modal-header">
                                <h3><i class='bx bx-search'></i> Buscar Producto</h3>
                                <button type="button" class="fc-modal-close" onclick="closeProductModal()">&times;</button>
                            </div>
                            <div class="fc-modal-body">
                                <div class="fc-input-group" style="margin-bottom: 20px;">
                                    <input type="text" id="modalSearch" class="fc-input"
                                        placeholder="Ej: Pizza Jamón..." onkeyup="filterProducts()">
                                </div>
                                <div id="modalProductList" style="max-height: 400px; overflow-y: auto; border-radius: 12px; border: 1px solid var(--fc-border);">
                                    <?php foreach ($products_list as $p): ?>
                                        <div class="modal-product-item" data-id="<?= $p['id'] ?>"
                                            data-name="<?= htmlspecialchars($p['name']) ?>" data-price="<?= $p['price'] ?>"
                                            data-stock="<?= $p['stock'] ?>" onclick="selectProduct(this)"
                                            style="padding: 15px; border-bottom: 1px solid var(--fc-border); cursor: pointer; display: flex; justify-content: space-between; align-items: center;">
                                            <div>
                                                <div style="font-weight: 600; color: var(--fc-text-main);"><?= htmlspecialchars($p['name']) ?></div>
                                                <div style="font-size: 12px; color: var(--fc-text-sec);">Stock: <?= $p['stock'] ?> ítem(s)</div>
                                            </div>
                                            <div style="font-weight: 800; color: var(--fc-primary);">C$<?= number_format($p['price'], 2) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
 
                    <div class="fc-input-group" style="margin-top: 25px;">
                        <label class="fc-label"><i class='bx bx-note'></i> Instrucciones / Notas</label>
                        <textarea name="notes" class="fc-input" rows="2" style="height: auto; padding: 12px;"
                            placeholder="Ej: Sin cebolla, llamar al llegar..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
 
                    <div style="background: rgba(225,29,72,0.05); padding: 25px; border-radius: 15px; margin: 30px 0; border: 1px dashed var(--fc-primary);">
                        <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 10px; color: var(--fc-text-sec);">
                            <span>Subtotal Bruto:</span>
                            <span id="subtotal">C$0.00</span>
                        </div>
                        <?php if ($iva_percentage > 0): ?>
                            <div class="summary-row" style="display: flex; justify-content: space-between; margin-bottom: 10px; color: var(--fc-text-sec);">
                                <span>IVA Aplicado (<?= $iva_percentage ?>%):</span>
                                <span id="iva">C$0.00</span>
                            </div>
                        <?php endif; ?>
                        <div style="display: flex; justify-content: space-between; padding-top: 15px; border-top: 1px solid rgba(225,29,72,0.2); font-size: 1.5em; font-weight: 800; color: var(--fc-text-main);">
                            <span>Total Final:</span>
                            <span id="total">C$0.00</span>
                        </div>
                    </div>
 
                    <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 60px; font-size: 1.2em;">
                        <i class='bx bx-save'></i> Registrar en Inventario
                    </button>
                </form>
            </div>

            <!-- Recent Orders -->
            <div class="fc-card" style="padding:0; overflow: hidden; display: flex; flex-direction: column;">
                <div class="fc-card-header">
                    <h3 style="margin:0;"><i class='bx bx-history'></i> Entregas Recientes</h3>
                </div>
                <div style="overflow-y: auto; padding: 20px;">
                    <?php if (empty($recent_orders)): ?>
                        <div style="text-align: center; padding: 40px; color: var(--fc-text-sec);">
                            <i class='bx bx-package' style="font-size: 40px; opacity: 0.3;"></i>
                            <p>No hay pedidos registrados aún</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_orders as $order): ?>
                            <div class="order-item" style="background: rgba(255,255,255,0.03); border-radius: 15px; padding: 20px; margin-bottom: 15px; border-left: 4px solid var(--fc-primary);">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 12px; align-items: center;">
                                    <strong style="color: var(--fc-primary); font-size: 1.1em;">#<?= htmlspecialchars($order['external_order_id']) ?></strong>
                                    <span style="font-size: 11px; color: var(--fc-text-sec); text-transform: uppercase;"><?= date('d M, h:i A', strtotime($order['date_created'])) ?></span>
                                </div>
                                <div style="margin-bottom: 15px; font-size: 14px;">
                                    <?php if ($order['customer_name']): ?>
                                        <div style="margin-bottom: 5px;"><i class='bx bx-user' style="color: var(--fc-text-sec);"></i> <?= htmlspecialchars($order['customer_name']) ?></div>
                                    <?php endif; ?>
                                    <div style="margin-bottom: 5px;"><i class='bx bx-package' style="color: var(--fc-text-sec);"></i> <?= $order['item_count'] ?> ítems</div>
                                    <div style="font-weight: 800; color: var(--fc-text-main); font-size: 1.2em;">C$<?= number_format($order['total'], 2) ?></div>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <a href="factura_pedidosya.php?id=<?= $order['id'] ?>" class="fc-btn fc-btn-outline fc-w100" style="padding: 10px;">
                                        <i class='bx bx-printer'></i> Factura
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<style>
    .pedidosya-layout {
        display: grid;
        grid-template-columns: 1fr 400px;
        gap: 30px;
    }
 
    @media (max-width: 1200px) {
        .pedidosya-layout {
            grid-template-columns: 1fr;
        }
    }
    
    .modal-product-item:hover {
        background: rgba(225,29,72,0.05) !important;
    }
</style>

<script>
    const ivaPercentage = <?= $iva_percentage ?>;
    let currentRow = null; // Track which row opened the modal

    function openProductModal(element) {
        currentRow = element.closest('.product-row');
        document.getElementById('productModal').style.display = 'flex';
        document.getElementById('modalSearch').value = '';
        document.getElementById('modalSearch').focus();
        filterProducts(); // Reset filter
    }

    function closeProductModal() {
        document.getElementById('productModal').style.display = 'none';
        currentRow = null;
    }

    function filterProducts() {
        const term = document.getElementById('modalSearch').value.toLowerCase();
        const items = document.querySelectorAll('.modal-product-item');

        items.forEach(item => {
            const name = item.dataset.name.toLowerCase();
            if (name.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function selectProduct(element) {
        if (!currentRow) return;

        const id = element.dataset.id;
        const name = element.dataset.name;
        const price = parseFloat(element.dataset.price);
        const stock = element.dataset.stock;

        // Update inputs
        currentRow.querySelector('.product-id').value = id;
        currentRow.querySelector('.product-name').value = name;

        // Store price/stock as data attributes on the hidden input for calculations
        const idInput = currentRow.querySelector('.product-id');
        idInput.dataset.price = price;
        idInput.dataset.stock = stock;

        closeProductModal();
        updateTotals();
    }

    function updateTotals() {
        let subtotal = 0;

        document.querySelectorAll('.product-row').forEach(row => {
            const idInput = row.querySelector('.product-id');
            const qtyInput = row.querySelector('.quantity-input');
            const subtotalSpan = row.querySelector('.item-subtotal');

            const price = parseFloat(idInput.dataset.price || 0);
            const qty = parseInt(qtyInput.value) || 0;
            const itemTotal = price * qty;

            subtotalSpan.textContent = 'C$' + itemTotal.toFixed(2);
            subtotal += itemTotal;
        });

        const iva = subtotal * (ivaPercentage / 100);
        const total = subtotal + iva;

        document.getElementById('subtotal').textContent = 'C$' + subtotal.toFixed(2);
        if (document.getElementById('iva')) {
            document.getElementById('iva').textContent = 'C$' + iva.toFixed(2);
        }
        document.getElementById('total').textContent = 'C$' + total.toFixed(2);
    }

    function createProductRow() {
        const container = document.getElementById('products-container');
        const firstRow = container.querySelector('.product-row');
        const newRow = firstRow.cloneNode(true);

        // Reset values
        newRow.querySelector('.product-id').value = '';
        newRow.querySelector('.product-id').dataset.price = '0';
        newRow.querySelector('.product-name').value = '';
        newRow.querySelector('.quantity-input').value = '1';
        newRow.querySelector('.item-subtotal').textContent = 'C$0.00';
        newRow.querySelector('.remove-product').style.display = 'block';

        // Re-attach event listeners (cloneNode doesn't copy dynamic listeners)
        // Inline onclicks work, but we need to re-attach addEventListener ones if any specific ones existed.
        // For this implementation, inline onclick="openProductModal()" works fine.

        newRow.querySelector('.quantity-input').addEventListener('input', updateTotals);
        newRow.querySelector('.remove-product').addEventListener('click', function () {
            newRow.remove();
            updateTotals();
        });

        container.appendChild(newRow);
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Close modal when clicking outside
        document.getElementById('productModal').addEventListener('click', function (e) {
            if (e.target === this) closeProductModal();
        });

        // Initial listeners
        document.querySelectorAll('.quantity-input').forEach(el => {
            el.addEventListener('input', updateTotals);
        });

        document.getElementById('addProductBtn').addEventListener('click', createProductRow);

        // Form validation
        document.getElementById('pedidosyaForm').addEventListener('submit', function (e) {
            const products = document.querySelectorAll('.product-id');
            let hasProduct = false;

            products.forEach(input => {
                if (input.value) hasProduct = true;
            });

            if (!hasProduct) {
                e.preventDefault();
                alert('Debe seleccionar al menos un producto.');
            }
        });
    });
</script>

<?php
if (!$clean_mode) {
    include __DIR__ . '/includes/footer.php';
} else {
    echo '</body></html>';
}
?>
