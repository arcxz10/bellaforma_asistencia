<?php
/**
 * Descarga de pedidos en PDF (para digitarlos en Syscafe).
 *   pedido_pdf.php?id=12            -> un pedido
 *   pedido_pdf.php?vendedor_p=..&estado_p=..&desde_p=..&hasta_p=..&buscar_p=..  -> varios (1 por página)
 */
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

ini_set("display_errors", 0);
ob_start();

require_once "conexion.php";
require_once "vendedores_db.php";
require_once "pedidos_lib.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

$autoload = __DIR__ . "/vendor/autoload.php";
if (!file_exists($autoload)) {
    ob_end_clean();
    http_response_code(500);
    exit("Falta instalar FPDF. Agregue \"setasign/fpdf\" en composer.json y vuelva a desplegar.");
}
require_once $autoload;

function t($texto)
{
    $r = @iconv("UTF-8", "windows-1252//TRANSLIT", (string) $texto);
    return $r === false ? preg_replace('/[^\x20-\x7E]/', "?", (string) $texto) : $r;
}

function recortar(FPDF $pdf, string $texto, float $ancho): string
{
    $texto = t($texto);
    if ($pdf->GetStringWidth($texto) <= $ancho) {
        return $texto;
    }
    while ($texto !== "" && $pdf->GetStringWidth($texto . "...") > $ancho) {
        $texto = substr($texto, 0, -1);
    }
    return $texto . "...";
}

function cop($v)
{
    return "$ " . number_format((float) $v, 0, ",", ".");
}

$pedidos = pedidosParaExportar($conexion);
if (!$pedidos) {
    ob_end_clean();
    exit("No hay pedidos para descargar.");
}
$pedidos = array_reverse($pedidos);   // el más antiguo primero

$pdf = new FPDF("P", "mm", "A4");
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(false);

function encabezadoTabla(FPDF $pdf)
{
    $pdf->SetFont("Arial", "B", 9);
    $pdf->SetFillColor(21, 101, 192);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(32, 7, "REFERENCIA", 1, 0, "L", true);
    $pdf->Cell(80, 7, "DESCRIPCION", 1, 0, "L", true);
    $pdf->Cell(16, 7, "CANT.", 1, 0, "R", true);
    $pdf->Cell(29, 7, "VR. UNITARIO", 1, 0, "R", true);
    $pdf->Cell(29, 7, "SUBTOTAL", 1, 1, "R", true);
    $pdf->SetTextColor(0, 0, 0);
}

foreach ($pedidos as $p) {

    $natural = ($p["cliente_tipo_persona"] ?? "juridica") === "natural";

    $pdf->AddPage();

    // Cabecera
    $pdf->SetFillColor(13, 71, 161);
    $pdf->Rect(0, 0, 210, 24, "F");
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(12, 6);
    $pdf->SetFont("Arial", "B", 15);
    $pdf->Cell(110, 7, t("GRUPO BELLAFORMA S.A.S."), 0, 0, "L");
    $pdf->SetFont("Arial", "B", 13);
    $pdf->Cell(76, 7, t("PEDIDO No. " . numeroPedido($p["id"])), 0, 1, "R");
    $pdf->SetX(12);
    $pdf->SetFont("Arial", "", 9);
    $pdf->Cell(110, 6, t("Pedido de ejecutivo de negocios - para digitar en Syscafe"), 0, 0, "L");
    $pdf->Cell(76, 6, t(date("d/m/Y H:i", strtotime($p["creado_en"])) . "  |  " . etiquetaEstadoPedido($p["estado"])), 0, 1, "R");
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(29);

    $campo = function (string $etiqueta, string $valor, float $anchoEt = 38) use ($pdf) {
        $pdf->SetFont("Arial", "B", 9);
        $pdf->Cell($anchoEt, 5.5, t($etiqueta), 0, 0, "L");
        $pdf->SetFont("Arial", "", 9);
        $pdf->MultiCell(0, 5.5, t($valor === "" ? "-" : $valor), 0, "L");
    };

    $pdf->SetFont("Arial", "B", 10);
    $pdf->SetFillColor(227, 242, 253);
    $pdf->Cell(0, 6.5, t("EJECUTIVO DE NEGOCIOS"), 0, 1, "L", true);
    $campo("Nombre:", $p["vendedor_nombre"] . "  (ID " . $p["vendedor_identificacion"] . ")");
    $pdf->Ln(1.5);

    $pdf->SetFont("Arial", "B", 10);
    $pdf->Cell(0, 6.5, t("CLIENTE - " . ($natural ? "PERSONA NATURAL" : "PERSONA JURIDICA")), 0, 1, "L", true);
    $campo(($natural ? "Cedula:" : "NIT:"), (string) $p["cliente_nit"]);
    $campo(($natural ? "Nombre completo:" : "Razon social:"), (string) $p["cliente_nombre"]);
    $campo("Nombre comercial:", (string) $p["cliente_nombre_comercial"]);
    $campo("Telefono:", (string) $p["cliente_telefono"]);
    $campo("Correo:", (string) $p["cliente_email"]);
    $campo("Departamento:", (string) $p["cliente_departamento"]);
    $campo("Municipio:", (string) $p["cliente_ciudad"]);
    $campo("Barrio:", (string) $p["cliente_barrio"]);
    $campo("Direccion de entrega:", (string) $p["cliente_direcciones"]);
    $campo("Puntos de referencia:", (string) $p["cliente_puntos_referencia"]);
    $pdf->Ln(1.5);

    $pdf->SetFont("Arial", "B", 10);
    $pdf->Cell(0, 6.5, t("CONDICIONES"), 0, 1, "L", true);
    $campo("Forma de pago:", etiquetaCondicion($p));
    $campo("Factura electronica:", !empty($p["factura_electronica"]) ? "SI  -  " . ($p["email_fe"] ?: $p["cliente_email"]) : "NO");
    if (!empty($p["observaciones"])) {
        $campo("Observaciones:", (string) $p["observaciones"]);
    }
    $pdf->Ln(3);

    // Tabla de productos
    encabezadoTabla($pdf);
    $fill = false;
    $totalUnidades = 0;

    foreach ($p["items"] as $it) {
        if ($pdf->GetY() > 262) {
            $pdf->AddPage();
            $pdf->SetY(14);
            encabezadoTabla($pdf);
        }
        $totalUnidades += (int) $it["cantidad"];
        $pdf->SetFillColor(245, 249, 255);
        $pdf->SetFont("Arial", "B", 9);
        $pdf->Cell(32, 6.5, recortar($pdf, $it["referencia"], 30), "LRB", 0, "L", $fill);
        $pdf->SetFont("Arial", "", 9);
        $pdf->Cell(80, 6.5, recortar($pdf, $it["nombre"], 78), "RB", 0, "L", $fill);
        $pdf->Cell(16, 6.5, (string) (int) $it["cantidad"], "RB", 0, "R", $fill);
        $pdf->Cell(29, 6.5, cop($it["precio_unitario"]), "RB", 0, "R", $fill);
        $pdf->Cell(29, 6.5, cop($it["subtotal"]), "RB", 1, "R", $fill);
        $fill = !$fill;
    }

    if ($pdf->GetY() > 262) {
        $pdf->AddPage();
        $pdf->SetY(14);
    }
    $pdf->SetFont("Arial", "B", 10);
    $pdf->Cell(112, 8, t("Total unidades: " . $totalUnidades), 0, 0, "L");
    $pdf->Cell(45, 8, "TOTAL PEDIDO", 0, 0, "R");
    $pdf->Cell(29, 8, cop($p["total"]), 0, 1, "R");
}

$idUnico = (int) ($_GET["id"] ?? 0);
$nombreArchivo = $idUnico > 0
    ? "pedido_" . numeroPedido($idUnico) . ".pdf"
    : "pedidos_" . date("Ymd_His") . ".pdf";

ob_end_clean();
$pdf->Output("D", $nombreArchivo);
