<?php
session_start();

if (empty($_SESSION["vendedor_id"])) {
    header("Location: login_vendedor.php");
    exit;
}

require_once "conexion.php";
require_once "vendedores_db.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}

$vendedorId = (int) $_SESSION["vendedor_id"];

// El vendedor debe seguir activo
$stmt = $conexion->prepare("SELECT id, nombre, identificacion FROM vendedores WHERE id = ? AND activo = 1 LIMIT 1");
$stmt->bind_param("i", $vendedorId);
$stmt->execute();
$res = $stmt->get_result();
$vendedor = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$vendedor) {
    unset($_SESSION["vendedor_id"], $_SESSION["vendedor_nombre"]);
    header("Location: login_vendedor.php");
    exit;
}

if (empty($_SESSION["csrf_vendedor"])) {
    $_SESSION["csrf_vendedor"] = bin2hex(random_bytes(16));
}

$mensaje = $_SESSION["msg_vendedor"] ?? "";
$tipoMensaje = $_SESSION["msg_vendedor_tipo"] ?? "exito";
unset($_SESSION["msg_vendedor"], $_SESSION["msg_vendedor_tipo"]);

function volverConMensaje($texto, $tipo, $panel = "nuevo")
{
    $_SESSION["msg_vendedor"] = $texto;
    $_SESSION["msg_vendedor_tipo"] = $tipo;
    header("Location: vendedor.php#" . $panel);
    exit;
}

/* ===== CREAR PEDIDO ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? "") === "crear_pedido") {

    if (!hash_equals($_SESSION["csrf_vendedor"], $_POST["csrf"] ?? "")) {
        volverConMensaje("La sesión expiró. Intente de nuevo.", "error");
    }

    $cNombre = trim($_POST["cliente_nombre"] ?? "");
    $cCiudad = trim($_POST["cliente_ciudad"] ?? "");
    $cNit = trim($_POST["cliente_nit"] ?? "");
    $cTelefono = trim($_POST["cliente_telefono"] ?? "");
    $cBarrio = trim($_POST["cliente_barrio"] ?? "");
    $cDirecciones = trim($_POST["cliente_direcciones"] ?? "");
    $observaciones = trim($_POST["observaciones"] ?? "");

    if ($cNombre === "" || $cCiudad === "" || $cNit === "" || $cTelefono === "") {
        volverConMensaje("Complete nombre, ciudad, NIT y teléfono del cliente.", "error");
    }

    $itemsEntrada = json_decode($_POST["items_json"] ?? "{}", true);
    $cantidades = [];
    if (is_array($itemsEntrada)) {
        foreach ($itemsEntrada as $pid => $cant) {
            $pid = (int) $pid;
            $cant = (int) $cant;
            if ($pid > 0 && $cant > 0 && $cant <= 100000) {
                $cantidades[$pid] = $cant;
            }
        }
    }

    if (!$cantidades) {
        volverConMensaje("Seleccione al menos un producto con cantidad.", "error");
    }

    // Los precios SIEMPRE se leen de la base de datos (no del navegador)
    $idsLista = implode(",", array_map("intval", array_keys($cantidades)));
    $resProd = $conexion->query(
        "SELECT id, referencia, nombre, precio FROM productos_mayoristas WHERE activo = 1 AND id IN ($idsLista)"
    );
    $lineas = [];
    $total = 0.0;
    if ($resProd) {
        while ($p = $resProd->fetch_assoc()) {
            $cant = $cantidades[(int) $p["id"]];
            $precio = (float) $p["precio"];
            $sub = round($precio * $cant, 2);
            $total += $sub;
            $lineas[] = [
                "id" => (int) $p["id"],
                "referencia" => $p["referencia"],
                "nombre" => $p["nombre"],
                "precio" => $precio,
                "cantidad" => $cant,
                "subtotal" => $sub,
            ];
        }
    }

    if (!$lineas) {
        volverConMensaje("Los productos seleccionados ya no están disponibles.", "error");
    }

    $total = round($total, 2);

    try {
        $conexion->begin_transaction();

        $ins = $conexion->prepare(
            "INSERT INTO pedidos_vendedores
             (vendedor_id, cliente_nombre, cliente_ciudad, cliente_nit, cliente_telefono, cliente_barrio, cliente_direcciones, observaciones, total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param(
            "isssssssd",
            $vendedorId, $cNombre, $cCiudad, $cNit, $cTelefono, $cBarrio, $cDirecciones, $observaciones, $total
        );
        $ins->execute();
        $pedidoId = $conexion->insert_id;
        $ins->close();

        $insItem = $conexion->prepare(
            "INSERT INTO pedido_vendedor_items (pedido_id, producto_id, referencia, nombre, precio_unitario, cantidad, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($lineas as $l) {
            $insItem->bind_param(
                "iissdid",
                $pedidoId, $l["id"], $l["referencia"], $l["nombre"], $l["precio"], $l["cantidad"], $l["subtotal"]
            );
            $insItem->execute();
        }
        $insItem->close();

        $conexion->commit();
    } catch (Throwable $ex) {
        $conexion->rollback();
        volverConMensaje("No se pudo guardar el pedido. Intente de nuevo.", "error");
    }

    volverConMensaje(
        "Pedido #" . numeroPedido($pedidoId) . " enviado por " . formatoCOP($total) . ".",
        "exito",
        "mis-pedidos"
    );
}

/* ===== DATOS PARA MOSTRAR ===== */
$productos = [];
$resProductos = $conexion->query(
    "SELECT id, referencia, nombre, precio FROM productos_mayoristas WHERE activo = 1 ORDER BY referencia ASC"
);
if ($resProductos) {
    $productos = $resProductos->fetch_all(MYSQLI_ASSOC);
}

$misPedidos = obtenerPedidos($conexion, ["vendedor" => $vendedorId], 100);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos | Grupo Bellaforma</title>
    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="stylesheet" href="css/vendedor.css">
</head>

<body>

    <div class="topbar">
        <img src="img/logo-blanco.png" alt="Grupo Bella Forma S.A.S.">
        <div class="quien">
            <span>👤 <?= e($vendedor["nombre"]) ?> · ID <?= e($vendedor["identificacion"]) ?></span>
            <a class="salir" href="logout_vendedor.php">Cerrar sesión</a>
        </div>
    </div>

    <div class="wrap">

        <?php if ($mensaje !== ""): ?>
            <div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <button type="button" class="tab-btn activo" data-panel="nuevo">🛒 Nuevo pedido</button>
            <button type="button" class="tab-btn" data-panel="mis-pedidos">📋 Mis pedidos (<?= count($misPedidos) ?>)</button>
        </div>

        <!-- ================= NUEVO PEDIDO ================= -->
        <div id="panel-nuevo" class="panel activo">
            <form method="POST" action="vendedor.php" id="formPedido">
                <input type="hidden" name="accion" value="crear_pedido">
                <input type="hidden" name="csrf" value="<?= e($_SESSION["csrf_vendedor"]) ?>">
                <input type="hidden" name="items_json" id="items_json" value="{}">

                <div class="card">
                    <h2>1. Datos del cliente</h2>
                    <p class="sub">Los campos con * son obligatorios.</p>
                    <div class="grid-form">
                        <div>
                            <label for="cliente_nombre">Nombre del cliente *</label>
                            <input type="text" id="cliente_nombre" name="cliente_nombre" maxlength="200" required>
                        </div>
                        <div>
                            <label for="cliente_nit">NIT *</label>
                            <input type="text" id="cliente_nit" name="cliente_nit" maxlength="40" required>
                        </div>
                        <div>
                            <label for="cliente_ciudad">Ciudad *</label>
                            <input type="text" id="cliente_ciudad" name="cliente_ciudad" maxlength="100" required>
                        </div>
                        <div>
                            <label for="cliente_telefono">Teléfono *</label>
                            <input type="text" id="cliente_telefono" name="cliente_telefono" maxlength="40" required>
                        </div>
                        <div>
                            <label for="cliente_barrio">Barrio</label>
                            <input type="text" id="cliente_barrio" name="cliente_barrio" maxlength="120">
                        </div>
                        <div class="ancho">
                            <label for="cliente_direcciones">Dirección y otras direcciones de entrega</label>
                            <textarea id="cliente_direcciones" name="cliente_direcciones"></textarea>
                        </div>
                        <div class="ancho">
                            <label for="observaciones">Observaciones del pedido</label>
                            <textarea id="observaciones" name="observaciones"></textarea>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h2>2. Productos</h2>
                    <p class="sub">Busque por referencia o nombre e ingrese la cantidad. El total se calcula solo.</p>

                    <div class="buscador">
                        <input type="text" id="buscarCatalogo" placeholder="🔍 Buscar por referencia o nombre..." autocomplete="off">
                    </div>

                    <div class="tabla-wrap">
                        <table id="tablaCatalogo">
                            <thead>
                                <tr>
                                    <th>Referencia</th>
                                    <th>Producto</th>
                                    <th class="num">Precio</th>
                                    <th class="num">Cantidad</th>
                                    <th class="num">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$productos): ?>
                                    <tr><td colspan="5" class="sin-resultados">Aún no hay productos cargados.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($productos as $p): ?>
                                        <tr data-id="<?= (int) $p["id"] ?>"
                                            data-ref="<?= e($p["referencia"]) ?>"
                                            data-nombre="<?= e($p["nombre"]) ?>"
                                            data-precio="<?= e($p["precio"]) ?>">
                                            <td class="ref"><?= e($p["referencia"]) ?></td>
                                            <td><?= e($p["nombre"]) ?></td>
                                            <td class="num"><?= formatoCOP($p["precio"]) ?></td>
                                            <td class="num"><input type="number" class="qty" min="0" max="100000" step="1" placeholder="0" inputmode="numeric"></td>
                                            <td class="num sub">—</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="filaSinResultados" style="display:none;"><td colspan="5" class="sin-resultados">Ningún producto coincide con la búsqueda.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <details class="resumen">
                        <summary>Ver resumen del pedido (<span id="resumenCantidad">0</span> productos)</summary>
                        <ul id="listaResumen"></ul>
                    </details>
                </div>

                <div class="barra-total">
                    <div class="total">
                        <small>Total del pedido</small>
                        <span id="totalPedido">$ 0</span>
                    </div>
                    <button type="submit" class="btn-enviar">Enviar pedido</button>
                </div>
            </form>
        </div>

        <!-- ================= MIS PEDIDOS ================= -->
        <div id="panel-mis-pedidos" class="panel">
            <div class="card">
                <h2>Mis pedidos</h2>
                <p class="sub">Se muestran sus últimos 100 pedidos.</p>

                <?php if (!$misPedidos): ?>
                    <p class="sin-resultados">Todavía no ha enviado pedidos.</p>
                <?php else: ?>
                    <?php foreach ($misPedidos as $ped): ?>
                        <details class="pedido-item">
                            <summary>
                                <strong>#<?= numeroPedido($ped["id"]) ?></strong>
                                <span><?= date("d/m/Y H:i", strtotime($ped["creado_en"])) ?></span>
                                <span><?= e($ped["cliente_nombre"]) ?></span>
                                <strong><?= formatoCOP($ped["total"]) ?></strong>
                                <span class="estado <?= claseEstadoPedido($ped["estado"]) ?>"><?= e(etiquetaEstadoPedido($ped["estado"])) ?></span>
                            </summary>
                            <div class="cuerpo">
                                <div class="datos">
                                    NIT: <?= e($ped["cliente_nit"]) ?> · Tel: <?= e($ped["cliente_telefono"]) ?> ·
                                    Ciudad: <?= e($ped["cliente_ciudad"]) ?>
                                    <?php if ($ped["cliente_barrio"] !== null && $ped["cliente_barrio"] !== ""): ?> · Barrio: <?= e($ped["cliente_barrio"]) ?><?php endif; ?>
                                    <?php if (!empty($ped["cliente_direcciones"])): ?><br>Direcciones: <?= nl2br(e($ped["cliente_direcciones"])) ?><?php endif; ?>
                                    <?php if (!empty($ped["observaciones"])): ?><br>Observaciones: <?= nl2br(e($ped["observaciones"])) ?><?php endif; ?>
                                </div>
                                <div class="tabla-wrap">
                                    <table>
                                        <thead>
                                            <tr><th>Ref.</th><th>Producto</th><th class="num">Cant.</th><th class="num">Precio</th><th class="num">Subtotal</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ped["items"] as $it): ?>
                                                <tr>
                                                    <td class="ref"><?= e($it["referencia"]) ?></td>
                                                    <td><?= e($it["nombre"]) ?></td>
                                                    <td class="num"><?= (int) $it["cantidad"] ?></td>
                                                    <td class="num"><?= formatoCOP($it["precio_unitario"]) ?></td>
                                                    <td class="num"><?= formatoCOP($it["subtotal"]) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        // ---------- Pestañas ----------
        function abrirPanel(nombre) {
            document.querySelectorAll(".panel").forEach(function (p) { p.classList.remove("activo"); });
            document.querySelectorAll(".tab-btn").forEach(function (b) { b.classList.remove("activo"); });
            var panel = document.getElementById("panel-" + nombre);
            if (!panel) { nombre = "nuevo"; panel = document.getElementById("panel-nuevo"); }
            panel.classList.add("activo");
            var boton = document.querySelector('.tab-btn[data-panel="' + nombre + '"]');
            if (boton) { boton.classList.add("activo"); }
            document.querySelector(".barra-total").style.display = (nombre === "nuevo") ? "flex" : "none";
        }
        document.querySelectorAll(".tab-btn").forEach(function (b) {
            b.addEventListener("click", function () {
                history.replaceState(null, "", "#" + b.dataset.panel);
                abrirPanel(b.dataset.panel);
            });
        });
        abrirPanel(location.hash.replace("#", "") || "nuevo");

        // ---------- Catálogo ----------
        var filas = Array.prototype.slice.call(document.querySelectorAll("#tablaCatalogo tbody tr[data-id]"));

        function normalizar(t) {
            return (t || "").toString().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        }
        function dinero(n) {
            return "$ " + Math.round(n).toLocaleString("es-CO");
        }

        filas.forEach(function (tr) {
            tr.dataset.busq = normalizar(tr.dataset.ref + " " + tr.dataset.nombre);
            tr.querySelector(".qty").addEventListener("input", recalcular);
        });

        document.getElementById("buscarCatalogo").addEventListener("input", function () {
            var q = normalizar(this.value.trim());
            var visibles = 0;
            filas.forEach(function (tr) {
                var ok = q === "" || tr.dataset.busq.indexOf(q) !== -1;
                tr.style.display = ok ? "" : "none";
                if (ok) { visibles++; }
            });
            var vacio = document.getElementById("filaSinResultados");
            if (vacio) { vacio.style.display = visibles === 0 ? "" : "none"; }
        });

        function recalcular() {
            var total = 0, cuantos = 0;
            var lista = document.getElementById("listaResumen");
            lista.innerHTML = "";

            filas.forEach(function (tr) {
                var cant = parseInt(tr.querySelector(".qty").value, 10) || 0;
                var celda = tr.querySelector(".sub");
                if (cant > 0) {
                    var sub = cant * parseFloat(tr.dataset.precio);
                    total += sub;
                    cuantos++;
                    celda.textContent = dinero(sub);
                    tr.classList.add("con-cantidad");
                    var li = document.createElement("li");
                    li.textContent = tr.dataset.ref + " — " + tr.dataset.nombre + " × " + cant + " = " + dinero(sub);
                    lista.appendChild(li);
                } else {
                    celda.textContent = "—";
                    tr.classList.remove("con-cantidad");
                }
            });

            document.getElementById("totalPedido").textContent = dinero(total);
            document.getElementById("resumenCantidad").textContent = cuantos;
        }

        document.getElementById("formPedido").addEventListener("submit", function (ev) {
            var items = {};
            var cuantos = 0;
            filas.forEach(function (tr) {
                var cant = parseInt(tr.querySelector(".qty").value, 10) || 0;
                if (cant > 0) { items[tr.dataset.id] = cant; cuantos++; }
            });
            if (cuantos === 0) {
                ev.preventDefault();
                alert("Seleccione al menos un producto con cantidad.");
                return;
            }
            if (!confirm("¿Enviar el pedido por " + document.getElementById("totalPedido").textContent + "?")) {
                ev.preventDefault();
                return;
            }
            document.getElementById("items_json").value = JSON.stringify(items);
        });
    </script>

</body>

</html>
