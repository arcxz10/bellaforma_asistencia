<?php
/** Importación masiva de productos (CSV): un catálogo con precio mayorista y precio detal. */
require_once __DIR__ . "/clientes_lib.php";

/**
 * Importa productos desde un CSV con columnas: referencia, nombre (o detalle), precio_mayorista y precio_detal.
 * (Si el archivo solo trae "precio", se toma como precio mayorista.) Si la referencia ya existe, se actualiza.
 */
function importarProductosCSV(mysqli $c, string $ruta): array
{
    $tabla = tablaProductos();
    $texto = file_get_contents($ruta);
    if ($texto === false || trim($texto) === "") {
        return ["ok" => false, "error" => "El archivo está vacío."];
    }
    if (substr($texto, 0, 3) === "\xEF\xBB\xBF") {
        $texto = substr($texto, 3);
    }
    if (!preg_match('//u', $texto)) {
        $conv = @iconv("Windows-1252", "UTF-8//TRANSLIT", $texto);
        $texto = $conv !== false ? $conv : $texto;
    }
    $primera = strtok($texto, "\n");
    $cuentas = [";" => substr_count($primera, ";"), "," => substr_count($primera, ","), "\t" => substr_count($primera, "\t")];
    arsort($cuentas);
    $delim = array_key_first($cuentas);

    $fh = fopen("php://memory", "r+");
    fwrite($fh, $texto);
    rewind($fh);
    $cab = fgetcsv($fh, 0, $delim);
    if (!$cab) {
        return ["ok" => false, "error" => "No se encontró la fila de encabezados."];
    }

    $alias = [
        "referencia" => ["referencia", "ref", "codigo", "cod"],
        "nombre" => ["nombre", "detalle", "descripcion", "producto", "articulo"],
        "precio_mayorista" => ["precio_mayorista", "mayorista", "precio_mayor", "precio", "valor", "vrunit2", "vr_unitario", "valor_unitario"],
        "precio_detal" => ["precio_detal", "detal", "precio_al_detal"],
        "activo" => ["activo", "estado"],
    ];
    $mapa = [];
    foreach ($alias as $campo => $lst) {
        foreach ($lst as $n) {
            $mapa[$n] = $campo;
        }
    }
    $cols = [];
    foreach ($cab as $i => $h) {
        $k = trim(preg_replace('/[^a-z0-9]+/', "_", strtolower(sinTildes(trim((string) $h)))), "_");
        if (isset($mapa[$k]) && !in_array($mapa[$k], $cols, true)) {
            $cols[$i] = $mapa[$k];
        }
    }
    foreach (["referencia", "nombre"] as $req) {
        if (!in_array($req, $cols, true)) {
            return ["ok" => false, "error" => "Falta la columna «$req». El archivo debe tener: referencia, nombre, precio_mayorista y precio_detal."];
        }
    }
    if (!in_array("precio_mayorista", $cols, true) && !in_array("precio_detal", $cols, true)) {
        return ["ok" => false, "error" => "Falta al menos una columna de precio: precio_mayorista y/o precio_detal."];
    }
    $traeMay = in_array("precio_mayorista", $cols, true);
    $traeDet = in_array("precio_detal", $cols, true);

    $existentes = [];
    $r = $c->query("SELECT referencia FROM `$tabla`");
    while ($r && ($f = $r->fetch_row())) {
        $existentes[$f[0]] = true;
    }

    $nuevos = 0;
    $actualizados = 0;
    $errores = [];
    $linea = 1;
    $c->begin_transaction();
    try {
        // Solo se actualizan los precios que el archivo trae; el otro precio no se toca
        $sets = ["nombre = VALUES(nombre)", "activo = VALUES(activo)"];
        if ($traeMay) {
            $sets[] = "precio_mayorista = VALUES(precio_mayorista)";
            $sets[] = "precio = VALUES(precio)";
        }
        if ($traeDet) {
            $sets[] = "precio_detal = VALUES(precio_detal)";
        }
        $st = $c->prepare(
            "INSERT INTO `$tabla` (referencia, nombre, precio, precio_mayorista, precio_detal, activo) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE " . implode(", ", $sets)
        );
        while (($fila = fgetcsv($fh, 0, $delim)) !== false) {
            $linea++;
            if (!$fila || (count($fila) === 1 && trim((string) $fila[0]) === "")) {
                continue;
            }
            $in = [];
            foreach ($cols as $i => $campo) {
                $in[$campo] = $fila[$i] ?? "";
            }
            $ref = textoCorto($in["referencia"] ?? "", 60);
            $nom = textoCorto($in["nombre"] ?? "", 255);
            $pMay = parsearPrecio((string) ($in["precio_mayorista"] ?? ""));
            $pDet = parsearPrecio((string) ($in["precio_detal"] ?? ""));
            $activoTxt = strtolower(trim((string) ($in["activo"] ?? "")));
            $activo = ($activoTxt === "" || esVerdadero($activoTxt) || $activoTxt === "activo") ? 1 : 0;
            if ($ref === "" || $nom === "" || ($pMay <= 0 && $pDet <= 0)) {
                if (count($errores) < 8) {
                    $errores[] = "fila $linea";
                }
                continue;
            }
            $st->bind_param("ssdddi", $ref, $nom, $pMay, $pMay, $pDet, $activo);
            $st->execute();
            if (isset($existentes[$ref])) {
                $actualizados++;
            } else {
                $nuevos++;
                $existentes[$ref] = true;
            }
        }
        $st->close();
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        return ["ok" => false, "error" => "Falló la importación en la fila $linea. No se guardó nada. (" . substr($e->getMessage(), 0, 120) . ")"];
    }
    return ["ok" => true, "nuevos" => $nuevos, "actualizados" => $actualizados, "errores" => $errores];
}
