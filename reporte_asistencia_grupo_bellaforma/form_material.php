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

$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $material = trim($_POST['material'] ?? '');
    $cantidad = (int)($_POST['cantidad'] ?? 1);
    $observacion = trim($_POST['observacion'] ?? '');

    if (!empty($material) && $cantidad > 0) {
        // Apuntando a tu tabla real: solicitud_materiales
        $sql = "INSERT INTO solicitud_materiales (empleado_id, material, cantidad, observacion) VALUES (?, ?, ?, ?)";
        $stmt = $conexion->prepare($sql);
        $stmt->bind_param("isis", $empleado_id, $material, $cantidad, $observacion);
        if ($stmt->execute()) {
            $url_retorno = isset($_GET['empleado_id']) ? "registro.php?empleado_id=" . $empleado_id : "registro.php";
            header("Location: " . $url_retorno);
            exit();
        } else {
            $mensaje = "Error DB: " . $stmt->error;
        }
    } else {
        $mensaje = "Por favor completa los campos requeridos.";
    }
}
$link_volver = isset($_GET['empleado_id']) ? "registro.php?empleado_id=" . htmlspecialchars($empleado_id) : "registro.php";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedir Material | Grupo Bellaforma</title>
    <link rel="stylesheet" href="css/registro_inicial.css">
    <style>
        .form-group { margin-bottom: 12px; text-align: left; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 0.85rem; color: #444; }
        .form-group input, .form-group textarea { width: 100%; padding: 9px; border-radius: 6px; border: 1px solid #ccc; font-family: inherit; font-size: 0.9rem; box-sizing: border-box; }
    </style>
</head>
<body>
    <div class="container-inicial">
        <div class="card-inicial">
            <div class="card-header">
                <div class="logo-circle">📦</div>
                <h1>Pedir Material</h1>
                <p>Grupo Bellaforma</p>
            </div>
            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-danger" style="margin-bottom:15px; padding:10px; background:#f2dede; color:#a94442; border-radius:6px; font-size:0.85rem;"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="empleado_id" value="<?= htmlspecialchars($empleado_id) ?>">
                <div class="form-group">
                    <label>Material / Insumo:</label>
                    <input type="text" name="material" required placeholder="Ej. Guantes, tapabocas, gel..." autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Cantidad:</label>
                    <input type="number" name="cantidad" value="1" min="1" required>
                </div>
                <div class="form-group">
                    <label>Observaciones (Opcional):</label>
                    <textarea name="observacion" rows="2" placeholder="Detalles adicionales..."></textarea>
                </div>
                <button type="submit" style="width:100%; padding:12px; background:#00897b; color:white; border:none; border-radius:6px; font-weight:bold; cursor:pointer; font-size: 0.95rem;">Enviar Solicitud</button>
            </form>
            <a href="<?= $link_volver ?>" style="display:block; margin-top:15px; text-align:center; color:#555; text-decoration:none; font-size:0.9rem;">← Volver al panel de registro</a>
        </div>
    </div>
</body>
</html>
