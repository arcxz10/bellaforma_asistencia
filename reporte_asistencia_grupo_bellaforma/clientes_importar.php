<?php
/**
 * Importación de clientes desde Excel (.xlsx) o CSV.
 * Pensado para el "Listado de terceros" que exporta Syscafe: el archivo trae títulos arriba
 * (nombre de la empresa, NIT, "LISTADO DE TERCEROS"), filas separadoras (departamento / ciudad)
 * y columnas repetidas (VENDEDOR dos veces). Aquí se busca solo la fila de encabezados,
 * se ignoran los separadores y se toma la primera columna cuando un nombre se repite.
 */
require_once __DIR__ . "/clientes_lib.php";
require_once __DIR__ . "/xlsx_leer.php";

function clienteClaveColumna($h): string
{
    return trim(preg_replace('/[^a-z0-9]+/', "_", strtolower(sinTildes(trim((string) $h)))), "_");
}

/** Convierte una fila de encabezados en [indiceColumna => campo]. Si un campo se repite, gana la primera columna. */
function clienteMapearEncabezados(array $fila, array $alias): array
{
    $m = [];
    foreach ($fila as $i => $h) {
        $k = clienteClaveColumna($h);
        if ($k !== "" && isset($alias[$k]) && !in_array($alias[$k], $m, true)) {
            $m[$i] = $alias[$k];
        }
    }
    return $m;
}

/**
 * Lee un .xlsx o un CSV y entrega cada fila como arreglo [columna => valor] a $porFila.
 * Si $porFila devuelve false, se detiene la lectura.
 */
function clienteRecorrerArchivo(string $ruta, callable $porFila): void
{
    $cabeza = (string) @file_get_contents($ruta, false, null, 0, 4);
    if (substr($cabeza, 0, 2) === "PK") {            // .xlsx es un ZIP
        xlsx_recorrer($ruta, $porFila);
        return;
    }
    if (substr($cabeza, 0, 4) === "\xD0\xCF\x11\xE0") {   // .xls antiguo
        throw new RuntimeException("Es un Excel antiguo (.xls). Ábralo en Excel y use Guardar como → Libro de Excel (.xlsx).");
    }

    $texto = file_get_contents($ruta);
    if ($texto === false || trim($texto) === "") {
        throw new RuntimeException("El archivo está vacío.");
    }
    if (substr($texto, 0, 3) === "\xEF\xBB\xBF") {
        $texto = substr($texto, 3);
    }
    if (!preg_match('//u', $texto)) {
        $conv = @iconv("Windows-1252", "UTF-8//TRANSLIT", $texto);
        $texto = $conv !== false ? $conv : $texto;
    }
    // El delimitador se detecta con las primeras líneas (los títulos pueden no tenerlo)
    $muestra = implode("\n", array_slice(explode("\n", $texto, 40), 0, 40));
    $cuentas = [";" => substr_count($muestra, ";"), "," => substr_count($muestra, ","), "\t" => substr_count($muestra, "\t")];
    arsort($cuentas);
    $delim = array_key_first($cuentas);

    $fh = fopen("php://memory", "r+");
    fwrite($fh, $texto);
    rewind($fh);
    while (($fila = fgetcsv($fh, 0, $delim)) !== false) {
        if ($porFila($fila) === false) {
            break;
        }
    }
    fclose($fh);
}

/** Importa clientes desde .xlsx o .csv. Devuelve ["ok", "nuevos", "actualizados", "omitidos", "separadores"] o ["ok"=>false, "error"]. */
function importarClientesArchivo(mysqli $c, string $tipoDefecto, string $ruta): array
{
    @set_time_limit(600);
    @ini_set("memory_limit", "512M");

    $tabla = tablaClientes();
    $tipoDefecto = listaValida($tipoDefecto);
    $alias = aliasColumnasClientes();

    $existentes = [];
    $r = $c->query("SELECT identificacion_norm FROM `$tabla`");
    while ($r && ($f = $r->fetch_row())) {
        $existentes[$f[0]] = true;
    }

    $columnas = null;          // se llena al encontrar la fila de encabezados
    $filasVistas = 0;
    $nuevos = 0;
    $actualizados = 0;
    $separadores = 0;
    $omitidos = [];
    $totalOmitidos = 0;
    $st = null;

    $c->begin_transaction();
    try {
        clienteRecorrerArchivo($ruta, function (array $fila) use (
            &$columnas, &$filasVistas, &$nuevos, &$actualizados, &$separadores, &$omitidos, &$totalOmitidos, &$st,
            &$existentes, $alias, $tipoDefecto, $c, $tabla
        ) {
            // 1) Buscar la fila de encabezados (dentro de las primeras 40 filas)
            if ($columnas === null) {
                if (++$filasVistas > 40) {
                    return false;
                }
                $m = clienteMapearEncabezados($fila, $alias);
                if (in_array("identificacion", $m, true) && in_array("razon_social", $m, true)) {
                    $columnas = $m;
                }
                return true;
            }

            // 2) Armar el cliente con las columnas conocidas
            $in = [];
            foreach ($columnas as $i => $campo) {
                $v = $fila[$i] ?? "";
                $in[$campo] = is_string($v) ? $v : (string) $v;
            }
            $tc = strtolower(trim((string) ($in["tipo_cliente"] ?? "")));
            $in["tipo_cliente"] = in_array($tc, ["mayorista", "detal"], true) ? $tc : $tipoDefecto;
            $d = normalizarCliente($in);

            // 3) Filas vacías o separadoras (departamento / ciudad): sin razón social y sin NIT
            if ($d["razon_social"] === "" && $d["identificacion_norm"] === "") {
                $separadores++;
                return true;
            }
            // Filas con datos pero sin NIT válido o sin nombre: se omiten y se reportan
            if (strlen($d["identificacion_norm"]) < 5 || $d["razon_social"] === "") {
                $totalOmitidos++;
                if (count($omitidos) < 10) {
                    $omitidos[] = ($d["razon_social"] !== "" ? $d["razon_social"] : "sin nombre") . " (NIT: " . ($d["identificacion"] !== "" ? $d["identificacion"] : "vacío") . ")";
                }
                return true;
            }

            // 4) Insertar o actualizar (la sentencia se prepara una sola vez)
            [$cols, $tipos, $vals] = tiposCliente($d);
            if ($st === null) {
                $lista_cols = implode(", ", array_map(fn($k) => "`$k`", $cols));
                $marcas = implode(", ", array_fill(0, count($cols), "?"));
                $upd = implode(", ", array_map(fn($k) => "`$k` = VALUES(`$k`)", array_filter($cols, fn($k) => $k !== "identificacion_norm")));
                $st = $c->prepare("INSERT INTO `$tabla` ($lista_cols) VALUES ($marcas) ON DUPLICATE KEY UPDATE $upd");
            }
            $st->bind_param($tipos, ...$vals);
            $st->execute();
            if (isset($existentes[$d["identificacion_norm"]])) {
                $actualizados++;
            } else {
                $nuevos++;
                $existentes[$d["identificacion_norm"]] = true;
            }
            return true;
        });
        if ($st) {
            $st->close();
        }
        if ($columnas === null) {
            $c->rollback();
            return ["ok" => false, "error" => "No se encontró la fila de encabezados. El archivo debe tener al menos las columnas NIT (o identificación) y RAZON SOCIAL (o nombre)."];
        }
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        return ["ok" => false, "error" => "Falló la importación y no se guardó nada: " . substr($e->getMessage(), 0, 160)];
    }
    return [
        "ok" => true, "nuevos" => $nuevos, "actualizados" => $actualizados,
        "omitidos" => $omitidos, "total_omitidos" => $totalOmitidos, "separadores" => $separadores,
    ];
}
