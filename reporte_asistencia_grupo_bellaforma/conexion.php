<?php

$servidor = "localhost";
$usuario = "root";
$contrasena = "";
$base_datos = "bellaforma_asistencia";

$conexion = new mysqli(
    $servidor,
    $usuario,
    $contrasena,
    $base_datos
);

if ($conexion->connect_error) {

    die("Error de conexión con la base de datos: " . $conexion->connect_error);

}

$conexion->set_charset("utf8mb4");

?>