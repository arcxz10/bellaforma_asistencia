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
function clienteRecorrerArchivo(string $ruta, callable $porFila, int $indiceHoja = 0): void
{
    $cabeza = (string) @file_get_contents($ruta, false, null, 0, 4);
    if (substr($cabeza, 0, 2) === "PK") {            // .xlsx es un ZIP
        xlsx_recorrer($ruta, $porFila, $indiceHoja);
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

/**
 * Decide qué hojas importar y con qué tipo de cliente.
 * Si el Excel trae hojas llamadas "mayorista(s)" y/o "detal", se importan SOLO esas, cada una con su tipo
 * (las demás, como "Hoja1" con todos juntos, se ignoran). Si no, se importa la primera hoja con el tipo elegido en pantalla.
 * Devuelve [ ["indice" => 0, "hoja" => "nombre", "tipo" => "mayorista"], ... ]
 */
function clientePlanDeHojas(string $ruta, string $tipoDefecto): array
{
    $cabeza = (string) @file_get_contents($ruta, false, null, 0, 2);
    if ($cabeza !== "PK") {
        return [["indice" => 0, "hoja" => "", "tipo" => $tipoDefecto]];
    }
    $plan = [];
    foreach (xlsx_nombres_hojas($ruta) as $i => $nombre) {
        $n = strtolower(sinTildes(trim($nombre)));
        if (strpos($n, "mayor") !== false) {
            $plan[] = ["indice" => $i, "hoja" => trim($nombre), "tipo" => "mayorista"];
        } elseif (strpos($n, "detal") !== false) {
            $plan[] = ["indice" => $i, "hoja" => trim($nombre), "tipo" => "detal"];
        }
    }
    return $plan ?: [["indice" => 0, "hoja" => "", "tipo" => $tipoDefecto]];
}

/**
 * Limpia en la base los restos de Excel (_x000D_, _x0002_...) que quedaron en cargas anteriores.
 * Se ejecuta después de cada importación; si el servidor no soporta REGEXP_REPLACE usa un reemplazo simple.
 */
function limpiarRestosExcelClientes(mysqli $c): void
{
    $tabla = tablaClientes();
    $campos = array_merge(array_keys(CLIENTE_TEXTOS), ["observaciones"]);
    foreach ($campos as $campo) {
        try {
            $c->query("UPDATE `$tabla` SET `$campo` = TRIM(REGEXP_REPLACE(`$campo`, '_x[0-9A-Fa-f]{4}_', ' ')) WHERE `$campo` REGEXP '_x[0-9A-Fa-f]{4}_'");
        } catch (Throwable $e) {
            try {
                $c->query("UPDATE `$tabla` SET `$campo` = TRIM(REPLACE(REPLACE(`$campo`, '_x000D_', ' '), '_x0002_', '')) WHERE `$campo` LIKE '%\\_x0%'");
            } catch (Throwable $e2) {
                // no es crítico: la importación ya quedó guardada
            }
        }
    }
}

/** Importa clientes desde .xlsx o .csv. Devuelve ["ok", "nuevos", "actualizados", "por_hoja", "omitidos", "total_omitidos", "separadores"] o ["ok"=>false, "error"]. */
function importarClientesArchivo(mysqli $c, string $tipoDefecto, string $ruta): array
{
    @set_time_limit(600);
    @ini_set("memory_limit", "512M");

    $tabla = tablaClientes();
    $tipoDefecto = listaValida($tipoDefecto);
    $alias = aliasColumnasClientes();

    try {
        $plan = clientePlanDeHojas($ruta, $tipoDefecto);
    } catch (Throwable $e) {
        return ["ok" => false, "error" => "No se pudo leer el archivo: " . substr($e->getMessage(), 0, 160)];
    }

    $existentes = [];
    $r = $c->query("SELECT identificacion_norm FROM `$tabla`");
    while ($r && ($f = $r->fetch_row())) {
        $existentes[$f[0]] = true;
    }

    $nuevos = 0;
    $actualizados = 0;
    $separadores = 0;
    $omitidos = [];
    $totalOmitidos = 0;
    $porHoja = [];
    $st = null;

    $c->begin_transaction();
    try {
        foreach ($plan as $trabajo) {
            $tipoHoja = $trabajo["tipo"];
            $columnas = null;          // se llena al encontrar la fila de encabezados de esta hoja
            $filasVistas = 0;
            $resumen = ["hoja" => $trabajo["hoja"], "tipo" => $tipoHoja, "nuevos" => 0, "actualizados" => 0];

            clienteRecorrerArchivo($ruta, function (array $fila) use (
                &$columnas, &$filasVistas, &$nuevos, &$actualizados, &$separadores, &$omitidos, &$totalOmitidos, &$st, &$resumen,
                &$existentes, $alias, $tipoHoja, $c, $tabla
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
                $in["tipo_cliente"] = in_array($tc, ["mayorista", "detal"], true) ? $tc : $tipoHoja;
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
                    $resumen["actualizados"]++;
                } else {
                    $nuevos++;
                    $resumen["nuevos"]++;
                    $existentes[$d["identificacion_norm"]] = true;
                }
                return true;
            }, $trabajo["indice"]);

            if ($columnas === null) {
                $c->rollback();
                $donde = $trabajo["hoja"] !== "" ? " en la hoja «" . $trabajo["hoja"] . "»" : "";
                return ["ok" => false, "error" => "No se encontró la fila de encabezados$donde. Debe tener al menos las columnas NIT (o identificación) y RAZON SOCIAL (o nombre). No se guardó nada."];
            }
            $porHoja[] = $resumen;
        }
        if ($st) {
            $st->close();
        }
        $c->commit();
        limpiarRestosExcelClientes($c);
    } catch (Throwable $e) {
        $c->rollback();
        return ["ok" => false, "error" => "Falló la importación y no se guardó nada: " . substr($e->getMessage(), 0, 160)];
    }
    return [
        "ok" => true, "nuevos" => $nuevos, "actualizados" => $actualizados, "por_hoja" => $porHoja,
        "omitidos" => $omitidos, "total_omitidos" => $totalOmitidos, "separadores" => $separadores,
    ];
}
