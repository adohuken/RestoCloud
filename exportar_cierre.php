<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    die("No autorizado");
}

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare('
    SELECT cr.*, u.name as cashier_name
    FROM cash_register cr
    JOIN users u ON cr.user_id = u.id
    WHERE cr.id = ?
');
$stmt->execute([$id]);
$cierre = $stmt->fetch();

if (!$cierre) {
    die("Cierre de caja no encontrado");
}

$denominations = json_decode($cierre['denominations'], true) ?: [];
$billetes = $denominations['billetes'] ?? [];
$monedas = $denominations['monedas'] ?? [];
$electronico = $denominations['electronico'] ?? [];

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cierre de Caja #<?= $cierre['id'] ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            width: 80mm;
            margin: 0 auto;
            padding: 5mm;
            font-size: 12px;
            background: #fff;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
        }
        .divider {
            border-bottom: 1px dashed #000;
            margin: 5px 0;
        }
        .row {
            display: flex;
            justify-content: space-between;
            margin: 3px 0;
        }
        .bold {
            font-weight: bold;
        }
        .text-center {
            text-align: center;
        }
        @media print {
            body {
                width: 100%;
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
        .btn-print {
            display: block;
            width: 100%;
            padding: 10px;
            background: #000;
            color: #fff;
            text-align: center;
            text-decoration: none;
            margin-bottom: 15px;
            border: none;
            cursor: pointer;
            font-family: inherit;
        }
    </style>
</head>
<body>
    <button class="no-print btn-print" onclick="window.print()">IMPRIMIR TICKET</button>

    <div class="header">
        <h2 style="margin:0;">REPORTE DE ARQUEO</h2>
        <div style="font-size:10px;">Comprobante Interno</div>
    </div>
    
    <div class="divider"></div>
    <div class="row">
        <span>Arqueo #:</span>
        <span><?= str_pad($cierre['id'], 6, '0', STR_PAD_LEFT) ?></span>
    </div>
    <div class="row">
        <span>Fecha/Hora:</span>
        <span><?= date('d/m/Y H:i', strtotime($cierre['date_created'])) ?></span>
    </div>
    <div class="row">
        <span>Cajero:</span>
        <span><?= mb_strtoupper($cierre['cashier_name']) ?></span>
    </div>
    <div class="row">
        <span>Tipo:</span>
        <span><?= strtoupper($cierre['type']) === 'CLOSE' ? 'CIERRE DE TURNO' : 'APERTURA' ?></span>
    </div>

    <div class="divider"></div>
    <div class="text-center bold" style="margin:5px 0;">DESGLOSE DE EFECTIVO</div>
    
    <?php 
    $total_efectivo = 0;
    if (!empty($billetes)): 
    ?>
    <div class="bold" style="margin-top:5px;">BILLETES</div>
    <?php 
        krsort($billetes);
        foreach ($billetes as $val => $qty): 
            if ($qty > 0):
                $sub = $val * $qty;
                $total_efectivo += $sub;
    ?>
        <div class="row">
            <span>C$<?= number_format($val, 2) ?> x <?= $qty ?></span>
            <span>C$<?= number_format($sub, 2) ?></span>
        </div>
    <?php 
            endif;
        endforeach; 
    endif; 
    ?>

    <?php if (!empty($monedas)): ?>
    <div class="bold" style="margin-top:5px;">MONEDAS</div>
    <?php 
        krsort($monedas);
        foreach ($monedas as $val => $qty): 
            if ($qty > 0):
                $sub = $val * $qty;
                $total_efectivo += $sub;
    ?>
        <div class="row">
            <span>C$<?= number_format($val, 2) ?> x <?= $qty ?></span>
            <span>C$<?= number_format($sub, 2) ?></span>
        </div>
    <?php 
            endif;
        endforeach; 
    endif; 
    ?>
    
    <div class="divider"></div>
    <div class="row bold">
        <span>TOTAL EFECTIVO:</span>
        <span>C$<?= number_format($total_efectivo, 2) ?></span>
    </div>

    <?php if (!empty($electronico['tarjeta']) || !empty($electronico['transferencia'])): ?>
    <div class="divider"></div>
    <div class="text-center bold" style="margin:5px 0;">MEDIOS ELECTRÓNICOS</div>
    <?php if (!empty($electronico['tarjeta'])): ?>
    <div class="row">
        <span>TARJETA:</span>
        <span>C$<?= number_format($electronico['tarjeta'], 2) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($electronico['transferencia'])): ?>
    <div class="row">
        <span>TRANSFERENCIA:</span>
        <span>C$<?= number_format($electronico['transferencia'], 2) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="divider"></div>
    <div class="text-center bold" style="margin:5px 0;">RESULTADOS DEL ARQUEO</div>
    <div class="row">
        <span>ESPERADO (SISTEMA):</span>
        <span>C$<?= number_format($cierre['expected_amount'], 2) ?></span>
    </div>
    <div class="row">
        <span>TOTAL DECLARADO:</span>
        <span>C$<?= number_format($cierre['amount'], 2) ?></span>
    </div>
    <div class="divider"></div>
    <div class="row bold">
        <span>DIFERENCIA:</span>
        <span>
            <?php 
            if ($cierre['difference'] > 0) echo '+ ';
            echo 'C$' . number_format($cierre['difference'], 2); 
            ?>
        </span>
    </div>
    
    <div style="text-align: center; margin-top: 5px;">
        <?php if ($cierre['difference'] == 0): ?>
            <span style="border: 1px solid #000; padding: 2px 5px;">CUADRADO PERFECTO</span>
        <?php elseif ($cierre['difference'] > 0): ?>
            <span style="border: 1px solid #000; padding: 2px 5px;">SOBRANTE</span>
        <?php else: ?>
            <span style="border: 1px solid #000; padding: 2px 5px;">FALTANTE</span>
        <?php endif; ?>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        ___________________________<br>
        Firma Cajero
    </div>
    <div style="margin-top: 30px; text-align: center;">
        ___________________________<br>
        Firma Supervisor/Gerente
    </div>

    <div style="text-align: center; margin-top: 20px; font-size: 10px;">
        Generado desde RestoCloud<br>
        <?= date('d/m/Y H:i:s') ?>
    </div>
    
    <script>
        // Auto-print option could be enabled here
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
