<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access
checkModuleAccess($pdo, $_SESSION['role_id'], 'recetas');

// --- BACKEND LOGIC ---

// 1. Fetch Categories
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch All Products (For the Gallery)
$sql_products = "
    SELECT p.*, c.name as category_name,
    (SELECT COALESCE(SUM(pr.quantity_required * i.cost), 0) 
     FROM product_recipes pr 
     JOIN ingredients i ON pr.ingredient_id = i.id 
     WHERE pr.product_id = p.id) as total_cost
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.name ASC
";
$stmt = $pdo->query($sql_products);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch All Ingredients (For the Search)
$sql_ingredients = "SELECT * FROM ingredients ORDER BY name ASC";
$stmt = $pdo->query($sql_ingredients);
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['ajax_action'] === 'save_category') {
    $name = trim($_POST['name'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($name) {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->execute([$name]);
        }
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid name']);
    }
    exit;
}
if ($_POST['ajax_action'] === 'delete_category') {
    $id = (int)($_POST['id'] ?? 0);
    // Check if category is used
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar la categoría porque tiene productos asignados.']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
    exit;
}
        if ($_POST['ajax_action'] === 'get_recipe') {
            $product_id = intval($_POST['product_id']);
            $stmt = $pdo->prepare("
                SELECT pr.id as recipe_item_id, pr.ingredient_id, pr.quantity_required, 
                       i.name, i.unit, i.cost, i.icon
                FROM product_recipes pr
                JOIN ingredients i ON pr.ingredient_id = i.id
                WHERE pr.product_id = ?
            ");
            $stmt->execute([$product_id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

        if ($_POST['ajax_action'] === 'save_product_details') {
            $id = intval($_POST['product_id']);
            $name = trim($_POST['name']);
            $price = floatval($_POST['price']);
            $cat_id = intval($_POST['category_id']);
            $image_url = null;

            // Handle Image Upload
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/products/';
                if (!is_dir($uploadDir))
                    mkdir($uploadDir, 0777, true);
                $fileName = time() . '_' . basename($_FILES['image']['name']);
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                    $image_url = $uploadDir . $fileName;
                }
            }

            // Insert or Update
            if ($id === 0) {
                $code = 'PROD-' . time() . rand(10,99);
                $sql = "INSERT INTO products (code, name, price, category_id, status, image_url) VALUES (?, ?, ?, ?, 'active', ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$code, $name, $price, $cat_id, $image_url]);
                $id = $pdo->lastInsertId();
            } else {
                if ($image_url) {
                    $sql = "UPDATE products SET name = ?, price = ?, category_id = ?, image_url = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$name, $price, $cat_id, $image_url, $id]);
                } else {
                    $sql = "UPDATE products SET name = ?, price = ?, category_id = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$name, $price, $cat_id, $id]);
                }
            }
            echo json_encode(['status' => 'success', 'id' => $id, 'image_url' => $image_url]);
            exit;
        }

        if ($_POST['ajax_action'] === 'add_ingredient') {
            $product_id = intval($_POST['product_id']);
            $ingredient_id = intval($_POST['ingredient_id']);
            $quantity = isset($_POST['quantity']) ? floatval($_POST['quantity']) : 1;

            $check = $pdo->prepare("SELECT id FROM product_recipes WHERE product_id = ? AND ingredient_id = ?");
            $check->execute([$product_id, $ingredient_id]);
            if ($check->rowCount() == 0) {
                $stmt = $pdo->prepare("INSERT INTO product_recipes (product_id, ingredient_id, quantity_required) VALUES (?, ?, ?)");
                $stmt->execute([$product_id, $ingredient_id, $quantity]);
            } else {
                // Determine if we should update or just return success (user might want to just add, but maybe update is better if it exists?)
                // For now, let's just update the quantity if it exists, effectively "setting" it.
                $stmt = $pdo->prepare("UPDATE product_recipes SET quantity_required = ? WHERE product_id = ? AND ingredient_id = ?");
                $stmt->execute([$quantity, $product_id, $ingredient_id]);
            }
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($_POST['ajax_action'] === 'update_ingredient_qty') {
            $recipe_item_id = intval($_POST['recipe_item_id']);
            $quantity = floatval($_POST['quantity']);
            $stmt = $pdo->prepare("UPDATE product_recipes SET quantity_required = ? WHERE id = ?");
            $stmt->execute([$quantity, $recipe_item_id]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($_POST['ajax_action'] === 'remove_ingredient') {
            $stmt = $pdo->prepare("DELETE FROM product_recipes WHERE id = ?");
            $stmt->execute([intval($_POST['recipe_item_id'])]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($_POST['ajax_action'] === 'delete_product') {
            $product_id = intval($_POST['product_id']);
            // Borramos de product_recipes primero para mantener integridad
            $stmt = $pdo->prepare("DELETE FROM product_recipes WHERE product_id = ?");
            $stmt->execute([$product_id]);
            // Luego borramos el producto
            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            echo json_encode(['status' => 'success']);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- FRONTEND ---
$pageTitle = "Gestor de Recetas";
include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content" style="padding: 0; overflow: hidden; height: 100vh;">
    <style>
        .recipe-builder-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 30px;
            height: calc(100vh - 80px);
            padding: 20px 30px;
            overflow: hidden;
        }

        .recipe-sidebar {
            background: #ffffff;
            border: 1px solid var(--fc-border);
            border-radius: 24px;
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .recipe-main {
            overflow-y: auto;
            padding-right: 5px;
        }

        .category-nav-item {
            padding: 12px 18px;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--fc-text-sec);
            border: 1px solid transparent;
        }

        .category-nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--fc-text-main);
        }

        .category-nav-item.active {
            background: rgba(139, 92, 246, 0.1);
            color: var(--fc-primary);
            border-color: rgba(139, 92, 246, 0.2);
            font-weight: 600;
        }

        .plato-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: 1px solid var(--fc-border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }

        .plato-card:hover {
            transform: translateY(-8px);
            border-color: var(--fc-primary);
            box-shadow: 0 20px 40px -12px rgba(139, 92, 246, 0.25);
        }

        .plato-visual {
            height: 180px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 50px;
            border-bottom: 1px solid var(--fc-border);
            position: relative;
            overflow: hidden;
        }

        .plato-visual img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .plato-card:hover .plato-visual img {
            transform: scale(1.1);
        }

        .plato-body {
            padding: 18px;
            flex-grow: 1;
        }

        .plato-name {
            font-weight: 700;
            color: var(--fc-text-main);
            font-size: 16px;
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .plato-footer {
            padding: 15px 18px;
            background: #f8fafc;
            border-top: 1px solid var(--fc-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .plato-price {
            font-weight: 800;
            color: var(--fc-primary);
            font-size: 18px;
        }

        .recipe-item-row {
            display: grid;
            grid-template-columns: 48px 1fr 100px 40px;
            gap: 15px;
            align-items: center;
            padding: 15px;
            background: #ffffff;
            border: 1px solid var(--fc-border);
            border-radius: 16px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
        }

        .recipe-item-row:hover {
            background: #f8fafc;
            border-color: var(--fc-primary);
        }

        .ing-catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 15px;
            padding: 20px;
            max-height: 50vh;
            overflow-y: auto;
        }

        .ing-pick-card {
            background: #ffffff;
            border: 1px solid var(--fc-border);
            border-radius: 16px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .ing-pick-card:hover {
            background: rgba(139, 92, 246, 0.05);
            border-color: var(--fc-primary);
            transform: scale(1.05);
        }

        @media (max-width: 1024px) {
            .recipe-builder-container {
                grid-template-columns: 1fr;
                padding: 15px;
                height: auto;
                overflow: visible;
            }
            .recipe-sidebar {
                display: none;
            }
        }
    </style>

        <div class="fc-header" style="padding: 25px 30px 10px 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-package'></i> Menú</h1>
                <p>Configuración de platos, costos de producción y márgenes</p>
            </div>
            <div class="fc-header-right no-print" style="display: flex; gap: 15px; align-items: center;">
                <button onclick="openCatManager()" class="fc-btn fc-btn-outline" style="height: 48px; border-color: #e2e8f0; color: #475569; background: white; text-decoration: none; display: flex; align-items: center; justify-content: center; font-weight: 500;">
                    <i class='bx bx-category' style="margin-right: 8px;"></i> Categorías
                </button>
                <button class="fc-btn fc-btn-primary" onclick="initNewProduct()" style="height: 48px;">
                    <i class='bx bx-plus'></i> Nuevo
                </button>
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></span>
                        <span class="role"><?= htmlspecialchars($_SESSION['role_name'] ?? 'Administrador') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="recipe-builder-container">

            <!-- LEFT: Categories -->
            <aside class="recipe-sidebar">
                <div style="margin-bottom: 10px;">
                    <input type="text" id="gridSearch" placeholder="Buscar plato..." class="fc-input" onkeyup="filterGrid()">
                </div>
                <div class="category-nav-list" style="display:flex; flex-direction:column; gap:8px;">
                    <div class="category-nav-item active" onclick="filterCategory('all', this)">
                        <span><i class='bx bx-grid-alt'></i> Todos</span>
                        <span class="fc-badge fc-badge-outline" style="font-size: 10px;"><?= count($products) ?></span>
                    </div>
                    <?php foreach ($categories as $cat): ?>
                        <div class="category-nav-item" onclick="filterCategory(<?= $cat['id'] ?>, this)">
                            <span><i class='bx bx-chevron-right'></i> <?= htmlspecialchars($cat['name']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>

            <!-- Main: Grid -->
            <section class="recipe-main">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 25px;" id="dishGrid">
                    <?php foreach ($products as $p): ?>
                        <div class="fc-card plato-card product-card" data-cat="<?= $p['category_id'] ?>" data-name="<?= strtolower($p['name']) ?>" 
                             onclick='loadProduct(<?= htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)'>
                            
                            <div class="plato-visual">
                                <?php if ($p['image_url']): ?>
                                    <img src="<?= htmlspecialchars($p['image_url']) ?>">
                                <?php else: ?>
                                    <i class='bx bx-dish' style="opacity: 0.2; font-size: 60px;"></i>
                                <?php endif; ?>
                            </div>

                            <div class="plato-body">
                                <div class="plato-name"><?= htmlspecialchars($p['name']) ?></div>
                                <div style="font-size: 11px; color: var(--fc-text-sec); display: flex; align-items: center; gap: 5px;">
                                    <i class='bx bx-purchase-tag-alt'></i>
                                    <span><?= htmlspecialchars($p['category_name']) ?></span>
                                </div>
                            </div>

                            <div class="plato-footer">
                                <div class="plato-price">C$<?= number_format($p['price'], 0) ?></div>
                                <div class="fc-badge fc-badge-outline" style="font-size: 9px; opacity: 0.8;">PROD</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>     </div>
    </main>
</div>

<!-- Editor Modal -->
<div id="editorModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 600px;">
        <div id="modalContent">
            <!-- Dynamic Content -->
        </div>
    </div>
</div>

<!-- Ingredient Picker Modal -->
<div id="ingredientModal" class="fc-modal-overlay" style="z-index: 10000;">
    <div class="fc-modal" style="max-width: 700px; height: 85vh; display: flex; flex-direction: column;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-search-alt'></i> Catálogo de Insumos</h3>
            <span class="close" onclick="closeIngModal()">&times;</span>
        </div>

        <div class="fc-modal-body" style="flex:1; overflow: hidden; display: flex; flex-direction: column; padding: 0;">
            <!-- View 1: Catalog -->
            <div id="ingCatalogView" style="height: 100%; display: flex; flex-direction: column;">
                <div style="padding: 20px; background: #f8fafc; border-bottom: 1px solid var(--fc-border);">
                    <div style="position:relative;">
                        <i class='bx bx-search' style="position:absolute; left:15px; top:50%; transform:translateY(-50%); font-size:1.2em; color: var(--fc-text-sec);"></i>
                        <input type="text" id="catalogSearch" class="fc-input" style="padding-left: 45px; height: 50px;" placeholder="Buscar insumo (ej: Tomate, Harina)..." onkeyup="filterCatalog()" autofocus>
                    </div>
                </div>
                <div id="catalogGrid" class="ing-catalog-grid" style="flex:1; padding: 25px;">
                    <!-- Items rendered via JS -->
                </div>
            </div>

            <!-- View 2: Detail -->
            <div id="ingDetailView" style="height: 100%; display: none; flex-direction: column; padding: 30px; text-align: center; overflow-y: auto;">
                <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                    <div id="selIngIcon" style="font-size: 60px; margin-bottom: 15px; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.2));">📦</div>
                    <h2 id="selIngName" style="margin:0; font-weight: 800; letter-spacing: -0.5px;">Nombre Insumo</h2>
                    <p id="selIngUnit" style="color:var(--fc-text-sec); margin-top:5px; font-weight: 500;">Costo Base: C$ 0.00 / un</p>

                    <!-- SIMPLE MODE ONLY -->
                    <div id="simpleInputSection" style="margin-top: 30px; width: 100%; max-width: 350px;">
                        <label class="fc-label" style="text-align: center; margin-bottom: 10px;">CANTIDAD A AGREGAR</label>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="number" id="selIngQty" class="fc-input" value="1" step="0.0001" oninput="updateSelTotal()" style="font-size:24px; font-weight:800; text-align:center;">
                            <div id="unitSelectorWrapper" style="width: 140px;"></div>
                        </div>
                    </div>

                    <div style="margin-top: 30px; background: #fff4f4; border: 1px dashed #fecdd3; padding: 20px; border-radius: 15px; width: 100%; max-width: 350px;">
                        <div style="font-size:10px; color:#f43f5e; font-weight:800; letter-spacing:1px; margin-bottom:5px;">COSTO POR ESTA CANTIDAD</div>
                        <div id="selIngTotal" style="font-size:32px; font-weight:900; color:#8b5cf6;">C$ 0.00</div>
                    </div>
                </div>
                <div style="display:flex; gap:10px; margin-top: 20px;">
                    <button class="fc-btn fc-btn-outline" style="width: 120px;" onclick="showCatalog()">
                        <i class='bx bx-left-arrow-alt'></i> Volver
                    </button>
                    <button class="fc-btn fc-btn-primary" style="flex:1; font-weight: 800;" onclick="confirmSelection()">
                        <i class='bx bx-check-circle'></i> Agregar a Receta
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Category Manager Modal -->
<div id="catModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 450px; background: #ffffff; border-radius: 24px; box-shadow: 0 25px 50px rgba(0,0,0,0.1); border: none; overflow: hidden;">
        <div class="fc-modal-header" style="background: transparent; border-bottom: 1px solid rgba(0,0,0,0.05); padding: 25px;">
            <h3 style="font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 10px; font-size: 20px;">
                <div style="width: 40px; height: 40px; border-radius: 12px; background: var(--fc-primary); color: white; display: flex; justify-content: center; align-items: center; box-shadow: 0 8px 15px rgba(139, 92, 246, 0.3);">
                    <i class='bx bx-category'></i>
                </div>
                Categorías
            </h3>
            <button class="fc-close" onclick="closeCatManager()" style="background: #f1f5f9 !important; border: none !important; box-shadow: none !important; border-radius: 50px !important; width: 35px !important; height: 35px !important; color: #64748b !important; display: flex !important; justify-content: center !important; align-items: center !important; font-size: 22px !important; cursor: pointer !important; transition: all 0.3s ease !important;" onmouseover="this.style.setProperty('background', '#e2e8f0', 'important'); this.style.setProperty('color', '#ef4444', 'important');" onmouseout="this.style.setProperty('background', '#f1f5f9', 'important'); this.style.setProperty('color', '#64748b', 'important');"><i class='bx bx-x'></i></button>
        </div>
        <div class="fc-modal-content" style="padding: 25px; background: #fafafa;">

            <div style="display: flex; gap: 15px; margin-bottom: 30px; position: relative;">
                <input type="text" id="newCatName" class="fc-input" placeholder="Crear nueva categoría..." style="background: #ffffff; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-radius: 16px; padding: 16px 20px; font-weight: 600; font-size: 15px; width: 100%; color: #334155;">
                <button class="fc-btn fc-btn-primary" onclick="saveCategory(0)" style="width: 55px; height: 55px; padding: 0; border-radius: 16px; box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3); display: flex; justify-content: center; align-items: center; flex-shrink: 0; font-size: 24px;">
                    <i class='bx bx-plus'></i>
                </button>
            </div>

            <div style="font-size: 13px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px;">Categorías Existentes</div>
            
            <div style="max-height: 350px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; padding-right: 5px;" class="custom-scrollbar">
                <?php foreach ($categories as $cat): ?>
                    <div style="display: flex; align-items: center; gap: 15px; padding: 15px 20px; background: #ffffff; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); transition: all 0.3s ease; border: 1px solid rgba(0,0,0,0.03);" onmouseover="this.style.boxShadow='0 8px 20px rgba(0,0,0,0.06)'; this.style.transform='translateY(-2px)';" onmouseout="this.style.boxShadow='0 2px 10px rgba(0,0,0,0.02)'; this.style.transform='translateY(0)';">
                        <div style="color: var(--fc-primary); font-size: 20px; opacity: 0.7;">
                            <i class='bx bx-folder'></i>
                        </div>
                        <input type="text" value="<?= htmlspecialchars($cat['name']) ?>" id="cat_input_<?= $cat['id'] ?>" 
                               style="background: transparent !important; border: none !important; box-shadow: none !important; height: auto !important; padding: 0 !important; font-weight: 700 !important; font-size: 15px !important; color: #475569 !important; width: 100% !important; outline: none !important;"
                               onchange="saveCategory(<?= $cat['id'] ?>)">
                        <button onclick="deleteCategory(<?= $cat['id'] ?>)" style="background: #fee2e2; border: none; color: #ef4444; cursor: pointer; width: 35px; height: 35px; border-radius: 10px; display: flex; justify-content: center; align-items: center; font-size: 16px; transition: all 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'">
                            <i class='bx bx-trash'></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    function openCatManager() { document.getElementById('catModal').classList.add('show'); }
    function closeCatManager() { document.getElementById('catModal').classList.remove('show'); }
    function saveCategory(id) {
        let name = id === 0 ? document.getElementById('newCatName').value : document.getElementById('cat_input_'+id).value;
        if (!name.trim()) return;
        let fd = new FormData();
        fd.append('ajax_action', 'save_category');
        fd.append('id', id);
        fd.append('name', name);
        fetch('productos.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res=>{
            if(res.status==='success') location.reload();
            else if(res.message) Swal.fire('Error', res.message, 'error');
        });
    }
    function deleteCategory(id) {
        Swal.fire({
            title: '¿Eliminar categoría?',
            text: "No podrás deshacer esto. Los productos de esta categoría podrían verse afectados.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                let fd = new FormData();
                fd.append('ajax_action', 'delete_category');
                fd.append('id', id);
                fetch('productos.php', { method: 'POST', body: fd }).then(r=>r.json()).then(res=>{
                    if(res.status==='success') location.reload();
                    else Swal.fire('Error', res.message, 'error');
                });
            }
        });
    }
    
    let currentProduct = null;
    let currentRecipe = [];
    const ingredients = <?= json_encode($ingredients) ?>;
    const allProducts = <?= json_encode($products) ?>;

    const unitConversions = {
        'g': { 'g': { factor: 1, label: 'g' }, 'kg': { factor: 1000, label: 'kg' }, 'lb': { factor: 453.59, label: 'lb' }, 'oz': { factor: 28.35, label: 'oz' } },
        'kg': { 'kg': { factor: 1, label: 'kg' }, 'g': { factor: 0.001, label: 'g' }, 'lb': { factor: 0.45359, label: 'lb' } },
        'lb': { 'lb': { factor: 1, label: 'lb' }, 'kg': { factor: 2.20462, label: 'kg' }, 'g': { factor: 0.00220462, label: 'g' } },
        'ml': { 'ml': { factor: 1, label: 'ml' }, 'lt': { factor: 1000, label: 'lt' }, 'fl oz': { factor: 29.57, label: 'fl oz' } },
        'lt': { 'lt': { factor: 1, label: 'lt' }, 'ml': { factor: 0.001, label: 'ml' } },
        'und': { 'und': { factor: 1, label: 'und' } },
        'pza': { 'pza': { factor: 1, label: 'pza' } }
    };

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action');
        const id = urlParams.get('id');
        if (action === 'new') initNewProduct();
        else if (id) {
            const product = allProducts.find(p => p.id == id);
            if (product) loadProduct(product);
        }
    });

    function filterGrid() {
        const term = document.getElementById('gridSearch').value.toLowerCase();
        document.querySelectorAll('.product-card').forEach(card => {
            card.style.display = card.dataset.name.includes(term) ? 'flex' : 'none';
        });
    }

    function filterCategory(catId, el) {
        document.querySelectorAll('.category-nav-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('.product-card').forEach(card => {
            card.style.display = (catId === 'all' || card.dataset.cat == catId) ? 'flex' : 'none';
        });
    }

    function openModal(id) { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    function initNewProduct() {
        currentProduct = { id: 0, name: '', price: 0, category_id: 1, image_url: null };
        renderEditor();
        openModal('editorModal');
    }

    function loadProduct(product) {
        currentProduct = product;
        renderEditor();
        fetchRecipe(product.id);
        openModal('editorModal');
    }

    function renderEditor() {
        const p = currentProduct;
        const isNew = p.id === 0;
        document.getElementById('modalContent').innerHTML = `
            <div class="fc-modal-header">
                <h3><i class='bx bx-edit'></i> ${isNew ? 'Nuevo Plato' : 'Configurar Receta'}</h3>
                <span class="close" onclick="closeModal('editorModal')">&times;</span>
            </div>
            <div class="fc-modal-body" style="padding: 25px; overflow-y: auto; max-height: 70vh;">
                <div onclick="document.getElementById('editImage').click()" style="width:100%; height:180px; border-radius:20px; background:#f8fafc; border:2px dashed var(--fc-border); display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; position:relative; margin-bottom: 25px;">
                    ${p.image_url ? `<img src="${p.image_url}" style="width:100%; height:100%; object-fit:cover;">` : `<i class='bx bx-camera' style="font-size:40px; color:var(--fc-text-sec);"></i><span style="font-size:11px; color:var(--fc-text-sec); margin-top:8px; font-weight: 700;">SUBIR IMAGEN DEL PLATO</span>`}
                </div>
                <input type="file" id="editImage" hidden onchange="previewUpload(this)">

                <div class="fc-form-group">
                    <label class="fc-label">Nombre del Plato</label>
                    <input type="text" id="editName" class="fc-input" value="${p.name}" placeholder="Ej: Pizza Artesanal">
                </div>
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-top: 15px;">
                    <div class="fc-form-group">
                        <label class="fc-label">Precio de Venta</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:12px; top:12px; font-weight:700; color:var(--fc-text-sec);">C$</span>
                            <input type="number" id="editPrice" class="fc-input" value="${p.price}" style="padding-left:35px;" oninput="renderRecipeList()">
                        </div>
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-label">Categoría</label>
                        <select id="editCat" class="fc-input">
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= $c['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin: 30px 0 15px; display: flex; align-items: center; justify-content: space-between;">
                    <h4 style="margin:0; font-weight: 800; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--fc-text-main);">🧪 Composición (Receta)</h4>
                    <button class="fc-btn fc-btn-outline" onclick="openIngModal()" style="height: 32px; padding: 0 12px; font-size: 11px;">
                        <i class='bx bx-plus-circle'></i> Agregar Insumo
                    </button>
                </div>
                
                <div id="recipeList" style="display: flex; flex-direction: column; gap: 8px;">
                    <!-- Items rendered via JS -->
                </div>
            </div>

            <div class="fc-modal-footer" style="padding: 20px 25px; background: #f8fafc; border-top: 1px solid var(--fc-border);">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div style="background: #ffffff; padding: 12px; border-radius: 12px; border: 1px solid var(--fc-border); text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <div style="font-size: 10px; color: var(--fc-text-sec); margin-bottom: 4px;">COSTO PRODUCCIÓN</div>
                        <div id="lblCost" style="font-size: 18px; font-weight: 800; color: #ef4444;">C$ 0.00</div>
                    </div>
                    <div style="background: #ffffff; padding: 12px; border-radius: 12px; border: 1px solid var(--fc-border); text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <div style="font-size: 10px; color: var(--fc-text-sec); margin-bottom: 4px;">UTILIDAD BRUTA</div>
                        <div id="lblProfit" style="font-size: 18px; font-weight: 800; color: #10b981;">C$ 0.00</div>
                    </div>
                </div>
                <div style="display:flex; gap:10px;">
                    ${!isNew ? `<button class="fc-btn fc-btn-outline" style="border-color:#e11d48; color:#e11d48; width: 50px;" onclick="deleteProduct(${p.id})"><i class='bx bx-trash' style="font-size:18px;"></i></button>` : ''}
                    <button class="fc-btn fc-btn-primary" style="flex:1; height: 50px; font-weight: 800;" onclick="saveDetails()">
                        <i class='bx bx-save'></i> Guardar Receta
                    </button>
                </div>
            </div>
        `;
        if(!isNew) document.getElementById('editCat').value = p.category_id || 1;
        if(isNew) { currentRecipe = []; renderRecipeList(); }
    }

    let selectedIng = null;

    async function openIngModal() { 
        if (currentProduct.id === 0) {
            const name = document.getElementById('editName').value.trim();
            if (!name) {
                return Swal.fire('Nombre requerido', 'Por favor ingresa un nombre para el plato antes de añadir ingredientes.', 'warning');
            }
            const fd = new FormData();
            fd.append('ajax_action', 'save_product_details');
            fd.append('product_id', currentProduct.id);
            fd.append('name', name);
            fd.append('price', document.getElementById('editPrice').value || 0);
            fd.append('category_id', document.getElementById('editCat').value || 1);
            
            const imgInput = document.getElementById('editImage');
            if (imgInput.files[0]) fd.append('image', imgInput.files[0]);

            const res = await fetch('productos.php', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') {
                currentProduct.id = res.id;
                openModal('ingredientModal'); 
                showCatalog();
            } else {
                Swal.fire('Error', res.message || 'No se pudo crear el plato', 'error');
            }
            return;
        }
        openModal('ingredientModal'); 
        showCatalog(); 
    }
    function closeIngModal() { closeModal('ingredientModal'); }

    function showCatalog() {
        document.getElementById('ingCatalogView').style.display = 'flex';
        document.getElementById('ingDetailView').style.display = 'none';
        renderCatalog(ingredients);
        document.getElementById('catalogSearch').value = '';
    }

    function filterCatalog() {
        const term = document.getElementById('catalogSearch').value.toLowerCase();
        renderCatalog(ingredients.filter(i => i.name.toLowerCase().includes(term)));
    }

    function renderCatalog(list) {
        const grid = document.getElementById('catalogGrid');
        grid.innerHTML = list.length === 0 ? '<div style="grid-column: 1/-1; text-align:center; padding:40px; color:var(--fc-text-sec);">No se encontraron insumos</div>' : '';
        list.forEach(i => {
            const el = document.createElement('div');
            el.className = 'ing-pick-card';
            el.onclick = () => showIngDetail(i);
            el.innerHTML = `
                <div class="fc-badge fc-badge-outline" style="position:absolute; top:8px; right:8px; font-size:8px;">${i.unit}</div>
                <div style="font-size:32px; margin-bottom:10px;">${i.icon || '📦'}</div>
                <div style="font-size:12px; font-weight:700; color:var(--fc-text-main); line-height:1.2;">${i.name}</div>
            `;
            grid.appendChild(el);
        });
    }

    function showIngDetail(ing) {
        selectedIng = ing;
        document.getElementById('ingCatalogView').style.display = 'none';
        document.getElementById('ingDetailView').style.display = 'flex';
        document.getElementById('selIngIcon').innerText = ing.icon || '📦';
        document.getElementById('selIngName').innerText = ing.name;
        document.getElementById('selIngUnit').innerText = `Costo Base: C$ ${parseFloat(ing.cost).toFixed(2)} / ${ing.unit || 'un'}`;

        const unitContainer = document.getElementById('unitSelectorWrapper');
        let baseUnit = ing.unit ? ing.unit.trim().toLowerCase() : 'und';
        
        let options = unitConversions[baseUnit] ? Object.entries(unitConversions[baseUnit]).map(([k, v]) => `<option value="${v.factor}">${v.label}</option>`).join('') : `<option value="1">${baseUnit}</option>`;
        unitContainer.innerHTML = `<select id="selIngFactor" class="fc-input" onchange="updateSelTotal()">${options}</select>`;

        const qtyInput = document.getElementById('selIngQty');
        qtyInput.value = 1; qtyInput.focus(); qtyInput.select();
        updateSelTotal();
    }

    function updateSelTotal() {
        const qty = parseFloat(document.getElementById('selIngQty').value) || 0;
        const factor = parseFloat(document.getElementById('selIngFactor')?.value || 1);
        const total = qty * factor * parseFloat(selectedIng.cost);
        document.getElementById('selIngTotal').innerText = 'C$ ' + total.toFixed(2);
    }

    function confirmSelection() {
        if (!selectedIng) return;
        const qty = parseFloat(document.getElementById('selIngQty').value) || 0;
        const factor = parseFloat(document.getElementById('selIngFactor')?.value || 1);
        if (qty <= 0) return Swal.fire('Atención', 'Ingrese una cantidad válida', 'warning');
        addIngredient(selectedIng.id, qty * factor);
        closeIngModal();
    }

    function addIngredient(id, qty = 1) {
        if (currentProduct.id === 0) return Swal.fire('Primero guarda', 'Debe guardar la información básica del plato antes de añadir ingredientes.', 'info');
        const fd = new FormData();
        fd.append('ajax_action', 'add_ingredient');
        fd.append('product_id', currentProduct.id);
        fd.append('ingredient_id', id);
        fd.append('quantity', qty);
        fetch('productos.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => { if (res.status === 'success') fetchRecipe(currentProduct.id); });
    }

    function fetchRecipe(pid) {
        const fd = new FormData();
        fd.append('ajax_action', 'get_recipe');
        fd.append('product_id', pid);
        fetch('productos.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                currentRecipe = res.data;
                renderRecipeList();
            });
    }

    function renderRecipeList() {
        const list = document.getElementById('recipeList');
        if (!list) return;
        list.innerHTML = '';
        let totalCost = 0;

        currentRecipe.forEach(item => {
            const subtotal = item.quantity_required * item.cost;
            totalCost += subtotal;
            const div = document.createElement('div');
            div.className = 'recipe-item-row';
            div.innerHTML = `
                <div style="font-size: 24px; background: #f8fafc; border-radius: 10px; height: 48px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--fc-border);">${item.icon || '📦'}</div>
                <div>
                    <div style="font-size: 13px; font-weight: 700; color: var(--fc-text-main);">${item.name}</div>
                    <div style="font-size: 10px; color: var(--fc-text-sec);">C$ ${parseFloat(item.cost).toFixed(2)} / ${item.unit}</div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end;">
                    <div style="display:flex; align-items:center; gap:5px; background:#f1f5f9; border: 1px solid var(--fc-border); border-radius:6px; padding:2px 6px;">
                        <input type="number" value="${parseFloat(item.quantity_required)}" step="0.0001" style="width:50px; background:transparent; border:none; color:var(--fc-text-main); font-weight:700; font-size:11px; text-align:right; outline:none;" onchange="updateQty(${item.recipe_item_id}, this.value)">
                        <span style="font-size:9px; color:var(--fc-text-sec); font-weight:700;">${item.unit}</span>
                    </div>
                    <div style="font-size:11px; font-weight:800; color:var(--fc-primary); margin-top:4px;">C$ ${subtotal.toFixed(2)}</div>
                </div>
                <button onclick="removeIng(${item.recipe_item_id})" style="background:none; border:none; color:var(--fc-text-sec); cursor:pointer; font-size:18px;"><i class='bx bx-x'></i></button>
            `;
            list.appendChild(div);
        });

        const price = parseFloat(document.getElementById('editPrice').value) || 0;
        document.getElementById('lblCost').innerText = 'C$ ' + totalCost.toFixed(2);
        document.getElementById('lblProfit').innerText = 'C$ ' + (price - totalCost).toFixed(2);
    }

    function updateQty(rid, qty) {
        const fd = new FormData();
        fd.append('ajax_action', 'update_ingredient_qty');
        fd.append('recipe_item_id', rid);
        fd.append('quantity', qty);
        fetch('productos.php', { method: 'POST', body: fd }).then(() => fetchRecipe(currentProduct.id));
    }

    function removeIng(rid) {
        const fd = new FormData();
        fd.append('ajax_action', 'remove_ingredient');
        fd.append('recipe_item_id', rid);
        fetch('productos.php', { method: 'POST', body: fd }).then(() => fetchRecipe(currentProduct.id));
    }


    function previewUpload(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const holder = document.querySelector('#modalContent img') || document.querySelector('#modalContent i').parentNode;
                if(holder.tagName === 'IMG') holder.src = e.target.result;
                else holder.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;">`;
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function saveDetails() {
        const fd = new FormData();
        fd.append('ajax_action', 'save_product_details');
        fd.append('product_id', currentProduct.id);
        fd.append('name', document.getElementById('editName').value);
        fd.append('price', document.getElementById('editPrice').value);
        fd.append('category_id', document.getElementById('editCat').value);
        const imgInput = document.getElementById('editImage');
        if (imgInput.files[0]) fd.append('image', imgInput.files[0]);

        fetch('productos.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                if (res.status === 'success') {
                    Swal.fire({ title: '¡Guardado!', text: 'La receta se ha actualizado correctamente', icon: 'success', confirmButtonColor: '#e11d48' })
                    .then(() => location.reload());
                } else Swal.fire('Error', res.message, 'error');
            });
    }

    function deleteProduct(id) {
        Swal.fire({
            title: '¿Eliminar este plato?',
            text: 'Se borrará el plato y toda su receta. ¡Esta acción no se puede deshacer!',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: '#94a3b8',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                const fd = new FormData();
                fd.append('ajax_action', 'delete_product');
                fd.append('product_id', id);
                fetch('productos.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(res => {
                        if (res.status === 'success') location.reload();
                    });
            }
        });
    }
</script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>
