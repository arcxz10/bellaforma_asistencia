<?php
/** Descarga la plantilla CSV para cargar clientes (clientes_plantilla.php?lista=mayorista|detal). */
session_start();
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}
$lista = ($_GET["lista"] ?? "") === "detal" ? "detal" : "mayorista";
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=plantilla_clientes_" . $lista . ".csv");
echo "\xEF\xBB\xBF";
$cols = ["tipo_documento", "identificacion", "dv", "codigo", "razon_social", "persona_juridica", "nombre_comercial", "nota", "inactivo",
         "direccion", "direccion2", "puntos_referencia", "telefono1", "telefono2", "telefono3", "movil", "codigo_postal", "email", "email_fe",
         "departamento", "codigo_municipio", "municipio", "codigo_pais", "pais", "codigo_barrio", "barrio",
         "grupo", "subgrupo", "encargado", "representante_legal", "observaciones",
         "zona", "codigo_vendedor", "vendedor", "codigo_cobrador", "cobrador", "codigo_agente", "agente_comercial", "codigo_transporta", "transportadora",
         "lista_precios", "cupo_cartera", "no_facturas", "calificacion", "dias_mora", "condicion_pago", "dias_credito"];
echo implode(";", $cols) . "\r\n";
$ej1 = ["NIT", "900123456", "7", "C001", "DROGUERIA EJEMPLO SAS", "si", "Droguería Ejemplo", "", "no",
        "Calle 10 # 5-20", "", "Frente al parque", "6021234567", "", "", "3001234567", "", "compras@ejemplo.com", "facturas@ejemplo.com",
        "Valle del Cauca", "76-001", "Cali", "169", "Colombia", "", "Centro",
        "E-COMMERCE", "", "", "", "cliente paga anticipado",
        "", "", "", "", "", "", "", "", "", "Lista General", "5000000", "0", "", "0", "credito", "30"];
$ej2 = ["Cédula de ciudadanía", "1020304050", "", "", "MARIA PEREZ GOMEZ", "no", "Tienda Maria", "", "no",
        "Carrera 7 # 12-30", "", "Local 2", "", "", "", "3109876543", "", "maria@correo.com", "",
        "Antioquia", "05-001", "Medellín", "169", "Colombia", "", "Laureles",
        "", "", "", "", "",
        "", "", "", "", "", "", "", "", "", "Lista General", "0", "0", "", "0", "contado", ""];
echo implode(";", $ej1) . "\r\n" . implode(";", $ej2) . "\r\n";
