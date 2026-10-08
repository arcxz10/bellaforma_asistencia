<?php
/**
 * Generador mínimo de archivos .xlsx (sin librerías ni extensiones): una hoja con encabezado, filtros y columnas numéricas.
 */

function xlsx_col(int $i): string
{
    $s = "";
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function xlsx_texto($t): string
{
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', "", (string) $t) ?? "";
    return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, "UTF-8");
}

function xlsx_zip(array $archivos): string
{
    $locales = "";
    $central = "";
    $offset = 0;
    $n = 0;
    foreach ($archivos as $nombre => $datos) {
        $crc = crc32($datos) & 0xFFFFFFFF;
        $tam = strlen($datos);
        $nl = strlen($nombre);
        $cab = pack("VvvvvvVVVvv", 0x04034b50, 20, 0x0800, 0, 0, 33, $crc, $tam, $tam, $nl, 0) . $nombre;
        $locales .= $cab . $datos;
        $central .= pack("VvvvvvvVVVvvvvvVV", 0x02014b50, 20, 20, 0x0800, 0, 0, 33, $crc, $tam, $tam, $nl, 0, 0, 0, 0, 0, $offset) . $nombre;
        $offset += strlen($cab) + $tam;
        $n++;
    }
    $fin = pack("VvvvvVVv", 0x06054b50, 0, 0, $n, $n, strlen($central), $offset, 0);
    return $locales . $central . $fin;
}

/**
 * @param string[] $encabezados
 * @param array[]  $filas          cada fila es un arreglo de valores (en el mismo orden que $encabezados)
 * @param int[]    $anchos         ancho de cada columna (caracteres)
 * @param int[]    $colsNumericas  índices (base 0) de columnas que se guardan como número
 */
function xlsx_generar(array $encabezados, array $filas, array $anchos, array $colsNumericas = []): string
{
    $nCols = count($encabezados);
    $filasXml = "";

    $celdas = "";
    foreach ($encabezados as $i => $h) {
        $celdas .= '<c r="' . xlsx_col($i) . '1" s="1" t="inlineStr"><is><t xml:space="preserve">' . xlsx_texto($h) . "</t></is></c>";
    }
    $filasXml .= '<row r="1">' . $celdas . "</row>";

    foreach ($filas as $k => $fila) {
        $r = $k + 2;
        $celdas = "";
        foreach ($fila as $i => $v) {
            if ($v === null || $v === "") {
                continue;
            }
            $ref = xlsx_col($i) . $r;
            if (in_array($i, $colsNumericas, true) && is_numeric($v)) {
                $celdas .= '<c r="' . $ref . '" s="2"><v>' . (0 + $v) . "</v></c>";
            } else {
                $celdas .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_texto($v) . "</t></is></c>";
            }
        }
        $filasXml .= '<row r="' . $r . '">' . $celdas . "</row>";
    }

    $cols = "";
    foreach ($anchos as $i => $w) {
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int) $w . '" customWidth="1"/>';
    }
    $ultima = xlsx_col($nCols - 1) . (count($filas) + 1);

    $hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . "<cols>" . $cols . "</cols>"
        . "<sheetData>" . $filasXml . "</sheetData>"
        . '<autoFilter ref="A1:' . $ultima . '"/>'
        . "</worksheet>";

    $tipos = <<<'XML_TIPOS'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>
XML_TIPOS;

    $relRaiz = <<<'XML_RELRAIZ'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>
XML_RELRAIZ;

    $libro = <<<'XML_LIBRO'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Pedidos" sheetId="1" r:id="rId1"/></sheets></workbook>
XML_LIBRO;

    $relLibro = <<<'XML_RELLIBRO'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>
XML_RELLIBRO;

    $estilos = <<<'XML_ESTILOS'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1565C0"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>
XML_ESTILOS;

    return xlsx_zip([
        "[Content_Types].xml" => $tipos,
        "_rels/.rels" => $relRaiz,
        "xl/workbook.xml" => $libro,
        "xl/_rels/workbook.xml.rels" => $relLibro,
        "xl/styles.xml" => $estilos,
        "xl/worksheets/sheet1.xml" => $hoja,
    ]);
}
