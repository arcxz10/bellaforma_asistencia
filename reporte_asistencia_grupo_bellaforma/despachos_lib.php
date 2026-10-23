<?php
/**
 * Control de despachos diarios.
 *  - Catálogos: transportadoras y formas de pago (los llena el ejecutivo en el pedido), empresa, tipo de flete y estados.
 *  - Tabla `despachos`: una fila por documento que salió. Las filas ligadas a un pedido (pedido_id) leen en vivo los datos
 *    del pedido; las filas históricas / manuales (pedido_id NULL) guardan sus propios datos.
 *  - Los campos que llena la oficina (empresa, # factura, guía, flete, estados) viven siempre en `despachos`.
 */

/* ================= CATÁLOGOS ================= */

/** Opciones de transportadora (clave => etiqueta). Para agregar una, solo añada una línea aquí. */
function transportadorasDespacho(): array
{
    return [
        "coordinadora"        => "Coordinadora",
        "domicilio_don_jairo" => "Domicilio",
        "domicilio_sandra"    => "Domicilio Sandra",
        "recoge_oficina"      => "Recoge en oficina",
        "tcc"                 => "TCC",
        "interrapidisimo"     => "Interrapidísimo",
    ];
}

/** Opciones de forma de pago (clave => etiqueta). */
function formasPagoDespacho(): array
{
    return [
        "anticipado"     => "Anticipado",
        "contra_entrega" => "Contra entrega",
        "dias_30"        => "30 días",
        "dias_45"        => "45 días",
        "dias_8"         => "8 días",
        "dias_3"         => "3 días",
        "dias_5"         => "5 días",
    ];
}

/** Días de crédito de una forma de pago (null si es anticipado / contra entrega). */
function formaPagoDias($clave): ?int
{
    return preg_match('/^dias_(\d+)$/', (string) $clave, $m) ? (int) $m[1] : null;
}

function fletesTipoDespacho(): array
{
    return ["anticipado" => "Anticipado", "cuenta_corriente" => "Cuenta corriente", "contra_entrega" => "Contra entrega"];
}

function empresasDespacho(): array
{
    return ["MIRELY" => "MIRELY", "GRUPO BELLAFORMA" => "GRUPO BELLAFORMA"];
}

function estadosEntregaDespacho(): array
{
    return ["pendiente" => "Pendiente", "en_transito" => "En tránsito", "entregado" => "Entregado", "novedad" => "Novedad", "devuelto" => "Devuelto"];
}

function estadosPagoDespacho(): array
{
    return ["pendiente" => "Pendiente", "pagado" => "Pagado", "parcial" => "Pago parcial"];
}

/** Etiqueta legible; si la clave no está en el catálogo (dato histórico) se muestra tal cual, con formato. */
function etiquetaDeCatalogo(array $catalogo, $clave): string
{
    $clave = (string) $clave;
    if ($clave === "") {
        return "";
    }
    if (isset($catalogo[$clave])) {
        return $catalogo[$clave];
    }
    $t = str_replace("_", " ", $clave);
    return function_exists("mb_convert_case") ? mb_convert_case($t, MB_CASE_TITLE, "UTF-8") : ucwords($t);
}

function etiquetaFormaPago($clave): string
{
    return etiquetaDeCatalogo(formasPagoDespacho(), $clave);
}

function etiquetaTransportadora($clave): string
{
    return etiquetaDeCatalogo(transportadorasDespacho(), $clave);
}

/* ================= TABLA Y DATOS INICIALES ================= */

function asegurarTablaDespachos(mysqli $c): bool
{
    $cols = "id INT AUTO_INCREMENT PRIMARY KEY,
        fecha DATE NOT NULL,
        pedido_id INT NULL,
        tipo_documento VARCHAR(12) NOT NULL DEFAULT 'pedido',
        nit VARCHAR(40) NOT NULL DEFAULT '',
        cliente_nombre VARCHAR(200) NOT NULL DEFAULT '',
        nombre_comercial VARCHAR(200) NOT NULL DEFAULT '',
        ciudad VARCHAR(100) NOT NULL DEFAULT '',
        departamento VARCHAR(80) NOT NULL DEFAULT '',
        valor DECIMAL(14,2) NULL,
        vendedor_nombre VARCHAR(150) NOT NULL DEFAULT '',
        forma_pago VARCHAR(30) NOT NULL DEFAULT '',
        transportadora VARCHAR(30) NOT NULL DEFAULT '',
        empresa VARCHAR(40) NOT NULL DEFAULT '',
        factura VARCHAR(60) NOT NULL DEFAULT '',
        numero_guia VARCHAR(40) NOT NULL DEFAULT '',
        flete_tipo VARCHAR(20) NOT NULL DEFAULT '',
        valor_flete DECIMAL(12,2) NULL,
        estado_entrega VARCHAR(20) NOT NULL DEFAULT '',
        estado_pago VARCHAR(20) NOT NULL DEFAULT '',
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_desp_pedido (pedido_id),
        KEY idx_desp_fecha (fecha)";
    try {
        try {
            $c->query("CREATE TABLE IF NOT EXISTS despachos ($cols,
                CONSTRAINT fk_desp_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos_vendedores(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            // Si la llave foránea no se pudo crear, la tabla se crea igual (al eliminar un pedido se limpia a mano)
            $c->query("CREATE TABLE IF NOT EXISTS despachos ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        sembrarDespachosIniciales($c);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Carga UNA SOLA VEZ los 24 despachos del miércoles 21/10/2026 (tomados de la hoja de control) para poder verificarlos.
 * Lo que la hoja tenía vacío queda vacío. La oficina puede editarlos o quitarlos desde el panel.
 */
function sembrarDespachosIniciales(mysqli $c): void
{
    // "Reclamo" atómico: solo la primera solicitud que llegue siembra los datos
    $c->query("INSERT IGNORE INTO esquema_modulo (clave, valor) VALUES ('despachos_seed', 1)");
    if ($c->affected_rows !== 1) {
        return;
    }

    $fecha = "2026-10-21";
    // [nit, nombre, comercial, ciudad, depto, empresa, factura, valor, vendedor, forma_pago, transportadora, guía, flete, valor_flete]
    $M = "MIRELY";
    $G = "GRUPO BELLAFORMA";
    $filas = [
        ["28553263", "NATHALY VASQUEZ", "NATHALY VASQUEZ", "IBAGUE", "TOLIMA", $M, "FV-7799", 35000, "ALEJANDRA PASTRANA", "anticipado", "domicilio_don_jairo", "", "", null],
        ["28553263", "NATHALY VASQUEZ", "NATHALY VASQUEZ", "IBAGUE", "TOLIMA", $M, "RM1-797", null, "", "", "domicilio_don_jairo", "", "", null],
        ["1121939949", "ZULLY ESMERALDA RODRIGUEZ TRUJILLO", "ZULLY ESMERALDA RODRIGUEZ TRUJILLO", "VILLAVICENCIO", "META", $G, "FE-2261", 442700, "NICOLE RAMIREZ", "anticipado", "coordinadora", "71449503916", "anticipado", 15500],
        ["1109921052", "CATALINA GALINDO GOMEZ", "CATALINA GALINDO GOMEZ", "CALI", "V/CAUCA", $M, "RM1-795", null, "NICOLE RAMIREZ", "", "coordinadora", "71449503917", "cuenta_corriente", null],
        ["52251505", "RUBBY MEJIA RAMOS", "RUBBY MEJIA RAMOS", "IBAGUE", "TOLIMA", $M, "FV-7800", 145400, "NICOLE RAMIREZ", "anticipado", "recoge_oficina", "", "", null],
        ["32728380", "JOSEFA HELENA NAVARRO OJITO", "JOSEFA HELENA NAVARRO OJITO", "BARRANQUILLA", "ATLANTICO", $M, "FV-7801", 78600, "NICOLE RAMIREZ", "anticipado", "coordinadora", "71449503918", "anticipado", 12700],
        ["49773243", "KATHAY DIAZ", "KATHAY DIAZ", "BARRANQUILLA", "ATLANTICO", $M, "RM1-794", null, "NICOLE RAMIREZ", "", "coordinadora", "71449503919", "cuenta_corriente", 12700],
        ["1004135574", "STEFANNY JULLIANA BURGOS GOMEZ", "STEFANNY JULLIANA BURGOS GOMEZ", "PASTO", "NARIÑO", $M, "FV-7802", 485900, "NICOLE RAMIREZ", "anticipado", "coordinadora", "71449503920", "anticipado", 15900],
        ["43187425", "NATHALIA CORREA", "NATHALIA CORREA", "ITAGUI", "ANTIOQUIA", $M, "FV-7803", 35600, "NICOLE RAMIREZ", "anticipado", "coordinadora", "71449503921", "cuenta_corriente", 7300],
        ["79895731", "HECTOR FABIO RENGIFO RENDON", "HECTOR FABIO RENGIFO RENDON", "MONIQUIRA", "BOYACA", $M, "FV-7804", 300000, "ALEJANDRA PASTRANA", "contra_entrega", "coordinadora", "71449503922", "contra_entrega", 20500],
        ["79895731", "HECTOR FABIO RENGIFO RENDON", "HECTOR FABIO RENGIFO RENDON", "MONIQUIRA", "BOYACA", $M, "FV-7805", 213000, "ALEJANDRA PASTRANA", "contra_entrega", "coordinadora", "71449503922", "contra_entrega", 20500],
        ["79895731", "HECTOR FABIO RENGIFO RENDON", "HECTOR FABIO RENGIFO RENDON", "MONIQUIRA", "BOYACA", $M, "FV-7806", 218400, "ALEJANDRA PASTRANA", "contra_entrega", "coordinadora", "71449503922", "contra_entrega", 20500],
        ["65752176", "YANETH VILLA VARON", "YANETH VILLA VARON", "IBAGUE", "TOLIMA", $M, "FV-7807", 197700, "SANDRA MONTEALEGRE", "contra_entrega", "domicilio_sandra", "", "", null],
        ["809011022", "ARTESCO S.A", "ARTESCO S.A", "IBAGUE", "TOLIMA", $G, "FE-2262", 45600, "SANDRA MONTEALEGRE", "contra_entrega", "domicilio_sandra", "", "", null],
        ["75077160", "CAMILO ERNESTO VALENCIA SUESCUN", "CAMILO ERNESTO VALENCIA SUESCUN", "MANIZALES", "CALDAS", $M, "FV-7809", 9800, "MERCADO LIBRE", "anticipado", "domicilio_don_jairo", "", "", null],
        ["7684401", "OSCAR ALBERTO FERIZ SOLANO", "OSCAR ALBERTO FERIZ SOLANO", "RIVERA", "HUILA", $M, "FV-7786", 1668000, "SANDRA MONTEALEGRE", "anticipado", "coordinadora", "71449503923", "cuenta_corriente", null],
        ["77039958", "XAVIER VERDESIA", "XAVIER VERDESIA", "CIENAGA", "MAGDALENA", $M, "FV-7810", 1183500, "ALEJANDRA PASTRANA", "transferencia", "coordinadora", "14532503461", "contra_entrega", 20100],
        ["77039958", "XAVIER VERDESIA", "XAVIER VERDESIA", "VALLEDUPAR", "CESAR", $M, "FV-7811", 1290000, "ALEJANDRA PASTRANA", "dias_3", "coordinadora", "14532503462", "contra_entrega", 20200],
        ["303870666", "LINORA IVONE BEDOYA QUINTERO", "LINORA IVONE BEDOYA QUINTERO", "ARMENIA", "QUINDIO", $M, "FV-7812", 640965, "LUZ NANCY CASTAÑEDA", "anticipado", "coordinadora", "14532503463", "contra_entrega", 9600],
        ["901798311", "MISS CABELLOS COSMETICS", "MISS CABELLOS COSMETICS", "CAREPA", "ANTIOQUIA", $M, "FV-7813", 441200, "ALEJANDRA PASTRANA", "anticipado", "coordinadora", "14532503464", "contra_entrega", 15500],
        ["87711537", "CARLOS ENRIQUE ROMO ANDRADE", "CARLOS ENRIQUE ROMO ANDRADE", "IPIALES", "NARIÑO", $G, "FE-2263", 1474495, "LUZ NANCY CASTAÑEDA", "contra_entrega", "coordinadora", "71449503925", "cuenta_corriente", null],
        ["80810274", "EL UNIVERSO DE LAS SORPRESAS S.A.S", "EL UNIVERSO DE LAS SORPRESAS S.A.S", "CALI", "V/CAUCA", $G, "FE-2264 / RM2-162", 2536200, "ENELIA VELASQUEZ", "dias_8", "coordinadora", "14532503465", "contra_entrega", 16100],
        ["43901948", "JOHANA MARIA FLOREZ ACUDELO", "JOHANA MARIA FLOREZ ACUDELO", "PUERTO BOYACA", "BOYACA", $M, "FV-7814", 373800, "MILY MURIEL", "contra_entrega", "coordinadora", "71449503926", "contra_entrega", 12700],
        ["901264229", "MERCOSMETICS S.A.S", "MERCOSMETICS S.A.S", "MONTERIA", "CORDOBA", $G, "RM2-160", null, "", "", "coordinadora", "71449503927", "cuenta_corriente", null],
    ];

    try {
        $c->begin_transaction();
        $st = $c->prepare(
            "INSERT INTO despachos (fecha, tipo_documento, nit, cliente_nombre, nombre_comercial, ciudad, departamento, valor,
                                    vendedor_nombre, forma_pago, transportadora, empresa, factura, numero_guia, flete_tipo, valor_flete)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($filas as $f) {
            [$nit, $nombre, $comercial, $ciudad, $depto, $empresa, $factura, $valor, $vend, $forma, $transp, $guia, $flete, $vflete] = $f;
            $tipo = strpos($factura, "RM") === 0 ? "remision" : "pedido";
            $st->bind_param("sssssssdsssssssd", $fecha, $tipo, $nit, $nombre, $comercial, $ciudad, $depto, $valor,
                $vend, $forma, $transp, $empresa, $factura, $guia, $flete, $vflete);
            $st->execute();
        }
        $st->close();
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        $c->query("DELETE FROM esquema_modulo WHERE clave = 'despachos_seed'");   // se reintenta en la próxima visita
        throw $e;
    }
}

/* ================= CREAR / CONSULTAR ================= */

/* ---------- Numeración automática del # de factura: FV (pedido sin factura electrónica, PE5) y FE (con factura electrónica, PE1) ---------- */

/** [prefijo => etiqueta]. Los contadores viven en la tabla numeracion_documentos con clave FV / FE. */
function prefijosFacturaDespacho(): array
{
    return [
        "FV" => "Factura de venta (pedidos PE5, sin factura electrónica)",
        "FE" => "Factura electrónica (pedidos PE1, con factura electrónica)",
    ];
}

/** [prefijo => PRÓXIMO número que se asignará]. 0 = sin configurar (la factura queda vacía para escribirla a mano, como antes). */
function ultimosNumerosFactura(mysqli $c): array
{
    asegurarTablaNumeracion($c);
    $r = ["FV" => 0, "FE" => 0];
    $res = $c->query("SELECT clave, ultimo FROM numeracion_documentos WHERE clave IN ('FV', 'FE')");
    while ($res && ($f = $res->fetch_assoc())) {
        $r[$f["clave"]] = (int) $f["ultimo"];
    }
    return $r;
}

/** Guarda lo que escribió el administrador. Vacío o 0 = sin numeración automática. Devuelve null si salió bien, o el mensaje de error. */
function guardarNumeracionFacturas(mysqli $c, $valores): ?string
{
    asegurarTablaNumeracion($c);
    $valores = is_array($valores) ? $valores : [];
    $nuevos = [];
    foreach (prefijosFacturaDespacho() as $pref => $etiqueta) {
        $v = trim((string) ($valores[$pref] ?? ""));
        if ($v === "") {
            $v = "0";
        }
        if (!preg_match('/^\d{1,9}$/', $v)) {
            return "El número de «" . $pref . "» solo puede tener dígitos (máximo 9).";
        }
        $nuevos[$pref] = (int) $v;
    }
    try {
        $c->begin_transaction();
        $st = $c->prepare("INSERT INTO numeracion_documentos (clave, ultimo) VALUES (?, ?) ON DUPLICATE KEY UPDATE ultimo = VALUES(ultimo)");
        foreach ($nuevos as $pref => $n) {
            $st->bind_param("si", $pref, $n);
            $st->execute();
        }
        $st->close();
        $c->commit();
    } catch (Throwable $e) {
        $c->rollback();
        return "No se pudo guardar la numeración de facturas.";
    }
    return null;
}

/**
 * Siguiente # de factura ("FV-7815") o "" si ese prefijo no tiene numeración configurada.
 * Llamar DENTRO de una transacción: el número queda bloqueado hasta guardar y, si falla, no se gasta. Salta los que ya estén escritos en el control.
 */
function siguienteFacturaDespacho(mysqli $c, string $prefijo): string
{
    for ($i = 0; $i < 500; $i++) {
        $st = $c->prepare("UPDATE numeracion_documentos SET ultimo = LAST_INSERT_ID(ultimo) + 1 WHERE clave = ? AND ultimo > 0");
        $st->bind_param("s", $prefijo);
        $st->execute();
        $filas = $st->affected_rows;
        $st->close();
        if ($filas < 1) {
            return "";
        }
        $factura = $prefijo . "-" . (int) $c->insert_id;
        // ¿Ya está escrita (sola o junto a otra, ej. "FE-2264 / RM2-162")?
        $a = $factura . " /%";
        $b = "%/ " . $factura;
        $m = "%/ " . $factura . " /%";
        $st = $c->prepare("SELECT id FROM despachos WHERE factura = ? OR factura LIKE ? OR factura LIKE ? OR factura LIKE ? LIMIT 1");
        $st->bind_param("ssss", $factura, $a, $b, $m);
        $st->execute();
        $uso = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$uso) {
            return $factura;
        }
    }
    return "";
}

/**
 * Registra el pedido en el control de despachos del día de hoy (una sola vez por pedido). No interrumpe el guardado si falla.
 * Los pedidos de cliente (no remisiones ni cotizaciones) reciben su # de factura automático: FV-n (PE5) o FE-n (PE1), si hay numeración configurada.
 */
function crearDespachoDePedido(mysqli $c, int $pedidoId): void
{
    $hoy = (new DateTime("now", new DateTimeZone("America/Bogota")))->format("Y-m-d");
    try {
        asegurarTablaNumeracion($c);   // DDL: antes de la transacción
        $c->begin_transaction();
        $st = $c->prepare("SELECT tipo_pedido, factura_electronica FROM pedidos_vendedores WHERE id = ? LIMIT 1");
        $st->bind_param("i", $pedidoId);
        $st->execute();
        $p = $st->get_result()->fetch_assoc();
        $st->close();
        $factura = "";
        if ($p && tipoDocumentoValido($p["tipo_pedido"] ?? "pedido") === "pedido") {
            $factura = siguienteFacturaDespacho($c, !empty($p["factura_electronica"]) ? "FE" : "FV");
        }
        $st = $c->prepare("INSERT IGNORE INTO despachos (fecha, pedido_id, factura) VALUES (?, ?, ?)");
        $st->bind_param("sis", $hoy, $pedidoId, $factura);
        $st->execute();
        $creado = $st->affected_rows === 1;
        $st->close();
        if ($creado) {
            $c->commit();
        } else {
            $c->rollback();   // ya existía: no se gasta el número
        }
    } catch (Throwable $e) {
        try {
            $c->rollback();
        } catch (Throwable $e2) {
        }
        // Si algo falló con la numeración, el despacho se crea igual (sin factura) para no perderlo
        try {
            $st = $c->prepare("INSERT IGNORE INTO despachos (fecha, pedido_id) VALUES (?, ?)");
            $st->bind_param("si", $hoy, $pedidoId);
            $st->execute();
            $st->close();
        } catch (Throwable $e3) {
            // el control de despachos nunca debe impedir que el pedido se envíe
        }
    }
}

function fechaDespachoValida($f): bool
{
    $d = DateTime::createFromFormat("Y-m-d", (string) $f);
    return $d && $d->format("Y-m-d") === (string) $f;
}

/**
 * Filas del día, ya unificadas: las ligadas a un pedido toman los datos en vivo del pedido;
 * las históricas / manuales usan los suyos.
 */
function obtenerDespachosDelDia(mysqli $c, string $fecha): array
{
    $st = $c->prepare(
        "SELECT d.*, p.id AS p_id, p.estado AS p_estado, p.tipo_pedido AS p_tipo, p.numero_pedido AS p_numero,
                p.cliente_nit AS p_nit, p.cliente_nombre AS p_nombre, p.cliente_nombre_comercial AS p_comercial,
                p.cliente_ciudad AS p_ciudad, p.cliente_departamento AS p_depto, p.total AS p_total,
                p.forma_pago AS p_forma, p.transportadora AS p_transp, p.factura_electronica AS p_fe, p.pedido_vinculado_id AS p_vinc,
                pv.id AS pv_id, pv.numero_pedido AS pv_numero, v.nombre AS p_vendedor
         FROM despachos d
         LEFT JOIN pedidos_vendedores p ON p.id = d.pedido_id
         LEFT JOIN pedidos_vendedores pv ON pv.id = p.pedido_vinculado_id
         LEFT JOIN vendedores v ON v.id = p.vendedor_id
         WHERE d.fecha = ?
         ORDER BY d.id ASC"
    );
    $st->bind_param("s", $fecha);
    $st->execute();
    $res = $st->get_result();
    $raw = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();

    $filas = [];
    foreach ($raw as $r) {
        $ligado = $r["p_id"] !== null;
        // Empresa: la que la oficina haya elegido a mano; si está vacía, sale sola.
        //  - Pedido con factura electrónica => GRUPO BELLAFORMA; sin factura electrónica => MIRELY.
        //  - Fila manual / histórica (sin pedido) => según el prefijo del # de factura, si se reconoce.
        $empresa = (string) $r["empresa"];
        if ($empresa === "") {
            $empresa = $ligado ? empresaPorFacturaElectronica($r["p_fe"]) : empresaPorFactura((string) $r["factura"]);
        }
        // # Factura: lo que la oficina haya escrito. Si está vacío y es una REMISIÓN de un ejecutivo, sale su código:
        // RM1-797 (sin factura electrónica, Mirely) o RM2-162 (con factura electrónica, Grupo Bellaforma).
        $factura = (string) $r["factura"];
        if ($factura === "" && $ligado && tipoDocumentoValido($r["p_tipo"] ?? "pedido") === "remision") {
            $numDoc = numeroDocumento(["numero_pedido" => $r["p_numero"], "id" => $r["p_id"]]);
            $factura = (!empty($r["p_fe"]) ? "RM2" : "RM1") . "-" . (int) $numDoc;
        }
        $fila = [
            "id" => (int) $r["id"],
            "pedido_id" => $ligado ? (int) $r["p_id"] : null,
            "fecha" => $r["fecha"],
            "anulado" => $ligado && $r["p_estado"] === "anulado",
            "empresa" => $empresa, "factura" => $factura, "numero_guia" => $r["numero_guia"],
            "flete_tipo" => $r["flete_tipo"], "valor_flete" => $r["valor_flete"] !== null ? (float) $r["valor_flete"] : null,
            "estado_entrega" => $r["estado_entrega"], "estado_pago" => $r["estado_pago"],
        ];
        if ($ligado) {
            $fila += [
                "tipo" => tipoDocumentoValido($r["p_tipo"] ?? "pedido"),
                "numero_doc" => numeroDocumento(["numero_pedido" => $r["p_numero"], "id" => $r["p_id"]]),
                "nit" => (string) $r["p_nit"], "cliente" => (string) $r["p_nombre"], "comercial" => (string) $r["p_comercial"],
                "ciudad" => (string) $r["p_ciudad"], "departamento" => (string) $r["p_depto"],
                "valor" => (float) $r["p_total"], "vendedor" => (string) $r["p_vendedor"],
                "forma_pago" => (string) $r["p_forma"], "transportadora" => (string) $r["p_transp"],
                // Remisión unida a un pedido: número del pedido al que va ("" si no está unida o ese pedido ya no existe)
                "vinculo_numero" => $r["pv_id"] !== null ? numeroDocumento(["numero_pedido" => $r["pv_numero"], "id" => $r["pv_id"]]) : "",
            ];
        } else {
            $fila += [
                "tipo" => tipoDocumentoValido($r["tipo_documento"] ?? "pedido"),
                "numero_doc" => "",
                "nit" => $r["nit"], "cliente" => $r["cliente_nombre"], "comercial" => $r["nombre_comercial"],
                "ciudad" => $r["ciudad"], "departamento" => $r["departamento"],
                "valor" => $r["valor"] !== null ? (float) $r["valor"] : null, "vendedor" => $r["vendedor_nombre"],
                "forma_pago" => $r["forma_pago"], "transportadora" => $r["transportadora"],
                "vinculo_numero" => "",
            ];
        }
        $filas[] = $fila;
    }
    return $filas;
}

/* ================= EDICIÓN (panel del administrador) ================= */

/**
 * Empresa según el prefijo del documento («PE5-123» → MIRELY, «PE1-45» → GRUPO BELLAFORMA).
 * Para agregar otro prefijo, añada una línea al mapa. Devuelve "" si el prefijo no se reconoce.
 */
function empresaPorFactura(string $factura): string
{
    $mapa = [
        "PE5" => "MIRELY", "FV" => "MIRELY", "RM1" => "MIRELY",
        "PE1" => "GRUPO BELLAFORMA", "FE" => "GRUPO BELLAFORMA", "RM2" => "GRUPO BELLAFORMA",
    ];
    if (!preg_match('/^\s*([A-Za-z]+\d*)\s*[-–]?/u', $factura, $m)) {
        return "";
    }
    return $mapa[strtoupper($m[1])] ?? "";
}

/** Empresa que emite el documento: con factura electrónica sale por GRUPO BELLAFORMA; sin ella, por MIRELY. */
function empresaPorFacturaElectronica($facturaElectronica): string
{
    return !empty($facturaElectronica) ? "GRUPO BELLAFORMA" : "MIRELY";
}

/** Pasa un despacho al día siguiente (por si ese día no se logró despachar). Devuelve la nueva fecha o null si no existe. */
function moverDespachoDiaSiguiente(mysqli $c, int $id): ?string
{
    $st = $c->prepare("UPDATE despachos SET fecha = DATE_ADD(fecha, INTERVAL 1 DAY) WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();
    $st = $c->prepare("SELECT fecha FROM despachos WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    return $f ? $f["fecha"] : null;
}

/** Texto corto de una línea. */
function textoDespacho($v, int $max): string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? (string) $v);
    return function_exists("mb_substr") ? mb_substr($t, 0, $max, "UTF-8") : substr($t, 0, $max);
}

/** Valor en pesos escrito por la oficina («15.500», «$ 15,500»…) → número o null si está vacío. */
function valorDespachoNumero($v): ?float
{
    $t = trim((string) $v);
    if ($t === "") {
        return null;
    }
    $n = parsearPrecio($t);
    return $n >= 0 ? $n : null;
}

/**
 * Copia un dato de envío (transportadora, guía, tipo de flete, valor del flete) a los despachos de los documentos
 * vinculados (remisión <-> pedido de cliente), porque salen juntos. Devuelve [["id"=>, "campo"=>, "valor"=>], ...]
 * para que la pantalla actualice las otras filas sin recargar. Nunca interrumpe el guardado principal.
 */
function sincronizarDespachoVinculado(mysqli $c, int $despachoId, string $campo, $valorDb, $valorPantalla): array
{
    $salida = [];
    try {
        $st = $c->prepare(
            "SELECT d2.id AS did, d2.pedido_id AS pid
             FROM despachos d
             JOIN pedidos_vendedores p ON p.id = d.pedido_id
             JOIN pedidos_vendedores p2 ON (p2.id = p.pedido_vinculado_id OR p2.pedido_vinculado_id = p.id)
             JOIN despachos d2 ON d2.pedido_id = p2.id
             WHERE d.id = ? AND d2.id <> d.id"
        );
        $st->bind_param("i", $despachoId);
        $st->execute();
        $res = $st->get_result();
        $otros = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();

        foreach ($otros as $o) {
            $otroId = (int) $o["did"];
            $otroPedido = (int) $o["pid"];
            if ($campo === "transportadora") {
                $up = $c->prepare("UPDATE pedidos_vendedores SET transportadora = ? WHERE id = ?");
                $up->bind_param("si", $valorDb, $otroPedido);
            } elseif ($campo === "valor_flete") {
                $up = $c->prepare("UPDATE despachos SET valor_flete = ? WHERE id = ?");
                $up->bind_param("di", $valorDb, $otroId);
            } elseif (in_array($campo, ["numero_guia", "flete_tipo"], true)) {
                // $campo viene de la lista blanca de arriba
                $up = $c->prepare("UPDATE despachos SET `$campo` = ? WHERE id = ?");
                $up->bind_param("si", $valorDb, $otroId);
            } else {
                continue;
            }
            $up->execute();
            $up->close();
            $salida[] = ["id" => $otroId, "campo" => $campo, "valor" => $valorPantalla];
        }
    } catch (Throwable $e) {
        // la sincronización es un extra: si falla, el cambio principal ya quedó guardado
    }
    return $salida;
}

/**
 * Cambia UN campo de un despacho (edición en línea de la tabla).
 * Devuelve ["ok" => bool, "error" => string, "valor" => valor ya guardado, "texto" => etiqueta].
 */
function actualizarCampoDespacho(mysqli $c, int $id, string $campo, string $valor): array
{
    $falla = fn(string $m) => ["ok" => false, "error" => $m];

    $st = $c->prepare("SELECT id, pedido_id FROM despachos WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$fila) {
        return $falla("Ese despacho ya no existe.");
    }
    $pedidoId = $fila["pedido_id"] !== null ? (int) $fila["pedido_id"] : null;
    $valor = trim($valor);

    // Campos de texto o catálogo que viven en `despachos`
    $catalogos = [
        "empresa" => empresasDespacho(), "flete_tipo" => fletesTipoDespacho(),
        "estado_entrega" => estadosEntregaDespacho(), "estado_pago" => estadosPagoDespacho(),
    ];
    $libres = ["factura" => 60, "numero_guia" => 40];

    if (isset($catalogos[$campo])) {
        if ($valor !== "" && !isset($catalogos[$campo][$valor])) {
            return $falla("Opción no válida.");
        }
        $guardar = $valor;
        $tipo = "s";
    } elseif (isset($libres[$campo])) {
        $guardar = textoDespacho($valor, $libres[$campo]);
        $tipo = "s";
    } elseif ($campo === "valor_flete") {
        $guardar = valorDespachoNumero($valor);
        if ($valor !== "" && $guardar === null) {
            return $falla("Escriba un valor de flete válido.");
        }
        $tipo = "d";
    } elseif ($campo === "fecha") {
        if (!fechaDespachoValida($valor)) {
            return $falla("Fecha no válida.");
        }
        $guardar = $valor;
        $tipo = "s";
    } elseif (in_array($campo, ["forma_pago", "transportadora"], true)) {
        $cat = $campo === "forma_pago" ? formasPagoDespacho() : transportadorasDespacho();
        if ($pedidoId !== null) {
            // Fila ligada a un pedido: el dato vive en el pedido (y se mantiene la condición de pago del pedido)
            if (!isset($cat[$valor])) {
                return $falla("Elija una opción de la lista.");
            }
            if ($campo === "forma_pago") {
                $dias = formaPagoDias($valor);
                $cond = $dias ? "credito" : "contado";
                $up = $c->prepare("UPDATE pedidos_vendedores SET forma_pago = ?, condicion_pago = ?, dias_credito = ? WHERE id = ?");
                $up->bind_param("ssii", $valor, $cond, $dias, $pedidoId);
            } else {
                $up = $c->prepare("UPDATE pedidos_vendedores SET transportadora = ? WHERE id = ?");
                $up->bind_param("si", $valor, $pedidoId);
            }
            $up->execute();
            $up->close();
            $resp = ["ok" => true, "valor" => $valor, "texto" => $cat[$valor]];
            if ($campo === "transportadora") {
                // Pedido y remisión van juntos: la transportadora también se pone en el documento vinculado
                $resp["vinculados"] = sincronizarDespachoVinculado($c, $id, "transportadora", $valor, $valor);
            }
            return $resp;
        }
        if ($valor !== "" && !isset($cat[$valor])) {
            return $falla("Opción no válida.");
        }
        $guardar = $valor;
        $tipo = "s";
    } else {
        return $falla("Campo no editable.");
    }

    // $campo sale de las listas blancas de arriba, nunca del usuario directamente
    $up = $c->prepare("UPDATE despachos SET `$campo` = ? WHERE id = ?");
    $up->bind_param($tipo . "i", $guardar, $id);
    $up->execute();
    $up->close();
    $resp = ["ok" => true, "valor" => $guardar, "texto" => is_string($guardar) ? $guardar : ($guardar === null ? "" : (string) $guardar)];

    // Pedido y remisión vinculados van juntos: guía, tipo de flete y valor del flete se copian al otro documento
    if (in_array($campo, ["numero_guia", "flete_tipo", "valor_flete"], true)) {
        $resp["vinculados"] = sincronizarDespachoVinculado($c, $id, $campo, $guardar, $guardar);
    }

    // El prefijo del documento define la empresa (PE5 = MIRELY, PE1 = GRUPO BELLAFORMA…)
    if ($campo === "factura") {
        $empresa = empresaPorFactura((string) $guardar);
        if ($empresa !== "") {
            $up = $c->prepare("UPDATE despachos SET empresa = ? WHERE id = ?");
            $up->bind_param("si", $empresa, $id);
            $up->execute();
            $up->close();
            $resp["empresa"] = $empresa;
        }
    }
    return $resp;
}

/**
 * Crea o edita un despacho manual / histórico (sin pedido). Devuelve ["ok" => bool, "error" => string, "id" => int].
 */
function guardarDespachoManual(mysqli $c, ?int $id, array $in): array
{
    $falla = fn(string $m) => ["ok" => false, "error" => $m];

    $fecha = (string) ($in["fecha"] ?? "");
    if (!fechaDespachoValida($fecha)) {
        return $falla("Elija una fecha válida.");
    }
    $cliente = textoDespacho($in["cliente_nombre"] ?? "", 200);
    if ($cliente === "") {
        return $falla("Escriba el nombre del cliente.");
    }
    $tipo = tipoDocumentoValido($in["tipo_documento"] ?? "pedido");
    $nit = textoDespacho($in["nit"] ?? "", 40);
    $comercial = textoDespacho($in["nombre_comercial"] ?? "", 200);
    $ciudad = textoDespacho($in["ciudad"] ?? "", 100);
    $depto = textoDespacho($in["departamento"] ?? "", 80);
    $vendedor = textoDespacho($in["vendedor_nombre"] ?? "", 150);
    $valorTxt = trim((string) ($in["valor"] ?? ""));
    $valor = valorDespachoNumero($valorTxt);
    if ($valorTxt !== "" && $valor === null) {
        return $falla("Escriba un valor válido.");
    }
    $forma = (string) ($in["forma_pago"] ?? "");
    $transp = (string) ($in["transportadora"] ?? "");
    // Se aceptan las opciones del catálogo (o dejarlo vacío); un dato histórico distinto solo se conserva si ya estaba así
    if ($forma !== "" && !isset(formasPagoDespacho()[$forma]) && !($id && $forma === ($in["_forma_actual"] ?? null))) {
        return $falla("Forma de pago no válida.");
    }
    if ($transp !== "" && !isset(transportadorasDespacho()[$transp]) && !($id && $transp === ($in["_transp_actual"] ?? null))) {
        return $falla("Transportadora no válida.");
    }
    $empresa = (string) ($in["empresa"] ?? "");
    if ($empresa !== "" && !isset(empresasDespacho()[$empresa])) {
        return $falla("Empresa no válida.");
    }
    $factura = textoDespacho($in["factura"] ?? "", 60);

    try {
        if ($id) {
            $st = $c->prepare(
                "UPDATE despachos SET fecha = ?, tipo_documento = ?, nit = ?, cliente_nombre = ?, nombre_comercial = ?, ciudad = ?,
                        departamento = ?, valor = ?, vendedor_nombre = ?, forma_pago = ?, transportadora = ?, empresa = ?, factura = ?
                 WHERE id = ? AND pedido_id IS NULL"
            );
            $st->bind_param("sssssssdsssssi", $fecha, $tipo, $nit, $cliente, $comercial, $ciudad, $depto, $valor, $vendedor, $forma, $transp, $empresa, $factura, $id);
            $st->execute();
            $st->close();
            return ["ok" => true, "id" => $id];
        }
        $st = $c->prepare(
            "INSERT INTO despachos (fecha, tipo_documento, nit, cliente_nombre, nombre_comercial, ciudad, departamento, valor,
                                    vendedor_nombre, forma_pago, transportadora, empresa, factura)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->bind_param("sssssssdsssss", $fecha, $tipo, $nit, $cliente, $comercial, $ciudad, $depto, $valor, $vendedor, $forma, $transp, $empresa, $factura);
        $st->execute();
        $nuevo = (int) $c->insert_id;
        $st->close();
        return ["ok" => true, "id" => $nuevo];
    } catch (Throwable $e) {
        return $falla("No se pudo guardar el despacho.");
    }
}
