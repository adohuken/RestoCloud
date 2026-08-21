<?php
// Make sure this is only included from ver_pedido.php
if (!isset($pdo) || !isset($order)) {
    exit;
}

// Get VAT percentage
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'iva_percentage'");
$stmt->execute();
$iva_percentage = $stmt->fetchColumn() ?: 0;

$subtotal = $order['total'];
$iva_amount = $subtotal * ($iva_percentage / 100);
$total_with_iva = $subtotal + $iva_amount;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cuenta - RestoCloud Mobile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="assets/css/mobile_mesero.css?v=<?= time() ?>">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .bill-container {
            background: var(--app-surface);
            border-radius: 20px;
            padding: 20px;
            box-shadow: var(--app-shadow-md);
            margin-bottom: 20px;
        }
        .bill-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid rgba(226, 232, 240, 0.6);
            color: var(--app-text-main);
        }
        .bill-item:last-child {
            border-bottom: none;
        }
        .bill-item .item-qty {
            color: var(--app-primary);
            font-weight: 700;
            margin-right: 8px;
        }
        .bill-summary {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px dashed rgba(226, 232, 240, 1);
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            color: var(--app-text-sec);
            font-weight: 600;
        }
        .summary-row.total {
            color: var(--app-text-main);
            font-size: 1.4rem;
            font-weight: 800;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(226, 232, 240, 0.6);
        }
        .summary-row.total span:last-child {
            color: var(--app-primary);
        }
        .action-btn {
            width: 100%;
            padding: 16px;
            border-radius: 16px;
            border: none;
            font-weight: 700;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .btn-primary {
            background: var(--app-gradient);
            color: white;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
        }
        .btn-secondary {
            background: white;
            color: var(--app-text-main);
            border: 2px solid rgba(226, 232, 240, 0.8);
        }
        .badge-status {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 20px;
        }
        .method-card {
            border: 2px solid rgba(226, 232, 240, 0.8);
            border-radius: 12px;
            padding: 15px 5px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .method-card i {
            font-size: 1.8rem;
        }
        .method-card span {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--app-text-main);
        }
        .method-card.active {
            border-color: var(--app-primary);
            background: rgba(99, 102, 241, 0.05);
        }
        .payment-tabs {
            display: flex;
            overflow-x: auto;
            gap: 10px;
            padding: 5px 2px 15px 2px;
            margin-bottom: 5px;
            scrollbar-width: none;
        }
        .payment-tabs::-webkit-scrollbar {
            display: none;
        }
        .payment-tab-btn {
            padding: 10px 18px;
            background: white;
            color: var(--app-text-sec);
            border-radius: 20px;
            border: 2px solid rgba(226, 232, 240, 0.8);
            font-weight: 700;
            font-size: 0.9rem;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s;
        }
        .payment-tab-btn.active {
            background: var(--app-text-main);
            color: white;
            border-color: var(--app-text-main);
        }
        .payment-panel {
            display: none;
            animation: fadeIn 0.3s;
        }
        .payment-panel.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .mixto-input-group {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            background: #f8fafc;
            border-radius: 12px;
            padding: 10px 15px;
            border: 1px solid rgba(226, 232, 240, 0.8);
        }
        .mixto-input-group i {
            font-size: 1.5rem;
            margin-right: 15px;
            color: var(--app-text-sec);
        }
        .mixto-input-group input {
            border: none;
            background: transparent;
            flex: 1;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--app-text-main);
            outline: none;
            width: 100%;
        }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="app-title">
            <a href="venta.php?table=<?= $table_id ?>" style="color: inherit; text-decoration: none;">
                <i class='bx bx-chevron-left'></i>
            </a>
            Cuenta <?= htmlspecialchars($table['name']) ?>
        </div>
    </header>

    <main class="app-content" style="padding-bottom: 30px;">
        
        <?php if ($order['payment_requested'] == 1): ?>
            <div style="text-align: center;">
                <div class="badge-status">
                    <i class='bx bx-check-circle'></i> Cobro solicitado
                </div>
            </div>
        <?php elseif ($order['payment_requested'] == 3): ?>
            <div style="text-align: center;">
                <div class="badge-status" style="background: rgba(99, 102, 241, 0.1); color: var(--app-primary);">
                    <i class='bx bx-check-double'></i> Pago en proceso
                </div>
            </div>
        <?php endif; ?>

        <div class="bill-container">
            <div style="font-weight: 800; margin-bottom: 15px; color: var(--app-text-main);">
                <i class='bx bx-receipt' style="color: var(--app-primary);"></i> Detalle de Pedido
            </div>
            
            <?php foreach ($order_items as $item): ?>
                <div class="bill-item">
                    <div style="flex: 1;">
                        <span class="item-qty"><?= $item['quantity'] ?>x</span>
                        <?= htmlspecialchars($item['product_name']) ?>
                    </div>
                    <div style="font-weight: 700;">
                        C$<?= number_format($item['price'] * $item['quantity'], 2) ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="bill-summary">
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>C$<?= number_format($subtotal, 2) ?></span>
                </div>
                <div class="summary-row">
                    <span>IVA (<?= $iva_percentage ?>%)</span>
                    <span>C$<?= number_format($iva_amount, 2) ?></span>
                </div>
                <div class="summary-row total">
                    <span>Total</span>
                    <span>C$<?= number_format($total_with_iva, 2) ?></span>
                </div>
            </div>
        </div>

        <div style="margin-top: 30px;">
            <?php if ($order['payment_requested'] == 0): ?>
                <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>">
                    <input type="hidden" name="request_cancellation" value="1">
                    <button type="button" class="action-btn btn-primary" onclick="confirmPaymentRequest(this)">
                        <i class='bx bx-bell'></i> Solicitar Cancelación
                    </button>
                </form>
            <?php elseif ($order['payment_requested'] == 2): ?>
                <div style="background: white; border-radius: 20px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px;">
                    <h3 style="margin-top: 0; color: var(--app-text-main); font-weight: 800; display: flex; align-items: center; gap: 8px; font-size: 1.1rem; margin-bottom: 15px;">
                        <i class='bx bx-credit-card' style="color: var(--app-primary);"></i> Configurar Pago
                    </h3>
                    
                    <div class="payment-tabs">
                        <div class="payment-tab-btn active" onclick="switchPaymentTab('unico', this)">Pago Único</div>
                        <div class="payment-tab-btn" onclick="switchPaymentTab('mixto', this)">Pago Mixto</div>
                        <div class="payment-tab-btn" onclick="switchPaymentTab('dividir', this)">Dividir Cuenta</div>
                        <div class="payment-tab-btn" onclick="switchPaymentTab('consumo', this)">Por Consumo</div>
                    </div>

                    <!-- Panel 1: Pago Único -->
                    <div id="panel-unico" class="payment-panel active">
                        <p style="color: var(--app-text-sec); font-size: 0.9rem; margin-bottom: 15px;">Selecciona un único método de pago.</p>
                        <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>">
                            <input type="hidden" name="process_payment" value="1">
                            <input type="hidden" name="payment_method" id="selectedMethod" value="efectivo">
                            
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px;">
                                <div class="method-card active" onclick="selectMethod('efectivo', this)">
                                    <i class='bx bx-money' style="color: #10b981;"></i>
                                    <span>Efectivo</span>
                                </div>
                                <div class="method-card" onclick="selectMethod('tarjeta', this)">
                                    <i class='bx bx-credit-card-alt' style="color: #3b82f6;"></i>
                                    <span>Tarjeta</span>
                                </div>
                                <div class="method-card" onclick="selectMethod('transferencia', this)">
                                    <i class='bx bx-mobile-alt' style="color: #8b5cf6;"></i>
                                    <span>Transf.</span>
                                </div>
                            </div>
                            
                            <button type="submit" class="action-btn btn-primary">
                                <i class='bx bx-send'></i> Enviar a Caja
                            </button>
                        </form>
                    </div>

                    <!-- Panel 2: Pago Mixto -->
                    <div id="panel-mixto" class="payment-panel">
                        <p style="color: var(--app-text-sec); font-size: 0.9rem; margin-bottom: 15px;">Ingresa los montos para cada método.</p>
                        <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>" onsubmit="return validateMixed(this)">
                            <input type="hidden" name="process_mixed_payment" value="1">
                            
                            <input type="hidden" name="mixed_methods[]" value="efectivo">
                            <div class="mixto-input-group">
                                <i class='bx bx-money' style="color: #10b981;"></i>
                                <input type="number" step="0.01" name="mixed_amounts[]" class="mixed-input" placeholder="Efectivo C$0.00" oninput="updateMixedTotal()">
                            </div>
                            
                            <input type="hidden" name="mixed_methods[]" value="tarjeta">
                            <div class="mixto-input-group">
                                <i class='bx bx-credit-card-alt' style="color: #3b82f6;"></i>
                                <input type="number" step="0.01" name="mixed_amounts[]" class="mixed-input" placeholder="Tarjeta C$0.00" oninput="updateMixedTotal()">
                            </div>
                            
                            <input type="hidden" name="mixed_methods[]" value="transferencia">
                            <div class="mixto-input-group">
                                <i class='bx bx-mobile-alt' style="color: #8b5cf6;"></i>
                                <input type="number" step="0.01" name="mixed_amounts[]" class="mixed-input" placeholder="Transf. C$0.00" oninput="updateMixedTotal()">
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; font-weight: 700; margin-bottom: 20px; color: var(--app-text-main);">
                                <span>Restante:</span>
                                <span id="mixed-remaining">C$<?= number_format($total_with_iva, 2) ?></span>
                            </div>

                            <button type="submit" class="action-btn btn-primary">
                                <i class='bx bx-send'></i> Enviar a Caja
                            </button>
                        </form>
                    </div>

                    <!-- Panel 3: Dividir Cuenta -->
                    <div id="panel-dividir" class="payment-panel">
                        <p style="color: var(--app-text-sec); font-size: 0.9rem; margin-bottom: 15px;">Divide el total en partes iguales.</p>
                        <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>">
                            <input type="hidden" name="process_split_bill" value="1">
                            
                            <div style="text-align: center; margin-bottom: 25px;">
                                <div style="display: inline-flex; align-items: center; gap: 15px; background: #f8fafc; padding: 10px 20px; border-radius: 30px; border: 1px solid rgba(226,232,240,0.8);">
                                    <button type="button" style="border: none; background: white; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; color: var(--app-text-main); font-weight: 800; box-shadow: 0 2px 5px rgba(0,0,0,0.1);" onclick="adjSplit(-1)">-</button>
                                    <span id="split-display" style="font-size: 1.5rem; font-weight: 800; color: var(--app-primary); width: 40px;">2</span>
                                    <input type="hidden" name="split_count" id="splitCountInput" value="2">
                                    <button type="button" style="border: none; background: white; width: 40px; height: 40px; border-radius: 50%; font-size: 20px; color: var(--app-text-main); font-weight: 800; box-shadow: 0 2px 5px rgba(0,0,0,0.1);" onclick="adjSplit(1)">+</button>
                                </div>
                            </div>
                            
                            <button type="submit" class="action-btn" style="background: white; color: var(--app-primary); border: 2px solid var(--app-primary);">
                                <i class='bx bx-pie-chart-alt-2'></i> Generar Facturas
                            </button>
                        </form>
                    </div>

                    <!-- Panel 4: Por Consumo -->
                    <div id="panel-consumo" class="payment-panel">
                        <p style="color: var(--app-text-sec); font-size: 0.9rem; margin-bottom: 20px; text-align: center;">Se abrirá una vista especial para asignar qué consumió cada persona.</p>
                        <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>">
                            <input type="hidden" name="process_individual_bill" value="1">
                            <button type="submit" class="action-btn" style="background: white; color: #8b5cf6; border: 2px solid #8b5cf6;">
                                <i class='bx bx-group'></i> Cuentas Individuales
                            </button>
                        </form>
                    </div>

                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        const TOTAL_AMOUNT = <?= $total_with_iva ?>;

        function switchPaymentTab(tab, el) {
            document.querySelectorAll('.payment-tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.payment-panel').forEach(p => p.classList.remove('active'));
            
            el.classList.add('active');
            document.getElementById('panel-' + tab).classList.add('active');
        }

        function updateMixedTotal() {
            let totalInputs = 0;
            document.querySelectorAll('.mixed-input').forEach(input => {
                totalInputs += parseFloat(input.value || 0);
            });
            let remaining = TOTAL_AMOUNT - totalInputs;
            let el = document.getElementById('mixed-remaining');
            el.innerText = 'C$' + remaining.toFixed(2);
            if(remaining < 0) {
                el.style.color = '#e11d48';
            } else if (remaining === 0) {
                el.style.color = '#10b981';
            } else {
                el.style.color = 'var(--app-text-main)';
            }
        }

        function validateMixed(form) {
            let totalInputs = 0;
            document.querySelectorAll('.mixed-input').forEach(input => {
                totalInputs += parseFloat(input.value || 0);
            });
            // Due to floating point issues, compare with a small margin
            if (Math.abs(TOTAL_AMOUNT - totalInputs) > 0.05) {
                Swal.fire({
                    title: 'Error',
                    text: 'La suma de los montos debe ser igual al Total (C$' + TOTAL_AMOUNT.toFixed(2) + ')',
                    icon: 'error',
                    confirmButtonColor: 'var(--app-primary)'
                });
                return false;
            }
            return true;
        }

        function adjSplit(delta) {
            let el = document.getElementById('splitCountInput');
            let display = document.getElementById('split-display');
            let val = parseInt(el.value) + delta;
            if(val >= 2 && val <= 20) {
                el.value = val;
                display.innerText = val;
            }
        }

        function selectMethod(method, el) {
            document.querySelectorAll('.method-card').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
            document.getElementById('selectedMethod').value = method;
        }

        function confirmPaymentRequest(btn) {
            Swal.fire({
                title: 'Solicitar Cobro',
                text: '¿Deseas enviar la notificación a caja para cobrar esta mesa?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: 'var(--app-primary)',
                confirmButtonText: 'Sí, solicitar',
                cancelButtonText: 'Cancelar',
                customClass: { popup: 'premium-swal compact-swal' }
            }).then((result) => {
                if(result.isConfirmed) {
                    btn.closest('form').submit();
                }
            });
        }
    </script>
    <script src="assets/js/auto_refresh.js?v=<?= time() ?>"></script>
</body>
</html>
