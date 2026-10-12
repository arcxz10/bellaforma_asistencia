<?php
/**
 * Lector mínimo de archivos .xlsx en PHP puro (no necesita librerías ni la extensión zip).
 * Entrega las filas de la primera hoja como arreglos [columna => valor] (columna 0 = A).
 */

/** Lista las entradas de un ZIP: nombre => [método, tamaño comprimido, tamaño real, posición local] */
function zip_entradas(string $bin): array
{
    $pos = strrpos($bin, "PK\x05\x06");
    if ($pos === false) {
        throw new RuntimeException("El archivo no es un Excel (.xlsx) válido.");
    }
    $e = unpack("vdisco/vdiscocd/ventradasdisco/ventradas/Vtam/Voff/vcom", substr($bin, $pos + 4, 18));
    $off = (int) $e["off"];
    $salida = [];
    for ($i = 0; $i < $e["entradas"]; $i++) {
        $h = unpack("Vsig/vvm/vvn/vflags/vmetodo/vhora/vfecha/Vcrc/Vcsize/Vusize/vnl/vel/vcl/vdisco/vint/Vext/Vlocal", substr($bin, $off, 46));
        if ($h["sig"] !== 0x02014b50) {
            break;
        }
        $nombre = substr($bin, $off + 46, $h["nl"]);
        $salida[$nombre] = [$h["metodo"], $h["csize"], $h["usize"], $h["local"]];
        $off += 46 + $h["nl"] + $h["el"] + $h["cl"];
    }
    return $salida;
}

function zip_extraer(string $bin, array $entrada): string
{
    [$metodo, $csize, , $local] = $entrada;
    $lh = unpack("Vsig/vvn/vflags/vmetodo/vhora/vfecha/Vcrc/Vcsize/Vusize/vnl/vel", substr($bin, $local, 30));
    $ini = $local + 30 + $lh["nl"] + $lh["el"];
    $datos = substr($bin, $ini, $csize);
    if ($metodo === 8) {
        $r = gzinflate($datos);
        if ($r === false) {
            throw new RuntimeException("No se pudo descomprimir el Excel (archivo dañado).");
        }
        return $r;
    }
    return $datos;
}

function xlsx_texto_xml(string $s): string
{
    return html_entity_decode($s, ENT_QUOTES | ENT_XML1, "UTF-8");
}

/** Textos compartidos (sharedStrings.xml) */
function xlsx_textos_compartidos(string $xml): array
{
    $lista = [];
    $pos = 0;
    while (preg_match('/<si\b[^>]*?(?:\/>|>(.*?)<\/si>)/s', $xml, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $cuerpo = $m[1][0] ?? "";
        $texto = "";
        if ($cuerpo !== "" && preg_match_all('/<t\b[^>]*?(?:\/>|>(.*?)<\/t>)/s', $cuerpo, $partes)) {
            $texto = implode("", $partes[1]);
        }
        $lista[] = xlsx_texto_xml($texto);
    }
    return $lista;
}

function xlsx_columna_a_indice(string $letras): int
{
    $n = 0;
    for ($i = 0, $l = strlen($letras); $i < $l; $i++) {
        $n = $n * 26 + (ord($letras[$i]) - 64);
    }
    return $n - 1;
}

/** Recorre las filas de la primera hoja. $porFila recibe un arreglo [columna => valor]; si devuelve false, se detiene. */
function xlsx_recorrer(string $ruta, callable $porFila): void
{
    $bin = file_get_contents($ruta);
    if ($bin === false || strlen($bin) < 100) {
        throw new RuntimeException("No se pudo leer el archivo.");
    }
    $ent = zip_entradas($bin);

    // Hoja a leer: la primera del libro
    $rutaHoja = null;
    if (isset($ent["xl/workbook.xml"], $ent["xl/_rels/workbook.xml.rels"])) {
        $wb = zip_extraer($bin, $ent["xl/workbook.xml"]);
        $rels = zip_extraer($bin, $ent["xl/_rels/workbook.xml.rels"]);
        if (preg_match('/<sheet\b[^>]*?\br:id="([^"]+)"/', $wb, $m)) {
            if (preg_match_all('/<Relationship\b[^>]*>/', $rels, $tags)) {
                foreach ($tags[0] as $tag) {
                    if (preg_match('/\bId="' . preg_quote($m[1], "/") . '"/', $tag) && preg_match('/\bTarget="([^"]+)"/', $tag, $t)) {
                        $destino = ltrim($t[1], "/");
                        $rutaHoja = strpos($destino, "xl/") === 0 ? $destino : "xl/" . $destino;
                    }
                }
            }
        }
    }
    if ($rutaHoja === null || !isset($ent[$rutaHoja])) {
        $rutaHoja = isset($ent["xl/worksheets/sheet1.xml"]) ? "xl/worksheets/sheet1.xml" : null;
        if ($rutaHoja === null) {
            foreach (array_keys($ent) as $nombre) {
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $nombre)) {
                    $rutaHoja = $nombre;
                    break;
                }
            }
        }
    }
    if ($rutaHoja === null) {
        throw new RuntimeException("El Excel no tiene hojas con datos.");
    }

    $textos = isset($ent["xl/sharedStrings.xml"]) ? xlsx_textos_compartidos(zip_extraer($bin, $ent["xl/sharedStrings.xml"])) : [];
    $xml = zip_extraer($bin, $ent[$rutaHoja]);
    unset($bin);

    $pos = 0;
    while (preg_match('/<row\b[^>]*?(?:\/>|>(.*?)<\/row>)/s', $xml, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $cuerpo = $m[1][0] ?? "";
        $fila = [];
        if ($cuerpo !== "" && preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $cuerpo, $celdas, PREG_SET_ORDER)) {
            foreach ($celdas as $c) {
                $attr = $c[1];
                $contenido = $c[2] ?? "";
                if (!preg_match('/\br="([A-Z]+)\d+"/', $attr, $r)) {
                    continue;
                }
                $col = xlsx_columna_a_indice($r[1]);
                $tipo = preg_match('/\bt="([^"]+)"/', $attr, $t) ? $t[1] : "n";
                $valor = null;
                if ($tipo === "s") {
                    if (preg_match('/<v>(\d+)<\/v>/', $contenido, $v)) {
                        $valor = $textos[(int) $v[1]] ?? "";
                    }
                } elseif ($tipo === "inlineStr") {
                    if (preg_match_all('/<t\b[^>]*?(?:\/>|>(.*?)<\/t>)/s', $contenido, $ps)) {
                        $valor = xlsx_texto_xml(implode("", $ps[1]));
                    }
                } else {
                    if (preg_match('/<v>(.*?)<\/v>/s', $contenido, $v)) {
                        $valor = xlsx_texto_xml($v[1]);
                    }
                }
                if ($valor !== null) {
                    $fila[$col] = $valor;
                }
            }
        }
        if ($porFila($fila) === false) {
            return;
        }
    }
}
