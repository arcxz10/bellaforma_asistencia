<?php
/** Plantilla CSV para cargar productos (precio mayorista y precio detal). */
session_start();
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=plantilla_productos.csv");
echo "\xEF\xBB\xBF";
echo "referencia;nombre;precio_mayorista;precio_detal;activo\r\n";
echo "LBC31;Labial humectante Pomelo;7100;10900;si\r\n";
echo "BEL01;Betún de cejas Dark Brown;7900;12000;si\r\n";
