<?php
/* Sección "Productos" del panel admin: un solo catálogo con precio mayorista y precio detal (una sola foto por producto). */

$productosLista = [];
$resPM = $conexion->query(
    "SELECT p.id, p.referencia, p.nombre, p.precio_mayorista, p.precio_detal, p.activo,
            (SELECT UNIX_TIMESTAMP(f.actualizado_en) FROM producto_fotos f WHERE f.producto_id = p.id) AS foto_v
     FROM productos_mayoristas p ORDER BY p.referencia ASC"
);
if ($resPM) {
    $productosLista = $resPM->fetch_all(MYSQLI_ASSOC);
}
$conFoto = count(array_filter($productosLista, fn($p) => !empty($p["foto_v"])));
$sinMay = count(array_filter($productosLista, fn($p) => (float) $p["precio_mayorista"] <= 0));
$sinDet = count(array_filter($productosLista, fn($p) => (float) $p["precio_detal"] <= 0));
?>
<style>
    .mini-foto { width: 46px; height: 46px; object-fit: cover; border-radius: 8px; display: block; background: #eef2f6; }
    .mini-foto-vacia { width: 46px; height: 46px; border-radius: 8px; background: #eef2f6; display: flex; align-items: center; justify-content: center; color: #9aa5b1; font-size: 18px; }
    .prev-foto { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #d5dbe3; display: none; margin-top: 8px; }
    .modal-contenido.modal-scroll { max-height: 90vh; overflow-y: auto; }
    .caja-carga { background: #fff; border: 1px dashed #90CAF9; border-radius: 10px; padding: 14px; margin-bottom: 16px; }
    .sin-precio { color: #b00020; font-size: 12px; }
</style>

<section id="productos_mayoristas" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🏷️ Productos</h2>
            <p>Un solo catálogo con <strong>precio mayorista</strong> y <strong>precio detal</strong>. El ejecutivo ve uno u otro según el tipo de cliente que elija. La <strong>referencia</strong> es la misma de Syscafe y la foto se sube una sola vez.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="nuevoProducto()">+ Nuevo Producto</button>
    </div>

    <div class="caja-carga">
        <strong>📥 Cargar o actualizar productos desde Excel</strong>
        <p style="margin:6px 0 10px; font-size:13px; color:#556;">
            Guarde el Excel como CSV con las columnas <em>referencia, nombre, precio_mayorista, precio_detal</em> y súbalo aquí. Si la referencia ya existe, se actualizan su nombre y los precios que el archivo traiga.
            <a href="productos_plantilla.php">Descargar plantilla</a>
        </p>
        <form method="POST" action="admin.php" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="accion" value="prod_importar">
            <input type="file" name="archivo" accept=".csv,text/csv,text/plain" required>
            <button type="submit" class="btn-nuevo">Importar productos</button>
        </form>
    </div>

    <div class="filtros">
        <div>
            <label for="buscarProd">Buscar</label>
            <input type="text" id="buscarProd" placeholder="Referencia o nombre" autocomplete="off">
        </div>
        <div>
            <label>&nbsp;</label>
            <span id="contadorProd" style="display:block; padding:10px 0; font-size:14px;"><?= count($productosLista) ?> productos · <?= $conFoto ?> con foto<?= $sinMay ? " · $sinMay sin precio mayorista" : "" ?><?= $sinDet ? " · $sinDet sin precio detal" : "" ?></span>
        </div>
    </div>

    <div class="tabla-contenedor" style="max-height: 600px; overflow-y: auto;">
        <table class="tabla" id="tablaProd">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Referencia</th>
                    <th>Producto</th>
                    <th class="num-der">Precio mayorista</th>
                    <th class="num-der">Precio detal</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$productosLista): ?>
                    <tr><td colspan="7" class="sin-resultados">No hay productos cargados.</td></tr>
                <?php else: ?>
                    <?php foreach ($productosLista as $p): ?>
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
                            <td class="num-der"><?= (float) $p["precio_mayorista"] > 0 ? formatoCOP($p["precio_mayorista"]) : '<span class="sin-precio">sin precio</span>' ?></td>
                            <td class="num-der"><?= (float) $p["precio_detal"] > 0 ? formatoCOP($p["precio_detal"]) : '<span class="sin-precio">sin precio</span>' ?></td>
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
                                        "precio_mayorista" => (float) $p["precio_mayorista"] > 0 ? rtrim(rtrim(number_format((float) $p["precio_mayorista"], 2, ".", ""), "0"), ".") : "",
                                        "precio_detal" => (float) $p["precio_detal"] > 0 ? rtrim(rtrim(number_format((float) $p["precio_detal"], 2, ".", ""), "0"), ".") : "",
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
<div class="modal" id="modalProd">
    <div class="modal-contenido modal-scroll">
        <h3 id="tituloModalProd">Nuevo producto</h3>
        <form method="POST" action="admin.php" class="formulario-modal" enctype="multipart/form-data">
            <input type="hidden" name="accion" id="prodAccion" value="prod_crear">
            <input type="hidden" name="id" id="prodId" value="">
            <div>
                <label for="prodRef">Referencia (igual a Syscafe) *</label>
                <input type="text" id="prodRef" name="referencia" maxlength="60" required>
            </div>
            <div>
                <label for="prodNom">Nombre del producto *</label>
                <input type="text" id="prodNom" name="nombre" maxlength="255" required>
            </div>
            <div>
                <label for="prodMay">Precio mayorista (COP)</label>
                <input type="text" id="prodMay" name="precio_mayorista" inputmode="decimal" placeholder="Ej: 7100">
            </div>
            <div>
                <label for="prodDet">Precio detal (COP)</label>
                <input type="text" id="prodDet" name="precio_detal" inputmode="decimal" placeholder="Ej: 10900">
                <small style="color:#667;">Ponga al menos uno. Si un precio queda vacío, el producto no aparece para ese tipo de cliente.</small>
            </div>
            <div>
                <label for="prodFoto">Foto del producto (sirve para mayorista y detal)</label>
                <input type="file" id="prodFoto" name="foto" accept="image/jpeg,image/png,image/webp">
                <img id="prodPrev" class="prev-foto" alt="Vista previa">
                <label id="prodQuitarBox" style="display:none; margin-top:8px; font-weight:normal;">
                    <input type="checkbox" name="quitar_foto" value="1" style="width:auto;"> Quitar la foto actual
                </label>
                <small style="color:#667;">La foto se reduce automáticamente antes de subirla.</small>
            </div>
            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarModalProd()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function mostrarPrevProd(url) {
        var img = document.getElementById("prodPrev");
        if (url) { img.src = url; img.style.display = "block"; } else { img.removeAttribute("src"); img.style.display = "none"; }
    }

    function nuevoProducto() {
        document.getElementById("tituloModalProd").textContent = "Nuevo producto";
        document.getElementById("prodAccion").value = "prod_crear";
        document.getElementById("prodId").value = "";
        ["prodRef", "prodNom", "prodMay", "prodDet", "prodFoto"].forEach(function (id) { document.getElementById(id).value = ""; });
        document.getElementById("prodQuitarBox").style.display = "none";
        mostrarPrevProd("");
        document.getElementById("modalProd").style.display = "flex";
    }

    function editarProducto(btn) {
        var d = JSON.parse(btn.dataset.p);
        document.getElementById("tituloModalProd").textContent = "Editar producto";
        document.getElementById("prodAccion").value = "prod_editar";
        document.getElementById("prodId").value = d.id;
        document.getElementById("prodRef").value = d.referencia;
        document.getElementById("prodNom").value = d.nombre;
        document.getElementById("prodMay").value = d.precio_mayorista;
        document.getElementById("prodDet").value = d.precio_detal;
        document.getElementById("prodFoto").value = "";
        document.getElementById("prodQuitarBox").style.display = d.foto ? "block" : "none";
        document.querySelector("#prodQuitarBox input").checked = false;
        mostrarPrevProd(d.foto);
        document.getElementById("modalProd").style.display = "flex";
    }

    function cerrarModalProd() { document.getElementById("modalProd").style.display = "none"; }

    // Reduce la foto en el navegador (máx. 800 px) antes de subirla
    document.getElementById("prodFoto").addEventListener("change", function () {
        var input = this, f = input.files && input.files[0];
        if (!f) { return; }
        mostrarPrevProd(URL.createObjectURL(f));
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

    document.getElementById("buscarProd").addEventListener("input", function () {
        var q = this.value.trim().toLowerCase();
        var visibles = 0;
        document.querySelectorAll("#tablaProd tbody tr[data-busq]").forEach(function (tr) {
            var ok = q === "" || tr.dataset.busq.toLowerCase().indexOf(q) !== -1;
            tr.style.display = ok ? "" : "none";
            if (ok) { visibles++; }
        });
        document.getElementById("contadorProd").textContent = visibles + " productos";
    });
</script>
