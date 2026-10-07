<?php
/**
 * Utilidades compartidas del módulo de vendedores / pedidos al por mayor.
 * - Crea las tablas automáticamente (CREATE TABLE IF NOT EXISTS).
 * - Funciones de formato y consulta de pedidos (usadas por admin.php y pedido_pdf.php).
 */

function asegurarTablasVendedores(mysqli $c): void
{
    static $listo = false;
    if ($listo) {
        return;
    }
    $listo = true;

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

    $c->query("CREATE TABLE IF NOT EXISTS pedidos_vendedores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vendedor_id INT NOT NULL,
        cliente_nombre VARCHAR(200) NOT NULL,
        cliente_ciudad VARCHAR(100) NOT NULL,
        cliente_nit VARCHAR(40) NOT NULL,
        cliente_telefono VARCHAR(40) NOT NULL,
        cliente_barrio VARCHAR(120) NULL,
        cliente_direcciones TEXT NULL,
        observaciones TEXT NULL,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        estado ENUM('pendiente','procesado','anulado') NOT NULL DEFAULT 'pendiente',
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
}

function formatoCOP($valor): string
{
    return "$ " . number_format((float) $valor, 0, ",", ".");
}

function etiquetaEstadoPedido(string $estado): string
{
    $mapa = [
        "pendiente" => "Pendiente",
        "procesado" => "Procesado en Syscafe",
        "anulado"   => "Anulado",
    ];
    return $mapa[$estado] ?? $estado;
}

function claseEstadoPedido(string $estado): string
{
    $mapa = [
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

/**
 * Devuelve pedidos (con sus ítems en $pedido["items"]).
 * Filtros: vendedor (id), estado, desde (Y-m-d), hasta (Y-m-d), buscar (cliente / NIT / n° pedido).
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
        if (in_array($estado, ["pendiente", "procesado", "anulado"], true)) {
            $where[] = "p.estado = ?";
            $tipos .= "s";
            $params[] = $estado;
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
            $where[] = "(p.cliente_nombre LIKE ? OR p.cliente_nit LIKE ? OR p.cliente_ciudad LIKE ? OR p.id = ?)";
            $like = "%" . $buscar . "%";
            $tipos .= "sssi";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = (int) $buscar;
        }
    }

    if ($where) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }
    $sql .= " ORDER BY p.creado_en DESC, p.id DESC";
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

/**
 * Convierte "12.500", "12500", "12.500,50", "$ 12,500.50" (formato colombiano o simple) a float.
 */
function parsearPrecio(string $texto): float
{
    $t = trim(str_replace(["$", " "], "", $texto));
    if ($t === "") {
        return 0.0;
    }
    if (strpos($t, ",") !== false && strpos($t, ".") !== false) {
        // El último separador es el decimal
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
