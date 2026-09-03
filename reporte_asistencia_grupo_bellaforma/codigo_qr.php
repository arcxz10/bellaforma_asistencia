<?php
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Código QR | Grupo Bellaforma</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #DCEDC8 0%, #8BC34A 50%, #558B2F 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .tarjeta-qr {
            background: white;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            text-align: center;
            max-width: 420px;
            width: 100%;
        }

        .tarjeta-qr h1 {
            color: #33691E;
            font-size: 24px;
            margin-bottom: 6px;
        }

        .tarjeta-qr p {
            color: #666;
            font-size: 14px;
            margin-bottom: 25px;
        }

        #qrcode {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        #qrcode img,
        #qrcode canvas {
            border: 10px solid white;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .url-destino {
            word-break: break-all;
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px;
            font-size: 13px;
            color: #444;
            margin-bottom: 20px;
        }

        .btn-imprimir {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #7CB342, #33691E);
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-imprimir:hover {
            opacity: 0.9;
        }

        @media print {
            body {
                background: white;
            }

            .btn-imprimir {
                display: none;
            }

            .tarjeta-qr {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

    <div class="tarjeta-qr">
        <h1>💅 Bellaforma</h1>
        <p>Escanea para registrar tu asistencia</p>

        <div id="qrcode"></div>

        <div class="url-destino" id="urlDestino"></div>

        <button class="btn-imprimir" onclick="window.print()">
            🖨️ Imprimir
        </button>
    </div>

    <script>
        // La URL se arma sola a partir de dónde esté corriendo el sitio.
        // En local apunta a localhost/tu-ip-local; cuando subas el
        // proyecto a un servidor real, apuntará sola al dominio final.
        const urlRegistro =
            window.location.origin +
            window.location.pathname.replace('codigo_qr.php', 'registro.html');

        document.getElementById('urlDestino').textContent = urlRegistro;

        new QRCode(document.getElementById('qrcode'), {
            text: urlRegistro,
            width: 220,
            height: 220,
            colorDark: '#1a1a1a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>

</body>
</html>