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

$totalRetraso = 0;
$totalExtra = 0;
foreach ($filas as $f) {
    $totalRetraso += (int) $f["minutos_retraso"];
    $totalExtra += (int) $f["minutos_extra"];
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reporte de Asistencia - <?= escapar($empleado["nombre"]) ?></title>

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1565C0;
            --primary-dark: #0D47A1;
            --primary-light: #E9F2FE;
            --success: #1E7A34;
            --warning: #B7791F;
            --danger: #C62839;
            --danger-light: #FDEAEC;
            --extra: #A0710A;
            --extra-light: #FFF6E0;
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
            --font-display: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
            --font-body: "Inter", system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-body);
            color: var(--ink);
            background: var(--canvas);
            margin: 0;
            padding: 30px 16px;
        }

        .hoja {
            max-width: 850px;
            margin: 0 auto;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 40px 46px;
            box-shadow: var(--shadow-card);
        }

        .encabezado {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 20px;
            margin-bottom: 24px;
        }

        .encabezado .marca {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .encabezado img {
            height: 44px;
            width: auto;
        }

        .encabezado .marca .nombre-empresa {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 14px;
            color: var(--ink);
        }

        .encabezado .titulo-reporte {
            text-align: right;
        }

        .encabezado h1 {
            font-family: var(--font-display);
            font-size: 19px;
            font-weight: 700;
            margin: 0 0 4px;
            color: var(--ink);
        }

        .encabezado .subt {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin: 0;
        }

        .datos-empleado {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 24px;
            background: var(--primary-light);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 24px;
            font-size: 13.5px;
            color: #0F3D73;
        }

        .datos-empleado .etiqueta {
            color: var(--ink-soft);
            font-weight: 600;
            margin-right: 6px;
        }

        .tabla-contenedor {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            overflow: hidden;
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            text-align: left;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--ink-soft);
            background: #FAFBFD;
            border-bottom: 1px solid var(--line);
            padding: 12px 14px;
        }

        tbody td {
            padding: 11px 14px;
            font-size: 13.5px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .etiqueta-tarde {
            display: inline-block;
            margin-top: 3px;
            font-size: 11px;
            font-weight: 700;
            color: var(--danger);
            background: var(--danger-light);
            padding: 2px 7px;
            border-radius: 5px;
        }

        .etiqueta-extra {
            display: inline-block;
            margin-top: 3px;
            font-size: 11px;
            font-weight: 700;
            color: var(--extra);
            background: var(--extra-light);
            padding: 2px 7px;
            border-radius: 5px;
        }

        .sin-dato {
            color: var(--ink-faint);
        }

        .resumen {
            display: flex;
            gap: 14px;
            margin-top: 10px;
        }

        .resumen .caja {
            flex: 1;
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            text-align: center;
        }

        .resumen .caja .numero {
            font-family: var(--font-display);
            font-size: 18px;
            font-weight: 800;
        }

        .resumen .caja .etiq {
            font-size: 11.5px;
            color: var(--ink-soft);
            margin-top: 2px;
        }

        .resumen .caja.retraso { background: var(--danger-light); }
        .resumen .caja.retraso .numero { color: var(--danger); }
        .resumen .caja.extra { background: var(--extra-light); }
        .resumen .caja.extra .numero { color: var(--extra); }

        .pie {
            margin-top: 28px;
            font-size: 11px;
            color: var(--ink-faint);
            text-align: center;
        }

        .barra-acciones {
            max-width: 850px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-imprimir {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            box-shadow: 0 6px 16px -6px rgba(21,101,192,0.55);
            transition: background .15s ease, transform .15s ease;
        }

        .btn-imprimir:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .barra-acciones {
                display: none;
            }
            .hoja {
                box-shadow: none;
                border-radius: 0;
                border: none;
                padding: 0;
                max-width: 100%;
            }
        }
    </style>

</head>

<body>

    <div class="barra-acciones">
        <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir / Guardar como PDF</button>
    </div>

    <div class="hoja">

        <div class="encabezado">
            <div class="marca">
                <img src="img/logo-verde.png" alt="Grupo Bellaforma S.A.S.">
                <span class="nombre-empresa">Grupo Bellaforma S.A.S.</span>
            </div>
            <div class="titulo-reporte">
                <h1>Reporte de Asistencia</h1>
                <p class="subt">Generado el <?= date("d/m/Y h:i A") ?></p>
            </div>
        </div>

        <div class="datos-empleado">
            <div><span class="etiqueta">Empleado:</span><?= escapar($empleado["nombre"]) ?></div>
            <div><span class="etiqueta">Identificación:</span><?= escapar($empleado["identificacion"]) ?></div>
            <div><span class="etiqueta">Cargo:</span><?= escapar($empleado["cargo"]) ?></div>
            <div><span class="etiqueta">Periodo:</span><?= date("d/m/Y", strtotime($desde)) ?> — <?= date("d/m/Y", strtotime($hasta)) ?></div>
        </div>

        <div class="tabla-contenedor">
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Hora de entrada</th>
                    <th>Hora de salida</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filas)): ?>
                    <tr>
                        <td colspan="3" style="text-align:center; color:#B7BFCB; padding:20px;">
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
        </div>

        <?php if (!empty($filas)): ?>
            <div class="resumen">
                <div class="caja retraso">
                    <div class="numero"><?= minutosAHoras($totalRetraso) ?></div>
                    <div class="etiq">Total retraso del periodo</div>
                </div>
                <div class="caja extra">
                    <div class="numero"><?= minutosAHoras($totalExtra) ?></div>
                    <div class="etiq">Total horas extra del periodo</div>
                </div>
            </div>
        <?php endif; ?>

        <div class="pie">
            Grupo Bella Forma S.A.S. — Sistema de Asistencia y Control
        </div>

    </div>

</body>

</html>
