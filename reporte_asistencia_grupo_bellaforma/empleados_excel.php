<?php
/**
 * Descarga en Excel de todos los empleados ACTIVOS.
 *   empleados_excel.php
 * Solo entra el administrador (o un subadministrador con permiso al módulo «empleados»).
 */
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

ini_set("display_errors", 0);
ob_start();

require_once "conexion.php";
require_once __DIR__ . "/admin_permisos.php";
require_once "xlsx_simple.php";
date_default_timezone_set("America/Bogota");

if (!adminPuede("empleados")) {
    ob_end_clean();
    http_response_code(403);
    exit("No tiene permiso para descargar los empleados.");
}

$sql = "
    SELECT
        e.id,
        e.nombre,
        e.identificacion,
        e.cargo,
        COALESCE(h.hora_entrada, e.hora_entrada) AS hora_entrada,
        COALESCE(h.hora_salida, e.hora_salida) AS hora_salida,
        (
            SELECT MIN(a.fecha)
            FROM asistencias a
            WHERE a.empleado_id = e.id
        ) AS primer_registro
    FROM empleados e
    LEFT JOIN horarios h
        ON h.cargo = e.cargo
        AND h.dia_semana = 1
        AND h.trabaja = 1
    WHERE e.activo = 1
    ORDER BY e.nombre ASC
";

$res = $conexion->query($sql);
if (!$res) {
    ob_end_clean();
    http_response_code(500);
    exit("No se pudo consultar los empleados.");
}

$hora = function ($h): string {
    $h = trim((string) $h);
    return $h === "" ? "" : substr($h, 0, 5);
};
$fecha = function ($f): string {
    $f = trim((string) $f);
    $t = $f === "" ? false : strtotime($f);
    return $t ? date("d/m/Y", $t) : "";
};

$encabezados = ["Nombre", "Identificación", "Cargo", "Hora de entrada", "Hora de salida", "Primer registro"];
$filas = [];
$vistos = [];
while ($e = $res->fetch_assoc()) {
    if (isset($vistos[$e["id"]])) {   // por si un cargo tuviera dos horarios, no repetir al empleado
        continue;
    }
    $vistos[$e["id"]] = true;
    $filas[] = [
        (string) $e["nombre"],
        (string) $e["identificacion"],
        (string) $e["cargo"],
        $hora($e["hora_entrada"]),
        $hora($e["hora_salida"]),
        $fecha($e["primer_registro"]),
    ];
}

$anchos = [36, 18, 24, 16, 16, 16];
$binario = xlsx_generar($encabezados, $filas, $anchos, []);

ob_end_clean();
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"empleados_activos_" . date("Ymd_His") . ".xlsx\"");
header("Content-Length: " . strlen($binario));
header("Cache-Control: private, no-store");
echo $binario;
