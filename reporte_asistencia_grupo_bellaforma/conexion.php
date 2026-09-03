<?php

$servidor   = $_ENV['MYSQLHOST']     ?? "localhost";
$usuario    = $_ENV['MYSQLUSER']     ?? "root";
$contrasena = $_ENV['MYSQLPASSWORD'] ?? "";
$base_datos = $_ENV['MYSQLDATABASE'] ?? "bellaforma_asistencia";
$puerto     = $_ENV['MYSQLPORT']     ?? 3306;

$conexion = new mysqli(
    $servidor,
    $usuario,
    $contrasena,
    $base_datos,
    $puerto
);

if ($conexion->connect_error) {
    die("Error de conexión con la base de datos: " . $conexion->connect_error);
}

$conexion->set_charset("utf8mb4");

?>