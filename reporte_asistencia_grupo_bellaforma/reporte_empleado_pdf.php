<?php

// Este archivo genera un PDF binario: apagamos la salida de errores en
// pantalla para que ningún aviso de PHP se mezcle con los bytes del PDF.
error_reporting(E_ALL);
ini_set("display_errors", 0);

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

require_once __DIR__ . "/vendor/autoload.php";
require_once "conexion.php";

date_default_timezone_set("America/Bogota");

// FPDF (clásico) no soporta UTF-8 directamente: convertimos cada texto
// a ISO-8859-1 antes de imprimirlo para que tildes y "ñ" se vean bien.
function t($texto)
{
    $resultado = @iconv("UTF-8", "ISO-8859-1//TRANSLIT", (string) $texto);
    return $resultado !== false ? $resultado : (string) $texto;
}

function minutosAHoras($minutos)
{
    $minutos = (int) $minutos;
    $horas = intdiv($minutos, 60);
    $resto = $minutos % 60;

    if ($horas > 0) {
        return $horas . " h " . $resto . " min";
    }

    return $resto . " min";
}

function horaBonita($hora)
{
    if (empty($hora)) {
        return null;
    }

    return date("h:i A", strtotime($hora));
}

function nombreDiaFecha($fecha)
{
    $nombres = [1 => "Lunes", 2 => "Martes", 3 => "Miércoles", 4 => "Jueves", 5 => "Viernes", 6 => "Sábado", 7 => "Domingo"];
    $n = (int) date("N", strtotime($fecha));

    return $nombres[$n] . " " . date("d/m/Y", strtotime($fecha));
}

// --- Parámetros ---
$empleadoId = (int) ($_GET["empleado_id"] ?? 0);
$desde = $_GET["desde"] ?? "";
$hasta = $_GET["hasta"] ?? "";

if ($empleadoId <= 0 || empty($desde) || empty($hasta)) {
    die("Faltan datos para generar el reporte (empleado o rango de fechas).");
}

// --- Datos del empleado ---
$stmtEmp = $conexion->prepare("SELECT nombre, identificacion, cargo FROM empleados WHERE id = ?");
$stmtEmp->bind_param("i", $empleadoId);
$stmtEmp->execute();
$empleado = $stmtEmp->get_result()->fetch_assoc();

if (!$empleado) {
    die("Empleado no encontrado.");
}

// --- Asistencias del periodo, día por día ---
$stmt = $conexion->prepare("
    SELECT fecha, hora_entrada, hora_salida, minutos_retraso, minutos_extra, minutos_deuda
    FROM asistencias
    WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
    ORDER BY fecha ASC
");
$stmt->bind_param("iss", $empleadoId, $desde, $hasta);
$stmt->execute();
$filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalRetrasoBruto = 0;
$totalExtraBruto = 0;
$totalDeudaBruta = 0;
foreach ($filas as $f) {
    $totalRetrasoBruto += (int) $f["minutos_retraso"];
    $totalExtraBruto += (int) $f["minutos_extra"];
    $totalDeudaBruta += (int) $f["minutos_deuda"];
}

// --- Misma lógica de compensación que usa Liquidación: el tiempo extra  ---
// --- primero recupera el retraso, y lo que sobre recupera la deuda.     ---
$extraDisponible = $totalExtraBruto;

if ($extraDisponible >= $totalRetrasoBruto) {
    $extraDisponible -= $totalRetrasoBruto;
    $retrasoNeto = 0;
} else {
    $retrasoNeto = $totalRetrasoBruto - $extraDisponible;
    $extraDisponible = 0;
}

if ($extraDisponible >= $totalDeudaBruta) {
    $extraDisponible -= $totalDeudaBruta;
    $deudaNeta = 0;
} else {
    $deudaNeta = $totalDeudaBruta - $extraDisponible;
    $extraDisponible = 0;
}

$extraNeto = $extraDisponible;
$compensadoRetraso = min($totalRetrasoBruto, $totalExtraBruto);
$compensadoDeuda = $totalDeudaBruta - $deudaNeta;
$compensadoTotal = $compensadoRetraso + $compensadoDeuda;

// ==========================================================================
// Generación del PDF con FPDF
// ==========================================================================

class ReportePDF extends FPDF
{
    public $nombreEmpresa = "Grupo Bella Forma S.A.S.";
    public $rutaLogo = "";

    function Header()
    {
        if ($this->rutaLogo && file_exists($this->rutaLogo)) {
            $this->Image($this->rutaLogo, 15, 10, 0, 9);
        }

        $this->SetXY(37, 12);
        $this->SetFont("Helvetica", "B", 11);
        $this->SetTextColor(22, 32, 46);
        $this->Cell(90, 6, t($this->nombreEmpresa), 0, 0, "L");

        $this->SetXY(-95, 10);
        $this->SetFont("Helvetica", "B", 14);
        $this->SetTextColor(22, 32, 46);
        $this->Cell(80, 6, t("Reporte de Asistencia"), 0, 2, "R");

        $this->SetX(-95);
        $this->SetFont("Helvetica", "", 8);
        $this->SetTextColor(91, 102, 118);
        $this->Cell(80, 5, t("Generado el " . date("d/m/Y h:i A")), 0, 0, "R");

        $this->SetY(26);
        $this->SetDrawColor(227, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Line(15, 26, 195, 26);
        $this->SetY(32);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont("Helvetica", "", 7);
        $this->SetTextColor(183, 191, 203);
        $this->Cell(0, 10, t("Grupo Bella Forma S.A.S. - Sistema de Asistencia y Control  -  Página " . $this->PageNo() . "/{nb}"), 0, 0, "C");
    }

    // Vuelve a dibujar los títulos de columna (se usa en cada página nueva)
    function encabezadoTabla($colFecha, $colEntrada, $colSalida)
    {
        $this->SetFillColor(250, 251, 253);
        $this->SetDrawColor(227, 232, 240);
        $this->SetTextColor(91, 102, 118);
        $this->SetFont("Helvetica", "B", 8);
        $this->SetX(15);
        $this->Cell($colFecha, 8, t("FECHA"), "B", 0, "L", true);
        $this->Cell($colEntrada, 8, t("HORA DE ENTRADA"), "B", 0, "L", true);
        $this->Cell($colSalida, 8, t("HORA DE SALIDA"), "B", 1, "L", true);
    }

    // Dibuja una celda con una hora arriba y, debajo, una o varias etiquetas
    // de color (ej: "Tarde: 7 min" en rojo, o "Debe: 5 min" en azul).
    // $etiquetas es un arreglo de ["texto" => ..., "color" => [r,g,b]]
    function celdaHoraEtiqueta($x, $y, $w, $hora, $etiquetas = [])
    {
        $this->SetXY($x, $y);
        $this->SetFont("Helvetica", "", 9);
        $this->SetTextColor(22, 32, 46);

        if ($hora) {
            $this->Cell($w, 5, t($hora), 0, 0);
        } else {
            $this->SetTextColor(183, 191, 203);
            $this->Cell($w, 5, "-", 0, 0);
        }

        $yEtiqueta = $y + 5;
        foreach ($etiquetas as $et) {
            if (empty($et["texto"])) {
                continue;
            }

            $this->SetXY($x, $yEtiqueta);
            $this->SetFont("Helvetica", "B", 7.5);
            [$r, $g, $b] = $et["color"];
            $this->SetTextColor($r, $g, $b);
            $this->Cell($w, 4, t($et["texto"]), 0, 0);
            $yEtiqueta += 4;
        }
    }
}

$pdf = new ReportePDF();
$pdf->rutaLogo = __DIR__ . "/img/logo-verde.png";
$pdf->AliasNbPages();
$pdf->SetMargins(15, 10, 15);
// Control manual de salto de página (ver el bucle de filas más abajo):
// así nos aseguramos de repetir los títulos de columna en cada página nueva.
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// --- Caja de datos del empleado ---
$pdf->SetFillColor(233, 242, 254);
$yCaja = $pdf->GetY();
$pdf->Rect(15, $yCaja, 180, 16, "F");

$pdf->SetXY(20, $yCaja + 3);
$pdf->SetFont("Helvetica", "B", 9);
$pdf->SetTextColor(15, 61, 115);
$pdf->Cell(26, 5, t("Empleado:"));
$pdf->SetFont("Helvetica", "", 9);
$pdf->Cell(65, 5, t($empleado["nombre"]));
$pdf->SetFont("Helvetica", "B", 9);
$pdf->Cell(32, 5, t("Identificación:"));
$pdf->SetFont("Helvetica", "", 9);
$pdf->Cell(0, 5, t($empleado["identificacion"]));

$pdf->SetXY(20, $yCaja + 9);
$pdf->SetFont("Helvetica", "B", 9);
$pdf->Cell(26, 5, t("Cargo:"));
$pdf->SetFont("Helvetica", "", 9);
$pdf->Cell(65, 5, t($empleado["cargo"]));
$pdf->SetFont("Helvetica", "B", 9);
$pdf->Cell(32, 5, t("Periodo:"));
$pdf->SetFont("Helvetica", "", 9);
$pdf->Cell(0, 5, t(date("d/m/Y", strtotime($desde)) . " al " . date("d/m/Y", strtotime($hasta))));

$pdf->SetY($yCaja + 22);

// --- Encabezado de la tabla ---
$colFecha = 55;
$colEntrada = 62;
$colSalida = 63;

$pdf->encabezadoTabla($colFecha, $colEntrada, $colSalida);

// --- Filas, día por día ---
if (empty($filas)) {
    $pdf->Ln(4);
    $pdf->SetFont("Helvetica", "", 9);
    $pdf->SetTextColor(183, 191, 203);
    $pdf->Cell(0, 10, t("No hay registros de asistencia en este rango de fechas."), 0, 1, "C");
} else {
    $rojo = [198, 40, 57];
    $ambar = [160, 113, 10];
    $azul = [20, 98, 196];

    foreach ($filas as $f) {
        $retraso = (int) $f["minutos_retraso"];
        $extra = (int) $f["minutos_extra"];
        $deuda = (int) $f["minutos_deuda"];
        $horaEntrada = horaBonita($f["hora_entrada"]);
        $horaSalida = horaBonita($f["hora_salida"]);

        // La altura de la fila crece un poco si la salida tiene 2 etiquetas
        // a la vez (extra Y deuda el mismo día — raro, pero puede pasar).
        $etiquetasSalida = [];
        if ($extra > 0) {
            $etiquetasSalida[] = ["texto" => "Extra: " . minutosAHoras($extra), "color" => $ambar];
        }
        if ($deuda > 0) {
            $etiquetasSalida[] = ["texto" => "Debe: " . minutosAHoras($deuda), "color" => $azul];
        }

        $alturaFila = 12 + max(0, (count($etiquetasSalida) - 1) * 4);

        // Si la fila no cabe antes del pie de página, pasamos de página
        // y repetimos los títulos de columna (FECHA / ENTRADA / SALIDA).
        if ($pdf->GetY() + $alturaFila > 265) {
            $pdf->AddPage();
            $pdf->encabezadoTabla($colFecha, $colEntrada, $colSalida);
        }

        $y = $pdf->GetY();

        $pdf->SetXY(15, $y + 3);
        $pdf->SetFont("Helvetica", "", 9);
        $pdf->SetTextColor(22, 32, 46);
        $pdf->Cell($colFecha, 5, t(nombreDiaFecha($f["fecha"])));

        $pdf->celdaHoraEtiqueta(
            15 + $colFecha,
            $y + 2,
            $colEntrada,
            $horaEntrada,
            $retraso > 0 ? [["texto" => "Tarde: " . minutosAHoras($retraso), "color" => $rojo]] : []
        );

        $pdf->celdaHoraEtiqueta(
            15 + $colFecha + $colEntrada,
            $y + 2,
            $colSalida,
            $horaSalida,
            $etiquetasSalida
        );

        $pdf->SetDrawColor(227, 232, 240);
        $pdf->Line(15, $y + $alturaFila, 195, $y + $alturaFila);

        $pdf->SetXY(15, $y + $alturaFila);
    }
}

// --- Resumen total + compensación (misma lógica que Liquidación) ---
if (!empty($filas)) {
    $pdf->Ln(6);

    if ($pdf->GetY() > 250) {
        $pdf->AddPage();
    }

    $y = $pdf->GetY();
    $anchoCaja = 58;

    $pdf->SetFillColor(253, 234, 236);
    $pdf->Rect(15, $y, $anchoCaja, 16, "F");
    $pdf->SetFillColor(255, 246, 224);
    $pdf->Rect(76, $y, $anchoCaja, 16, "F");
    $pdf->SetFillColor(226, 237, 252);
    $pdf->Rect(137, $y, $anchoCaja, 16, "F");

    $pdf->SetXY(15, $y + 2.5);
    $pdf->SetFont("Helvetica", "B", 12);
    $pdf->SetTextColor(198, 40, 57);
    $pdf->Cell($anchoCaja, 6, t(minutosAHoras($totalRetrasoBruto)), 0, 2, "C");
    $pdf->SetX(15);
    $pdf->SetFont("Helvetica", "", 7.5);
    $pdf->SetTextColor(91, 102, 118);
    $pdf->Cell($anchoCaja, 5, t("Total retraso del periodo"), 0, 0, "C");

    $pdf->SetXY(76, $y + 2.5);
    $pdf->SetFont("Helvetica", "B", 12);
    $pdf->SetTextColor(160, 113, 10);
    $pdf->Cell($anchoCaja, 6, t(minutosAHoras($totalExtraBruto)), 0, 2, "C");
    $pdf->SetX(76);
    $pdf->SetFont("Helvetica", "", 7.5);
    $pdf->SetTextColor(91, 102, 118);
    $pdf->Cell($anchoCaja, 5, t("Total horas extra del periodo"), 0, 0, "C");

    $pdf->SetXY(137, $y + 2.5);
    $pdf->SetFont("Helvetica", "B", 12);
    $pdf->SetTextColor(20, 98, 196);
    $pdf->Cell($anchoCaja, 6, t(minutosAHoras($totalDeudaBruta)), 0, 2, "C");
    $pdf->SetX(137);
    $pdf->SetFont("Helvetica", "", 7.5);
    $pdf->SetTextColor(91, 102, 118);
    $pdf->Cell($anchoCaja, 5, t("Total deuda del periodo"), 0, 0, "C");

    $pdf->SetY($y + 20);

    // --- Nota de compensación: igual que en Liquidación, las horas extra ---
    // --- primero recuperan el retraso y lo que sobra recupera la deuda.  ---
    $lineasNota = [];

    if ($compensadoRetraso > 0) {
        $lineasNota[] = "Se descuentan " . minutosAHoras($compensadoRetraso) . " de las horas extra para compensar el retraso acumulado.";
    }

    if ($compensadoDeuda > 0) {
        $lineasNota[] = "Se descuentan " . minutosAHoras($compensadoDeuda) . " de las horas extra para compensar el tiempo de deuda (salidas antes de hora).";
    }

    if (!empty($lineasNota)) {
        $lineasNota[] = "Quedan pendientes -  Retraso: " .
            ($retrasoNeto > 0 ? minutosAHoras($retrasoNeto) : "0 min (ya compensado)") .
            "   |   Extra: " .
            ($extraNeto > 0 ? minutosAHoras($extraNeto) : "0 min (ya compensado)") .
            "   |   Deuda: " .
            ($deudaNeta > 0 ? minutosAHoras($deudaNeta) : "0 min (ya compensada)");

        $alturaNota = 5 + (count($lineasNota) * 5.5);
        $yNota = $pdf->GetY();

        if ($yNota + $alturaNota > 270) {
            $pdf->AddPage();
            $yNota = $pdf->GetY();
        }

        $pdf->SetFillColor(244, 247, 251);
        $pdf->Rect(15, $yNota, 180, $alturaNota, "F");
        $pdf->SetDrawColor(21, 101, 192);
        $pdf->SetLineWidth(0.8);
        $pdf->Line(15, $yNota, 15, $yNota + $alturaNota);

        $yLinea = $yNota + 2.5;
        foreach ($lineasNota as $i => $linea) {
            $pdf->SetXY(19, $yLinea);
            $pdf->SetFont("Helvetica", "B", $i === count($lineasNota) - 1 ? 8 : 8.5);
            $pdf->SetTextColor(22, 32, 46);
            $pdf->Cell(170, 5, t($linea));
            $yLinea += 5.5;
        }

        $pdf->SetLineWidth(0.2);
    }
}

// Nombre de archivo limpio: le quita tildes al nombre del empleado (en vez
// de convertirlas en guiones bajos) para que quede legible, ej: "Jose_Perez".
function nombreArchivoLimpio($texto)
{
    $sinTildes = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", (string) $texto);
    return preg_replace('/[^A-Za-z0-9]+/', '_', $sinTildes);
}

$nombreArchivo = "Reporte_" . nombreArchivoLimpio($empleado["nombre"]) . "_" . $desde . "_a_" . $hasta . ".pdf";

$pdf->Output("D", $nombreArchivo);
exit;
