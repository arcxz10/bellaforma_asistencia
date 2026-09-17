<?php
session_start();
include_once 'conexion.php'; // Ajusta si tu archivo de conexión se llama diferente

$empleado_id = $_SESSION['empleado_id'] ?? $_GET['empleado_id'] ?? null;
$mensaje = "";
$tipo_alerta = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $empleado_id_post = $_POST['empleado_id'] ?? $empleado_id;
    $tipo = $_POST['tipo'];
    $fecha = $_POST['fecha'];
    $motivo = trim($_POST['motivo']);

    if ($empleado_id_post && $tipo && $fecha && $motivo) {
        $stmt = $conexion->prepare("INSERT INTO permisos (empleado_id, tipo, fecha, motivo) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $empleado_id_post, $tipo, $fecha, $motivo);
        if ($stmt->execute()) {
            $mensaje = "¡Permiso solicitado con éxito! El administrador lo revisará.";
            $tipo_alerta = "success";
        } else {
            $mensaje = "Error al guardar la solicitud.";
            $tipo_alerta = "error";
        }
        $stmt->close();
    } else {
        $mensaje = "Por favor completa todos los campos.";
        $tipo_alerta = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Solicitar Permiso - Bellaforma</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background-color: #e8f5e9; margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .card { background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); width: 100%; max-width: 450px; }
        h2 { color: #2e7d32; text-align: center; margin-bottom: 20px; }
        label { display: block; margin-top: 15px; font-weight: 600; color: #333; font-size: 14px; }
        select, input, textarea { width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; font-family: inherit; }
        button { width: 100%; background-color: #2e7d32; color: white; border: none; padding: 12px; border-radius: 8px; margin-top: 20px; font-weight: 600; cursor: pointer; font-size: 16px; }
        button:hover { background-color: #1b5e20; }
        .back-link { display: block; text-align: center; margin-top: 15px; color: #555; text-decoration: none; font-size: 14px; }
        .alert { padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center; font-size: 14px; }
        .alert.success { background-color: #d4edda; color: #155724; }
        .alert.error { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Solicitar Permiso</h2>
        <?php if($mensaje): ?>
            <div class="alert <?= $tipo_alerta ?>"><?= $mensaje ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="empleado_id" value="<?= htmlspecialchars($empleado_id) ?>">
            <label>Tipo de Permiso:</label>
            <select name="tipo" required>
                <option value="llegada_tarde">Llegada Tarde</option>
                <option value="salida_temprana">Salida Temprana</option>
                <option value="ausencia">Ausencia / Día libre</option>
            </select>

            <label>Fecha:</label>
            <input type="date" name="fecha" required min="<?= date('Y-m-d') ?>">

            <label>Motivo:</label>
            <textarea name="motivo" rows="4" placeholder="Explica brevemente..." required></textarea>

            <button type="submit">Enviar Solicitud</button>
        </form>
        <a href="registro_inicial.php?id=<?= $empleado_id ?>" class="back-link">← Volver al panel de registro</a>
    </div>
</body>
</html>
