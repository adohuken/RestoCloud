#!/usr/bin/env python3
"""
RestoCloud - Cliente de Impresión Local
=======================================
Este script corre en la computadora que tiene la impresora instalada.
Se conecta al sistema en el hosting y detecta cuando llegan pedidos nuevos.
Los imprime automáticamente en la impresora que elijas.

Requisitos:
    pip install requests pywin32

Uso:
    python printer_client.py
"""

import sys
import time
import json
import os

try:
    import requests
except ImportError:
    print("=" * 50)
    print("ERROR: Falta la librería 'requests'")
    print("Ejecuta: pip install requests")
    print("=" * 50)
    input("Presiona Enter para salir...")
    sys.exit(1)

try:
    import win32print
    import win32ui
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    print("=" * 50)
    print("ERROR: Faltan librerías necesarias")
    print("Ejecuta: pip install pywin32")
    print("=" * 50)
    input("Presiona Enter para salir...")
    sys.exit(1)


# ============================================================
# CONFIGURACIÓN - Edita estos valores
# ============================================================
CONFIG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "printer_config.json")


def load_config():
    if os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE, 'r') as f:
            return json.load(f)
    return {}


def save_config(config):
    with open(CONFIG_FILE, 'w') as f:
        json.dump(config, f, indent=2)


def list_printers():
    """Lista todas las impresoras instaladas en Windows."""
    printers = []
    for flags, desc, name, comment in win32print.EnumPrinters(
        win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
    ):
        printers.append(name)
    return printers


def select_printer():
    """Permite al usuario seleccionar una impresora."""
    printers = list_printers()
    default = win32print.GetDefaultPrinter()

    print("\n" + "=" * 50)
    print("  IMPRESORAS DISPONIBLES")
    print("=" * 50)

    for i, p in enumerate(printers):
        marker = " ★ (Predeterminada)" if p == default else ""
        print(f"  [{i + 1}] {p}{marker}")

    print()
    choice = input(f"Selecciona impresora (1-{len(printers)}) [Enter = predeterminada]: ").strip()

    if choice == "" or not choice.isdigit():
        selected = default
    else:
        idx = int(choice) - 1
        if 0 <= idx < len(printers):
            selected = printers[idx]
        else:
            selected = default

    print(f"\n  ✓ Impresora seleccionada: {selected}\n")
    return selected


def print_ticket_raw(printer_name, ticket_data):
    """
    Envía el ticket a la impresora usando RAW mode (ESC/POS).
    Compatible con impresoras térmicas de recibos.
    """
    try:
        items = json.loads(ticket_data.get('items_json', '[]'))
    except (json.JSONDecodeError, TypeError):
        items = []

    # ESC/POS Commands
    ESC = b'\x1b'
    GS = b'\x1d'
    LF = b'\x0a'

    init = ESC + b'@'                    # Initialize
    center = ESC + b'a' + b'\x01'        # Center align
    left = ESC + b'a' + b'\x00'          # Left align
    bold_on = ESC + b'E' + b'\x01'       # Bold ON
    bold_off = ESC + b'E' + b'\x00'      # Bold OFF
    double_size = ESC + b'!' + b'\x30'   # Double width+height
    normal_size = ESC + b'!' + b'\x00'   # Normal
    cut = LF + LF + LF + LF + GS + b'V' + bytes([66, 0])  # Cut paper

    # Build ticket
    data = init
    data += center
    data += double_size + b'COMANDA COCINA' + normal_size + LF
    data += left
    data += encode_safe(f"Mesa:   {ticket_data.get('table_name', 'N/A')}") + LF
    data += encode_safe(f"Mesero: {ticket_data.get('waiter_name', 'N/A')}") + LF
    data += encode_safe(f"Fecha:  {ticket_data.get('created_at', '')}") + LF
    data += b'-' * 32 + LF

    for item in items:
        qty = item.get('quantity', 1)
        name = item.get('product_name', '???')
        notes = item.get('notes', '')

        data += bold_on + double_size
        data += encode_safe(f"[{qty}] {name}") + LF
        data += bold_off + normal_size

        if notes:
            data += encode_safe(f"  * {notes} *") + LF

    data += b'-' * 32 + LF
    data += center + bold_on + b'FIN DE ORDEN' + bold_off + LF
    data += cut

    # Send to printer via Windows RAW mode
    try:
        hprinter = win32print.OpenPrinter(printer_name)
        try:
            win32print.StartDocPrinter(hprinter, 1, ("Comanda Cocina", None, "RAW"))
            win32print.StartPagePrinter(hprinter)
            win32print.WritePrinter(hprinter, data)
            win32print.EndPagePrinter(hprinter)
            win32print.EndDocPrinter(hprinter)
            return True
        finally:
            win32print.ClosePrinter(hprinter)
    except Exception as e:
        print(f"  ✗ Error al imprimir: {e}")
        return False


def encode_safe(text):
    """Convierte texto a bytes, reemplazando caracteres especiales."""
    replacements = {
        'á': 'a', 'é': 'e', 'í': 'i', 'ó': 'o', 'ú': 'u',
        'Á': 'A', 'É': 'E', 'Í': 'I', 'Ó': 'O', 'Ú': 'U',
        'ñ': 'n', 'Ñ': 'N', 'ü': 'u', 'Ü': 'U',
    }
    for k, v in replacements.items():
        text = text.replace(k, v)
    try:
        return text.encode('cp437', errors='replace')
    except Exception:
        return text.encode('ascii', errors='replace')


def setup_wizard():
    """Asistente de configuración inicial."""
    print("\n" + "=" * 50)
    print("  RESTOCLOUD - CONFIGURACIÓN INICIAL")
    print("=" * 50)

    # URL del sistema
    url = input("\n  URL de tu sistema (ej: https://mirestaurante.com): ").strip().rstrip('/')
    if not url.startswith('http'):
        url = 'https://' + url

    # API Key
    print("\n  Para obtener tu API Key:")
    print("  1. Abre tu sistema en el navegador")
    print("  2. Ve a la consola del navegador (F12)")
    print(f"  3. Ejecuta: fetch('{url}/api_print_jobs.php', {{method:'POST',body:new URLSearchParams({{action:'generate_key'}})}}).then(r=>r.json()).then(d=>console.log('TU KEY:',d.key))")
    print()
    api_key = input("  Pega tu API Key aquí: ").strip()

    # Printer
    printer = select_printer()

    config = {
        'url': url,
        'api_key': api_key,
        'printer': printer,
        'poll_interval': 3
    }
    save_config(config)

    print("\n  ✓ Configuración guardada!")
    print(f"  ✓ URL: {url}")
    print(f"  ✓ Impresora: {printer}")
    print()
    return config


def main():
    os.system('title RestoCloud - Cliente de Impresion')
    os.system('cls' if os.name == 'nt' else 'clear')

    print()
    print("  ██████╗ ███████╗███████╗████████╗ ██████╗ ")
    print("  ██╔══██╗██╔════╝██╔════╝╚══██╔══╝██╔═══██╗")
    print("  ██████╔╝█████╗  ███████╗   ██║   ██║   ██║")
    print("  ██╔══██╗██╔══╝  ╚════██║   ██║   ██║   ██║")
    print("  ██║  ██║███████╗███████║   ██║   ╚██████╔╝")
    print("  ╚═╝  ╚═╝╚══════╝╚══════╝   ╚═╝    ╚═════╝ ")
    print("  Cliente de Impresión Local v1.0")
    print()

    # Load or create config
    config = load_config()
    if not config.get('url') or not config.get('api_key') or not config.get('printer'):
        config = setup_wizard()

    url = config['url']
    api_key = config['api_key']
    printer = config['printer']
    interval = config.get('poll_interval', 3)

    print("=" * 50)
    print(f"  Servidor:   {url}")
    print(f"  Impresora:  {printer}")
    print(f"  Intervalo:  {interval}s")
    print("=" * 50)
    print()
    print("  Escuchando pedidos... (Ctrl+C para detener)")
    print()

    error_count = 0

    while True:
        try:
            # Poll for pending jobs
            resp = requests.get(
                f"{url}/api_print_jobs.php",
                params={'key': api_key},
                timeout=10
            )
            resp.raise_for_status()
            data = resp.json()

            if error_count > 0:
                print("  ✓ Conexión restaurada")
                error_count = 0

            jobs = data.get('jobs', [])

            for job in jobs:
                job_id = job['id']
                table = job.get('table_name', '?')
                print(f"  🖨️  Nuevo pedido! Mesa: {table} (ID: {job_id})")

                # Print the ticket
                success = print_ticket_raw(printer, job)

                if success:
                    # Mark as printed
                    requests.post(
                        f"{url}/api_print_jobs.php",
                        data={'key': api_key, 'action': 'mark_printed', 'job_id': job_id},
                        timeout=10
                    )
                    print(f"  ✓ Ticket impreso correctamente")
                else:
                    print(f"  ✗ Fallo al imprimir, se reintentará...")

        except requests.exceptions.ConnectionError:
            error_count += 1
            if error_count == 1 or error_count % 10 == 0:
                print(f"  ⚠ Sin conexión al servidor... reintentando")
        except requests.exceptions.Timeout:
            error_count += 1
            if error_count == 1:
                print(f"  ⚠ Timeout del servidor")
        except KeyboardInterrupt:
            print("\n\n  Detenido por el usuario. ¡Hasta luego!")
            break
        except Exception as e:
            error_count += 1
            if error_count == 1:
                print(f"  ✗ Error: {e}")

        time.sleep(interval)


if __name__ == '__main__':
    main()
