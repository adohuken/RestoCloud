<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/inventory_helper.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// --- BACKEND LOGIC ---

// 1. Fetch Categories
$stmt = $pdo->query("SELECT * FROM ingredient_categories ORDER BY name ASC");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Ingredients
$stmt = $pdo->query("SELECT * FROM ingredients ORDER BY name ASC");
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle AJAX Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['ajax_action'] === 'save_ingredient') {
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $cost = floatval($_POST['cost']);
            $stock = floatval($_POST['stock']);
            $min_stock = floatval($_POST['min_stock']);
            $unit = $_POST['unit'];
            $cat_id = intval($_POST['category_id']);
            $icon = $_POST['icon'] ?? '📦';
            $image_url = null;

            // Handle Image Upload
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/ingredients/';
                if (!is_dir($uploadDir))
                    mkdir($uploadDir, 0777, true);
                $fileName = uniqid('ing_') . '.' . strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                    $image_url = $uploadDir . $fileName;
                }
            }

            if ($id === 0) {
                $sql = "INSERT INTO ingredients (name, cost, stock, min_stock, unit, category_id, icon, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$name, $cost, $stock, $min_stock, $unit, $cat_id, $icon, $image_url]);
                $id = $pdo->lastInsertId();
            } else {
                if ($image_url) {
                    $sql = "UPDATE ingredients SET name=?, cost=?, stock=?, min_stock=?, unit=?, category_id=?, icon=?, image_url=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$name, $cost, $stock, $min_stock, $unit, $cat_id, $icon, $image_url, $id]);
                } else {
                    $sql = "UPDATE ingredients SET name=?, cost=?, stock=?, min_stock=?, unit=?, category_id=?, icon=? WHERE id=?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$name, $cost, $stock, $min_stock, $unit, $cat_id, $icon, $id]);
                }
            }
            echo json_encode(['status' => 'success', 'id' => $id]);
            exit;
        }

        if ($_POST['ajax_action'] === 'delete_ingredient') {
            $id = intval($_POST['id']);
            // Check usage
            $check = $pdo->prepare("SELECT COUNT(*) FROM product_recipes WHERE ingredient_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: en uso por recetas.']);
            } else {
                $stmt = $pdo->prepare("DELETE FROM ingredients WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['status' => 'success']);
            }
            exit;
        }

        if ($_POST['ajax_action'] === 'save_category') {
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            if (empty($name)) {
                echo json_encode(['status' => 'error', 'message' => 'El nombre es obligatorio']);
                exit;
            }

            if ($id === 0) {
                $stmt = $pdo->prepare("INSERT INTO ingredient_categories (name) VALUES (?)");
                $stmt->execute([$name]);
            } else {
                $stmt = $pdo->prepare("UPDATE ingredient_categories SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
            }
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($_POST['ajax_action'] === 'delete_category') {
            $id = intval($_POST['id']);
            // Check usage
            $check = $pdo->prepare("SELECT COUNT(*) FROM ingredients WHERE category_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: tiene insumos asociados.']);
            } else {
                $stmt = $pdo->prepare("DELETE FROM ingredient_categories WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['status' => 'success']);
            }
            exit;
        }

        if ($_POST['ajax_action'] === 'register_adjustment') {
            $id = intval($_POST['id']);
            $qty = floatval($_POST['quantity']);
            $type = $_POST['type']; // Entry, Waste, Adjustment
            $notes = trim($_POST['notes']);

            $success = InventoryManager::registerMovement($id, $qty, $type, $_SESSION['user_id'], null, $notes);
            echo json_encode(['status' => $success ? 'success' : 'error']);
            exit;
        }

        if ($_POST['ajax_action'] === 'get_history') {
            $id = intval($_POST['id']);
            $stmt = $pdo->prepare("
                SELECT sm.*, u.name as user_name 
                FROM stock_movements sm 
                JOIN users u ON sm.user_id = u.id 
                WHERE sm.ingredient_id = ? 
                ORDER BY sm.date_created DESC 
                LIMIT 50
            ");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Map categories for JS
$jsCategories = json_encode($categories);

// --- FRONTEND ---
$pageTitle = "Inventario de Insumos";
include __DIR__ . '/includes/header.php';
?><div class="dashboard-wrapper">
    <style>
        .inventory-container {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 30px;
            height: calc(100vh - 100px);
            overflow: hidden;
        }

        .inventory-sidebar {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--fc-border);
            border-radius: 20px;
            padding: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            overflow-y: auto;
        }

        .inventory-main {
            overflow-y: auto;
            padding-right: 5px;
        }

        .category-item {
            padding: 12px 18px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--fc-text-sec);
            border: 1px solid transparent;
        }

        .category-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--fc-text-main);
        }

        .category-item.active {
            background: rgba(225, 29, 72, 0.1);
            color: var(--fc-primary);
            border-color: rgba(225, 29, 72, 0.2);
            font-weight: 600;
        }

        .insumo-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            border: 1px solid var(--fc-border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .insumo-card:hover {
            transform: translateY(-5px);
            border-color: var(--fc-primary);
            box-shadow: 0 10px 25px -5px rgba(225, 29, 72, 0.15);
        }

        .insumo-image {
            height: 140px;
            background: rgba(255, 255, 255, 0.02);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            border-bottom: 1px solid var(--fc-border);
            position: relative;
        }

        .insumo-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .insumo-status {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
        }

        .insumo-content {
            padding: 15px;
            flex-grow: 1;
        }

        .insumo-title {
            font-weight: 700;
            color: var(--fc-text-main);
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .insumo-meta {
            font-size: 12px;
            color: var(--fc-text-sec);
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
        }

        .insumo-footer {
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.01);
            border-top: 1px solid var(--fc-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .insumo-price {
            font-weight: 800;
            color: var(--fc-text-main);
            font-size: 15px;
        }

        @media (max-width: 1024px) {
            .inventory-container {
                grid-template-columns: 1fr;
            }
            .inventory-sidebar {
                display: none;
            }
        }
    </style>

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-package'></i> Maestra de Insumos</h1>
                <p>Control de materias primas y existencias críticas</p>
            </div>
            <div class="fc-header-right no-print">
                <div style="display: flex; gap: 12px;">
                    <button class="fc-btn fc-btn-outline" onclick="openCatManager()">
                        <i class='bx bx-cog'></i> Categorías
                    </button>
                    <button class="fc-btn fc-btn-primary" onclick="initNewIngredient()">
                        <i class='bx bx-plus'></i> Nuevo Insumo
                    </button>
                </div>
            </div>
        </div>

        <div class="inventory-container">
            <!-- Sidebar: Categories -->
            <aside class="inventory-sidebar">
                <div style="margin-bottom: 10px;">
                    <input type="text" id="gridSearch" placeholder="Filtrar por nombre..." class="fc-input" onkeyup="filterGrid()">
                </div>
                <div class="category-list" style="display:flex; flex-direction:column; gap:5px;">
                    <div class="category-item active" onclick="filterCategory('all', this)">
                        <span><i class='bx bx-grid-alt'></i> Todos</span>
                        <span class="fc-badge fc-badge-outline" style="font-size: 10px;"><?= count($ingredients) ?></span>
                    </div>
                    <?php foreach ($categories as $cat): ?>
                        <div class="category-item" onclick="filterCategory(<?= $cat['id'] ?>, this)">
                            <span><i class='bx bx-chevron-right'></i> <?= htmlspecialchars($cat['name']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>

            <!-- Main: Grid -->
            <div class="inventory-main">
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px;" id="ingGrid">
                    <?php foreach ($ingredients as $ing): ?>
                        <?php 
                        $isLow = $ing['stock'] <= $ing['min_stock'];
                        $isCritical = $ing['stock'] <= ($ing['min_stock'] / 2);
                        ?>
                        <div class="fc-card insumo-card" data-cat="<?= $ing['category_id'] ?>" data-name="<?= strtolower($ing['name']) ?>" 
                             onclick='loadIngredient(<?= htmlspecialchars(json_encode($ing, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)'>
                            
                            <div class="insumo-image">
                                <?php if($isLow): ?>
                                    <div class="insumo-status">
                                        <span class="fc-badge <?= $isCritical ? 'fc-badge-primary' : 'fc-badge-outline' ?>" style="font-size: 9px; box-shadow: 0 4px 10px rgba(0,0,0,0.3);">
                                            <i class='bx bxs-error-circle'></i> <?= $isCritical ? 'CRÍTICO' : 'BAJO' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($ing['image_url']): ?>
                                    <img src="<?= htmlspecialchars($ing['image_url']) ?>">
                                <?php else: ?>
                                    <span><?= $ing['icon'] ?: '📦' ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="insumo-content">
                                <div class="insumo-title"><?= htmlspecialchars($ing['name']) ?></div>
                                <div class="insumo-meta">
                                    <span>Stock Actual</span>
                                    <span style="color: <?= $isLow ? 'var(--fc-primary)' : '#10b981' ?>; font-weight: 700;">
                                        <?= $ing['stock'] ?> <?= $ing['unit'] ?>
                                    </span>
                                </div>
                                <div style="height: 4px; background: rgba(255,255,255,0.05); border-radius: 10px; overflow: hidden;">
                                    <?php 
                                    $perc = min(100, ($ing['stock'] / (max($ing['min_stock'] * 2, 1))) * 100);
                                    $barColor = $isLow ? 'var(--fc-primary)' : '#10b981';
                                    ?>
                                    <div style="width: <?= $perc ?>%; height: 100%; background: <?= $barColor ?>; transition: width 0.3s;"></div>
                                </div>
                            </div>

                            <div class="insumo-footer">
                                <div class="insumo-price">C$<?= number_format($ing['cost'], 2) ?></div>
                                <div style="font-size: 11px; color: var(--fc-text-sec);">x <?= $ing['unit'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Category Manager Modal -->
<div id="catModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-category'></i> Categorías de Insumos</h3>
            <span class="close" onclick="closeCatManager()">&times;</span>
        </div>
        <div class="fc-modal-body" style="padding: 25px;">
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <input type="text" id="newCatName" class="fc-input" placeholder="Nueva categoría..." style="height: 45px;">
                <button class="fc-btn fc-btn-primary" onclick="saveCategory(0)" style="width: 45px; padding: 0;">
                    <i class='bx bx-plus'></i>
                </button>
            </div>

            <div style="max-height: 350px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                <?php foreach ($categories as $cat): ?>
                    <div style="display: flex; align-items: center; gap: 12px; padding: 12px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid var(--fc-border);">
                        <input type="text" value="<?= htmlspecialchars($cat['name']) ?>" id="cat_input_<?= $cat['id'] ?>" 
                               class="fc-input" style="background:transparent; border:none; height:auto; padding:0; font-weight:600;"
                               onchange="saveCategory(<?= $cat['id'] ?>)">
                        <button onclick="deleteCategory(<?= $cat['id'] ?>)" style="background:none; border:none; color:var(--fc-primary); cursor:pointer; font-size:18px;">
                            <i class='bx bx-trash'></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Ingredient Modal -->
<div id="ingredientModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 550px;">
        <div id="ingredientModalContent">
            <!-- Dynamically Rendered -->
        </div>
    </div>
</div>

<script>
    let currentIngredient = null;
    const foodIcons = {
        'Carnes': ['🥩', '🍗', '🍖', '🥓', '🍔', '🌭'],
        'Verduras': ['🥦', '🥕', '🌽', '🥬', '🍅', '🍆', '🥔', '🧅', '🧄', '🥒', '🍄'],
        'Frutas': ['🍎', '🍌', '🍇', '🍊', '🍋', '🍍', '🥭', '🍑', '🍒', '🍓', '🥑'],
        'Lácteos/Huevos': ['🥛', '🧀', '🥚', '🧈', '🍦'],
        'Granos/Pan': ['🍞', '🥐', '🥖', '🥨', '🥞', '🌾', '🍚', '🍝', '🍜'],
        'Bebidas': ['🥤', '🧃', '☕', '🍵', '🍺', '🍷', '🍹', '🍾'],
        'Condimentos': ['🧂', '🌶️', '🌿', '🍯', '🍫', '🍬', '🥥', '🥜']
    };

    function filterGrid() {
        const term = document.getElementById('gridSearch').value.toLowerCase();
        document.querySelectorAll('.insumo-card').forEach(card => {
            const name = card.dataset.name;
            card.style.display = name.includes(term) ? 'flex' : 'none';
        });
    }

    function filterCategory(catId, el) {
        document.querySelectorAll('.category-item').forEach(i => i.classList.remove('active'));
        el.classList.add('active');
        document.querySelectorAll('.insumo-card').forEach(card => {
            card.style.display = (catId === 'all' || card.dataset.cat == catId) ? 'flex' : 'none';
        });
    }

    function initNewIngredient() {
        currentIngredient = { id: 0, name: '', cost: 0, stock: 0, min_stock: 5, unit: 'kg', category_id: 1, icon: '📦', image_url: null };
        renderEditor();
        document.getElementById('ingredientModal').classList.add('show');
    }

    function loadIngredient(ing) {
        currentIngredient = ing;
        renderEditor();
        document.getElementById('ingredientModal').classList.add('show');
    }

    function renderEditor() {
        const ing = currentIngredient;
        const isNew = ing.id === 0;

        let iconPickerHtml = `<div class="icon-picker" style="display:none; margin-top:15px; background:rgba(0,0,0,0.2); padding:15px; border-radius:15px; border:1px solid var(--fc-border); max-height:200px; overflow-y:auto;">`;
        for (const [cat, icons] of Object.entries(foodIcons)) {
            iconPickerHtml += `<div style="font-size:11px; color:var(--fc-text-sec); margin-top:8px; text-transform:uppercase; letter-spacing:1px;">${cat}</div><div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:5px;">`;
            icons.forEach(ic => { iconPickerHtml += `<span onclick="selectIcon('${ic}')" style="cursor:pointer; font-size:20px; transition:transform 0.1s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">${ic}</span>`; });
            iconPickerHtml += `</div>`;
        }
        iconPickerHtml += `</div>`;

        document.getElementById('ingredientModalContent').innerHTML = `
            <div class="fc-modal-header">
                <h3><i class='bx bx-edit'></i> ${isNew ? 'Nuevo Insumo' : 'Gestionar Insumo'}</h3>
                <span class="close" onclick="closeModal('ingredientModal')">&times;</span>
            </div>
            <div class="fc-modal-body" style="padding: 25px;">
                <div style="display: grid; grid-template-columns: 140px 1fr; gap: 20px; margin-bottom: 25px;">
                    <div onclick="document.getElementById('editImage').click()" style="width:140px; height:140px; border-radius:15px; background:rgba(255,255,255,0.03); border:2px dashed var(--fc-border); display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; position:relative;">
                        ${ing.image_url ? `<img src="${ing.image_url}" style="width:100%; height:100%; object-fit:cover;">` : `<i class='bx bx-camera' style="font-size:32px; color:var(--fc-text-sec);"></i><span style="font-size:10px; color:var(--fc-text-sec); margin-top:5px;">SUBIR FOTO</span>`}
                    </div>
                    <input type="file" id="editImage" hidden onchange="previewUpload(this)">
                    
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <div class="fc-form-group">
                            <label class="fc-label">Nombre del Insumo</label>
                            <input type="text" id="editName" class="fc-input" value="${ing.name}" placeholder="Ej: Harina de Trigo">
                        </div>
                        <div style="display:flex; gap:12px;">
                           <div class="fc-form-group" style="flex:1">
                                <label class="fc-label">Icono</label>
                                <div style="display:flex; gap:8px;">
                                    <input type="text" id="editIcon" class="fc-input" value="${ing.icon}" style="text-align:center; width:50px;">
                                    <button class="fc-btn fc-btn-outline" onclick="toggleIconPicker()" style="flex:1; font-size:12px;">🎨 Galería</button>
                                </div>
                           </div>
                           <div class="fc-form-group" style="flex:1">
                                <label class="fc-label">Categoría</label>
                                <select id="editCategory" class="fc-input">
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= $c['id'] ?>" ${ing.category_id == <?= $c['id'] ?> ? 'selected' : ''}><?= $c['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                           </div>
                        </div>
                    </div>
                </div>

                ${iconPickerHtml}

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 25px;">
                    <div class="fc-form-group">
                        <label class="fc-label">Costo x Unidad</label>
                        <div style="position:relative;">
                            <span style="position:absolute; left:12px; top:12px; font-weight:700; color:var(--fc-text-sec);">C$</span>
                            <input type="number" id="editCost" class="fc-input" value="${ing.cost}" style="padding-left:35px;">
                        </div>
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-label">Unidad Medida</label>
                        <select id="editUnit" class="fc-input">
                            <option value="kg">kg</option><option value="lb">lb</option><option value="g">g</option>
                            <option value="lt">lt</option><option value="ml">ml</option><option value="unidad">unidad</option>
                        </select>
                    </div>
                    <div class="fc-form-group">
                        <label class="fc-label">Stock Mínimo</label>
                        <input type="number" id="editMinStock" class="fc-input" value="${ing.min_stock}">
                    </div>
                </div>

                ${!isNew ? `
                <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--fc-border); border-radius: 15px; padding: 15px; margin-bottom: 25px; display:flex; justify-content:space-around; text-align:center;">
                    <div>
                        <div style="font-size:10px; color:var(--fc-text-sec); margin-bottom:5px;">STOCK ACTUAL</div>
                        <div style="font-size:20px; font-weight:800; color:${ing.stock <= ing.min_stock ? 'var(--fc-primary)' : '#10b981'};">${ing.stock} ${ing.unit}</div>
                    </div>
                    <div style="width:1px; background:var(--fc-border);"></div>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button class="fc-btn fc-btn-outline" onclick="showAdjustment('Entry')" style="height:40px; border-color:#10b981; color:#10b981;"><i class='bx bx-plus'></i></button>
                        <button class="fc-btn fc-btn-outline" onclick="showAdjustment('Waste')" style="height:40px; border-color:var(--fc-primary); color:var(--fc-primary);"><i class='bx bx-minus'></i></button>
                    </div>
                </div>
                ` : `
                <div class="fc-form-group" style="margin-bottom:25px;">
                    <label class="fc-label">Stock Inicial</label>
                    <input type="number" id="editStock" class="fc-input" value="0">
                </div>
                `}

                <div style="display: flex; gap: 12px;">
                    <button class="fc-btn fc-btn-primary fc-w100" style="height: 50px; font-weight:700;" onclick="saveIngredient()">
                        <i class='bx bx-save'></i> Guardar Insumo
                    </button>
                    ${!isNew ? `
                        <button class="fc-btn fc-btn-outline" style="width:50px; padding:0; border-color:#8b5cf6; color:#8b5cf6;" onclick="showHistory(${ing.id})" title="Ver Historial">
                            <i class='bx bx-history' style="font-size:20px;"></i>
                        </button>
                        <button class="fc-btn fc-btn-outline" style="width:50px; padding:0; border-color:var(--fc-primary); color:var(--fc-primary);" onclick="deleteIngredient(${ing.id})" title="Eliminar">
                            <i class='bx bx-trash' style="font-size:20px;"></i>
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
        document.getElementById('editUnit').value = ing.unit;
    }

    function toggleIconPicker() {
        const p = document.querySelector('.icon-picker');
        p.style.display = p.style.display === 'none' ? 'block' : 'none';
    }

    function selectIcon(ic) {
        document.getElementById('editIcon').value = ic;
        toggleIconPicker();
    }

    function previewUpload(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const modal = document.querySelector('#ingredientModalContent img') || document.querySelector('#ingredientModalContent i').parentNode;
                if(modal.tagName === 'IMG') modal.src = e.target.result;
                else modal.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;">`;
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    function saveIngredient() {
        const fd = new FormData();
        fd.append('ajax_action', 'save_ingredient');
        fd.append('id', currentIngredient.id);
        fd.append('name', document.getElementById('editName').value);
        fd.append('cost', document.getElementById('editCost').value);
        fd.append('stock', document.getElementById('editStock')?.value || currentIngredient.stock);
        fd.append('min_stock', document.getElementById('editMinStock').value);
        fd.append('unit', document.getElementById('editUnit').value);
        fd.append('category_id', document.getElementById('editCategory').value);
        fd.append('icon', document.getElementById('editIcon').value);
        const imgInput = document.getElementById('editImage');
        if (imgInput.files[0]) fd.append('image', imgInput.files[0]);

        fetch('inventario_insumos.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                if (res.status === 'success') location.reload();
                else Swal.fire('Error', res.message, 'error');
            });
    }

    function deleteIngredient(id) {
        Swal.fire({
            title: '¿Eliminar insumo?',
            text: 'Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            confirmButtonText: 'Sí, Eliminar'
        }).then(res => {
            if(res.isConfirmed) {
                const fd = new FormData();
                fd.append('ajax_action', 'delete_ingredient');
                fd.append('id', id);
                fetch('inventario_insumos.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(res => {
                        if (res.status === 'success') location.reload();
                        else Swal.fire('Acceso Denegado', res.message, 'error');
                    });
            }
        });
    }

    function showAdjustment(type) {
        const isEntry = type === 'Entry';
        Swal.fire({
            title: isEntry ? '📥 Registrar Entrada' : '🗑️ Reportar Merma',
            html: `
                <div class="fc-form" style="text-align:left;">
                    <div class="fc-form-group">
                        <label class="fc-label">Cantidad a ${isEntry ? 'Sumar' : 'Restar'}</label>
                        <input type="number" id="adjQty" class="fc-input" step="0.01" placeholder="0.00">
                    </div>
                    <div class="fc-form-group" style="margin-top:15px;">
                        <label class="fc-label">Notas / Motivo</label>
                        <textarea id="adjNotes" class="fc-input" placeholder="Ej: Compra factura #123" style="height:80px;"></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Confirmar Movimiento',
            background: '#0f172a',
            color: '#f8fafc',
            preConfirm: () => {
                const qty = parseFloat(document.getElementById('adjQty').value);
                const notes = document.getElementById('adjNotes').value;
                if(!qty || qty <= 0) return Swal.showValidationMessage('Ingrese una cantidad válida');
                return { qty, notes };
            }
        }).then(result => {
            if(result.isConfirmed) {
                const fd = new FormData();
                fd.append('ajax_action', 'register_adjustment');
                fd.append('id', currentIngredient.id);
                fd.append('quantity', isEntry ? result.value.qty : -result.value.qty);
                fd.append('type', type);
                fd.append('notes', result.value.notes);
                fetch('inventario_insumos.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(res => {
                        if (res.status === 'success') location.reload();
                        else Swal.fire('Error', 'No se pudo registrar el movimiento', 'error');
                    });
            }
        });
    }

    function showHistory(id) {
        const fd = new FormData();
        fd.append('ajax_action', 'get_history');
        fd.append('id', id);
        fetch('inventario_insumos.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                if (res.status !== 'success') return;
                let rows = res.data.map(m => {
                    const colors = { Sale: '#ef4444', Entry: '#10b981', Waste: '#f59e0b', Adjustment: '#6366f1' };
                    return `<tr>
                        <td style="padding:10px; font-size:11px; color:var(--fc-text-sec); border-bottom:1px solid var(--fc-border);">${new Date(m.date_created).toLocaleString()}</td>
                        <td style="padding:10px; border-bottom:1px solid var(--fc-border);"><span class="fc-badge" style="background:${colors[m.type]}; color:white; font-size:9px;">${m.type}</span></td>
                        <td style="padding:10px; border-bottom:1px solid var(--fc-border); font-weight:700;">${m.quantity > 0 ? '+' : ''}${m.quantity}</td>
                        <td style="padding:10px; font-size:11px; color:var(--fc-text-sec); border-bottom:1px solid var(--fc-border);">${m.notes || '-'}</td>
                    </tr>`;
                }).join('');
                
                Swal.fire({
                    title: '📜 Historial de Stock',
                    width: '600px',
                    html: `<div style="overflow-x:auto;"><table style="width:100%; border-collapse:collapse; text-align:left;">
                        <thead><tr style="color:var(--fc-text-sec); font-size:11px;"><th>FECHA</th><th>TIPO</th><th>CANT</th><th>NOTAS</th></tr></thead>
                        <tbody>${rows || '<tr><td colspan="4" style="text-align:center; padding:20px;">Sin registros</td></tr>'}</tbody>
                    </table></div>`,
                    background: '#0f172a',
                    color: '#f8fafc'
                });
            });
    }

    function openCatManager() { document.getElementById('catModal').classList.add('show'); }
    function closeCatManager() { document.getElementById('catModal').classList.remove('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    function saveCategory(id) {
        const name = (id === 0) ? document.getElementById('newCatName').value : document.getElementById('cat_input_' + id).value;
        if (!name) return;
        const fd = new FormData();
        fd.append('ajax_action', 'save_category');
        fd.append('id', id);
        fd.append('name', name);
        fetch('inventario_insumos.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => { if (res.status === 'success') location.reload(); });
    }

    function deleteCategory(id) {
        Swal.fire({
            title: '¿Eliminar categoría?',
            text: 'Solo podrá eliminarse si no tiene insumos asociados.',
            icon: 'warning',
            showCancelButton: true
        }).then(res => {
            if(res.isConfirmed) {
                const fd = new FormData();
                fd.append('ajax_action', 'delete_category');
                fd.append('id', id);
                fetch('inventario_insumos.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(res => {
                        if (res.status === 'success') location.reload();
                        else Swal.fire('Error', res.message, 'error');
                    });
            }
        });
    }

    window.onclick = function(e) { if(e.target.classList.contains('fc-modal-overlay')) closeModal(e.target.id); }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>