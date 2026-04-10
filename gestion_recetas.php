<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

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
                $sql = "INSERT INTO products (name, price, category_id, status, image_url) VALUES (?, ?, ?, 'active', ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $price, $cat_id, $image_url]);
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
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--fc-border);
            border-radius: 24px;
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
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
            background: rgba(225, 29, 72, 0.1);
            color: var(--fc-primary);
            border-color: rgba(225, 29, 72, 0.2);
            font-weight: 600;
        }

        .plato-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            border: 1px solid var(--fc-border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: rgba(30, 41, 59, 0.5);
            backdrop-filter: blur(10px);
        }

        .plato-card:hover {
            transform: translateY(-8px);
            border-color: var(--fc-primary);
            box-shadow: 0 20px 40px -12px rgba(225, 29, 72, 0.25);
        }

        .plato-visual {
            height: 180px;
            background: rgba(255, 255, 255, 0.02);
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
            background: rgba(255, 255, 255, 0.01);
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
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--fc-border);
            border-radius: 16px;
            margin-bottom: 12px;
            transition: all 0.2s ease;
        }

        .recipe-item-row:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.2);
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
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--fc-border);
            border-radius: 16px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .ing-pick-card:hover {
            background: rgba(225, 29, 72, 0.1);
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
                <h1><i class='bx bx-dish'></i> Gestor de Recetas</h1>
                <p>Configuración de platos, costos de producción y márgenes</p>
            </div>
            <div class="fc-header-right no-print">
                <button class="fc-btn fc-btn-primary" onclick="initNewProduct()">
                    <i class='bx bx-plus'></i> Nuevo Plato
                </button>
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
<div id="ingredientModal" class="fc-modal-overlay" style="z-index: 1100;">
    <div class="fc-modal" style="max-width: 700px; height: 85vh; display: flex; flex-direction: column;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-search-alt'></i> Catálogo de Insumos</h3>
            <span class="close" onclick="closeIngModal()">&times;</span>
        </div>

        <div class="fc-modal-body" style="flex:1; overflow: hidden; display: flex; flex-direction: column; padding: 0;">
            <!-- View 1: Catalog -->
            <div id="ingCatalogView" style="height: 100%; display: flex; flex-direction: column;">
                <div style="padding: 20px; background: rgba(255,255,255,0.02); border-bottom: 1px solid var(--fc-border);">
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

                    <!-- Calculator Tabs -->
                    <div style="background: rgba(0,0,0,0.2); padding: 5px; border-radius: 14px; display: inline-flex; margin-top: 25px; gap: 5px; border: 1px solid var(--fc-border);">
                        <button id="modeSimple" class="fc-btn fc-btn-outline" style="border:none; height: 36px; padding: 0 20px; font-size: 13px;" onclick="setMode('simple')">Simple</button>
                        <button id="modeYield" class="fc-btn fc-btn-outline" style="border:none; height: 36px; padding: 0 20px; font-size: 13px;" onclick="setMode('yield')">⏳ Prorrateo</button>
                    </div>

                    <!-- SIMPLE MODE -->
                    <div id="simpleInputSection" style="margin-top: 30px; width: 100%; max-width: 350px;">
                        <label class="fc-label" style="text-align: center; margin-bottom: 10px;">CANTIDAD A AGREGAR</label>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="number" id="selIngQty" class="fc-input" value="1" step="0.0001" oninput="updateSelTotal()" style="font-size:24px; font-weight:800; text-align:center;">
                            <div id="unitSelectorWrapper" style="width: 140px;"></div>
                        </div>
                    </div>

                    <!-- YIELD MODE -->
                    <div id="yieldInputSection" style="margin-top: 25px; width: 100%; max-width: 400px; display: none; background: rgba(255,255,255,0.03); border: 1px solid var(--fc-border); padding: 20px; border-radius: 20px;">
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                            <div style="text-align: left;">
                                <label class="fc-label" style="font-size: 10px;">TOTAL LOTE/ENVASE</label>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <input type="number" id="yieldTotalQty" class="fc-input" placeholder="0" oninput="calcYield()">
                                    <span class="yield-unit" style="font-weight: 700; color: var(--fc-text-sec);">L</span>
                                </div>
                            </div>
                            <div style="text-align: left;">
                                <label class="fc-label" style="font-size: 10px;">RENDIMIENTO (PORC)</label>
                                <input type="number" id="yieldPortions" class="fc-input" placeholder="0" oninput="calcYield()">
                            </div>
                        </div>
                        <div style="text-align: center; padding-top: 15px; border-top: 1px dashed var(--fc-border); font-size: 13px; color: var(--fc-text-sec);">
                            <i class='bx bx-info-circle'></i> Resultado: <strong id="yieldResult" style="color:var(--fc-text-main);">0.0000</strong> <span class="yield-unit">L</span> por orden
                        </div>
                    </div>

                    <div style="margin-top: 30px; background: rgba(225, 29, 72, 0.05); border: 1px solid rgba(225, 29, 72, 0.2); padding: 20px; border-radius: 20px; width: 100%; max-width: 350px;">
                        <div style="font-size:11px; color:var(--fc-text-sec); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px;">COSTO POR PORCIÓN</div>
                        <div id="selIngTotal" style="font-size:28px; font-weight:900; color:var(--fc-primary);">C$ 0.00</div>
                    </div>
                </div>

                <div style="margin-top: 30px; display:grid; grid-template-columns: 100px 1fr; gap: 15px;">
                    <button class="fc-btn fc-btn-outline" onclick="showCatalog()">
                        <i class='bx bx-left-arrow-alt'></i> Volver
                    </button>
                    <button class="fc-btn fc-btn-primary" onclick="confirmSelection()" style="height: 50px; font-weight: 700;">
                        <i class='bx bx-check-circle'></i> Agregar a Receta
                    </button>
                </div>
<script>
    let currentProduct = null;
    let currentRecipe = [];
    const ingredients = <?= json_encode($ingredients) ?>;
    const allProducts = <?= json_encode($products) ?>;

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
                <div onclick="document.getElementById('editImage').click()" style="width:100%; height:180px; border-radius:20px; background:rgba(255,255,255,0.03); border:2px dashed var(--fc-border); display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; position:relative; margin-bottom: 25px;">
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
                            <input type="number" id="editPrice" class="fc-input" value="${p.price}" style="padding-left:35px;">
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

            <div class="fc-modal-footer" style="padding: 20px 25px; background: rgba(0,0,0,0.2); border-top: 1px solid var(--fc-border);">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div style="background: rgba(255,255,255,0.02); padding: 12px; border-radius: 12px; border: 1px solid var(--fc-border); text-align: center;">
                        <div style="font-size: 10px; color: var(--fc-text-sec); margin-bottom: 4px;">COSTO PRODUCCIÓN</div>
                        <div id="lblCost" style="font-size: 18px; font-weight: 800; color: #ef4444;">C$ 0.00</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.02); padding: 12px; border-radius: 12px; border: 1px solid var(--fc-border); text-align: center;">
                        <div style="font-size: 10px; color: var(--fc-text-sec); margin-bottom: 4px;">UTILIDAD BRUTA</div>
                        <div id="lblProfit" style="font-size: 18px; font-weight: 800; color: #10b981;">C$ 0.00</div>
                    </div>
                </div>
                <button class="fc-btn fc-btn-primary fc-w100" style="height: 50px; font-weight: 800;" onclick="saveDetails()">
                    <i class='bx bx-save'></i> Guardar Receta
                </button>
            </div>
        `;
        if(!isNew) document.getElementById('editCat').value = p.category_id || 1;
        if(isNew) { currentRecipe = []; renderRecipeList(); }
    }

    let selectedIng = null;
    const unitConversions = {
        'kg': { 'kg': { factor: 1, label: 'kg' }, 'g': { factor: 0.001, label: 'g' }, 'lb': { factor: 0.453592, label: 'lb' } },
        'lt': { 'lt': { factor: 1, label: 'lt' }, 'ml': { factor: 0.001, label: 'ml' } },
        'unidad': { 'unidad': { factor: 1, label: 'un' } }
    };

    function openIngModal() { openModal('ingredientModal'); showCatalog(); }
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
        document.getElementById('selIngUnit').innerText = `Costo Base: C$ ${parseFloat(ing.cost).toFixed(2)} / ${ing.unit}`;

        const unitContainer = document.getElementById('unitSelectorWrapper');
        let baseUnit = ing.unit.toLowerCase();
        if (['l', 'litro', 'litros'].includes(baseUnit)) baseUnit = 'lt';
        if (['k', 'kilo', 'kilos'].includes(baseUnit)) baseUnit = 'kg';
        if (['u', 'un', 'und'].includes(baseUnit)) baseUnit = 'unidad';

        let options = unitConversions[baseUnit] ? Object.entries(unitConversions[baseUnit]).map(([k, v]) => `<option value="${v.factor}">${v.label}</option>`).join('') : `<option value="1">${ing.unit}</option>`;
        unitContainer.innerHTML = `<select id="selIngFactor" class="fc-input" onchange="updateSelTotal()">${options}</select>`;

        const qtyInput = document.getElementById('selIngQty');
        qtyInput.value = 1; qtyInput.focus(); qtyInput.select();
        updateSelTotal();
        qtyInput.oninput = updateSelTotal;
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
        fetch('gestion_recetas.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => { if (res.status === 'success') fetchRecipe(currentProduct.id); });
    }

    function fetchRecipe(pid) {
        const fd = new FormData();
        fd.append('ajax_action', 'get_recipe');
        fd.append('product_id', pid);
        fetch('gestion_recetas.php', { method: 'POST', body: fd })
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
                <div style="font-size: 24px; background: rgba(255,255,255,0.03); border-radius: 10px; height: 48px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--fc-border);">${item.icon || '📦'}</div>
                <div>
                    <div style="font-size: 13px; font-weight: 700; color: var(--fc-text-main);">${item.name}</div>
                    <div style="font-size: 10px; color: var(--fc-text-sec);">C$ ${parseFloat(item.cost).toFixed(2)} / ${item.unit}</div>
                </div>
                <div style="display:flex; flex-direction:column; align-items:flex-end;">
                    <div style="display:flex; align-items:center; gap:5px; background:rgba(0,0,0,0.2); border-radius:6px; padding:2px 6px;">
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
        fetch('gestion_recetas.php', { method: 'POST', body: fd }).then(() => fetchRecipe(currentProduct.id));
    }

    function removeIng(rid) {
        const fd = new FormData();
        fd.append('ajax_action', 'remove_ingredient');
        fd.append('recipe_item_id', rid);
        fetch('gestion_recetas.php', { method: 'POST', body: fd }).then(() => fetchRecipe(currentProduct.id));
    }

    let currentMode = 'simple';
    function setMode(mode) {
        currentMode = mode;
        document.getElementById('modeSimple').classList.toggle('fc-btn-primary', mode === 'simple');
        document.getElementById('modeYield').classList.toggle('fc-btn-primary', mode === 'yield');
        document.getElementById('simpleInputSection').style.display = mode === 'simple' ? 'block' : 'none';
        document.getElementById('yieldInputSection').style.display = mode === 'yield' ? 'block' : 'none';
        if (mode === 'yield') calcYield();
        updateSelTotal();
    }

    function calcYield() {
        const total = parseFloat(document.getElementById('yieldTotalQty').value) || 0;
        const portions = parseFloat(document.getElementById('yieldPortions').value) || 1;
        const result = total / portions;
        document.getElementById('yieldResult').textContent = result.toFixed(5);
        document.getElementById('selIngQty').value = result.toFixed(5);
        updateSelTotal();
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

        fetch('gestion_recetas.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                if (res.status === 'success') {
                    Swal.fire({ title: '¡Guardado!', text: 'La receta se ha actualizado correctamente', icon: 'success', confirmButtonColor: '#e11d48' })
                    .then(() => location.reload());
                } else Swal.fire('Error', res.message, 'error');
            });
    }
</script>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>