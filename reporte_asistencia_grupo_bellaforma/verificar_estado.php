<?php
date_default_timezone_set("America/Bogota");
require_once "conexion.php";

header('Content-Type: application/json');

$documento = trim($_GET["documento"] ?? "");

if ($documento === "") {
    echo json_encode(["tipo" => "entrada", "tarde" => false]);
    exit;
}

// 1. Buscar empleado
$sql = "SELECT id, cargo FROM empleados WHERE identificacion = ? AND activo = 1 LIMIT 1";
$stmt = $conexion->prepare($sql);
$stmt->bind_param("s", $documento);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows !== 1) {
    echo json_encode(["tipo" => "entrada", "tarde" => false]);
    exit;
}

$empleado = $resultado->fetch_assoc();
$empleadoId = (int)$empleado["id"];
$cargo = $empleado["cargo"];
$stmt->close();

$fecha = date("Y-m-d");
$horaActual = date("H:i:s");
$diaSemana = (int)date("N");

// 2. Comprobar si ya tiene entrada hoy para definir si es SALIDA o ENTRADA
$sqlCheck = "SELECT hora_entrada, hora_salida FROM asistencias WHERE empleado_id = ? AND fecha = ? LIMIT 1";
$stmtCheck = $conexion->prepare($sqlCheck);
$stmtCheck->bind_param("is", $empleadoId, $fecha);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();

$tipoAccion = "entrada";
if ($resCheck->num_rows > 0) {
    $reg = $resCheck->fetch_assoc();
    if (empty($reg["hora_salida"])) {
        $tipoAccion = "salida"; // Ya tiene entrada, lo que sigue es salida
    }
}
$stmtCheck->close();

// Si es salida, no aplica evaluar retraso de entrada
if ($tipoAccion === "salida") {
    echo json_encode(["tipo" => "salida", "tarde" => false]);
    exit;
}

// 3. Consultar horario para ver si llegó tarde a la ENTRADA
$sqlHorario = "SELECT hora_entrada, trabaja FROM horarios WHERE cargo = ? AND dia_semana = ? LIMIT 1";
$stmtHorario = $conexion->prepare($sqlHorario);
$stmtHorario->bind_param("si", $cargo, $diaSemana);
$stmtHorario->execute();
$resHorario = $stmtHorario->get_result();

if ($resHorario->num_rows === 1) {
    $horario = $resHorario->fetch_assoc();
    if ((int)$horario["trabaja"] === 1) {
        $minutosActuales = convertirMinutos($horaActual);
        $minutosEntrada = convertirMinutos($horario["hora_entrada"]);

        if ($minutosActuales > $minutosEntrada) {
            echo json_encode(["tipo" => "entrada", "tarde" => true]);
            exit;
        }
    }
}
$stmtHorario->close();
$conexion->close();

echo json_encode(["tipo" => "entrada", "tarde" => false]);

function convertirMinutos($hora) {
    $partes = explode(":", $hora);
    return ((int)($partes[0] ?? 0) * 60) + (int)($partes[1] ?? 0);
}
