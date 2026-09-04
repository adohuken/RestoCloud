<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$page_title = "Monitor de Impresión (Cocina)";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - RestoCloud</title>
    
    <!-- BoxIcons & Google Fonts -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #0f172a;
            color: white;
            height: 100vh;
            overflow: hidden;
        }

        .monitor-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
            padding: 20px;
        }

        /* --- IDLE STATE --- */
        .idle-state {
            text-align: center;
            transition: all 0.3s;
        }

        .idle-state .icon-wrapper {
            width: 120px; height: 120px; border-radius: 50%;
            background: rgba(79, 70, 229, 0.15); border: 2px solid rgba(79, 70, 229, 0.3);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 25px;
            animation: breathe 3s ease-in-out infinite;
        }

        .idle-state .icon-wrapper i { font-size: 50px; color: #818cf8; }

        @keyframes breathe {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(79, 70, 229, 0.2); }
            50% { transform: scale(1.05); box-shadow: 0 0 30px 10px rgba(79, 70, 229, 0.15); }
        }

        .idle-state h2 { font-size: 24px; font-weight: 700; margin-bottom: 10px; }
        .idle-state p { font-size: 15px; color: #94a3b8; line-height: 1.6; }

        .connection-badge {
            display: inline-flex; align-items: center; gap: 8px;
            margin-top: 25px; padding: 10px 20px; border-radius: 50px;
            background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3);
            font-size: 13px; font-weight: 600; color: #34d399;
        }

        .connection-badge .dot {
            width: 8px; height: 8px; border-radius: 50%; background: #10b981;
            animation: blink 1.5s infinite;
        }

        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .connection-badge.error { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #f87171; }
        .connection-badge.error .dot { background: #ef4444; }

        /* --- TICKET ARRIVED STATE --- */
        .ticket-alert {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.85);
            z-index: 1000;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.2s;
        }

        .ticket-alert.active { display: flex; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .ticket-preview {
            background: white; color: #1e293b;
            width: 320px; max-height: 60vh; overflow-y: auto;
            border-radius: 16px; padding: 30px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            margin-bottom: 25px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.5);
        }

        .ticket-preview .t-header { text-align: center; font-weight: bold; font-size: 18px; margin-bottom: 12px; }
        .ticket-preview .t-info { margin-bottom: 10px; line-height: 1.6; }
        .ticket-preview .t-divider { border-top: 2px dashed #cbd5e1; margin: 12px 0; }
        .ticket-preview .t-item { font-weight: bold; font-size: 15px; margin-bottom: 4px; }
        .ticket-preview .t-notes { font-size: 12px; color: #64748b; margin-left: 10px; margin-bottom: 8px; }
        .ticket-preview .t-footer { text-align: center; font-weight: bold; margin-top: 12px; font-size: 13px; color: #64748b; }

        .print-btn {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: white; border: none; padding: 18px 50px;
            border-radius: 16px; font-size: 18px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 12px;
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.4);
            transition: all 0.2s; animation: pulseBtn 1.5s infinite;
        }

        .print-btn:hover { transform: scale(1.05); }
        .print-btn i { font-size: 24px; }

        @keyframes pulseBtn {
            0%, 100% { box-shadow: 0 10px 30px rgba(79, 70, 229, 0.4); }
            50% { box-shadow: 0 10px 40px rgba(79, 70, 229, 0.7); }
        }

        .ticket-count {
            position: fixed; top: 20px; right: 20px;
            background: #ef4444; color: white;
            padding: 8px 16px; border-radius: 50px;
            font-weight: 700; font-size: 14px;
            display: none; z-index: 1001;
        }

        .ticket-count.active { display: block; animation: shake 0.5s; }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        /* --- PRINT STYLES (THERMAL 80mm) --- */
        @media print {
            body * { visibility: hidden !important; }
            
            #print-frame, #print-frame * { visibility: visible !important; }
            
            #print-frame {
                visibility: visible !important;
                position: fixed !important;
                left: 0 !important; top: 0 !important;
                width: 72mm !important;
                margin: 0 !important; padding: 2mm !important;
                font-family: 'Courier New', Courier, monospace !important;
                font-size: 12px !important;
                color: black !important;
                background: white !important;
            }

            .p-header { text-align: center; font-weight: bold; font-size: 16px; margin-bottom: 8px; }
            .p-info { margin-bottom: 8px; font-size: 12px; line-height: 1.5; }
            .p-divider { border-top: 1px dashed black; margin: 8px 0; }
            .p-item { font-weight: bold; font-size: 14px; margin-bottom: 3px; }
            .p-notes { font-size: 11px; margin-left: 8px; margin-bottom: 6px; }
            .p-footer { text-align: center; font-weight: bold; margin-top: 10px; font-size: 11px; }

            /* Force no margins on page */
            @page { margin: 0; size: 80mm auto; }
        }
    </style>
</head>
<body>

    <div class="monitor-container">
        <!-- Idle State -->
        <div class="idle-state" id="idle-state">
            <div class="icon-wrapper">
                <i class='bx bx-broadcast'></i>
            </div>
            <h2>Monitor de Impresión Activo</h2>
            <p>Esperando comandas de cocina...<br>No cierres esta ventana.</p>
            <div class="connection-badge" id="connection-badge">
                <span class="dot"></span>
                Conectado en Tiempo Real
            </div>
        </div>
    </div>

    <!-- Ticket Alert Overlay -->
    <div class="ticket-alert" id="ticket-alert">
        <div class="ticket-preview" id="ticket-preview"></div>
        <button class="print-btn" id="print-btn" onclick="printNow()">
            <i class='bx bx-printer'></i> IMPRIMIR COMANDA
        </button>
    </div>

    <!-- Pending ticket counter -->
    <div class="ticket-count" id="ticket-count"></div>

    <!-- Hidden print area (only visible during @media print) -->
    <div id="print-frame" style="position: absolute; left: -9999px; top: 0;"></div>

    <script>
        // Queue of tickets waiting to be printed
        let printQueue = [];
        let isPrinting = false;

        // Audio notification (beep sound using Web Audio API)
        function playAlertSound() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                // Play 3 short beeps
                [0, 0.2, 0.4].forEach(delay => {
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.frequency.value = 880;
                    osc.type = 'square';
                    gain.gain.value = 0.3;
                    osc.start(ctx.currentTime + delay);
                    osc.stop(ctx.currentTime + delay + 0.15);
                });
            } catch(e) {
                console.log('Audio not supported');
            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            if (typeof(EventSource) === "undefined") {
                alert("Tu navegador no soporta Server-Sent Events. Usa Chrome o Edge.");
                return;
            }

            const source = new EventSource("api_stream_impresion.php");
            const badge = document.getElementById('connection-badge');

            source.onopen = function() {
                badge.className = 'connection-badge';
                badge.innerHTML = '<span class="dot"></span> Conectado en Tiempo Real';
            };

            source.onmessage = function(event) {
                const data = JSON.parse(event.data);
                console.log("Ticket recibido:", data);

                // Add to queue
                printQueue.push(data);
                updateCounter();

                // If not currently printing, process queue
                if (!isPrinting) {
                    processQueue();
                }
            };

            source.onerror = function() {
                badge.className = 'connection-badge error';
                badge.innerHTML = '<span class="dot"></span> Reconectando...';
            };
        });

        function processQueue() {
            if (printQueue.length === 0) {
                isPrinting = false;
                document.getElementById('ticket-alert').classList.remove('active');
                return;
            }

            isPrinting = true;
            const data = printQueue[0]; // Peek at next ticket

            // Play alert sound
            playAlertSound();

            // Render preview on screen
            renderPreview(data);

            // Render print version
            renderPrintFrame(data);

            // Show the alert overlay
            document.getElementById('ticket-alert').classList.add('active');

            // Try to auto-print after a short delay
            setTimeout(() => {
                try {
                    window.print();
                } catch(e) {
                    console.log('Auto-print blocked, user must click button');
                }
            }, 800);
        }

        function printNow() {
            // Ensure print frame has the current ticket
            if (printQueue.length > 0) {
                renderPrintFrame(printQueue[0]);
            }
            window.print();
        }

        // After print completes (or is cancelled), move to next ticket
        window.onafterprint = function() {
            // Remove the printed ticket from queue
            if (printQueue.length > 0) {
                printQueue.shift();
            }
            updateCounter();

            // Small delay before processing next
            setTimeout(() => {
                processQueue();
            }, 1000);
        };

        function updateCounter() {
            const counter = document.getElementById('ticket-count');
            if (printQueue.length > 1) {
                counter.textContent = printQueue.length + ' tickets pendientes';
                counter.classList.add('active');
            } else {
                counter.classList.remove('active');
            }
        }

        function renderPreview(data) {
            let items = [];
            try { items = JSON.parse(data.items_json); } catch(e) {}

            let html = `<div class="t-header">COMANDA COCINA</div>`;
            html += `<div class="t-info">Mesa: ${data.table_name}<br>Mesero: ${data.waiter_name}<br>Fecha: ${new Date().toLocaleString()}</div>`;
            html += `<div class="t-divider"></div>`;

            items.forEach(item => {
                html += `<div class="t-item">[${item.quantity}] ${item.product_name}</div>`;
                if (item.notes) html += `<div class="t-notes">* ${item.notes} *</div>`;
            });

            html += `<div class="t-divider"></div>`;
            html += `<div class="t-footer">FIN DE ORDEN</div>`;

            document.getElementById('ticket-preview').innerHTML = html;
        }

        function renderPrintFrame(data) {
            let items = [];
            try { items = JSON.parse(data.items_json); } catch(e) {}

            let html = `<div class="p-header">COMANDA COCINA</div>`;
            html += `<div class="p-info">Mesa: ${data.table_name}<br>Mesero: ${data.waiter_name}<br>${new Date().toLocaleString()}</div>`;
            html += `<div class="p-divider"></div>`;

            items.forEach(item => {
                html += `<div class="p-item">[${item.quantity}] ${item.product_name}</div>`;
                if (item.notes) html += `<div class="p-notes">* ${item.notes} *</div>`;
            });

            html += `<div class="p-divider"></div>`;
            html += `<div class="p-footer">FIN DE ORDEN</div>`;

            document.getElementById('print-frame').innerHTML = html;
        }
    </script>
</body>
</html>
