<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pedidos - RestoCloud Mobile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/css/mobile_mesero.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .categories-scroll {
            display: flex;
            overflow-x: auto;
            gap: 12px;
            padding: 10px 5px 15px 5px;
            scrollbar-width: none;
            margin-bottom: 5px;
        }
        .cat-chip {
            padding: 10px 20px;
            background: var(--app-surface);
            color: var(--app-text-sec);
            border-radius: 24px;
            white-space: nowrap;
            font-weight: 700;
            font-size: 0.85rem;
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--app-shadow-sm);
        }
        .cat-chip.active {
            background: var(--app-gradient);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            transform: translateY(-2px);
        }
        .product-list {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
            margin-bottom: 25px;
        }
        .product-card {
            display: flex;
            align-items: center;
            background: var(--app-surface);
            padding: 16px;
            border-radius: 20px;
            box-shadow: var(--app-shadow-md);
            border: 1px solid rgba(226, 232, 240, 0.5);
            gap: 16px;
            transition: transform 0.2s;
        }
        .product-card:active {
            transform: scale(0.98);
        }
        .product-img {
            width: 70px;
            height: 70px;
            border-radius: 16px;
            background: var(--app-primary-light);
            object-fit: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--app-primary);
            font-size: 28px;
        }
        .product-details {
            flex: 1;
        }
        .product-name {
            font-weight: 800;
            margin-bottom: 6px;
            color: var(--app-text-main);
            font-size: 1.05rem;
        }
        .product-price {
            color: var(--app-primary);
            font-weight: 800;
            font-size: 1.1rem;
        }
        .add-btn {
            background: var(--app-gradient);
            color: white;
            border: none;
            border-radius: 16px;
            width: 44px;
            height: 44px;
            font-size: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            transition: all 0.2s;
        }
        .add-btn:active {
            transform: scale(0.9);
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.2);
        }

        /* Bottom Sheet for Current Order */
        .order-sheet {
            position: fixed;
            bottom: var(--nav-height);
            left: 0;
            width: 100%;
            background: var(--app-surface);
            border-top-left-radius: 30px;
            border-top-right-radius: 30px;
            box-shadow: 0 -10px 40px rgba(15, 23, 42, 0.15);
            transform: translateY(100%);
            transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1);
            z-index: 999;
            display: flex;
            flex-direction: column;
            max-height: 85vh;
        }
        .order-sheet.open {
            transform: translateY(0);
        }
        .order-sheet-header {
            padding: 25px 25px 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }
        .order-sheet-content {
            padding: 20px 25px;
            overflow-y: auto;
            flex: 1;
        }
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
        }
        .order-item-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .order-sheet-footer {
            padding: 20px 25px 25px 25px;
            background: var(--app-surface);
        }
        .btn-kitchen {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 20px;
            font-size: 1.15rem;
            font-weight: 800;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
            transition: transform 0.2s;
        }
        .btn-kitchen:active {
            transform: scale(0.97);
        }
        
        .floating-cart-btn {
            position: fixed;
            bottom: calc(var(--nav-height) + 25px);
            right: 25px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            width: 64px;
            height: 64px;
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
            z-index: 998;
            border: none;
            transition: transform 0.2s;
        }
        .floating-cart-btn:active {
            transform: scale(0.92);
        }
        .cart-badge {
            position: absolute;
            top: 0;
            right: 0;
            background: var(--app-danger);
            color: white;
            font-size: 12px;
            font-weight: 700;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }
        
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 990;
            display: none;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .overlay.active {
            display: block;
            opacity: 1;
        }

        /* SweetAlert Premium Styling */
        div:where(.swal2-container) div:where(.swal2-popup) {
            border-radius: 28px;
            padding: 24px 20px;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
            font-family: 'Inter', sans-serif;
        }
        div:where(.swal2-container) h2:where(.swal2-title) {
            font-weight: 800;
            font-size: 1.4rem;
            color: var(--app-text-main);
        }
        div:where(.swal2-container) input:where(.swal2-input) {
            border-radius: 16px;
            border: 2px solid rgba(226, 232, 240, 0.8);
            padding: 16px;
            height: auto;
            font-size: 1rem;
            transition: all 0.3s;
        }
        div:where(.swal2-container) input:where(.swal2-input):focus {
            border-color: var(--app-primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15);
        }
        div:where(.swal2-container) button:where(.swal2-styled).swal2-confirm {
            border-radius: 16px;
            font-weight: 700;
            padding: 14px 28px;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
            border: none;
        }
        div:where(.swal2-container) button:where(.swal2-styled).swal2-cancel {
            border-radius: 16px;
            font-weight: 700;
            padding: 14px 24px;
            background: #f1f5f9;
            color: #64748b;
        }
    </style>
</head>
<body>

    <header class="app-header">
        <div class="app-title">
            <a href="mesas.php" style="color: inherit; text-decoration: none;">
                <i class='bx bx-chevron-left'></i>
            </a>
            <?= htmlspecialchars($table['name']) ?>
        </div>
        <div class="app-header-actions" style="font-weight: 800;">
            <a href="ver_pedido.php?table=<?= $table_id ?>&view=bill" style="color: var(--app-primary); text-decoration: none; display: flex; align-items: center; gap: 5px;">
                <span id="headerTotal">C$<?= number_format($order_total, 0) ?></span>
                <i class='bx bx-receipt' style="font-size: 1.2rem;"></i>
            </a>
        </div>
    </header>

    <main class="app-content" style="padding-bottom: 80px;">
        <div class="categories-scroll">
            <div class="cat-chip active" onclick="filterCategory('all', this)">Todos</div>
            <?php foreach ($categories as $cat): ?>
                <div class="cat-chip" onclick="filterCategory(<?= $cat['id'] ?>, this)"><?= htmlspecialchars($cat['name']) ?></div>
            <?php endforeach; ?>
        </div>

        <div class="product-list" id="productList">
            <?php foreach ($products as $p): ?>
                <div class="product-card" data-category="<?= $p['category_id'] ?>">
                    <?php if (!empty($p['image_url'])): ?>
                        <img src="<?= htmlspecialchars($p['image_url']) ?>" class="product-img">
                    <?php else: ?>
                        <div class="product-img"><i class='bx bx-food-menu'></i></div>
                    <?php endif; ?>
                    <div class="product-details">
                        <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                        <div class="product-price">C$<?= number_format($p['price'], 0) ?></div>
                    </div>
                    <button class="add-btn" onclick="addToOrder(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')">
                        <i class='bx bx-plus'></i>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Floating Action Button for Cart -->
    <button class="floating-cart-btn" onclick="toggleOrderSheet()">
        <i class='bx bx-receipt'></i>
        <div class="cart-badge" id="cartBadge">0</div>
    </button>

    <!-- Overlay -->
    <div class="overlay" id="overlay" onclick="toggleOrderSheet()"></div>

    <!-- Order Bottom Sheet -->
    <div class="order-sheet" id="orderSheet">
        <div class="order-sheet-header">
            <h3 style="margin: 0; font-size: 1.2rem;">Comanda Actual</h3>
            <button class="icon-btn" onclick="toggleOrderSheet()" style="background: none; border: none; font-size: 1.5rem;"><i class='bx bx-x'></i></button>
        </div>
        <div class="order-sheet-content" id="orderItemsContainer">
            <!-- Items loaded via JS -->
            <div style="text-align: center; color: var(--app-text-sec); margin-top: 20px;">Cargando pedido...</div>
        </div>
        <div class="order-sheet-footer">
            <div style="display: flex; justify-content: space-between; margin-bottom: 15px; font-size: 1.2rem; font-weight: 800;">
                <span>Total:</span>
                <a href="ver_pedido.php?table=<?= $table_id ?>&view=bill" style="color: var(--app-text-main); text-decoration: none; display: flex; align-items: center; gap: 5px;">
                    <span id="sheetTotal">C$<?= number_format($order_total, 0) ?></span>
                    <i class='bx bx-receipt' style="color: var(--app-primary); font-size: 1.4rem;"></i>
                </a>
            </div>
            <button class="btn-kitchen" onclick="sendToKitchen()" id="btnKitchen">
                <i class='bx bx-send'></i> Enviar a Cocina
            </button>
        </div>
    </div>

    <nav class="bottom-nav">
        <a href="mesas.php" class="nav-item">
            <i class='bx bx-grid-alt'></i>
            <span>Mesas</span>
        </a>
        <a href="javascript:void(0)" class="nav-item active">
            <i class='bx bxs-receipt'></i>
            <span>Pedido</span>
        </a>
    </nav>

    <script>
        const tableId = <?= json_encode($table_id) ?>;
        const libreOrder = <?= json_encode($libre_order) ?>;
        
        function filterCategory(id, el) {
            document.querySelectorAll('.cat-chip').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            
            document.querySelectorAll('.product-card').forEach(card => {
                if (id === 'all' || card.dataset.category == id) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function toggleOrderSheet() {
            const sheet = document.getElementById('orderSheet');
            const overlay = document.getElementById('overlay');
            sheet.classList.toggle('open');
            overlay.classList.toggle('active');
            if (sheet.classList.contains('open')) {
                loadOrder();
            }
        }

        async function addToOrder(productId, name) {
            Swal.fire({
                title: '¿Agregar nota?',
                text: name,
                input: 'text',
                inputPlaceholder: 'Ej: Sin cebolla, extra salsa...',
                showCancelButton: true,
                confirmButtonText: 'Agregar',
                cancelButtonText: 'Sin nota',
                confirmButtonColor: '#4f46e5',
                cancelButtonColor: '#64748b',
                reverseButtons: true
            }).then(async (result) => {
                const notes = result.value || '';
                
                try {
                    const formData = new FormData();
                    formData.append('product_id', productId);
                    formData.append('quantity', 1);
                    formData.append('notes', notes);

                    let url = `venta.php?ajax=add_to_order&table=${tableId}`;
                    if (libreOrder) url += `&libre=${libreOrder}`;

                    const res = await fetch(url, { method: 'POST', body: formData });
                    const data = await res.json();
                    
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Agregado',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500
                        });
                        loadOrder();
                    }
                } catch (e) {
                    console.error(e);
                }
            });
        }

        async function removeFromOrder(detailId) {
            try {
                const formData = new FormData();
                formData.append('detail_id', detailId);

                let url = `venta.php?ajax=remove_from_order&table=${tableId}`;
                if (libreOrder) url += `&libre=${libreOrder}`;

                const res = await fetch(url, { method: 'POST', body: formData });
                const data = await res.json();
                
                if (data.success) {
                    loadOrder();
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function loadOrder() {
            let url = `venta.php?ajax=get_order&table=${tableId}`;
            if (libreOrder) url += `&libre=${libreOrder}`;

            const res = await fetch(url);
            const data = await res.json();

            const container = document.getElementById('orderItemsContainer');
            container.innerHTML = '';
            
            document.getElementById('headerTotal').innerText = `C$${Number(data.total).toLocaleString('es-NI')}`;
            document.getElementById('sheetTotal').innerText = `C$${Number(data.total).toLocaleString('es-NI')}`;
            document.getElementById('cartBadge').innerText = data.items.length;

            if (data.items.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #94a3b8; margin-top: 20px;">No hay productos en el pedido.</div>';
                document.getElementById('btnKitchen').style.opacity = '0.5';
                document.getElementById('btnKitchen').disabled = true;
                return;
            }

            document.getElementById('btnKitchen').style.opacity = data.has_pending_items ? '1' : '0.5';
            document.getElementById('btnKitchen').disabled = !data.has_pending_items;

            data.items.forEach(item => {
                const isDraft = item.item_status === 'draft';
                const div = document.createElement('div');
                div.className = 'order-item';
                div.innerHTML = `
                    <div>
                        <div style="font-weight: 700; ${!isDraft ? 'color: var(--app-text-sec);' : ''}">${item.quantity}x ${item.product_name}</div>
                        ${item.notes ? `<div style="font-size: 0.8rem; color: #f59e0b;"><i class='bx bx-note'></i> ${item.notes}</div>` : ''}
                        <div style="font-size: 0.8rem; color: var(--app-text-sec);">${isDraft ? 'Sin enviar' : 'Enviado'}</div>
                    </div>
                    <div class="order-item-actions">
                        <div style="font-weight: 700; display: flex; align-items: center; margin-right: 10px;">C$${(item.price * item.quantity).toLocaleString('es-NI')}</div>
                        ${isDraft ? `
                        <button onclick="removeFromOrder(${item.id})" style="background: var(--app-danger); color: white; border: none; width: 35px; height: 35px; border-radius: 8px; font-size: 18px;">
                            <i class='bx bx-trash'></i>
                        </button>
                        ` : ''}
                    </div>
                `;
                container.appendChild(div);
            });
        }

        async function sendToKitchen() {
            let url = `venta.php?ajax=send_to_kitchen&table=${tableId}`;
            if (libreOrder) url += `&libre=${libreOrder}`;

            const res = await fetch(url);
            const data = await res.json();

            if (data.success) {
                Swal.fire('¡Enviado!', 'El pedido fue enviado a la cocina', 'success');
                toggleOrderSheet();
                loadOrder();
            } else {
                Swal.fire('Error', data.message || 'Error al enviar a cocina', 'error');
            }
        }

        // Initial load
        loadOrder();
    </script>
</body>
</html>
