<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

require_once "conexion.php";

date_default_timezone_set("America/Bogota");

// ==========================================================================
// PARÁMETROS LEGALES COLOMBIA 2026 (Decretos 1469 y 1470 de 2025 - Mintrabajo)
// Fuente: SMMLV y auxilio de transporte vigentes desde el 1 de enero de 2026.
// Actualiza estos 2 valores apenas el Gobierno publique los del año siguiente.
// ==========================================================================
define("SMMLV_2026", 1750905);
define("AUX_TRANSPORTE_2026", 249095);
define("DIAS_MES", 30); // días de referencia para prorratear un salario mensual

// La jornada laboral legal se redujo de 44 a 42 horas semanales a partir del
// 15 de julio de 2026 (Ley 2101 de 2021), lo que sube el valor de la hora:
//   - Antes del 15/jul/2026: 44h/semana -> divisor 240 -> hora ≈ $7.295,44
//   - Desde el 15/jul/2026:  42h/semana -> divisor 210 -> hora ≈ $8.337,64
// Estas fórmulas usan el valor VIGENTE hoy. Si necesitas liquidar un periodo
// completo ANTERIOR al 15/jul/2026, cambia HORAS_MES_SMMLV a 240 antes de
// generar esa liquidación.
define("HORAS_MES_SMMLV", 210);
define("VALOR_HORA_SMMLV", round(SMMLV_2026 / HORAS_MES_SMMLV, 2));
define("VALOR_MINUTO_SMMLV", VALOR_HORA_SMMLV / 60);

define("RECARGO_HORA_EXTRA", 1.25); // 25% de recargo legal, hora extra diurna

// --- Producción: tarifa fija diaria (no por SMMLV) ---
define("VALOR_DIA_PRODUCCION", 60000);
define("MINUTOS_JORNADA_PRODUCCION", 575); // 7:30am a 5:05pm = 9h35
define("VALOR_MINUTO_PRODUCCION", VALOR_DIA_PRODUCCION / MINUTOS_JORNADA_PRODUCCION);

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

function pesos($valor)
{
    return "$" . number_format((float) $valor, 0, ",", ".");
}

/**
 * Calcula el valor a pagar de un empleado en el periodo, según su cargo.
 * Devuelve null cuando falta información necesaria (p. ej. un empleado de
 * salario mensual sin un rango de fechas seleccionado, ya que sin eso no
 * se puede prorratear el salario).
 */
function calcularPago($cargo, $diasConRegistro, $diasPeriodo, $retrasoNeto, $extraNeto, $deudaNeta, $extraBruto, $minutosTrabajados)
{
    $detalle = [];
    $total = 0;

    if ($cargo === "Producción") {

        $base = $diasConRegistro * VALOR_DIA_PRODUCCION;
        $detalle[] = ["label" => "Días trabajados ({$diasConRegistro} x " . pesos(VALOR_DIA_PRODUCCION) . ")", "valor" => $base];
        $total += $base;

        if ($extraNeto > 0) {
            $valorExtra = round($extraNeto * VALOR_MINUTO_PRODUCCION * RECARGO_HORA_EXTRA);
            $detalle[] = ["label" => "+ Horas extra (" . minutosAHoras($extraNeto) . ", recargo 25%)", "valor" => $valorExtra];
            $total += $valorExtra;
        }

        $descontar = $retrasoNeto + $deudaNeta;
        if ($descontar > 0) {
            $valorDescuento = round($descontar * VALOR_MINUTO_PRODUCCION);
            $detalle[] = ["label" => "- Retraso / deuda (" . minutosAHoras($descontar) . ")", "valor" => -$valorDescuento];
            $total -= $valorDescuento;
        }

    } elseif ($cargo === "Temporales") {

        // Se paga estrictamente por tiempo trabajado: si llegó tarde o salió
        // antes, ya cobra menos minutos de forma automática (no se resta
        // retraso/deuda aparte, porque eso ya está reflejado en menos horas).
        $minutosOrdinarios = max(0, $minutosTrabajados - $extraBruto);
        $pagoOrdinario = round($minutosOrdinarios * VALOR_MINUTO_SMMLV);
        $detalle[] = ["label" => "Horas laboradas (" . minutosAHoras($minutosOrdinarios) . ")", "valor" => $pagoOrdinario];
        $total += $pagoOrdinario;

        if ($extraBruto > 0) {
            $valorExtra = round($extraBruto * VALOR_MINUTO_SMMLV * RECARGO_HORA_EXTRA);
            $detalle[] = ["label" => "+ Horas extra (" . minutosAHoras($extraBruto) . ", recargo 25%)", "valor" => $valorExtra];
            $total += $valorExtra;
        }

        $auxilio = round((AUX_TRANSPORTE_2026 / DIAS_MES) * $diasConRegistro);
        $detalle[] = ["label" => "+ Auxilio de transporte ({$diasConRegistro} días)", "valor" => $auxilio];
        $total += $auxilio;

    } else {

        // Salario mínimo mensual + auxilio de transporte, prorrateados por
        // el rango de fechas filtrado. Sin un rango no se puede prorratear.
        if ($diasPeriodo === null) {
            return null;
        }

        $base = round((SMMLV_2026 / DIAS_MES) * $diasPeriodo);
        $auxilio = round((AUX_TRANSPORTE_2026 / DIAS_MES) * $diasPeriodo);
        $detalle[] = ["label" => "Salario base ({$diasPeriodo} días del periodo)", "valor" => $base];
        $detalle[] = ["label" => "+ Auxilio de transporte", "valor" => $auxilio];
        $total += $base + $auxilio;

        if ($extraNeto > 0) {
            $valorExtra = round($extraNeto * VALOR_MINUTO_SMMLV * RECARGO_HORA_EXTRA);
            $detalle[] = ["label" => "+ Horas extra (" . minutosAHoras($extraNeto) . ", recargo 25%)", "valor" => $valorExtra];
            $total += $valorExtra;
        }

        $descontar = $retrasoNeto + $deudaNeta;
        if ($descontar > 0) {
            $valorDescuento = round($descontar * VALOR_MINUTO_SMMLV);
            $detalle[] = ["label" => "- Retraso / deuda (" . minutosAHoras($descontar) . ")", "valor" => -$valorDescuento];
            $total -= $valorDescuento;
        }
    }

    return ["total" => $total, "detalle" => $detalle];
}

$mensaje = "";
$tipoMensaje = "exito";

// --- PROCESAR LIQUIDACIÓN (marcar como pagado) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"]) && $_POST["accion"] === "liquidar") {

    $empleadoId = (int) ($_POST["empleado_id"] ?? 0);
    $desdeForm = $_POST["desde"] ?? "";
    $hastaForm = $_POST["hasta"] ?? "";

    if ($empleadoId > 0) {

        $sqlLiquidar = "
            UPDATE asistencias
            SET liquidado = 1,
                fecha_liquidacion = NOW()
            WHERE empleado_id = ?
              AND liquidado = 0
        ";

        $paramsL = [$empleadoId];
        $tiposL = "i";

        if (!empty($desdeForm) && !empty($hastaForm)) {
            $sqlLiquidar .= " AND fecha BETWEEN ? AND ?";
            $paramsL[] = $desdeForm;
            $paramsL[] = $hastaForm;
            $tiposL .= "ss";
        }

        $stmtL = $conexion->prepare($sqlLiquidar);

        if ($stmtL) {
            $stmtL->bind_param($tiposL, ...$paramsL);
            $stmtL->execute();
            $stmtL->close();

            $mensaje = "Se liquidó correctamente el periodo de ese empleado. Sus contadores de retraso, extra y deuda vuelven a cero para el próximo periodo (sin borrar nada del Historial Completo).";
        } else {
            $mensaje = "No se pudo liquidar el periodo.";
            $tipoMensaje = "error";
        }
    }

    // Guardamos el mensaje y redirigimos para evitar reenvío del formulario al recargar
    $_SESSION["mensaje_liquidacion"] = $mensaje;
    $_SESSION["tipo_mensaje_liquidacion"] = $tipoMensaje;

    $redirParams = [];
    if (!empty($_POST["desde"])) {
        $redirParams[] = "desde=" . urlencode($_POST["desde"]);
    }
    if (!empty($_POST["hasta"])) {
        $redirParams[] = "hasta=" . urlencode($_POST["hasta"]);
    }

    $queryString = !empty($redirParams) ? ("?" . implode("&", $redirParams)) : "";

    header("Location: liquidacion.php" . $queryString);
    exit;
}

if (isset($_SESSION["mensaje_liquidacion"])) {
    $mensaje = $_SESSION["mensaje_liquidacion"];
    $tipoMensaje = $_SESSION["tipo_mensaje_liquidacion"] ?? "exito";
    unset($_SESSION["mensaje_liquidacion"], $_SESSION["tipo_mensaje_liquidacion"]);
}

// --- CONSULTAR PENDIENTES POR LIQUIDAR ---
$desde = $_GET["desde"] ?? "";
$hasta = $_GET["hasta"] ?? "";
$buscar = trim($_GET["buscar"] ?? "");

$diasPeriodo = null;
if (!empty($desde) && !empty($hasta)) {
    $diasPeriodo = (int) ((strtotime($hasta) - strtotime($desde)) / 86400) + 1;
}

$sql = "
    SELECT
        e.id,
        e.nombre,
        e.identificacion,
        e.cargo,
        COUNT(a.id) AS total_dias,
        COALESCE(SUM(a.minutos_retraso), 0) AS total_retraso,
        COALESCE(SUM(a.minutos_extra), 0) AS total_extra,
        COALESCE(SUM(a.minutos_deuda), 0) AS total_deuda,
        COALESCE(SUM(
            CASE
                WHEN a.hora_entrada IS NOT NULL AND a.hora_salida IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, CONCAT(a.fecha, ' ', a.hora_entrada), CONCAT(a.fecha, ' ', a.hora_salida))
                     - CASE
                         WHEN a.hora_salida_almuerzo IS NOT NULL AND a.hora_entrada_almuerzo IS NOT NULL
                         THEN TIMESTAMPDIFF(MINUTE, CONCAT(a.fecha, ' ', a.hora_salida_almuerzo), CONCAT(a.fecha, ' ', a.hora_entrada_almuerzo))
                         ELSE 0
                       END
                ELSE 0
            END
        ), 0) AS total_minutos_trabajados
    FROM empleados e
    INNER JOIN asistencias a ON a.empleado_id = e.id
";

$params = [];
$tipos = "";
$where = ["e.activo = 1", "a.liquidado = 0"];

if (!empty($desde) && !empty($hasta)) {
    $where[] = "a.fecha BETWEEN ? AND ?";
    $params[] = $desde;
    $params[] = $hasta;
    $tipos .= "ss";
}

if (!empty($buscar)) {
    $where[] = "(e.nombre LIKE ? OR e.identificacion LIKE ?)";
    $like = "%" . $buscar . "%";
    $params[] = $like;
    $params[] = $like;
    $tipos .= "ss";
}

$sql .= " WHERE " . implode(" AND ", $where);
$sql .= " GROUP BY e.id, e.nombre, e.identificacion, e.cargo ORDER BY e.nombre ASC";

$stmt = $conexion->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
} else {
    $resultado = null;
}

// Procesamos todas las filas de una vez (las necesitamos para la tabla Y
// para el total general de nómina al final).
$filasProcesadas = [];
$totalNomina = 0;
$faltaRangoParaAlguno = false;

if ($resultado) {
    while ($fila = $resultado->fetch_assoc()) {

        $retrasoBruto = (int) $fila["total_retraso"];
        $extraBruto = (int) $fila["total_extra"];
        $deudaBruta = (int) $fila["total_deuda"];
        $diasConRegistro = (int) $fila["total_dias"];
        $minutosTrabajados = (int) $fila["total_minutos_trabajados"];

        // El tiempo extra primero recupera el retraso, y lo que sobre recupera la deuda.
        $extraDisponible = $extraBruto;

        if ($extraDisponible >= $retrasoBruto) {
            $extraDisponible -= $retrasoBruto;
            $retrasoNeto = 0;
        } else {
            $retrasoNeto = $retrasoBruto - $extraDisponible;
            $extraDisponible = 0;
        }

        if ($extraDisponible >= $deudaBruta) {
            $extraDisponible -= $deudaBruta;
            $deudaNeta = 0;
        } else {
            $deudaNeta = $deudaBruta - $extraDisponible;
            $extraDisponible = 0;
        }

        $extraNeto = $extraDisponible;

        $pago = calcularPago(
            $fila["cargo"],
            $diasConRegistro,
            $diasPeriodo,
            $retrasoNeto,
            $extraNeto,
            $deudaNeta,
            $extraBruto,
            $minutosTrabajados
        );

        if ($pago === null) {
            $faltaRangoParaAlguno = true;
        } else {
            $totalNomina += $pago["total"];
        }

        $fila["retrasoNeto"] = $retrasoNeto;
        $fila["extraNeto"] = $extraNeto;
        $fila["deudaNeta"] = $deudaNeta;
        $fila["pago"] = $pago;

        $filasProcesadas[] = $fila;
    }
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Liquidación de Nómina - Grupo Bella Forma S.A.S.
    </title>

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">

    <link rel="stylesheet" href="css/admin.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1565C0;
            --primary-dark: #0D47A1;
            --primary-light: #E9F2FE;
            --success: #1E7A34;
            --success-light: #E9F7EC;
            --warning: #B7791F;
            --warning-light: #FFF6E0;
            --danger: #C62839;
            --danger-light: #FDEAEC;
            --extra: #A0710A;
            --deuda: #1462C4;
            --ink: #16202E;
            --ink-soft: #5B6676;
            --ink-faint: #93A0B1;
            --line: #E3E8F0;
            --surface: #FFFFFF;
            --canvas: #F4F7FB;
            --radius-lg: 16px;
            --radius-md: 10px;
            --radius-sm: 7px;
            --shadow-card: 0 1px 2px rgba(22,32,46,0.04), 0 8px 24px -12px rgba(22,32,46,0.10);
            --shadow-pop: 0 12px 32px -8px rgba(22,32,46,0.22);
            --font-display: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            --font-body: "Inter", system-ui, -apple-system, sans-serif;
        }

        body {
            background: var(--canvas);
            font-family: var(--font-body);
            color: var(--ink);
        }

        .main-content {
            animation: subirEntrada .45s cubic-bezier(.2,.7,.3,1) both;
        }

        @keyframes subirEntrada {
            from { opacity: 0; transform: translateY(10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (prefers-reduced-motion: reduce) {
            .main-content { animation: none; }
        }

        /* ===== ENCABEZADO ===== */
        .top-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding-bottom: 18px;
            margin-bottom: 22px;
            border-bottom: 1px solid var(--line);
        }

        .top-header .titulo-bloque {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .top-header .icono-titulo {
            width: 46px;
            height: 46px;
            border-radius: var(--radius-md);
            background: linear-gradient(145deg, var(--primary), var(--primary-dark));
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 6px 16px -4px rgba(21,101,192,0.45);
            flex-shrink: 0;
        }

        .top-header h1 {
            font-family: var(--font-display);
            font-size: 22px;
            font-weight: 700;
            color: var(--ink);
            margin: 0;
            line-height: 1.2;
        }

        .top-header .subtitulo {
            font-size: 13px;
            color: var(--ink-soft);
            margin: 2px 0 0;
        }

        .btn-volver {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            text-decoration: none;
            color: var(--ink-soft);
            background: var(--surface);
            border: 1px solid var(--line);
            padding: 9px 16px 9px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            box-shadow: var(--shadow-card);
            transition: transform .15s ease, color .15s ease, border-color .15s ease, box-shadow .15s ease;
        }

        .btn-volver .flecha {
            transition: transform .2s ease;
            font-size: 15px;
            line-height: 1;
        }

        .btn-volver:hover {
            color: var(--primary-dark);
            border-color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px -6px rgba(21,101,192,0.35);
        }

        .btn-volver:hover .flecha {
            transform: translateX(-3px);
        }

        /* ===== TARJETAS DE NOTA ===== */
        .nota-liquidacion,
        .nota-legal {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border: none;
            border-left: none;
            border-radius: var(--radius-md);
            padding: 14px 18px;
            margin-bottom: 14px;
            font-size: 13.5px;
            line-height: 1.55;
        }

        .nota-liquidacion {
            background: var(--primary-light);
            color: #0F3D73;
        }

        .nota-legal {
            background: var(--warning-light);
            color: #6B4C0A;
        }

        .nota-liquidacion .icono-nota,
        .nota-legal .icono-nota {
            flex-shrink: 0;
            font-size: 17px;
            line-height: 1.4;
        }

        .nota-liquidacion strong,
        .nota-legal strong {
            font-weight: 700;
        }

        /* ===== MENSAJES ===== */
        .mensaje-liq {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-weight: 600;
            font-size: 13.5px;
            animation: subirEntrada .35s ease both;
        }

        .mensaje-liq.exito {
            background: var(--success-light);
            color: var(--success);
        }

        .mensaje-liq.error {
            background: var(--danger-light);
            color: var(--danger);
        }

        /* ===== FILTROS ===== */
        .filtros {
            display: flex;
            gap: 14px;
            align-items: flex-end;
            flex-wrap: wrap;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 18px 20px;
            margin-bottom: 22px;
            box-shadow: var(--shadow-card);
        }

        .filtros > div {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filtros label {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink-soft);
            text-transform: none;
        }

        .filtros input {
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            padding: 9px 12px;
            font-size: 13.5px;
            font-family: var(--font-body);
            color: var(--ink);
            background: var(--canvas);
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
            min-width: 170px;
        }

        .filtros input:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--surface);
            box-shadow: 0 0 0 3px rgba(21,101,192,0.14);
        }

        .btn-filtrar {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 22px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            box-shadow: 0 6px 16px -6px rgba(21,101,192,0.55);
            transition: background .15s ease, transform .15s ease, box-shadow .15s ease;
        }

        .btn-filtrar:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-filtrar:disabled {
            opacity: .7;
            cursor: default;
            transform: none;
        }

        /* ===== SPINNER ===== */
        .spinner {
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            display: inline-block;
            animation: girar .7s linear infinite;
        }

        .spinner.oscuro {
            border: 2px solid rgba(21,101,192,0.25);
            border-top-color: var(--primary);
        }

        @keyframes girar {
            to { transform: rotate(360deg); }
        }

        /* ===== TABLA ===== */
        .tabla-contenedor {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }

        .tabla {
            width: 100%;
            border-collapse: collapse;
        }

        .tabla thead th {
            text-align: left;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--ink-soft);
            text-transform: uppercase;
            letter-spacing: .04em;
            background: #FAFBFD;
            padding: 13px 16px;
            border-bottom: 1px solid var(--line);
            white-space: nowrap;
        }

        .tabla tbody td {
            padding: 14px 16px;
            font-size: 13.5px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
        }

        .tabla tbody tr:last-child td {
            border-bottom: none;
        }

        .tabla tbody tr {
            transition: background .12s ease;
        }

        .tabla tbody tr:hover {
            background: #FAFBFE;
        }

        .pill-cargo {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: #EEF2F8;
            color: var(--ink-soft);
        }

        .sin-resultados {
            text-align: center;
            padding: 48px 20px !important;
            color: var(--ink-faint);
            font-size: 14px;
        }

        .color-retraso {
            color: var(--danger) !important;
            font-weight: 700;
        }

        .color-extra {
            color: var(--extra) !important;
            font-weight: 700;
        }

        .color-deuda {
            color: var(--deuda) !important;
            font-weight: 700;
        }

        .valor-pagar {
            font-weight: 700;
            color: var(--success);
            font-size: 14px;
            font-variant-numeric: tabular-nums;
        }

        .btn-detalle {
            display: block;
            margin-top: 3px;
            background: none;
            border: none;
            color: var(--primary);
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            padding: 0;
            transition: color .15s ease;
        }

        .btn-detalle:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .sin-valor {
            color: var(--ink-faint);
            font-size: 12px;
            line-height: 1.4;
        }

        .btn-liquidar {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 15px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 700;
            transition: background .15s ease, transform .15s ease;
        }

        .btn-liquidar:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-liquidar:disabled {
            opacity: .7;
            cursor: default;
            transform: none;
        }

        /* ===== TOTAL NÓMINA ===== */
        .total-nomina-caja {
            margin-top: 20px;
            padding: 20px 24px;
            background: linear-gradient(135deg, var(--success-light), #F2FBF3);
            border: 1px solid #C9E8CE;
            border-radius: var(--radius-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: var(--shadow-card);
        }

        .total-nomina-caja .titulo {
            font-weight: 700;
            color: var(--success);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .total-nomina-caja .titulo .icono-total {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: var(--success);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .total-nomina-caja .aviso-parcial {
            display: block;
            font-weight: 500;
            font-size: 11.5px;
            color: #4C7A54;
            margin-top: 2px;
        }

        .total-nomina-caja .valor {
            font-family: var(--font-display);
            font-size: 26px;
            font-weight: 800;
            color: var(--success);
            font-variant-numeric: tabular-nums;
        }

        /* ===== MODAL DETALLE ===== */
        .modal-contenido h3 {
            font-family: var(--font-display);
            font-size: 17px;
            margin-bottom: 4px;
        }

        .detalle-linea {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 0;
            border-bottom: 1px solid var(--line);
            font-size: 13.5px;
            color: var(--ink-soft);
            font-variant-numeric: tabular-nums;
        }

        .detalle-linea.total {
            border-bottom: none;
            border-top: 2px solid var(--ink);
            margin-top: 8px;
            padding-top: 12px;
            font-weight: 700;
            font-size: 15.5px;
            color: var(--ink);
        }

        .boton.boton-secundario {
            background: var(--canvas);
            color: var(--ink-soft);
            border: 1px solid var(--line);
            padding: 9px 18px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: background .15s ease;
        }

        .boton.boton-secundario:hover {
            background: #ECEFF4;
        }

        @media (max-width: 720px) {
            .top-header { align-items: flex-start; }
            .filtros { flex-direction: column; align-items: stretch; }
            .filtros input { min-width: 0; width: 100%; }
        }
    </style>

</head>

<body>

    <div class="container-admin">

        <div class="main-content" style="margin: 0 auto; max-width: 1350px; width: 100%;">

            <div class="top-header">
                <div class="titulo-bloque">
                    <div class="icono-titulo">💰</div>
                    <div>
                        <h1>Liquidación de Nómina</h1>
                        <p class="subtitulo">Calcula y liquida lo pendiente de cada empleado por periodo</p>
                    </div>
                </div>
                <a href="admin.php" class="btn-volver">
                    <span class="flecha">←</span>
                    Volver al panel
                </a>
            </div>

            <div class="content">

                <div class="nota-liquidacion">
                    <span class="icono-nota">ℹ️</span>
                    <span>
                        Aquí solo se cuentan los minutos <strong>pendientes por liquidar</strong> (los que aún no has pagado/descontado). El tiempo extra ya compensa primero el retraso y luego la deuda pendiente — por ejemplo, 30 min de retraso con 45 min de extra quedan mostrados como 15 min de extra y 0 de retraso. Si filtras por fechas (ej. del 1 al 15), el botón "Liquidar" solo marca como pagados los registros de <strong>ese rango de fechas</strong> para ese empleado. Nada se borra: en <strong>Historial Completo</strong> siempre vas a seguir viendo todo (sin compensar), liquidado o no.
                    </span>
                </div>

                <div class="nota-legal">
                    <span class="icono-nota">⚠️</span>
                    <span>
                        El valor a pagar es una <strong>ayuda de cálculo</strong>, no un reemplazo de tu contador(a). Usa el SMMLV y auxilio de transporte 2026 ($<?= number_format(SMMLV_2026, 0, ",", ".") ?> y $<?= number_format(AUX_TRANSPORTE_2026, 0, ",", ".") ?>, Decretos 1469 y 1470 de 2025) y el recargo legal de hora extra diurna (25%). <strong>No incluye</strong> seguridad social, parafiscales, cesantías, prima, vacaciones, retención en la fuente ni recargos nocturnos/dominicales. Para el salario mensual (Ventas, Administración, y demás cargos que no sean Producción o Temporales) necesitas seleccionar un rango de fechas "Desde/Hasta" para poder prorratear el mes.
                    </span>
                </div>

                <?php if ($mensaje !== ""): ?>
                    <div class="mensaje-liq <?= $tipoMensaje ?>">
                        <?= $tipoMensaje === "exito" ? "✅" : "⚠️" ?>
                        <span><?= escapar($mensaje) ?></span>
                    </div>
                <?php endif; ?>

                <form method="GET" action="liquidacion.php" class="filtros" id="formFiltros">

                    <div>
                        <label for="desde">Desde</label>
                        <input type="date" id="desde" name="desde" value="<?= escapar($desde) ?>">
                    </div>

                    <div>
                        <label for="hasta">Hasta</label>
                        <input type="date" id="hasta" name="hasta" value="<?= escapar($hasta) ?>">
                    </div>

                    <div>
                        <label for="buscar">Empleado</label>
                        <input type="text" id="buscar" name="buscar" placeholder="Nombre o identificación" value="<?= escapar($buscar) ?>">
                    </div>

                    <button type="submit" class="btn-filtrar" id="btnFiltrar">Filtrar</button>

                </form>

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Identificación</th>
                                <th>Cargo</th>
                                <th>Días pendientes</th>
                                <th>Retraso</th>
                                <th>Extra</th>
                                <th>Deuda</th>
                                <th>Valor a pagar</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($filasProcesadas)): ?>
                            <tr>
                                <td colspan="9" class="sin-resultados">
                                    Aquí van a aparecer los empleados con minutos pendientes por liquidar.<br>
                                    Por ahora no hay ninguno en este rango.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($filasProcesadas as $i => $fila): ?>
                                <tr>
                                    <td><?= escapar($fila["nombre"]) ?></td>
                                    <td><?= escapar($fila["identificacion"]) ?></td>
                                    <td><span class="pill-cargo"><?= escapar($fila["cargo"]) ?></span></td>
                                    <td><?= (int) $fila["total_dias"] ?></td>
                                    <td>
                                        <?php if ($fila["retrasoNeto"] > 0): ?>
                                            <span class="color-retraso"><?= minutosAHoras($fila["retrasoNeto"]) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($fila["extraNeto"] > 0): ?>
                                            <span class="color-extra"><?= minutosAHoras($fila["extraNeto"]) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($fila["deudaNeta"] > 0): ?>
                                            <span class="color-deuda"><?= minutosAHoras($fila["deudaNeta"]) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($fila["pago"] === null): ?>
                                            <span class="sin-valor">Selecciona un rango<br>de fechas</span>
                                        <?php else: ?>
                                            <span class="valor-pagar"><?= pesos($fila["pago"]["total"]) ?></span>
                                            <button type="button" class="btn-detalle" onclick="abrirDetalle(<?= $i ?>)">Ver detalle</button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="liquidacion.php" class="form-liquidar" style="display:inline;">
                                            <input type="hidden" name="accion" value="liquidar">
                                            <input type="hidden" name="empleado_id" value="<?= (int) $fila["id"] ?>">
                                            <input type="hidden" name="desde" value="<?= escapar($desde) ?>">
                                            <input type="hidden" name="hasta" value="<?= escapar($hasta) ?>">
                                            <button type="submit" class="btn-liquidar" data-nombre="<?= escapar($fila["nombre"]) ?>">💰 Liquidar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($filasProcesadas)): ?>
                    <div class="total-nomina-caja">
                        <span class="titulo">
                            <span class="icono-total">💵</span>
                            <span>
                                Total nómina de este periodo
                                <?php if ($faltaRangoParaAlguno): ?>
                                    <span class="aviso-parcial">No incluye a quienes tienen salario mensual sin fecha seleccionada</span>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="valor"><?= pesos($totalNomina) ?></span>
                    </div>
                <?php endif; ?>

            </div>

        </div>

    </div>

    <!-- Modal de detalle de pago -->
    <div class="modal" id="modalDetallePago" style="display:none;">
        <div class="modal-contenido" style="max-width:450px;">
            <h3 id="detalleNombreEmpleado">Detalle del pago</h3>
            <div id="detalleLineas"></div>
            <div class="botones-modal" style="display:flex; justify-content:flex-end; margin-top:15px;">
                <button type="button" class="boton boton-secundario" onclick="cerrarDetalle()">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        const detallesPago = <?= json_encode(array_map(function ($f) {
            return [
                "nombre" => $f["nombre"],
                "pago" => $f["pago"],
            ];
        }, $filasProcesadas), JSON_UNESCAPED_UNICODE) ?>;

        function formatoPesos(valor) {
            const signo = valor < 0 ? "-$" : "$";
            return signo + Math.abs(Math.round(valor)).toLocaleString("es-CO");
        }

        function abrirDetalle(indice) {
            const info = detallesPago[indice];
            if (!info || !info.pago) return;

            document.getElementById("detalleNombreEmpleado").textContent = "Detalle del pago — " + info.nombre;

            const contenedor = document.getElementById("detalleLineas");
            contenedor.innerHTML = "";

            info.pago.detalle.forEach(linea => {
                const div = document.createElement("div");
                div.className = "detalle-linea";
                div.innerHTML = "<span>" + linea.label + "</span><span>" + formatoPesos(linea.valor) + "</span>";
                contenedor.appendChild(div);
            });

            const total = document.createElement("div");
            total.className = "detalle-linea total";
            total.innerHTML = "<span>Total a pagar</span><span>" + formatoPesos(info.pago.total) + "</span>";
            contenedor.appendChild(total);

            document.getElementById("modalDetallePago").style.display = "flex";
        }

        function cerrarDetalle() {
            document.getElementById("modalDetallePago").style.display = "none";
        }

        window.addEventListener("click", function (event) {
            const modal = document.getElementById("modalDetallePago");
            if (event.target === modal) {
                cerrarDetalle();
            }
        });

        // ===== ESTADOS DE CARGA =====
        // Al filtrar: el botón muestra un spinner mientras la página recarga con los nuevos resultados.
        document.getElementById("formFiltros").addEventListener("submit", function () {
            const btn = document.getElementById("btnFiltrar");
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Filtrando...';
        });

        // Al liquidar: se confirma primero, y si la persona acepta, el botón muestra spinner
        // mientras se procesa (evita doble clic y avisa que la acción está en curso).
        document.querySelectorAll(".form-liquidar").forEach(function (formulario) {
            formulario.addEventListener("submit", function (evento) {
                const boton = formulario.querySelector(".btn-liquidar");
                const nombre = boton.getAttribute("data-nombre");

                const confirmado = confirm(
                    "¿Liquidar el periodo pendiente de " + nombre + "? " +
                    "Sus minutos pendientes quedarán en cero para el próximo periodo. " +
                    "Esto no borra nada de Historial Completo."
                );

                if (!confirmado) {
                    evento.preventDefault();
                    return;
                }

                boton.disabled = true;
                boton.innerHTML = '<span class="spinner"></span> Liquidando...';
            });
        });
    </script>

</body>

</html>
