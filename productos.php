<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'inventory');

// Handle AJAX Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    if ($_POST['ajax_action'] === 'add_category') {
        $name = trim($_POST['name']);
        if (!empty($name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->execute([$name]);
                echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId(), 'name' => $name]);
            } catch (Exception $e) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio']);
        }
        exit;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':

                $code = $_POST['code'];
                $name = $_POST['name'];
                $price = $_POST['price'];
                $stock = $_POST['stock'];
                $icon = $_POST['icon'] ?? '🍽️';
                $category_id = $_POST['category_id'] ?: null;

                $stmt = $pdo->prepare('INSERT INTO products (code, name, price, stock, icon, category_id) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$code, $name, $price, $stock, $icon, $category_id]);
                header('Location: productos.php?success=added');
                exit();
                break;

            case 'delete':
                checkModuleAccess($pdo, $_SESSION['role_id'], 'inventory_delete');
                $id = $_POST['id'];
                try {
                    $stmt = $pdo->prepare('DELETE FROM products WHERE id = ?');
                    $stmt->execute([$id]);
                    header('Location: productos.php?success=deleted');
                } catch (PDOException $e) {
                    if ($e->getCode() == '23000') {
                        header('Location: productos.php?error=dependency');
                    } else {
                        // In a production env, log this error instead of throwing
                        error_log($e->getMessage());
                        header('Location: productos.php?error=unknown');
                    }
                }
                exit();
                break;

            case 'edit':
                checkModuleAccess($pdo, $_SESSION['role_id'], 'inventory_edit');
                $id = $_POST['id'];
                $code = $_POST['code'];
                $name = $_POST['name'];
                $price = $_POST['price'];
                $stock = $_POST['stock'];
                $icon = $_POST['icon'] ?? '🍽️';
                $category_id = $_POST['category_id'] ?: null;
                $status = $_POST['status'];

                // Handle Image Upload
                $image_url_sql = "";
                $params = [$code, $name, $price, $stock, $icon, $category_id, $status];

                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['image']['tmp_name'];
                    $fname = basename($_FILES['image']['name']);
                    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                    if (in_array($ext, $allowed)) {
                        $new_name = uniqid('prod_') . '.' . $ext;
                        $upload_dir = __DIR__ . '/uploads/products/';
                        if (!is_dir($upload_dir))
                            mkdir($upload_dir, 0777, true);

                        if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                            $image_url_sql = ", image_url = ?";
                            $params[] = 'uploads/products/' . $new_name;
                        }
                    }
                }

                $params[] = $id;

                $stmt = $pdo->prepare("UPDATE products SET code = ?, name = ?, price = ?, stock = ?, icon = ?, category_id = ?, status = ? $image_url_sql WHERE id = ?");
                $stmt->execute($params);

                header('Location: productos.php?success=edited');
                exit();
                break;
        }
    }
}

// Get all products
$products = $pdo->query('SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.id DESC')->fetchAll();
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-restaurant'></i> Gestión del Menú</h1>
                <p>Administra platos, bebidas y existencias</p>
            </div>
            <div class="fc-header-right">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: '¡Logrado!',
                    text: '<?php
                        if ($_GET["success"] === "added") echo "Platillo agregado exitosamente";
                        if ($_GET["success"] === "deleted") echo "Platillo eliminado exitosamente";
                        if ($_GET["success"] === "edited") echo "Platillo actualizado exitosamente";
                        if ($_GET["success"] === "bulk_added") echo "Se han agregado " . intval($_GET["count"] ?? 0) . " platillos exitosamente";
                    ?>',
                    background: '#0f172a',
                    color: '#f8fafc',
                    timer: 2500
                });
            </script>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    html: '<?php
                        if ($_GET["error"] === "dependency") echo "<b>No se puede eliminar:</b> El platillo tiene ventas asociadas. Cambie su estado a Inactivo.";
                        if ($_GET["error"] === "unknown") echo "Ocurrió un error inesperado.";
                    ?>',
                    background: '#0f172a',
                    color: '#f8fafc'
                });
            </script>
        <?php endif; ?>




        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap;">
            <div class="fc-tabs">
                 <button class="fc-tab active" onclick="window.location.href='productos.php'">
                    <i class='bx bx-list-ul'></i> <span>Productos</span>
                </button>
                <button class="fc-tab" onclick="window.location.href='categorias.php'">
                    <i class='bx bx-category'></i> <span>Categorías</span>
                </button>
            </div>
            
            <button class="fc-btn fc-btn-primary" onclick="showAddModal()" style="height: 48px;">
                <i class='bx bx-plus'></i> Nuevo Platillo
            </button>
        </div>

        <div class="fc-card" style="padding: 0; margin-bottom: 25px;">
            <div class="visual-categories-wrapper">
                <div class="visual-category-card active" data-category="" onclick="filterByCategory(this, '')">
                    <div class="cat-icon grad-default"><i class='fas fa-border-all'></i></div>
                    <span class="cat-name">Todos</span>
                </div>

                <?php foreach ($categories as $cat): ?>
                    <?php
                    $cat_icon = 'fas fa-utensils';
                    $cat_grad = 'grad-default';
                    $cat_name_lower = mb_strtolower($cat['name'], 'UTF-8');

                    if (strpos($cat_name_lower, 'alitas') !== false || strpos($cat_name_lower, 'pollo') !== false) {
                        $cat_icon = 'fas fa-drumstick-bite';
                        $cat_grad = 'grad-warm';
                    } elseif (strpos($cat_name_lower, 'hamburguesa') !== false || strpos($cat_name_lower, 'burger') !== false) {
                        $cat_icon = 'fas fa-burger';
                        $cat_grad = 'grad-warm';
                    } elseif (strpos($cat_name_lower, 'bebida') !== false || strpos($cat_name_lower, 'refresco') !== false || strpos($cat_name_lower, 'soda') !== false) {
                        $cat_icon = 'fas fa-martini-glass-citrus';
                        $cat_grad = 'grad-cool';
                    } elseif (strpos($cat_name_lower, 'postre') !== false || strpos($cat_name_lower, 'dulce') !== false) {
                        $cat_icon = 'fas fa-cake-candles';
                        $cat_grad = 'grad-sweet';
                    } elseif (strpos($cat_name_lower, 'combo') !== false || strpos($cat_name_lower, 'complemento') !== false || strpos($cat_name_lower, 'papa') !== false || strpos($cat_name_lower, 'entrada') !== false) {
                        $cat_icon = 'fas fa-bowl-food';
                        $cat_grad = 'grad-amber';
                    } elseif (strpos($cat_name_lower, 'pizza') !== false) {
                        $cat_icon = 'fas fa-pizza-slice';
                        $cat_grad = 'grad-warm';
                    } elseif (strpos($cat_name_lower, 'ensalada') !== false || strpos($cat_name_lower, 'salad') !== false) {
                        $cat_icon = 'fas fa-leaf';
                        $cat_grad = 'grad-default';
                    }
                    ?>
                    <div class="visual-category-card" data-category="<?= htmlspecialchars($cat['name']) ?>"
                        onclick="filterByCategory(this, '<?= htmlspecialchars($cat['name']) ?>')">
                        <div class="cat-icon <?= $cat_grad ?>"><i class='<?= $cat_icon ?>'></i></div>
                        <span class="cat-name"><?= htmlspecialchars($cat['name']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="filter-controls-row" style="display: flex; gap: 15px; padding: 20px; align-items: center; border-bottom: 1px solid var(--fc-border);">
                <div style="position: relative; flex: 1;">
                    <i class='bx bx-search' style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--fc-text-sec); font-size: 20px;"></i>
                    <input type="text" id="searchInput" class="fc-input" placeholder="Buscar por nombre, código o categoría..." style="padding-left: 45px; width: 100%;">
                </div>
                <select id="statusFilter" class="fc-input" style="width: 160px;">
                    <option value="">Cualquier Estado</option>
                    <option value="Activo">Activos</option>
                    <option value="Inactivo">Inactivos</option>
                </select>
            </div>

            <div class="fc-table-responsive">

                <table class="fc-table" id="productsTable">
                    <thead>
                        <tr>
                            <th style="width: 60px;"></th>
                            <th>CÓDIGO</th>
                            <th>PRODUCTO</th>
                            <th>CATEGORÍA</th>
                            <th style="text-align: right;">PRECIO</th>
                            <th>STOCK</th>
                            <th>ESTADO</th>
                            <th style="text-align: right;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <?php
                            $cat_icon = 'bx-restaurant';
                            $gradientClass = 'grad-default';
                            $p_cat_name = mb_strtolower($product['category_name'] ?? '', 'UTF-8');

                            if (strpos($p_cat_name, 'alitas') !== false || strpos($p_cat_name, 'pollo') !== false) {
                                $cat_icon = 'bx-dish';
                                $gradientClass = 'grad-warm';
                            } elseif (strpos($p_cat_name, 'hamburguesa') !== false || strpos($p_cat_name, 'burger') !== false || strpos($p_cat_name, 'pizza') !== false) {
                                $cat_icon = 'bx-restaurant';
                                $gradientClass = 'grad-warm';
                            } elseif (strpos($p_cat_name, 'bebida') !== false || strpos($p_cat_name, 'refresco') !== false || strpos($p_cat_name, 'soda') !== false) {
                                $cat_icon = 'bx-drink';
                                $gradientClass = 'grad-cool';
                            } elseif (strpos($p_cat_name, 'postre') !== false || strpos($p_cat_name, 'dulce') !== false) {
                                $cat_icon = 'bx-cake';
                                $gradientClass = 'grad-sweet';
                            } elseif (strpos($p_cat_name, 'combo') !== false || strpos($p_cat_name, 'complemento') !== false || strpos($p_cat_name, 'papa') !== false || strpos($p_cat_name, 'entrada') !== false) {
                                $cat_icon = 'bx-bowl-hot';
                                $gradientClass = 'grad-amber';
                            }
                            ?>
                            <tr data-category="<?= htmlspecialchars($product['category_name'] ?? '') ?>" data-status="<?= $product['status'] === 'active' ? 'Activo' : 'Inactivo' ?>">
                                <td>
                                    <div class="product-mini-img <?= $gradientClass ?>" style="color: white;">
                                        <?php if (!empty($product['image_url'])): ?>
                                            <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                                            <span class="thumb-fallback" style="display:none;"><i class='bx <?= $cat_icon ?>'></i></span>
                                        <?php else: ?>
                                            <span class="thumb-emoji"><i class='bx <?= $cat_icon ?>'></i></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="fc-badge fc-badge-outline" style="font-family: monospace;"><?= htmlspecialchars($product['code']) ?></span></td>
                                <td>
                                    <div style="font-weight: 600; color: var(--fc-text-main);">
                                        <?= htmlspecialchars($product['name']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fc-badge fc-badge-outline" style="background: rgba(255,255,255,0.03);">
                                        <?= htmlspecialchars($product['category_name'] ?? 'Sin categoría') ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <div style="font-weight: 800; color: var(--fc-primary); font-size: 16px;">
                                        C$<?= number_format($product['price'], 0) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="<?= $product['stock'] < 10 ? 'fc-text-rose' : '' ?>" style="font-weight: 600;">
                                        <i class='bx bx-package'></i> <?= $product['stock'] ?> ud.
                                    </div>
                                </td>
                                <td>
                                    <?php if ($product['status'] === 'active'): ?>
                                        <span class="fc-badge fc-badge-success">Activo</span>
                                    <?php else: ?>
                                        <span class="fc-badge fc-badge-rose">Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'inventory_edit')): ?>
                                            <button class="fc-btn fc-btn-outline" style="width: 40px; height: 40px; padding: 0;"
                                                onclick="showEditModal(<?= htmlspecialchars(json_encode($product)) ?>)"
                                                title="Editar">
                                                <i class='bx bx-edit-alt'></i>
                                            </button>
                                        <?php endif; ?>

                                        <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'inventory_delete')): ?>
                                            <form method="POST" style="display:inline;" onsubmit="confirmDelete(event, this)">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                                <button type="submit" class="fc-btn fc-btn-outline" style="width: 40px; height: 40px; padding: 0; color: var(--fc-rose);"
                                                    title="Eliminar">
                                                    <i class='bx bx-trash'></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<!-- Edit Product Modal -->
<div class="fc-modal-overlay" id="editModal">
    <div class="fc-modal" style="max-width: 550px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-edit-alt'></i> Editar Platillo</h3>
            <button class="fc-modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" enctype="multipart/form-data" class="fc-form">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">

                <div class="fc-form-group">
                    <label class="fc-label">Código de Producto</label>
                    <input type="text" name="code" id="edit_code" class="fc-input" required>
                </div>

                <div class="fc-form-row">
                    <div class="fc-form-group" style="flex: 0 0 80px;">
                        <label class="fc-label">Icono</label>
                        <input type="text" name="icon" id="edit_icon" class="fc-input" style="text-align: center; font-size: 1.5rem;">
                    </div>
                    <div class="fc-form-group" style="flex: 1;">
                        <label class="fc-label">Nombre del Platillo</label>
                        <input type="text" name="name" id="edit_name" class="fc-input" required>
                    </div>
                </div>

                <div class="fc-form-row">
                    <div class="fc-form-group">
                        <label class="fc-label">Precio (C$)</label>
                        <input type="number" step="1" name="price" id="edit_price" class="fc-input" required>
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-label">Stock Actual</label>
                        <input type="number" name="stock" id="edit_stock" class="fc-input" required>
                    </div>
                </div>

                <div class="fc-form-row">
                    <div class="fc-form-group">
                        <label class="fc-label">Categoría</label>
                        <select name="category_id" id="edit_category" class="fc-input">
                            <option value="">Sin categoría</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-label">Estado</label>
                        <select name="status" id="edit_status" class="fc-input">
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                        </select>
                    </div>
                </div>

                <div class="fc-form-group">
                    <label class="fc-label">Imagen (Opcional)</label>
                    <input type="file" name="image" class="fc-input" accept="image/*">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeEditModal()">Cancelar</button>
                    <button type="submit" class="fc-btn fc-btn-primary fc-w100">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="fc-modal-overlay" id="addModal">
    <div class="fc-modal" style="max-width: 550px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-plus-circle'></i> Nuevo Platillo</h3>
            <button class="fc-modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" enctype="multipart/form-data" class="fc-form">
                <input type="hidden" name="action" value="add">

                <div class="fc-form-group">
                    <label class="fc-label">Código de Producto</label>
                    <input type="text" name="code" class="fc-input" required placeholder="Ej: HAM-001">
                </div>

                <div class="fc-form-row">
                    <div class="fc-form-group" style="flex: 0 0 80px;">
                        <label class="fc-label">Icono</label>
                        <input type="text" name="icon" class="fc-input" style="text-align: center; font-size: 1.5rem;" value="🍽️">
                    </div>
                    <div class="fc-form-group" style="flex: 1;">
                        <label class="fc-label">Nombre del Platillo</label>
                        <input type="text" name="name" class="fc-input" required placeholder="Nombre descriptivo...">
                    </div>
                </div>

                <div class="fc-form-row">
                    <div class="fc-form-group">
                        <label class="fc-label">Precio de Venta</label>
                        <input type="number" step="1" name="price" class="fc-input" required placeholder="0">
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-label">Stock Inicial</label>
                        <input type="number" name="stock" class="fc-input" required value="0">
                    </div>
                </div>

                <div class="fc-form-group">
                    <label class="fc-label">Categoría</label>
                    <div style="display: flex; gap: 10px;">
                        <select name="category_id" class="fc-input" style="flex: 1;">
                            <option value="">Sin categoría</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="fc-btn fc-btn-outline" style="padding: 0 15px;" onclick="quickAddCategory()">
                            <i class='bx bx-plus'></i>
                        </button>
                    </div>
                </div>

                <div class="fc-form-group">
                    <label class="fc-label">Imagen Ilustrativa</label>
                    <input type="file" name="image" class="fc-input" accept="image/*">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeAddModal()">Cancelar</button>
                    <button type="submit" class="fc-btn fc-btn-primary fc-w100">Crear Producto</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .visual-categories-wrapper {
        display: flex;
        gap: 15px;
        padding: 20px;
        overflow-x: auto;
        border-bottom: 1px solid var(--fc-border);
        background: rgba(255,255,255,0.02);
    }
    .visual-categories-wrapper::-webkit-scrollbar { height: 4px; }
    .visual-categories-wrapper::-webkit-scrollbar-thumb { background: var(--fc-border); border-radius: 10px; }

    .visual-category-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        padding: 15px;
        min-width: 100px;
        background: var(--fc-bg-dark);
        border: 1px solid var(--fc-border);
        border-radius: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    .visual-category-card:hover { border-color: var(--fc-primary); transform: translateY(-3px); }
    .visual-category-card.active { background: var(--fc-primary); border-color: var(--fc-primary); box-shadow: 0 8px 20px rgba(225, 29, 72, 0.3); }

    .cat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; color: white; }
    .cat-icon i { color: #ffffff !important; }
    .visual-category-card.active .cat-icon { background: rgba(255,255,255,0.2) !important; }
    .visual-category-card.active .cat-name { color: white; }
    .cat-name { font-size: 13px; font-weight: 600; color: var(--fc-text-sec); }

    .product-mini-img { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .product-mini-img img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-emoji { font-size: 22px; }

    .grad-default { background: linear-gradient(135deg, #3f3f46, #18181b); }
    .grad-warm { background: linear-gradient(135deg, var(--fc-primary), #881337); }
    .grad-cool { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
    .grad-amber { background: linear-gradient(135deg, #f59e0b, #b45309); }
    .grad-sweet { background: linear-gradient(135deg, #d946ef, #a21caf); }
</style>

<script>
    let currentCategory = '';

    function filterByCategory(element, category) {
        document.querySelectorAll('.visual-category-card').forEach(c => c.classList.remove('active'));
        if (element) element.classList.add('active');
        currentCategory = category;
        applyFilters();
    }

    function applyFilters() {
        const search = document.getElementById('searchInput').value.toLowerCase();
        const status = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('#productsTable tbody tr');

        rows.forEach(row => {
            const rowText = row.innerText.toLowerCase();
            const rowCategory = row.dataset.category;
            const rowStatus = row.dataset.status;

            const matchesSearch = rowText.includes(search);
            const matchesCategory = !currentCategory || rowCategory === currentCategory;
            const matchesStatus = !status || rowStatus === status;

            row.style.display = (matchesSearch && matchesCategory && matchesStatus) ? '' : 'none';
        });
    }

    document.getElementById('searchInput').addEventListener('input', applyFilters);
    document.getElementById('statusFilter').addEventListener('change', applyFilters);

    function showAddModal() { document.getElementById('addModal').classList.add('show'); }
    function closeAddModal() { document.getElementById('addModal').classList.remove('show'); }
    function showEditModal(p) {
        document.getElementById('edit_id').value = p.id;
        document.getElementById('edit_code').value = p.code;
        document.getElementById('edit_name').value = p.name;
        document.getElementById('edit_icon').value = p.icon || '🍽️';
        document.getElementById('edit_price').value = Math.round(p.price);
        document.getElementById('edit_stock').value = p.stock;
        document.getElementById('edit_category').value = p.category_id || '';
        document.getElementById('edit_status').value = p.status;
        document.getElementById('editModal').classList.add('show');
    }
    function closeEditModal() { document.getElementById('editModal').classList.remove('show'); }

    // Close modals on overlay click
    document.querySelectorAll('.fc-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('show');
        });
    });


    function confirmDelete(e, form) {
        e.preventDefault();
        Swal.fire({
            title: '¿Eliminar producto?',
            text: 'Esta acción no se puede deshacer',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--fc-primary)',
            cancelButtonColor: 'var(--fc-bg-dark)',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            background: 'var(--fc-bg-dark)',
            color: 'var(--fc-text-main)'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    }

    function quickAddCategory() {
        Swal.fire({
            title: 'Nueva Categoría',
            input: 'text',
            inputPlaceholder: 'Nombre de la categoría...',
            showCancelButton: true,
            confirmButtonText: 'Crear',
            confirmButtonColor: 'var(--fc-primary)',
            background: 'var(--fc-bg-dark)',
            color: 'var(--fc-text-main)'
        }).then((result) => {
            if (result.value) {
                const fd = new FormData();
                fd.append('ajax_action', 'add_category');
                fd.append('name', result.value);
                fetch('productos.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') location.reload();
                    });
            }
        });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>