<?php
// Toda la aplicación trabaja con hora de Colombia (UTC-5, sin horario de verano).
// Se fija en PHP y también en MySQL: Railway usa UTC por defecto, y eso causaba
// horas con +5 y fechas de "otro día" después de las 7:00 pm.
date_default_timezone_set("America/Bogota");

$host = "mysql.railway.internal";
$user = "root";
$pass = "zfhJTFzwzIdzxFGOrQXoHAIsbGURuxtC";
$db   = "railway";
$port = 3306;

$conexion = new mysqli($host, $user, $pass, $db, $port);

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$conexion->set_charset("utf8");

// Zona horaria de la sesión MySQL: afecta NOW(), CURRENT_TIMESTAMP, CURDATE(),
// DATE(columna_timestamp) y cómo se leen las columnas TIMESTAMP.
$conexion->query("SET time_zone = '-05:00'");

// Datos de conexión disponibles para el registro de movimientos (que se escribe al terminar la petición).
$GLOBALS["bf_db_cfg"] = ["host" => $host, "user" => $user, "pass" => $pass, "db" => $db, "port" => $port];

// Permisos de subadministradores (se bloquea en el servidor) y registro de sus movimientos.
if (PHP_SAPI !== "cli" && isset($_SESSION) && !empty($_SESSION["admin_id"])) {
    require_once __DIR__ . "/admin_permisos.php";
    adminGuardia($conexion);
    require_once __DIR__ . "/admin_movimientos_lib.php";
    movSubIniciar($conexion);
}
?>
