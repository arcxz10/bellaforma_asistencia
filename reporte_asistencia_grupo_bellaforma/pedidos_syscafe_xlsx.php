<?php
/**
 * Pedidos en la PLANTILLA DE PEDIDOS de Syscafe (PEDIDOS.xlsx, 60 columnas), lista para importar.
 * Una fila por producto; los datos del pedido (tipo, número, cliente, vendedor, fechas...) se repiten en cada fila.
 *
 * Qué va en cada columna (las que no se mencionan van vacías o en 0, igual que la plantilla):
 *   Tipo        -> tipo de documento de Syscafe (SYSCAFE_PE_TIPO, ej. PE5)
 *   Numero      -> N° que el ejecutivo escribió en el pedido/remisión/cotización (si lo dejó vacío, el id automático + SYSCAFE_PE_NUMERO_BASE), a 10 dígitos
 *   Tercero2    -> cédula del VENDEDOR (ejecutivo)          Tercero1 -> NIT / cédula del CLIENTE
 *   Ano/Mes/Dia -> fecha del pedido                         AnoE/MesE/DiaE -> fecha + plazo
 *   Plazo       -> días de crédito (0 si es de contado)     Anulado -> TRUE si el pedido está anulado
 *   Detalle     -> observaciones del pedido                 Numexterno -> el mismo N° del documento
 *   Referencia, Cantidad, VrSubtot (TOTAL de la línea = cantidad x precio; Syscafe lo divide entre la cantidad), Descritem (nombre del producto)
 *   Fpago       -> forma de pago (SYSCAFE_PE_FPAGO: contado / crédito)         Periodo -> AAAAMM
 *   VrSubtot/VrIva/pIva -> con factura electrónica: VrSubtot = línea sin IVA, VrIva = IVA incluido, pIva = 19 (VrSubtot + VrIva = valor original); sin factura electrónica: IVA 0 y VrSubtot = cantidad x precio
 *   Sugerido    -> precio unitario (para que Syscafe no calcule descuentos)
 */
require_once __DIR__ . "/xlsx_simple.php";   // xlsx_col(), xlsx_texto(), xlsx_zip()
require_once __DIR__ . "/xls_syscafe.php";   // syscafe_mayusculas()

/* ---- Ajustes de Syscafe (cámbielos aquí si en Syscafe usan otros) ---- */
const SYSCAFE_PE_TIPO = "PE5";        // tipo de documento del pedido (en pantalla de Syscafe: PE5)
/* Tipo de documento de Syscafe según lo que elige el ejecutivo y si el pedido lleva factura electrónica:
   [sin factura electrónica, con factura electrónica] */
const SYSCAFE_PE_TIPOS = [
    "pedido"     => ["PE5", "PE1"],
    "remision"   => ["RM1", "RM2"],
    "cotizacion" => ["CT1", "CT2"],
];
const SYSCAFE_PE_NUMERO_BASE = 0;     // se suma al N° de pedido de la página. Ej: 4500 -> el pedido 22 sale como 0000004522
/* Forma de pago (columna Fpago) para TODOS los documentos, con o sin factura electrónica (PE5, PE1, RM1, RM2, CT1, CT2).
   Son las cuentas que muestra Syscafe en FPago:
     130505001 = CUENTAS X COBRAR CLIENTES  (pedido a crédito)
     130505002 = PAGO CONTRAENTREGA          (pedido de contado)
   Si prefiere otra combinación, solo intercambie los códigos. */
const SYSCAFE_PE_FPAGO = [
    "contado" => "130505002",
    "credito" => "130505001",
];

/* Forma de pago por TIPO DE DOCUMENTO (solo si un tipo usa un código distinto al de arriba).
   Syscafe respondió "PE1-xxxx : forma de pago no existe": para PE1 (pedido con factura electrónica) el código de arriba
   no existe. Escriba aquí el código EXACTO que aparece en el campo FPago de Syscafe al abrir un documento PE1.
   Formato:  "TIPO" => ["contado" => "CODIGO", "credito" => "CODIGO"]
   Ej:       "PE1" => ["contado" => "110505001", "credito" => "130505001"],
   Lo que no esté aquí usa SYSCAFE_PE_FPAGO. */
const SYSCAFE_PE_FPAGO_POR_TIPO = [
    // 006 = CUENTAS X COBRAR CLIENTES (CTASXCOBRAR), tal como lo muestra Syscafe en PE1, RM2 y CT2.
    // El contado también usa 006 porque así los registran hoy en Syscafe (vencimiento el mismo día, forma de pago CTASXCOBRAR).
    // Si algún día el contado debe ir por otra forma de pago, cambie solo ese código.
    "PE1" => ["contado" => "006", "credito" => "006"],
    "RM2" => ["contado" => "006", "credito" => "006"],
    "CT2" => ["contado" => "006", "credito" => "006"],
    // Documentos SIN factura electrónica: también usan la forma de pago 006 (130505001 es la cuenta contable, no la forma de pago)
    "PE5" => ["contado" => "006", "credito" => "006"],
    "RM1" => ["contado" => "006", "credito" => "006"],
    "CT1" => ["contado" => "006", "credito" => "006"],
];

/* IVA SOLO PARA LOS DOCUMENTOS CON FACTURA ELECTRÓNICA (PE1, RM2, CT2).
   Los precios del pedido YA INCLUYEN el IVA, así que lo que paga el cliente por cada línea NO cambia.
   Syscafe calcula el total de la línea como  VrSubtot + VrIva, por eso con IVA incluido se envía:
       VrSubtot = valor de la línea SIN IVA (total / 1.19)      VrIva = total - VrSubtot      pIva = 19
   y Syscafe muestra el precio normal, el %Iva 19 y el Iva/Inc, sin descuentos ni recargos.
   Los documentos sin factura electrónica (PE5, RM1, CT1) siguen saliendo sin IVA (pIva y VrIva en 0).
   Para quitar el IVA también de los PE1, cambie el 19 por 0. */
const SYSCAFE_PE_IVA = 19;

/* true  -> el precio ya trae el IVA dentro: el total de la línea queda igual (lo normal).
   false -> el IVA se suma ENCIMA del precio (el total sube un 19 %). */
const SYSCAFE_PE_IVA_INCLUIDO = true;

/* true -> el precio unitario también va en la columna Sugerido, para que Syscafe use ese valor como precio y no calcule descuentos. */
const SYSCAFE_PE_USAR_SUGERIDO = true;

/* CÉDULA DEL VENDEDOR EN SYSCAFE (columna Tercero2).
   Debe ser la cédula con la que el vendedor existe como tercero en Syscafe. Si la cédula registrada en esta página
   es de prueba (ej. 101010) o distinta a la de Syscafe, Syscafe no encuentra al vendedor y rechaza el archivo.
   Formato:  "cédula en esta página" => "cédula en Syscafe".   Ej: "101010" => "1234567890"
   Si un ejecutivo no está en la lista, se usa la cédula registrada en la página. */
const SYSCAFE_PE_VENDEDORES = [
    // "101010" => "AQUI_LA_CEDULA_REAL_EN_SYSCAFE",
];

/* [encabezado, tipo (T texto, N número, B verdadero/falso), estilo de datos, estilo del encabezado] — igual que la plantilla */
const SYSCAFE_PE_COLUMNAS = [
    ["Tipo", "T", 1, 1],
    ["Numero", "T", 1, 1],
    ["Tercero2", "T", 1, 1],
    ["Ano", "N", 2, 2],
    ["Mes", "N", 2, 2],
    ["Dia", "N", 2, 2],
    ["Anulado", "B", 1, 0],
    ["Plazo", "T", 1, 3],
    ["AnoE", "N", 2, 3],
    ["MesE", "N", 2, 3],
    ["DiaE", "N", 2, 3],
    ["Detalle", "T", 1, 0],
    ["Tercero1", "T", 1, 0],
    ["Area", "T", 1, 0],
    ["Referencia", "T", 1, 0],
    ["Cantidad", "N", 2, 3],
    ["VrSubtot", "N", 2, 3],
    ["VrIva", "N", 2, 3],
    ["pIva", "N", 2, 3],
    ["CodServ", "T", 1, 0],
    ["vrretef", "N", 2, 3],
    ["pretefte", "N", 2, 3],
    ["Fpago", "T", 1, 0],
    ["Condipago", "T", 1, 0],
    ["Tercero3", "T", 1, 0],
    ["vrreIva", "N", 2, 3],
    ["PReIva", "N", 2, 3],
    ["vrreIca", "N", 2, 3],
    ["PReIca", "N", 2, 3],
    ["Numexterno", "T", 1, 1],
    ["Talla", "T", 1, 1],
    ["VrIpocon", "N", 2, 2],
    ["Sugerido", "N", 2, 2],
    ["VrRetec", "N", 2, 2],
    ["PRetec", "N", 2, 2],
    ["Fpago2", "T", 1, 0],
    ["VRpago2", "N", 2, 3],
    ["Fpago3", "T", 1, 0],
    ["VRpago3", "N", 2, 3],
    ["Bodega", "T", 1, 0],
    ["Anticipo", "N", 2, 0],
    ["Causal", "T", 1, 0],
    ["Anexo", "T", 1, 0],
    ["Periodo", "T", 1, 0],
    ["Ccosto", "T", 1, 0],
    ["Sccosto", "T", 1, 0],
    ["Afecta", "T", 1, 0],
    ["Descuento Gral", "N", 2, 0],
    ["Razon Dto", "T", 1, 0],
    ["Vrpropina", "N", 2, 0],
    ["Descritem", "T", 1, 0],
    ["VrInc", "N", 1, 1],
    ["Ref1", "T", 1, 1],
    ["Ref2", "T", 1, 1],
    ["Ref3", "T", 1, 1],
    ["Ref4", "T", 1, 1],
    ["Agente", "T", 1, 1],
    ["VrIBUA", "N", 2, 1],
    ["VrICUI", "N", 2, 1],
    ["pICUI", "N", 2, 1],
];

function syscafe_pe_digitos(string $v): string
{
    $v = trim($v);
    if (strpos($v, "-") !== false) {
        $v = explode("-", $v)[0];      // 900123456-7 -> 900123456 (sin dígito de verificación)
    }
    return preg_replace('/\D+/', "", $v) ?? "";
}

/** Filas (una por producto) de un pedido, en el orden de las 60 columnas de la plantilla. */
function syscafe_pe_filas(array $p): array
{
    $ts = strtotime((string) ($p["creado_en"] ?? "now")) ?: time();
    $credito = ($p["condicion_pago"] ?? "contado") === "credito";
    $plazo = $credito ? max(0, (int) ($p["dias_credito"] ?? 0)) : 0;
    $tsVence = strtotime("+" . $plazo . " days", $ts) ?: $ts;

    $conFE = !empty($p["factura_electronica"]);
    $tipo = SYSCAFE_PE_TIPOS[tipoDocumentoValido($p["tipo_pedido"] ?? "pedido")][$conFE ? 1 : 0];
    $claveFpago = $credito ? "credito" : "contado";
    $fpago = SYSCAFE_PE_FPAGO_POR_TIPO[$tipo][$claveFpago] ?? SYSCAFE_PE_FPAGO[$claveFpago] ?? "";
    $pIva = $conFE ? (float) SYSCAFE_PE_IVA : 0.0;
    $numeroPropio = trim((string) ($p["numero_pedido"] ?? ""));
    $numero = str_pad($numeroPropio !== "" ? $numeroPropio : (string) (SYSCAFE_PE_NUMERO_BASE + (int) $p["id"]), 10, "0", STR_PAD_LEFT);
    $vendedor = syscafe_pe_digitos((string) ($p["vendedor_identificacion"] ?? ""));
    if (isset(SYSCAFE_PE_VENDEDORES[$vendedor]) && SYSCAFE_PE_VENDEDORES[$vendedor] !== "") {
        $vendedor = syscafe_pe_digitos((string) SYSCAFE_PE_VENDEDORES[$vendedor]);
    }
    $cliente = syscafe_pe_digitos((string) ($p["cliente_nit"] ?? ""));
    $anulado = ($p["estado"] ?? "") === "anulado";
    $detalle = trim(preg_replace('/\s+/', " ", (string) ($p["observaciones"] ?? "")) ?? "");
    $detalle = syscafe_mayusculas(function_exists("mb_substr") ? mb_substr($detalle, 0, 100, "UTF-8") : substr($detalle, 0, 100));
    $numExterno = numeroDocumento($p);

    $filas = [];
    foreach ($p["items"] as $it) {
        $cant = (float) $it["cantidad"];
        // Syscafe toma VrSubtot (+ VrIva) como el TOTAL de la línea y calcula el unitario dividiendo entre Cantidad
        $precio = (float) $it["precio_unitario"];
        $esBon = !empty($it["bonificado"]);                 // bonificado: 100 % de descuento -> total de la línea en 0 (y sin IVA)
        $bruto = $esBon ? 0.0 : round($cant * $precio, 2);   // cantidad x precio (el valor que ya tenía la línea)
        if ($pIva <= 0 || $esBon) {
            $subtotal = $bruto;
            $vrIva = 0.0;
        } elseif (SYSCAFE_PE_IVA_INCLUIDO) {
            $subtotal = round($bruto / (1 + $pIva / 100), 2);   // base sin IVA
            $vrIva = round($bruto - $subtotal, 2);                // IVA incluido: base + IVA = valor original de la línea
        } else {
            $subtotal = $bruto;
            $vrIva = round($bruto * $pIva / 100, 2);              // IVA sumado encima del precio
        }
        $filas[] = [
            $tipo,                          // A  Tipo
            $numero,                        // B  Numero
            $vendedor,                      // C  Tercero2 (cédula del vendedor)
            (float) date("Y", $ts),         // D  Ano
            (float) date("n", $ts),         // E  Mes
            (float) date("j", $ts),         // F  Dia
            $anulado,                       // G  Anulado
            (string) $plazo,                // H  Plazo
            (float) date("Y", $tsVence),    // I  AnoE
            (float) date("n", $tsVence),    // J  MesE
            (float) date("j", $tsVence),    // K  DiaE
            $detalle,                       // L  Detalle
            $cliente,                       // M  Tercero1 (cédula / NIT del cliente)
            "",                             // N  Area
            (string) $it["referencia"],     // O  Referencia
            $cant,                          // P  Cantidad
            $subtotal,                      // Q  VrSubtot (valor de la línea SIN IVA; con IVA incluido: total / 1.19)
            $vrIva,                         // R  VrIva (Syscafe suma VrSubtot + VrIva = valor original de la línea)
            $pIva,                          // S  pIva
            "",                             // T  CodServ
            0.0,                            // U  vrretef
            0.0,                            // V  pretefte
            $fpago,                         // W  Fpago
            "",                             // X  Condipago
            "",                             // Y  Tercero3
            0.0, 0.0, 0.0, 0.0,             // Z..AC  vrreIva, PReIva, vrreIca, PReIca
            $numExterno,                    // AD Numexterno
            "",                             // AE Talla
            0.0,                            // AF VrIpocon
            SYSCAFE_PE_USAR_SUGERIDO ? $precio : 0.0,   // AG Sugerido (precio unitario)
            0.0,                            // AH VrRetec
            0.0,                            // AI PRetec
            "",                             // AJ Fpago2
            0.0,                            // AK VRpago2
            "",                             // AL Fpago3
            0.0,                            // AM VRpago3
            "",                             // AN Bodega
            0.0,                            // AO Anticipo
            "",                             // AP Causal
            "",                             // AQ Anexo
            date("Ym", $ts),                // AR Periodo
            "",                             // AS Ccosto
            "",                             // AT Sccosto
            "",                             // AU Afecta
            0.0,                            // AV Descuento Gral
            "",                             // AW Razon Dto
            0.0,                            // AX Vrpropina
            syscafe_mayusculas($esBon
                ? (function_exists("mb_substr") ? mb_substr((string) $it["nombre"], 0, 86, "UTF-8") : substr((string) $it["nombre"], 0, 86)) . " (BONIFICADO)"
                : (function_exists("mb_substr") ? mb_substr((string) $it["nombre"], 0, 100, "UTF-8") : substr((string) $it["nombre"], 0, 100))),   // AY Descritem
            0.0,                            // AZ VrInc
            "", "", "", "", "",             // BA..BE  Ref1, Ref2, Ref3, Ref4, Agente
            0.0, 0.0, 0.0,                  // BF..BH  VrIBUA, VrICUI, pICUI
        ];
    }
    return $filas;
}

/** Genera el .xlsx con la plantilla de pedidos de Syscafe. $pedidos: pedidos con su arreglo "items". */
function syscafe_pedidos_xlsx(array $pedidos): string
{
    $cols = SYSCAFE_PE_COLUMNAS;
    $nCols = count($cols);

    $compartidas = [];      // texto => índice
    $listaComp = [];
    $totalRefs = 0;
    $idx = function (string $t) use (&$compartidas, &$listaComp, &$totalRefs): int {
        $totalRefs++;
        if (!isset($compartidas[$t])) {
            $compartidas[$t] = count($listaComp);
            $listaComp[] = $t;
        }
        return $compartidas[$t];
    };

    // Fila 1: encabezados
    $celdas = "";
    foreach ($cols as $i => $c) {
        $s = $c[3] ? ' s="' . $c[3] . '"' : "";
        $celdas .= '<c r="' . xlsx_col($i) . '1"' . $s . ' t="s"><v>' . $idx($c[0]) . "</v></c>";
    }
    $filasXml = '<row r="1">' . $celdas . "</row>";

    // Filas de datos
    $r = 1;
    foreach ($pedidos as $p) {
        foreach (syscafe_pe_filas($p) as $fila) {
            $r++;
            $celdas = "";
            foreach ($cols as $i => $c) {
                $v = $fila[$i];
                $ref = xlsx_col($i) . $r;
                $s = ' s="' . $c[2] . '"';
                if ($c[1] === "T") {
                    $celdas .= '<c r="' . $ref . '"' . $s . ' t="s"><v>' . $idx((string) $v) . "</v></c>";
                } elseif ($c[1] === "B") {
                    $celdas .= '<c r="' . $ref . '"' . $s . ' t="b"><v>' . ($v ? 1 : 0) . "</v></c>";
                } else {
                    $celdas .= '<c r="' . $ref . '"' . $s . "><v>" . (0 + $v) . "</v></c>";
                }
            }
            $filasXml .= '<row r="' . $r . '">' . $celdas . "</row>";
        }
    }

    $hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . xlsx_col($nCols - 1) . $r . '"/>'
        . '<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr baseColWidth="10" defaultRowHeight="12"/>'
        . "<sheetData>" . $filasXml . "</sheetData>"
        . "</worksheet>";

    $sst = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $totalRefs . '" uniqueCount="' . count($listaComp) . '">';
    foreach ($listaComp as $t) {
        $sst .= '<si><t xml:space="preserve">' . xlsx_texto($t) . "</t></si>";
    }
    $sst .= "</sst>";

    $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';

    $relRaiz = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';

    $libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja1" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $relLibro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>';

    // Mismos estilos de la plantilla de Syscafe (texto @, #,##0.00 y 0)
    $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac x16r2 xr" xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac" xmlns:x16r2="http://schemas.microsoft.com/office/spreadsheetml/2015/02/main" xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision"><fonts count="1" x14ac:knownFonts="1"><font><sz val="9"/><color theme="1"/><name val="Segoe UI"/><family val="2"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';

    return xlsx_zip([
        "[Content_Types].xml" => $tipos,
        "_rels/.rels" => $relRaiz,
        "xl/workbook.xml" => $libro,
        "xl/_rels/workbook.xml.rels" => $relLibro,
        "xl/styles.xml" => $estilos,
        "xl/sharedStrings.xml" => $sst,
        "xl/worksheets/sheet1.xml" => $hoja,
    ]);
}

/* ================= Versión .XLS (la que lee Syscafe en "Archivo estandar (xls)") =================
 * Syscafe no abre .xlsx ("El formato del archivo Microsoft Excel no es válido"): necesita el .XLS antiguo (Excel 2.0 / BIFF2),
 * el mismo que ya usaba este sistema. Lleva las MISMAS 60 columnas de la plantilla.
 */

/** Ancho de cada columna en caracteres: en el .XLS define el tamaño del campo que crea Syscafe. */
function syscafe_pe_ancho(int $i, string $tipo): int
{
    $texto = [0 => 6, 1 => 12, 2 => 20, 6 => 8, 7 => 6, 11 => 100, 12 => 20, 14 => 22, 22 => 6, 24 => 20, 29 => 20, 30 => 6,
              35 => 6, 37 => 6, 43 => 8, 48 => 30, 50 => 100, 52 => 30, 53 => 30, 54 => 30, 55 => 30, 56 => 20];
    if (isset($texto[$i])) {
        return $texto[$i];
    }
    return $tipo === "T" ? 10 : 14;
}

function biff2_logico(int $fila, int $col, bool $v): string
{
    return biff2_rec(5, pack("vv", $fila, $col) . "\x40\x00\x00" . chr($v ? 1 : 0) . "\x00");
}

/** Genera el .XLS (BIFF2) con la plantilla de pedidos de Syscafe. $pedidos: pedidos con su arreglo "items".
 *  $conEncabezado = false -> sin la fila de títulos (para probar si Syscafe lee la 1.ª fila como un pedido más). */
function syscafe_pedidos_xls(array $pedidos, bool $conEncabezado = true): string
{
    $cols = SYSCAFE_PE_COLUMNAS;
    $n = count($cols);
    $datos = [];
    foreach ($pedidos as $p) {
        foreach (syscafe_pe_filas($p) as $fila) {
            $datos[] = $fila;
        }
    }

    $out = biff2_rec(9, pack("vv", 2, 0x10)) . biff2_rec(0x42, pack("v", 1252));
    $desp = $conEncabezado ? 1 : 0;     // filas ocupadas por el encabezado
    $out .= biff2_rec(0, pack("vvvv", 0, count($datos) + $desp, 0, $n));
    foreach ($cols as $i => $c) {
        $out .= biff2_rec(0x24, pack("CCv", $i, $i, syscafe_pe_ancho($i, $c[1]) * 256));
    }
    if ($conEncabezado) {
        foreach ($cols as $i => $c) {
            $out .= biff2_label(0, $i, $c[0]);
        }
    }
    foreach ($datos as $k => $fila) {
        $r = $k + $desp;
        foreach ($cols as $i => $c) {
            $v = $fila[$i];
            if ($c[1] === "T") {
                $out .= biff2_label($r, $i, (string) $v);
            } elseif ($c[1] === "B") {
                $out .= biff2_logico($r, $i, (bool) $v);
            } else {
                $out .= biff2_numero($r, $i, (float) $v);
            }
        }
    }
    return $out . biff2_rec(0x0A, "") . "\x00\x00";
}

function syscafe_pe_nombre_archivo(array $pedido): string
{
    $base = strtr((string) $pedido["cliente_nombre"], ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n",
                                                     "Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U", "Ü" => "U", "Ñ" => "N"]);
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', "_", strtoupper($base)) ?? "", "_");
    $base = substr($base, 0, 24);
    return strtoupper(tiposDocumentoPedido()[tipoDocumentoValido($pedido["tipo_pedido"] ?? "pedido")][3]) . "_" . numeroDocumento($pedido) . ($base !== "" ? "_" . $base : "") . ".XLS";
}
