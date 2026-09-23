<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require 'conexion.php';

$empleado_id = $_GET['empleado_id'] ?? $_POST['empleado_id'] ?? $_SESSION['empleado_id'] ?? null;

if (!$empleado_id && isset($_SESSION['documento'])) {
    $st = $conexion->prepare("SELECT id FROM empleados WHERE identificacion = ?");
    $st->bind_param("s", $_SESSION['documento']);
    $st->execute();
    if ($row = $st->get_result()->fetch_assoc()) {
        $empleado_id = $row['id'];
        $_SESSION['empleado_id'] = $empleado_id;
    }
}

if (!$empleado_id) {
    header("Location: registro.html");
    exit();
}

// Tipos de archivo de soporte permitidos y tamaño máximo (5 MB)
$tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$tamanoMaximo = 5 * 1024 * 1024;

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_permiso = trim($_POST['tipo_permiso'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');
    $fecha_seleccionada = trim($_POST['fecha_cita'] ?? '');

    $soporte_datos = null;
    $soporte_tipo = null;
    $soporte_nombre = null;
    $errorArchivo = '';

    if (isset($_FILES['soporte']) && $_FILES['soporte']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['soporte']['error'] !== UPLOAD_ERR_OK) {
            $errorArchivo = "Ocurrió un error al subir el archivo.";
        } elseif ($_FILES['soporte']['size'] > $tamanoMaximo) {
            $errorArchivo = "El archivo supera el tamaño máximo permitido (5 MB).";
        } else {
            $mimeReal = mime_content_type($_FILES['soporte']['tmp_name']);
            if (!in_array($mimeReal, $tiposPermitidos, true)) {
                $errorArchivo = "Formato no permitido. Solo se aceptan imágenes (JPG, PNG, WEBP) o PDF.";
            } else {
                $soporte_datos = base64_encode(file_get_contents($_FILES['soporte']['tmp_name']));
                $soporte_tipo = $mimeReal;
                $soporte_nombre = basename($_FILES['soporte']['name']);
            }
        }
    }

    if (!empty($errorArchivo)) {
        $mensaje = $errorArchivo;
    } elseif ($tipo_permiso === 'cita_medica' && !empty($motivo) && !empty($fecha_seleccionada)) {
        $sql = "INSERT INTO permisos (empleado_id, tipo, fecha, motivo, soporte_datos, soporte_tipo, soporte_nombre) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param(
            "issssss",
            $empleado_id,
            $tipo_permiso,
            $fecha_seleccionada,
            $motivo,
            $soporte_datos,
            $soporte_tipo,
            $soporte_nombre
        );

        if ($stmt->execute()) {
            $url_retorno = isset($_GET['empleado_id']) ? "registro.php?empleado_id=" . $empleado_id : "registro.php";
            header("Location: " . $url_retorno);
            exit();
        } else {
            $mensaje = "Error DB: " . $stmt->error;
        }
    } else {
        $mensaje = "Por favor selecciona la fecha de la cita y escribe el motivo.";
    }
}
$link_volver = isset($_GET['empleado_id']) ? "registro.php?empleado_id=" . htmlspecialchars($empleado_id) : "registro.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedir Permiso | Grupo Bellaforma</title>

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
    <link rel="stylesheet" href="css/registro_inicial.css">
    <style>
        .form-group { margin-bottom: 15px; text-align: left; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 0.85rem; color: #444; }
        .form-group input, .form-group textarea { width: 100%; padding: 9px; border-radius: 6px; border: 1px solid #ccc; font-family: inherit; font-size: 0.9rem; box-sizing: border-box; }
        .archivo-ayuda { font-size: 0.78rem; color: #777; margin-top: 4px; }
        .archivo-preview { margin-top: 8px; font-size: 0.85rem; color: #2e7d32; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container-inicial">
        <div class="card-inicial">
            <div class="card-header">
                <div class="logo-circle">🩺</div>
                <h1>Pedir Permiso</h1>
                <p>Grupo Bellaforma</p>
            </div>

            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-danger" style="margin-bottom:15px; padding:10px; background:#f2dede; color:#a94442; border-radius:6px; font-size:0.85rem;"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>

            <form method="POST" id="formPermiso" enctype="multipart/form-data">
                <input type="hidden" name="empleado_id" value="<?= htmlspecialchars($empleado_id) ?>">
                <input type="hidden" name="tipo_permiso" value="cita_medica">

                <div class="form-group">
                    <label>🩺 Cita médica</label>
                    <p style="font-size:0.85rem; color:#666; margin:0 0 10px;">
                        Usa este formulario para solicitar permiso por una cita médica.
                    </p>
                </div>

                <div class="form-group">
                    <label>Fecha de la cita:</label>
                    <input type="date" name="fecha_cita" required>
                </div>

                <div class="form-group">
                    <label>Motivo:</label>
                    <textarea name="motivo" rows="3" required placeholder="Explica brevemente el motivo de la cita..."></textarea>
                </div>

                <div class="form-group">
                    <label>Soporte (opcional):</label>
                    <input type="file" name="soporte" id="input_soporte" accept="image/jpeg,image/png,image/webp,application/pdf" onchange="mostrarNombreArchivo()">
                    <div class="archivo-ayuda">Puedes adjuntar una foto o PDF de la orden, incapacidad o comprobante de la cita (máx. 5 MB).</div>
                    <div class="archivo-preview" id="nombreArchivo"></div>
                </div>

                <button type="submit" style="width:100%; padding:12px; background:#4caf50; color:white; border:none; border-radius:6px; font-weight:bold; cursor:pointer; font-size: 0.95rem;">Enviar Solicitud</button>
            </form>
            <a href="<?= $link_volver ?>" style="display:block; margin-top:15px; text-align:center; color:#555; text-decoration:none; font-size:0.9rem;">← Volver al panel de registro</a>
        </div>
    </div>

    <script>
        function mostrarNombreArchivo() {
            const input = document.getElementById('input_soporte');
            const etiqueta = document.getElementById('nombreArchivo');
            if (input.files && input.files.length > 0) {
                etiqueta.textContent = "📎 " + input.files[0].name;
            } else {
                etiqueta.textContent = "";
            }
        }
    </script>
</body>
</html>
