<?php
/**
 * Base del módulo de ejecutivos de negocios / pedidos al por mayor.
 * - Crea y actualiza las tablas automáticamente (no hay que ejecutar SQL a mano).
 * - Funciones de formato y consulta de pedidos (admin.php, pedido_pdf.php, pedido_excel.php, vendedor.php).
 */

const ESQUEMA_VEND_VERSION = 5;

function agregarColumna(mysqli $c, string $tabla, string $col, string $def): bool
{
    try {
        $r = $c->query("SHOW COLUMNS FROM `$tabla` LIKE '$col'");
        if ($r && $r->num_rows === 0) {
            $c->query("ALTER TABLE `$tabla` ADD COLUMN `$col` $def");
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function asegurarTablasVendedores(mysqli $c): void
{
    static $listo = false;
    if ($listo) {
        return;
    }
    $listo = true;

    $c->query("CREATE TABLE IF NOT EXISTS esquema_modulo (
        clave VARCHAR(40) NOT NULL PRIMARY KEY,
        valor INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $r = $c->query("SELECT valor FROM esquema_modulo WHERE clave = 'vendedores'");
    $fila = $r ? $r->fetch_row() : null;
    if ($fila && (int) $fila[0] >= ESQUEMA_VEND_VERSION) {
        return;
    }

    $c->query("CREATE TABLE IF NOT EXISTS vendedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        identificacion VARCHAR(40) NOT NULL,
        ciudad VARCHAR(100) NULL,
        telefono VARCHAR(40) NULL,
        usuario VARCHAR(60) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_vendedor_usuario (usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $c->query("CREATE TABLE IF NOT EXISTS productos_mayoristas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referencia VARCHAR(60) NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        precio DECIMAL(12,2) NOT NULL DEFAULT 0,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_producto_referencia (referencia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Fotos de productos guardadas en la base de datos (no se pierden al redesplegar en Railway)
    $c->query("CREATE TABLE IF NOT EXISTS producto_fotos (
        producto_id INT NOT NULL PRIMARY KEY,
        mime VARCHAR(40) NOT NULL,
        datos MEDIUMBLOB NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Base de datos de clientes (estructura basada en el catálogo de terceros de Syscafe)
    $c->query("CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo_persona VARCHAR(10) NOT NULL DEFAULT 'juridica',
        tipo_documento VARCHAR(20) NOT NULL DEFAULT 'NIT',
        identificacion VARCHAR(30) NOT NULL,
        identificacion_norm VARCHAR(30) NOT NULL,
        dv VARCHAR(3) NULL,
        codigo VARCHAR(30) NULL,
        razon_social VARCHAR(200) NOT NULL,
        nombre_comercial VARCHAR(200) NULL,
        direccion VARCHAR(255) NULL,
        direccion2 VARCHAR(255) NULL,
        puntos_referencia VARCHAR(255) NULL,
        telefono1 VARCHAR(40) NULL,
        telefono2 VARCHAR(40) NULL,
        telefono3 VARCHAR(40) NULL,
        movil VARCHAR(40) NULL,
        codigo_postal VARCHAR(20) NULL,
        email VARCHAR(150) NULL,
        email_fe VARCHAR(150) NULL,
        departamento VARCHAR(80) NULL,
        municipio VARCHAR(100) NULL,
        codigo_municipio VARCHAR(12) NULL,
        pais VARCHAR(60) NULL DEFAULT 'Colombia',
        barrio VARCHAR(120) NULL,
        grupo VARCHAR(80) NULL,
        subgrupo VARCHAR(80) NULL,
        encargado VARCHAR(150) NULL,
        representante_legal VARCHAR(150) NULL,
        zona VARCHAR(80) NULL,
        vendedor_asignado VARCHAR(100) NULL,
        cobrador VARCHAR(100) NULL,
        lista_precios VARCHAR(40) NULL,
        condicion_pago VARCHAR(10) NOT NULL DEFAULT 'contado',
        dias_credito INT NULL,
        cupo_cartera DECIMAL(14,2) NOT NULL DEFAULT 0,
        observaciones TEXT NULL,
        inactivo TINYINT(1) NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cliente_ident (identificacion_norm),
        KEY idx_cliente_razon (razon_social)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $c->query("CREATE TABLE IF NOT EXISTS pedidos_vendedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id INT NOT NULL,
        cliente_nombre VARCHAR(200) NOT NULL DEFAULT '',
        cliente_ciudad VARCHAR(100) NOT NULL DEFAULT '',
        cliente_nit VARCHAR(40) NOT NULL DEFAULT '',
        cliente_telefono VARCHAR(40) NOT NULL DEFAULT '',
        cliente_barrio VARCHAR(120) NULL,
        cliente_direcciones TEXT NULL,
        observaciones TEXT NULL,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        estado ENUM('borrador','pendiente','procesado','anulado') NOT NULL DEFAULT 'pendiente',
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ped_vendedor (vendedor_id),
        KEY idx_ped_fecha (creado_en),
        CONSTRAINT fk_ped_vendedor FOREIGN KEY (vendedor_id) REFERENCES vendedores(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $c->query("CREATE TABLE IF NOT EXISTS pedido_vendedor_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id INT NOT NULL,
        producto_id INT NULL,
        referencia VARCHAR(60) NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        precio_unitario DECIMAL(12,2) NOT NULL,
        cantidad INT NOT NULL,
        subtotal DECIMAL(14,2) NOT NULL,
        KEY idx_item_pedido (pedido_id),
        CONSTRAINT fk_item_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos_vendedores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Migración de pedidos (por si la tabla ya existía con la versión anterior)
    $ok = true;
    try {
        $c->query("ALTER TABLE pedidos_vendedores MODIFY estado ENUM('borrador','pendiente','procesado','anulado') NOT NULL DEFAULT 'pendiente'");
    } catch (Throwable $e) {
        $ok = false;
    }
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_id", "INT NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_tipo_persona", "VARCHAR(10) NOT NULL DEFAULT 'juridica'") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_tipo_documento", "VARCHAR(20) NOT NULL DEFAULT 'NIT'") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_nombre_comercial", "VARCHAR(200) NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_departamento", "VARCHAR(80) NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_puntos_referencia", "VARCHAR(255) NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "cliente_email", "VARCHAR(150) NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "factura_electronica", "TINYINT(1) NOT NULL DEFAULT 0") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "email_fe", "VARCHAR(150) NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "condicion_pago", "VARCHAR(10) NOT NULL DEFAULT 'contado'") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "dias_credito", "INT NULL") && $ok;
    $ok = agregarColumna($c, "pedidos_vendedores", "actualizado_en", "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP") && $ok;

    // Solo se marca como terminado si TODO se aplicó; si no, se reintenta en la próxima visita
    if ($ok) {
        $c->query("REPLACE INTO esquema_modulo (clave, valor) VALUES ('vendedores', " . ESQUEMA_VEND_VERSION . ")");
    }
}

/* ================= FORMATO ================= */

function formatoCOP($valor): string
{
    return "$ " . number_format((float) $valor, 0, ",", ".");
}

function etiquetaEstadoPedido(string $estado): string
{
    $mapa = [
        "borrador"  => "Borrador",
        "pendiente" => "Enviado",
        "procesado" => "Subido a Syscafe",
        "anulado"   => "Anulado",
    ];
    return $mapa[$estado] ?? $estado;
}

function claseEstadoPedido(string $estado): string
{
    $mapa = [
        "borrador"  => "estado-borrador",
        "pendiente" => "estado-pendiente",
        "procesado" => "estado-activo",
        "anulado"   => "estado-inactivo",
    ];
    return $mapa[$estado] ?? "estado-sin";
}

function numeroPedido($id): string
{
    return str_pad((string) (int) $id, 6, "0", STR_PAD_LEFT);
}

function etiquetaDocumento(string $tipoPersona): string
{
    return $tipoPersona === "natural" ? "Cédula" : "NIT";
}

function etiquetaCondicion(array $p): string
{
    if (($p["condicion_pago"] ?? "contado") === "credito") {
        return "Crédito a " . (int) ($p["dias_credito"] ?? 0) . " días";
    }
    return "Contado";
}

function parsearPrecio(string $texto): float
{
    $t = trim(str_replace(["$", " "], "", $texto));
    if ($t === "") {
        return 0.0;
    }
    if (strpos($t, ",") !== false && strpos($t, ".") !== false) {
        if (strrpos($t, ",") > strrpos($t, ".")) {
            $t = str_replace(".", "", $t);
            $t = str_replace(",", ".", $t);
        } else {
            $t = str_replace(",", "", $t);
        }
    } elseif (strpos($t, ",") !== false) {
        $t = preg_match('/^\d{1,3}(,\d{3})+$/', $t) ? str_replace(",", "", $t) : str_replace(",", ".", $t);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
        $t = str_replace(".", "", $t);
    }
    return is_numeric($t) ? round((float) $t, 2) : 0.0;
}

/* ================= CONSULTA DE PEDIDOS ================= */

/**
 * Devuelve pedidos con sus ítems en $pedido["items"].
 * Filtros: vendedor (id), estado, desde, hasta (Y-m-d), buscar (cliente / documento / ciudad / n° pedido),
 *          con_borradores (true para incluir borradores cuando no se filtra por estado).
 */
function obtenerPedidos(mysqli $c, array $f = [], ?int $limite = 300, ?int $id = null): array
{
    $sql = "SELECT p.*, v.nombre AS vendedor_nombre, v.identificacion AS vendedor_identificacion
            FROM pedidos_vendedores p
            JOIN vendedores v ON v.id = p.vendedor_id";
    $where = [];
    $tipos = "";
    $params = [];

    if ($id !== null && $id > 0) {
        $where[] = "p.id = ?";
        $tipos .= "i";
        $params[] = $id;
    } else {
        $vend = (int) ($f["vendedor"] ?? 0);
        if ($vend > 0) {
            $where[] = "p.vendedor_id = ?";
            $tipos .= "i";
            $params[] = $vend;
        }

        $estado = $f["estado"] ?? "";
        if (in_array($estado, ["borrador", "pendiente", "procesado", "anulado"], true)) {
            $where[] = "p.estado = ?";
            $tipos .= "s";
            $params[] = $estado;
        } elseif (empty($f["con_borradores"])) {
            $where[] = "p.estado <> 'borrador'";
        }

        $desde = $f["desde"] ?? "";
        $hasta = $f["hasta"] ?? "";
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
            $where[] = "DATE(p.creado_en) >= ?";
            $tipos .= "s";
            $params[] = $desde;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            $where[] = "DATE(p.creado_en) <= ?";
            $tipos .= "s";
            $params[] = $hasta;
        }

        $buscar = trim($f["buscar"] ?? "");
        if ($buscar !== "") {
            $where[] = "(p.cliente_nombre LIKE ? OR p.cliente_nombre_comercial LIKE ? OR p.cliente_nit LIKE ? OR p.cliente_ciudad LIKE ? OR p.id = ?)";
            $like = "%" . $buscar . "%";
            $tipos .= "ssssi";
            array_push($params, $like, $like, $like, $like, (int) $buscar);
        }
    }

    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY p.actualizado_en DESC, p.id DESC";
    if ($limite !== null && $limite > 0) {
        $sql .= " LIMIT " . (int) $limite;
    }

    $stmt = $c->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($params) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $pedidos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();

    if (!$pedidos) {
        return [];
    }

    $ids = implode(",", array_map(fn($p) => (int) $p["id"], $pedidos));
    $items = [];
    $resItems = $c->query("SELECT * FROM pedido_vendedor_items WHERE pedido_id IN ($ids) ORDER BY id ASC");
    if ($resItems) {
        while ($it = $resItems->fetch_assoc()) {
            $items[(int) $it["pedido_id"]][] = $it;
        }
    }
    foreach ($pedidos as &$p) {
        $p["items"] = $items[(int) $p["id"]] ?? [];
    }
    unset($p);

    return $pedidos;
}
