<?php
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}
require_once __DIR__ . "/conexion.php";   // aplica los permisos de subadministradores
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
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
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
            position: relative;
            background: rgba(255, 255, 255, .97);
            border: 1px solid rgba(255, 255, 255, .55);
            border-radius: 22px;
            padding: 40px;
            box-shadow: 0 0 0 1px rgba(56, 189, 248, .18), 0 40px 80px -30px rgba(2, 10, 28, .75), 0 0 80px -20px rgba(56, 189, 248, .35);
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

        .tarjeta-qr::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 3px;
            border-radius: 22px 22px 0 0;
            background: linear-gradient(90deg, #1E7BD8, #38BDF8, #22D3A5);
        }

        .tarjeta-qr p {
            font-family: var(--fuente-titulo);
            color: #0E1B33;
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
            padding: 18px;
            background: #fff;
            border: 1px solid #E3E9F3;
            border-radius: 18px;
            box-shadow: 0 18px 36px -22px rgba(7, 27, 64, .5);
        }

        #qrcode img,
        #qrcode canvas {
            width: 100% !important;
            max-width: 480px;
            height: auto !important;
            image-rendering: pixelated;
        }

        .btn-imprimir {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            padding: 13px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 12px;
            box-shadow: 0 1px 0 rgba(255, 255, 255, .25) inset, 0 14px 26px -12px rgba(13, 71, 161, .95);
            background: linear-gradient(135deg, #1E7BD8, #0D47A1);
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

            .tarjeta-qr::before { display: none; }

            #qrcode {
                padding: 0;
                border: none;
                box-shadow: none;
            }

            .tarjeta-qr {
                border: none;
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
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg> Imprimir
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
