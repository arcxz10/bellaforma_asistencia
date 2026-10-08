<?php
/** Descarga la plantilla CSV para cargar clientes. */
session_start();
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=plantilla_clientes.csv");
echo "\xEF\xBB\xBF";
$cols = ["tipo_persona", "identificacion", "dv", "codigo", "razon_social", "nombre_comercial", "direccion", "direccion2",
         "puntos_referencia", "telefono1", "telefono2", "movil", "email", "email_fe", "departamento", "municipio", "barrio",
         "grupo", "subgrupo", "encargado", "representante_legal", "zona", "vendedor", "cobrador", "lista_precios",
         "condicion_pago", "dias_credito", "cupo_cartera", "observaciones", "inactivo"];
echo implode(";", $cols) . "\r\n";
echo implode(";", ["juridica", "900123456", "7", "C001", "DROGUERIA EJEMPLO SAS", "Droguería Ejemplo", "Calle 10 # 5-20", "",
                   "Frente al parque", "6021234567", "", "3001234567", "compras@ejemplo.com", "facturas@ejemplo.com", "Valle del Cauca",
                   "Cali", "Centro", "", "", "", "", "", "", "", "", "credito", "30", "5000000", "", "no"]) . "\r\n";
echo implode(";", ["natural", "1020304050", "", "", "MARIA PEREZ GOMEZ", "Tienda Maria", "Carrera 7 # 12-30", "",
                   "Local 2", "", "", "3109876543", "maria@correo.com", "", "Antioquia", "Medellín", "Laureles", "", "", "", "", "",
                   "", "", "", "contado", "", "0", "", "no"]) . "\r\n";
