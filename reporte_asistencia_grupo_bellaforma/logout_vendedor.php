<?php
session_start();
// Solo cierra la sesión del vendedor (no toca una posible sesión de administrador).
unset($_SESSION["vendedor_id"], $_SESSION["vendedor_nombre"], $_SESSION["csrf_vendedor"]);
header("Location: login_vendedor.php");
exit;
