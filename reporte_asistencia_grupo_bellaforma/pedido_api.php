<?php
/** Envoltorio de la API de pedidos (la lógica está en pedidos_lib.php > manejarApiPedido). */
session_start();
require_once "conexion.php";
require_once "vendedores_db.php";
require_once "pedidos_lib.php";
manejarApiPedido($conexion);
