<?php
date_default_timezone_set("America/Bogota");
require_once "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: registro.html");
    exit;
}

$documento = trim($_POST["documento"] ?? "");
$dispositivoId = trim($_POST["dispositivo_id"] ?? "");

if ($documento === "" || $dispositivoId === "") {
    header("Location: registro.html");
    exit;
}

$sql = "SELECT id, nombre, cargo, dispositivo_id FROM empleados WHERE identificacion = ? AND activo = 1 LIMIT 1";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $documento);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows !== 1) {
    $stmt->close();
    $conexion->close();
    echo "<script>alert('Empleado no encontrado o inactivo.'); window.location.href='registro.html';</script>";
    exit;
}

$empleado = $resultado->fetch_assoc();
$stmt->close();
$conexion->close();

if (!empty($empleado["dispositivo_id"]) && $empleado["dispositivo_id"] !== $dispositivoId) {
    echo "<script>alert('Este empleado solo puede registrar su asistencia desde el dispositivo asociado.'); window.location.href='registro.html';</script>";
    exit;
}

$nombre = $empleado["nombre"];
$cargo = $empleado["cargo"];
$empleadoId = $empleado["id"];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seleccionar Acción | Grupo Bellaforma</title>
    <link rel="stylesheet" href="css/registro.css">
</head>
<body>
    <div class="registro-header">
        <div class="header-title">💅 Bellaforma</div>
    </div>

    <div class="container-registro">
        <div class="registro-card" style="text-align: center;">
            <div style="font-size: 3rem; margin-bottom: 10px;">👤</div>
            <h2>Bienvenido, <?= htmlspecialchars($nombre) ?></h2>
            <p style="color: #666; margin-bottom: 20px;"><?= htmlspecialchars($cargo) ?></p>

            <div style="background: #f0f8ff; border: 1px solid #bce8f1; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: left;">
                <span style="font-size: 1.2rem; vertical-align: middle;">ℹ️</span>
                <div style="display:inline-block; vertical-align:middle; font-size: 0.9rem; color: #31708f;">
                    <strong>¿Qué deseas hacer?</strong><br>Selecciona si estás llegando (entrada) o yéndote (salida).
                </div>
            </div>

            <!-- Botón para Entrada (lleva a la pantalla donde se pide la justificación si llega tarde) -->
            <form action="procesar_entrada_form.php" method="POST" style="display: inline-block; width: 48%;">
                <input type="hidden" name="empleado_id" value="<?= $empleadoId ?>">
                <input type="hidden" name="dispositivo_id" value="<?= htmlspecialchars($dispositivoId) ?>">
                <button type="submit" class="btn btn-primary" style="background-color: #5cb85c; width: 100%;">🟢 Registrar Entrada</button>
            </form>

            <!-- Botón para Salida -->
            <form action="registro.php" method="POST" style="display: inline-block; width: 48%;">
                <input type="hidden" name="documento" value="<?= htmlspecialchars($documento) ?>">
                <input type="hidden" name="dispositivo_id" value="<?= htmlspecialchars($dispositivoId) ?>">
                <input type="hidden" name="tipo_accion" value="salida">
                <button type="submit" class="btn btn-primary" style="background-color: #d9534f; width: 100%;">🔴 Registrar Salida</button>
            </form>

            <div style="margin-top: 20px;">
                <a href="registro.html" class="btn btn-back" style="display: block;">← Volver a Registro</a>
            </div>
        </div>
    </div>
</body>
</html>
