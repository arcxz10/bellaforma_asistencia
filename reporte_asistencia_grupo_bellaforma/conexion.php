<?php
$host = getenv('MYSQLHOST') ?: 'localhost';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$db   = getenv('MYSQLDATABASE') ?: 'railway';
$port = getenv('MYSQLPORT') ?: 3306;

// Es importante pasar el puerto y usar la IP 127.0.0.1 si fuera local, pero con getenv se conecta por red a Railway
$conexion = new mysqli($host, $user, $pass, $db, (int)$port);

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$conexion->set_charset("utf8");
?>
