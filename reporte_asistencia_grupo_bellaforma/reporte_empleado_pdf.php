<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

require_once __DIR__ . "/vendor/autoload.php";
require_once "conexion.php";

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set("America/Bogota");

function escapar($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
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
    SELECT fecha, hora_entrada, hora_salida, minutos_retraso, minutos_extra
    FROM asistencias
    WHERE empleado_id = ? AND fecha BETWEEN ? AND ?
    ORDER BY fecha ASC
");
$stmt->bind_param("iss", $empleadoId, $desde, $hasta);
$stmt->execute();
$filas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalRetrasoBruto = 0;
$totalExtraBruto = 0;
foreach ($filas as $f) {
    $totalRetrasoBruto += (int) $f["minutos_retraso"];
    $totalExtraBruto += (int) $f["minutos_extra"];
}

// --- Misma lógica de compensación que usa Liquidación: el tiempo extra ---
// --- primero recupera el retraso. Lo que sobre de cada lado queda neto.  ---
$compensado = min($totalRetrasoBruto, $totalExtraBruto);
$retrasoNeto = max(0, $totalRetrasoBruto - $totalExtraBruto);
$extraNeto = max(0, $totalExtraBruto - $totalRetrasoBruto);

// --- Logo embebido en base64 (evita problemas de rutas dentro de Dompdf) ---
$logoPath = __DIR__ . "/img/logo-verde.png";
$logoBase64 = file_exists($logoPath)
    ? "data:image/png;base64," . base64_encode(file_get_contents($logoPath))
    : "";

// ==========================================================================
// Construcción del HTML. Dompdf soporta CSS limitado (sin flexbox, sin
// grid, sin variables CSS), así que aquí se usan tablas y colores literales.
// ==========================================================================
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: "DejaVu Sans", sans-serif;
        color: #16202E;
        font-size: 11.5px;
    }

    .encabezado-tabla {
        width: 100%;
        border-bottom: 1.5px solid #E3E8F0;
        padding-bottom: 14px;
        margin-bottom: 18px;
    }

    .encabezado-tabla td {
        vertical-align: middle;
    }

    .logo-img {
        height: 36px;
    }

    .nombre-empresa {
        font-size: 12px;
        font-weight: bold;
        padding-left: 10px;
    }

    .titulo-reporte {
        text-align: right;
    }

    .titulo-reporte h1 {
        font-size: 16px;
        margin: 0 0 3px;
        color: #16202E;
    }

    .titulo-reporte p {
        font-size: 10px;
        color: #5B6676;
        margin: 0;
    }

    .datos-empleado {
        width: 100%;
        background-color: #E9F2FE;
        border-radius: 6px;
        margin-bottom: 18px;
    }

    .datos-empleado td {
        padding: 8px 14px;
        font-size: 11px;
        color: #0F3D73;
    }

    .etiqueta {
        color: #5B6676;
        font-weight: bold;
    }

    table.tabla-dias {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 18px;
    }

    table.tabla-dias th {
        text-align: left;
        font-size: 9.5px;
        text-transform: uppercase;
        color: #5B6676;
        background-color: #FAFBFD;
        border-bottom: 1px solid #E3E8F0;
        padding: 8px 10px;
    }

    table.tabla-dias td {
        padding: 7px 10px;
        font-size: 10.5px;
        border-bottom: 1px solid #E3E8F0;
        vertical-align: top;
    }

    .etiqueta-tarde {
        color: #C62839;
        font-weight: bold;
        font-size: 9.5px;
    }

    .etiqueta-extra {
        color: #A0710A;
        font-weight: bold;
        font-size: 9.5px;
    }

    .sin-dato {
        color: #B7BFCB;
    }

    table.resumen-tabla {
        width: 100%;
        margin-bottom: 12px;
    }

    table.resumen-tabla td {
        width: 50%;
        text-align: center;
        padding: 10px;
        font-size: 10px;
        color: #5B6676;
    }

    .caja-retraso {
        background-color: #FDEAEC;
        border-radius: 6px;
    }

    .caja-extra {
        background-color: #FFF6E0;
        border-radius: 6px;
    }

    .numero-resumen {
        font-size: 15px;
        font-weight: bold;
        display: block;
        margin-bottom: 2px;
    }

    .color-retraso { color: #C62839; }
    .color-extra { color: #A0710A; }

    .nota-compensacion {
        background-color: #F4F7FB;
        border-left: 3px solid #1565C0;
        padding: 10px 14px;
        font-size: 10px;
        color: #16202E;
        margin-bottom: 6px;
    }

    .pie {
        margin-top: 24px;
        font-size: 9px;
        color: #B7BFCB;
        text-align: center;
    }
</style>
</head>
<body>

    <table class="encabezado-tabla">
        <tr>
            <td style="width:50%;">
                <?php if ($logoBase64): ?>
                    <img src="<?= $logoBase64 ?>" class="logo-img">
                <?php endif; ?>
                <span class="nombre-empresa">Grupo Bella Forma S.A.S.</span>
            </td>
            <td class="titulo-reporte" style="width:50%;">
                <h1>Reporte de Asistencia</h1>
                <p>Generado el <?= date("d/m/Y h:i A") ?></p>
            </td>
        </tr>
    </table>

    <table class="datos-empleado">
        <tr>
            <td style="width:50%;"><span class="etiqueta">Empleado:</span> <?= escapar($empleado["nombre"]) ?></td>
            <td style="width:50%;"><span class="etiqueta">Identificación:</span> <?= escapar($empleado["identificacion"]) ?></td>
        </tr>
        <tr>
            <td><span class="etiqueta">Cargo:</span> <?= escapar($empleado["cargo"]) ?></td>
            <td><span class="etiqueta">Periodo:</span> <?= date("d/m/Y", strtotime($desde)) ?> — <?= date("d/m/Y", strtotime($hasta)) ?></td>
        </tr>
    </table>

    <table class="tabla-dias">
        <thead>
            <tr>
                <th style="width:34%;">Fecha</th>
                <th style="width:33%;">Hora de entrada</th>
                <th style="width:33%;">Hora de salida</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($filas)): ?>
                <tr>
                    <td colspan="3" style="text-align:center; color:#B7BFCB; padding:16px;">
                        No hay registros de asistencia en este rango de fechas.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($filas as $f): ?>
                    <?php
                    $retraso = (int) $f["minutos_retraso"];
                    $extra = (int) $f["minutos_extra"];
                    $horaEntrada = horaBonita($f["hora_entrada"]);
                    $horaSalida = horaBonita($f["hora_salida"]);
                    ?>
                    <tr>
                        <td><?= nombreDiaFecha($f["fecha"]) ?></td>
                        <td>
                            <?php if ($horaEntrada): ?>
                                <?= $horaEntrada ?>
                                <?php if ($retraso > 0): ?>
                                    <br><span class="etiqueta-tarde">Tarde: <?= minutosAHoras($retraso) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sin-dato">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($horaSalida): ?>
                                <?= $horaSalida ?>
                                <?php if ($extra > 0): ?>
                                    <br><span class="etiqueta-extra">Extra: <?= minutosAHoras($extra) ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="sin-dato">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if (!empty($filas)): ?>
        <table class="resumen-tabla">
            <tr>
                <td class="caja-retraso">
                    <span class="numero-resumen color-retraso"><?= minutosAHoras($totalRetrasoBruto) ?></span>
                    Total retraso del periodo
                </td>
                <td class="caja-extra">
                    <span class="numero-resumen color-extra"><?= minutosAHoras($totalExtraBruto) ?></span>
                    Total horas extra del periodo
                </td>
            </tr>
        </table>

        <?php if ($compensado > 0): ?>
            <div class="nota-compensacion">
                ⚠ Se descuentan <strong><?= minutosAHoras($compensado) ?></strong> de las horas extra para compensar el retraso acumulado.<br>
                <strong>Quedan pendientes:</strong>
                Retraso: <?= $retrasoNeto > 0 ? minutosAHoras($retrasoNeto) : "0 min (ya compensado)" ?>
                &nbsp;|&nbsp;
                Extra: <?= $extraNeto > 0 ? minutosAHoras($extraNeto) : "0 min (ya compensado)" ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="pie">
        Grupo Bella Forma S.A.S. — Sistema de Asistencia y Control
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// --- Generar y descargar el PDF ---
$opciones = new Options();
$opciones->set("isRemoteEnabled", false);
$opciones->set("defaultFont", "DejaVu Sans");

$dompdf = new Dompdf($opciones);
$dompdf->loadHtml($html);
$dompdf->setPaper("A4", "portrait");
$dompdf->render();

$nombreArchivo = "Reporte_" . preg_replace('/[^A-Za-z0-9_]+/', '_', $empleado["nombre"]) . "_" . $desde . "_a_" . $hasta . ".pdf";

$dompdf->stream($nombreArchivo, ["Attachment" => true]);
exit;
