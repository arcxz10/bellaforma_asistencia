<?php
date_default_timezone_set("America/Bogota");
require_once "conexion.php";

header('Content-Type: application/json');

$documento = trim($_GET["documento"] ?? "");
if ($documento === "") {
    echo json_encode(["tarde" => false]);
    exit;
}

$fecha = date("Y-m-d");
$diaSemana = (int) date("N");
$horaActual = date("H:i:s");

// Buscar cargo del empleado
$sql = "SELECT cargo FROM empleados WHERE identificacion = ? AND activo = 1 LIMIT 1";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $documento);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows !== 1) {
    echo json_encode(["tarde" => false]);
    exit;
}
$empleado = $resultado->fetch_assoc();
$cargo = $empleado["cargo"];
$stmt->close();

// Buscar horario de hoy
$sqlHorario = "SELECT hora_entrada, trabaja FROM horarios WHERE cargo = ? AND dia_semana = ? LIMIT 1";
$stmtHorario = $conexion->prepare($sqlHorario);
$stmtHorario->bind_param("si", $cargo, $diaSemana);
$stmtHorario->execute();
$resHorario = $stmtHorario->get_result();

if ($resHorario->num_rows !== 1) {
    echo json_encode(["tarde" => false]);
    exit;
}
$horario = $resHorario->fetch_assoc();
$stmtHorario->close();
$conexion->close();

if ((int)$horario["trabaja"] !== 1) {
    echo json_encode(["tarde" => false]);
    exit;
}

// Convertir horas a minutos para comparar
function convertirMinutos($h) {
    $p = explode(":", $h);
    return ((int)($p[0] ?? 0) * 60) + (int)($p[1] ?? 0);
}

$minutosActuales = convertirMinutos($horaActual);
$minutosEntrada = convertirMinutos($horario["hora_entrada"]);

$tarde = ($minutosActuales > $minutosEntrada);

echo json_encode(["tarde" => $tarde]);
