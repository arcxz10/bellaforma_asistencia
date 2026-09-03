<?php
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
?>
