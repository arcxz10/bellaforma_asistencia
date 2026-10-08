<?php
/* Sección "Productos al por mayor" del panel admin (se incluye dentro de admin.php). */

$productosMayor = [];
$resPM = $conexion->query(
    "SELECT p.id, p.referencia, p.nombre, p.precio, p.activo,
            (SELECT UNIX_TIMESTAMP(f.actualizado_en) FROM producto_fotos f WHERE f.producto_id = p.id) AS foto_v
     FROM productos_mayoristas p ORDER BY p.referencia ASC"
);
if ($resPM) {
    $productosMayor = $resPM->fetch_all(MYSQLI_ASSOC);
}
$conFoto = count(array_filter($productosMayor, fn($p) => !empty($p["foto_v"])));
?>
<style>
    .mini-foto { width: 46px; height: 46px; object-fit: cover; border-radius: 8px; display: block; background: #eef2f6; }
    .mini-foto-vacia { width: 46px; height: 46px; border-radius: 8px; background: #eef2f6; display: flex; align-items: center; justify-content: center; color: #9aa5b1; font-size: 18px; }
    .prev-foto { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #d5dbe3; display: none; margin-top: 8px; }
    .modal-contenido.modal-scroll { max-height: 90vh; overflow-y: auto; }
</style>

<section id="productos_mayoristas" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🏷️ Productos al por mayor</h2>
            <p>Catálogo que ven los ejecutivos. La <strong>referencia</strong> es la misma que se usa en Syscafe.</p>
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
            <span id="contadorProductos" style="display:block; padding:10px 0; font-size:14px;"><?= count($productosMayor) ?> productos · <?= $conFoto ?> con foto</span>
        </div>
    </div>

    <div class="tabla-contenedor" style="max-height: 600px; overflow-y: auto;">
        <table class="tabla" id="tablaProductosMayor">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Referencia</th>
                    <th>Producto</th>
                    <th class="num-der">Precio</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$productosMayor): ?>
                    <tr><td colspan="6" class="sin-resultados">No hay productos cargados.</td></tr>
                <?php else: ?>
                    <?php foreach ($productosMayor as $p): ?>
                        <tr data-busq="<?= escapar($p["referencia"] . " " . $p["nombre"]) ?>">
                            <td>
                                <?php if (!empty($p["foto_v"])): ?>
                                    <img class="mini-foto" loading="lazy" src="foto.php?id=<?= (int) $p["id"] ?>&amp;v=<?= (int) $p["foto_v"] ?>" alt="">
                                <?php else: ?>
                                    <div class="mini-foto-vacia">📷</div>
                                <?php endif; ?>
                            </td>
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
                                        "foto" => !empty($p["foto_v"]) ? "foto.php?id=" . (int) $p["id"] . "&v=" . (int) $p["foto_v"] : "",
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
    <div class="modal-contenido modal-scroll">
        <h3 id="tituloModalProducto">Nuevo producto</h3>
        <form method="POST" action="admin.php" class="formulario-modal" enctype="multipart/form-data" id="formProducto">
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
            <div>
                <label for="prod_foto">Foto del producto</label>
                <input type="file" id="prod_foto" name="foto" accept="image/jpeg,image/png,image/webp">
                <img id="prod_prev" class="prev-foto" alt="Vista previa">
                <label id="prod_quitar_box" style="display:none; margin-top:8px; font-weight:normal;">
                    <input type="checkbox" name="quitar_foto" value="1" style="width:auto;"> Quitar la foto actual
                </label>
                <small style="color:#667;">La foto se reduce automáticamente antes de subirla.</small>
            </div>
            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarModalProducto()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function mostrarPrevProducto(url) {
        var img = document.getElementById("prod_prev");
        if (url) { img.src = url; img.style.display = "block"; } else { img.removeAttribute("src"); img.style.display = "none"; }
    }

    function nuevoProducto() {
        document.getElementById("tituloModalProducto").textContent = "Nuevo producto";
        document.getElementById("prod_accion").value = "prod_crear";
        document.getElementById("prod_id").value = "";
        document.getElementById("prod_referencia").value = "";
        document.getElementById("prod_nombre").value = "";
        document.getElementById("prod_precio").value = "";
        document.getElementById("prod_foto").value = "";
        document.getElementById("prod_quitar_box").style.display = "none";
        mostrarPrevProducto("");
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
        document.getElementById("prod_foto").value = "";
        document.getElementById("prod_quitar_box").style.display = d.foto ? "block" : "none";
        document.querySelector("#prod_quitar_box input").checked = false;
        mostrarPrevProducto(d.foto);
        document.getElementById("modalProducto").style.display = "flex";
    }

    function cerrarModalProducto() {
        document.getElementById("modalProducto").style.display = "none";
    }

    // Reduce la foto en el navegador (máx. 800 px) antes de subirla
    document.getElementById("prod_foto").addEventListener("change", function () {
        var input = this, f = input.files && input.files[0];
        if (!f) { return; }
        mostrarPrevProducto(URL.createObjectURL(f));
        if (!f.type.match(/^image\/(jpeg|png|webp)$/) || typeof DataTransfer === "undefined") { return; }
        var img = new Image();
        img.onload = function () {
            var max = 800, w = img.naturalWidth, h = img.naturalHeight;
            var esc = Math.min(1, max / Math.max(w, h));
            var c = document.createElement("canvas");
            c.width = Math.round(w * esc); c.height = Math.round(h * esc);
            var ctx = c.getContext("2d");
            ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(img, 0, 0, c.width, c.height);
            c.toBlob(function (blob) {
                if (!blob) { return; }
                var dt = new DataTransfer();
                dt.items.add(new File([blob], "foto.jpg", { type: "image/jpeg" }));
                input.files = dt.files;
            }, "image/jpeg", 0.82);
        };
        img.src = URL.createObjectURL(f);
    });

    document.getElementById("buscarProductoMayor").addEventListener("input", function () {
        var q = this.value.trim().toLowerCase();
        var visibles = 0;
        document.querySelectorAll("#tablaProductosMayor tbody tr[data-busq]").forEach(function (tr) {
            var ok = q === "" || tr.dataset.busq.toLowerCase().indexOf(q) !== -1;
            tr.style.display = ok ? "" : "none";
            if (ok) { visibles++; }
        });
        document.getElementById("contadorProductos").textContent = visibles + " productos";
    });
</script>
