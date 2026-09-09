<?php
date_default_timezone_set("America/Bogota");
require_once "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: registro.html");
    exit;
}

$empleadoId = (int)($_POST["empleado_id"] ?? 0);
$justificacion = trim($_POST["justificacion"] ?? "");
$enviado = isset($_POST["enviar_registro"]);

$fecha = date("Y-m-d");
$horaActual = date("H:i:s");

// BLOQUEO ESTRICTO: Si la hora actual es mayor a las 5:30 PM (17:30:00), se rechaza el registro
if ($horaActual > "17:30:00") {
    $conexion->close();
    echo "<script>alert('El horario de registro de asistencia ha finalizado a las 5:30 PM.'); window.location.href='registro.html';</script>";
    exit;
}

// Buscar datos del empleado
$sql = "SELECT nombre, cargo FROM empleados WHERE id = ? AND activo = 1 LIMIT 1";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("i", $empleadoId);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows !== 1) {
    $stmt->close();
    $conexion->close();
    echo "<script>alert('Error de empleado.'); window.location.href='registro.html';</script>";
    exit;
}
$emp = $res->fetch_assoc();
$stmt->close();

$cargo = $emp["cargo"];
$nombre = $emp["nombre"];
$diaSemana = (int)date("N");

// Consultar horario de entrada
$sqlH = "SELECT hora_entrada, trabaja FROM horarios WHERE cargo = ? AND dia_semana = ? LIMIT 1";
$stmtH = $conexion->prepare($sqlH);
$stmtH->bind_param("si", $cargo, $diaSemana);
$stmtH->execute();
$resH = $stmtH->get_result();
$horario = $resH->fetch_assoc();
$stmtH->close();

$horaEntradaProg = $horario["hora_entrada"] ?? "08:00:00";
$minActuales = (int)explode(":", $horaActual)[0] * 60 + (int)explode(":", $horaActual)[1];
$minProg = (int)explode(":", $horaEntradaProg)[0] * 60 + (int)explode(":", $horaEntradaProg)[1];

$estaTarde = $minActuales > $minProg;
$minutosRetraso = $estaTarde ? ($minActuales - $minProg) : 0;

// Si está tarde y aún no ha enviado la justificación, mostramos la pantalla con el cuadro de texto
if ($estaTarde && !$enviado) {
    $conexion->close();
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Justificación de Retraso | Bellaforma</title>
        <link rel="stylesheet" href="css/registro.css">
    </head>
    <body>
        <div class="registro-header"><div class="header-title">💅 Bellaforma</div></div>
        <div class="container-registro">
            <div class="registro-card">
                <h2>Registrar Entrada</h2>
                <p>Hola, <?= htmlspecialchars($nombre) ?></p>

                <div style="background-color: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; padding: 12px; border-radius: 6px; margin-bottom: 15px;">
                    ⚠️ Has llegado <strong><?= $minutosRetraso ?> minutos tarde</strong>. Es obligatorio ingresar una justificación para continuar.
                </div>

                <form method="POST" action="procesar_entrada_form.php">
                    <input type="hidden" name="empleado_id" value="<?= $empleadoId ?>">
                    <input type="hidden" name="enviar_registro" value="1">

                    <div class="form-group">
                        <label for="justificacion" style="color: #d9534f; font-weight: bold;">Justificación de Retraso *</label>
                        <textarea id="justificacion" name="justificacion" rows="3" required placeholder="Escribe el motivo de tu retraso..." style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #d9534f;"></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary" style="background-color: #5cb85c;">Guardar Entrada con Justificación</button>
                    <a href="registro.html" class="btn btn-back">← Cancelar</a>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Verificar si ya registró asistencia hoy para evitar duplicados
$sqlCheck = "SELECT id FROM asistencias WHERE empleado_id = ? AND fecha = ? LIMIT 1";
$stmtCheck = $conexion->prepare($sqlCheck);
$stmtCheck->bind_param("is", $empleadoId, $fecha);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
if ($resCheck->num_rows > 0) {
    $stmtCheck->close();
    $conexion->close();
    echo "<script>alert('Ya has registrado tu asistencia el día de hoy.'); window.location.href='registro.html';</script>";
    exit;
}
$stmtCheck->close();

// Guardar la asistencia en la base de datos
$estado = $estaTarde ? "Tarde" : "A tiempo";
$sqlInsert = "INSERT INTO asistencias (empleado_id, fecha, hora_entrada, estado, minutos_retraso, justificacion) VALUES (?, ?, ?, ?, ?, ?)";
$stmtInsert = $conexion->prepare($sqlInsert);
$stmtInsert->bind_param("isssis", $empleadoId, $fecha, $horaActual, $estado, $minutosRetraso, $justificacion);

if ($stmtInsert->execute()) {
    $stmtInsert->close();
    $conexion->close();
    echo "<script>alert('¡Asistencia registrada con éxito!'); window.location.href='registro.html';</script>";
    exit;
} else {
    $stmtInsert->close();
    $conexion->close();
    echo "<script>alert('Error al registrar la asistencia.'); window.location.href='registro.html';</script>";
    exit;
}
