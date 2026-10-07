<?php
/* Sección "Productos al por mayor" del panel admin (se incluye dentro de admin.php). */

$productosMayor = [];
$resPM = $conexion->query("SELECT id, referencia, nombre, precio, activo FROM productos_mayoristas ORDER BY referencia ASC");
if ($resPM) {
    $productosMayor = $resPM->fetch_all(MYSQLI_ASSOC);
}
?>
<section id="productos_mayoristas" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🏷️ Productos al por mayor</h2>
            <p>Catálogo que ven los vendedores. La <strong>referencia</strong> es la misma que se usa en Syscafe.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="nuevoProducto()">+ Nuevo Producto</button>
    </div>

    <div class="filtros">
        <div>
            <label for="buscarProductoMayor">Buscar</label>
            <input type="text" id="buscarProductoMayor" placeholder="Referencia o nombre" autocomplete="off">
        </div>
        <div>
            <label>&nbsp;</label>
            <span id="contadorProductos" style="display:block; padding:10px 0; font-size:14px;"><?= count($productosMayor) ?> productos</span>
        </div>
    </div>

    <div class="tabla-contenedor" style="max-height: 600px; overflow-y: auto;">
        <table class="tabla" id="tablaProductosMayor">
            <thead>
                <tr>
                    <th>Referencia</th>
                    <th>Producto</th>
                    <th class="num-der">Precio</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$productosMayor): ?>
                    <tr><td colspan="5" class="sin-resultados">No hay productos cargados.</td></tr>
                <?php else: ?>
                    <?php foreach ($productosMayor as $p): ?>
                        <tr data-busq="<?= escapar(mb_strtolower($p["referencia"] . " " . $p["nombre"], "UTF-8")) ?>">
                            <td><strong><?= escapar($p["referencia"]) ?></strong></td>
                            <td><?= escapar($p["nombre"]) ?></td>
                            <td class="num-der"><?= formatoCOP($p["precio"]) ?></td>
                            <td>
                                <?php if ((int) $p["activo"] === 1): ?>
                                    <span class="estado estado-activo">Activo</span>
                                <?php else: ?>
                                    <span class="estado estado-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn-editar btn-fila"
                                    data-p="<?= escapar(json_encode([
                                        "id" => (int) $p["id"],
                                        "referencia" => $p["referencia"],
                                        "nombre" => $p["nombre"],
                                        "precio" => rtrim(rtrim(number_format((float) $p["precio"], 2, ".", ""), "0"), "."),
                                    ], JSON_UNESCAPED_UNICODE)) ?>"
                                    onclick="editarProducto(this)">Editar</button>

                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="prod_estado">
                                    <input type="hidden" name="id" value="<?= (int) $p["id"] ?>">
                                    <input type="hidden" name="activo" value="<?= (int) $p["activo"] === 1 ? 0 : 1 ?>">
                                    <?php if ((int) $p["activo"] === 1): ?>
                                        <button type="submit" class="btn-eliminar btn-fila">Desactivar</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-activar btn-fila">Activar</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- MODAL PRODUCTO -->
<div class="modal" id="modalProducto">
    <div class="modal-contenido">
        <h3 id="tituloModalProducto">Nuevo producto</h3>
        <form method="POST" action="admin.php" class="formulario-modal">
            <input type="hidden" name="accion" id="prod_accion" value="prod_crear">
            <input type="hidden" name="id" id="prod_id" value="">
            <div>
                <label for="prod_referencia">Referencia (igual a Syscafe) *</label>
                <input type="text" id="prod_referencia" name="referencia" maxlength="60" required>
            </div>
            <div>
                <label for="prod_nombre">Nombre del producto *</label>
                <input type="text" id="prod_nombre" name="nombre" maxlength="255" required>
            </div>
            <div>
                <label for="prod_precio">Precio al por mayor (COP) *</label>
                <input type="text" id="prod_precio" name="precio" inputmode="decimal" placeholder="Ej: 12500" required>
            </div>
            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarModalProducto()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function nuevoProducto() {
        document.getElementById("tituloModalProducto").textContent = "Nuevo producto";
        document.getElementById("prod_accion").value = "prod_crear";
        document.getElementById("prod_id").value = "";
        document.getElementById("prod_referencia").value = "";
        document.getElementById("prod_nombre").value = "";
        document.getElementById("prod_precio").value = "";
        document.getElementById("modalProducto").style.display = "flex";
    }

    function editarProducto(btn) {
        var d = JSON.parse(btn.dataset.p);
        document.getElementById("tituloModalProducto").textContent = "Editar producto";
        document.getElementById("prod_accion").value = "prod_editar";
        document.getElementById("prod_id").value = d.id;
        document.getElementById("prod_referencia").value = d.referencia;
        document.getElementById("prod_nombre").value = d.nombre;
        document.getElementById("prod_precio").value = d.precio;
        document.getElementById("modalProducto").style.display = "flex";
    }

    function cerrarModalProducto() {
        document.getElementById("modalProducto").style.display = "none";
    }

    document.getElementById("buscarProductoMayor").addEventListener("input", function () {
        var q = this.value.trim().toLowerCase();
        var visibles = 0;
        document.querySelectorAll("#tablaProductosMayor tbody tr[data-busq]").forEach(function (tr) {
            var ok = q === "" || tr.dataset.busq.indexOf(q) !== -1;
            tr.style.display = ok ? "" : "none";
            if (ok) { visibles++; }
        });
        document.getElementById("contadorProductos").textContent = visibles + " productos";
    });
</script>
