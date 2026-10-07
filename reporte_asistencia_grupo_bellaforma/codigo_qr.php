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

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500..700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="css/base.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--fuente-texto);
            background: var(--fondo-auth);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .tarjeta-qr {
            background: white;
            border-radius: 26px;
            padding: 40px;
            box-shadow: var(--sombra-md);
            text-align: center;
            max-width: 600px;
            width: 100%;
        }

        .logo {
            max-width: 280px;
            width: 100%;
            height: auto;
            margin-bottom: 10px;
        }

        .tarjeta-qr p {
            color: #444;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 25px;
        }

        /* El QR se genera en alta resolución (900px) y se escala con CSS,
           así se ve nítido tanto en pantalla como impreso. */
        #qrcode {
            display: flex;
            justify-content: center;
            margin-bottom: 25px;
        }

        #qrcode img,
        #qrcode canvas {
            width: 100% !important;
            max-width: 480px;
            height: auto !important;
            image-rendering: pixelated;
        }

        .btn-imprimir {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(180deg, #1E7BD8, #1565C0);
            color: white;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-transform: none;
        }

        .btn-imprimir:hover {
            opacity: 0.9;
        }

        @media print {
            @page {
                margin: 1cm;
            }

            body {
                background: white;
                padding: 0;
                min-height: auto;
                display: block;
            }

            .btn-imprimir {
                display: none;
            }

            .tarjeta-qr {
                box-shadow: none;
                max-width: 100%;
                padding: 0;
                border-radius: 0;
            }

            .logo {
                max-width: 9cm;
            }

            .tarjeta-qr p {
                font-size: 22pt;
                margin-bottom: 0.6cm;
            }

            /* Tamaño del QR al imprimir (se ajusta bien en hoja carta/A4) */
            #qrcode img,
            #qrcode canvas {
                width: 15cm !important;
                max-width: 15cm;
            }
        }
    </style>
</head>
<body>

    <div class="tarjeta-qr">
        <img class="logo" src="img/logo-verde.png" alt="Grupo Bella Forma S.A.S.">
        <p>Escanea para registrar tu asistencia</p>

        <div id="qrcode"></div>

        <button class="btn-imprimir" onclick="window.print()">
            🖨️ Imprimir
        </button>
    </div>

    <script>
        // La URL se arma sola a partir de dónde esté corriendo el sitio.
        const urlRegistro =
            window.location.origin +
            window.location.pathname.replace('codigo_qr.php', 'registro.html');

        new QRCode(document.getElementById('qrcode'), {
            text: urlRegistro,
            width: 900,
            height: 900,
            colorDark: '#1a1a1a',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    </script>

    <script src="js/bellaforma-ui.js"></script>
</body>
</html>
