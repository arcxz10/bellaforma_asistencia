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
    $r = @iconv("UTF-8", "windows-1252//TRANSLIT//IGNORE", (string) $texto);
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

$idUnico = (int) ($_GET["id"] ?? 0);
if ($idUnico > 0) {
    $pedidos = obtenerPedidos($conexion, [], null, $idUnico);
} else {
    $pedidos = obtenerPedidos($conexion, [
        "vendedor" => (int) ($_GET["vendedor_p"] ?? 0),
        "estado"   => $_GET["estado_p"] ?? "",
        "desde"    => $_GET["desde_p"] ?? "",
        "hasta"    => $_GET["hasta_p"] ?? "",
        "buscar"   => $_GET["buscar_p"] ?? "",
    ], 500);
}

if (!$pedidos) {
    ob_end_clean();
    exit("No hay pedidos para descargar.");
}

// Orden cronológico (el más antiguo primero) para digitar en orden
$pedidos = array_reverse($pedidos);

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
    $pdf->Cell(110, 6, t("Pedido de vendedor - para digitar en Syscafe"), 0, 0, "L");
    $pdf->Cell(76, 6, t(date("d/m/Y H:i", strtotime($p["creado_en"])) . "  |  " . etiquetaEstadoPedido($p["estado"])), 0, 1, "R");
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(30);

    // Datos del vendedor y cliente
    $campo = function (string $etiqueta, string $valor, float $anchoEt = 32) use ($pdf) {
        $pdf->SetFont("Arial", "B", 9);
        $pdf->Cell($anchoEt, 6, t($etiqueta), 0, 0, "L");
        $pdf->SetFont("Arial", "", 9);
        $pdf->MultiCell(0, 6, t($valor === "" ? "-" : $valor), 0, "L");
    };

    $pdf->SetFont("Arial", "B", 10);
    $pdf->SetFillColor(227, 242, 253);
    $pdf->Cell(0, 7, t("VENDEDOR"), 0, 1, "L", true);
    $campo("Nombre:", $p["vendedor_nombre"]);
    $campo("Identificacion:", $p["vendedor_identificacion"]);
    $pdf->Ln(2);

    $pdf->SetFont("Arial", "B", 10);
    $pdf->Cell(0, 7, t("CLIENTE"), 0, 1, "L", true);
    $campo("Nombre:", $p["cliente_nombre"]);
    $campo("NIT:", $p["cliente_nit"]);
    $campo("Telefono:", $p["cliente_telefono"]);
    $campo("Ciudad:", $p["cliente_ciudad"]);
    $campo("Barrio:", (string) $p["cliente_barrio"]);
    $campo("Direcciones:", (string) $p["cliente_direcciones"]);
    if (!empty($p["observaciones"])) {
        $campo("Observaciones:", (string) $p["observaciones"]);
    }
    $pdf->Ln(4);

    // Tabla de productos
    encabezadoTabla($pdf);
    $pdf->SetFont("Arial", "", 9);
    $fill = false;

    foreach ($p["items"] as $it) {
        if ($pdf->GetY() > 262) {
            $pdf->AddPage();
            $pdf->SetY(14);
            encabezadoTabla($pdf);
            $pdf->SetFont("Arial", "", 9);
        }
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
    $totalUnidades = 0;
    foreach ($p["items"] as $it) {
        $totalUnidades += (int) $it["cantidad"];
    }
    $pdf->SetFont("Arial", "B", 10);
    $pdf->Cell(112, 8, t("Total unidades: " . $totalUnidades), 0, 0, "L");
    $pdf->Cell(45, 8, "TOTAL PEDIDO", 0, 0, "R");
    $pdf->Cell(29, 8, cop($p["total"]), 0, 1, "R");
}

$nombreArchivo = $idUnico > 0
    ? "pedido_" . numeroPedido($idUnico) . ".pdf"
    : "pedidos_" . date("Ymd_His") . ".pdf";

ob_end_clean();
$pdf->Output("D", $nombreArchivo);
