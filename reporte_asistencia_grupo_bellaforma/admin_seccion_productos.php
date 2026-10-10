<?php
/*
 * Sección de productos del panel admin. Se incluye dos veces desde admin.php:
 *   $ProdLista = "mayorista"  -> Productos al por mayor
 *   $ProdLista = "detal"      -> Productos al detal
 */
$ProdLista = listaValida($ProdLista ?? "mayorista");
$S = $ProdLista === "detal" ? "D" : "M";
$ProdTabla = tablaProductos($ProdLista);
$ProdFotos = tablaFotos($ProdLista);
$ProdSeccion = $ProdLista === "detal" ? "productos_detal" : "productos_mayoristas";
$ProdTitulo = $ProdLista === "detal" ? "Productos al detal" : "Productos al por mayor";

$productosLista = [];
$resPM = $conexion->query(
    "SELECT p.id, p.referencia, p.nombre, p.precio, p.activo,
            (SELECT UNIX_TIMESTAMP(f.actualizado_en) FROM `$ProdFotos` f WHERE f.producto_id = p.id) AS foto_v
     FROM `$ProdTabla` p ORDER BY p.referencia ASC"
);
if ($resPM) {
    $productosLista = $resPM->fetch_all(MYSQLI_ASSOC);
}
$conFoto = count(array_filter($productosLista, fn($p) => !empty($p["foto_v"])));
$sinPrecio = count(array_filter($productosLista, fn($p) => (float) $p["precio"] <= 0));
?>
<style>
    .mini-foto { width: 46px; height: 46px; object-fit: cover; border-radius: 8px; display: block; background: #eef2f6; }
    .mini-foto-vacia { width: 46px; height: 46px; border-radius: 8px; background: #eef2f6; display: flex; align-items: center; justify-content: center; color: #9aa5b1; font-size: 18px; }
    .prev-foto { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #d5dbe3; display: none; margin-top: 8px; }
    .modal-contenido.modal-scroll { max-height: 90vh; overflow-y: auto; }
    .caja-carga { background: #fff; border: 1px dashed #90CAF9; border-radius: 10px; padding: 14px; margin-bottom: 16px; }
</style>

<section id="<?= $ProdSeccion ?>" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><?= $ProdLista === "detal" ? "🛍️" : "🏷️" ?> <?= $ProdTitulo ?></h2>
            <p>Catálogo con <strong>precio <?= $ProdLista === "detal" ? "detal" : "mayorista" ?></strong> que ven los ejecutivos al elegir ese tipo de cliente. La <strong>referencia</strong> es la misma que se usa en Syscafe.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="nuevoProducto<?= $S ?>()">+ Nuevo Producto</button>
    </div>

    <div class="caja-carga">
        <strong>📥 Cargar productos desde Excel</strong>
        <p style="margin:6px 0 10px; font-size:13px; color:#556;">
            Guarde el Excel como CSV con las columnas <em>referencia, nombre, precio</em> y súbalo aquí. Si la referencia ya existe, se actualiza su nombre y precio.
            <a href="productos_plantilla.php?lista=<?= $ProdLista ?>">Descargar plantilla</a>
        </p>
        <form method="POST" action="admin.php" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="accion" value="prod_importar">
            <input type="hidden" name="lista" value="<?= $ProdLista ?>">
            <input type="file" name="archivo" accept=".csv,text/csv,text/plain" required>
            <button type="submit" class="btn-nuevo">Importar productos</button>
        </form>
        <?php if ($ProdLista === "detal"): ?>
            <form method="POST" action="admin.php" style="margin-top:12px;" onsubmit="return confirm('Se copiarán las referencias y nombres de los productos mayoristas que aún no estén aquí, inactivas y con precio 0. ¿Continuar?')">
                <input type="hidden" name="accion" value="prod_copiar">
                <input type="hidden" name="lista" value="detal">
                <button type="submit" class="btn-editar">📋 Copiar las referencias de mayoristas (con precio 0 para llenar)</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="filtros">
        <div>
            <label for="buscarProd<?= $S ?>">Buscar</label>
            <input type="text" id="buscarProd<?= $S ?>" placeholder="Referencia o nombre" autocomplete="off">
        </div>
        <div>
            <label>&nbsp;</label>
            <span id="contadorProd<?= $S ?>" style="display:block; padding:10px 0; font-size:14px;"><?= count($productosLista) ?> productos · <?= $conFoto ?> con foto<?= $sinPrecio ? " · $sinPrecio sin precio" : "" ?></span>
        </div>
    </div>

    <div class="tabla-contenedor" style="max-height: 600px; overflow-y: auto;">
        <table class="tabla" id="tablaProd<?= $S ?>">
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>Referencia</th>
                    <th>Producto</th>
                    <th class="num-der">Precio <?= $ProdLista === "detal" ? "detal" : "mayorista" ?></th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$productosLista): ?>
                    <tr><td colspan="6" class="sin-resultados">No hay productos cargados en esta lista.</td></tr>
                <?php else: ?>
                    <?php foreach ($productosLista as $p): ?>
                        <tr data-busq="<?= escapar($p["referencia"] . " " . $p["nombre"]) ?>">
                            <td>
                                <?php if (!empty($p["foto_v"])): ?>
                                    <img class="mini-foto" loading="lazy" src="foto.php?id=<?= (int) $p["id"] ?>&amp;l=<?= $ProdLista ?>&amp;v=<?= (int) $p["foto_v"] ?>" alt="">
                                <?php else: ?>
                                    <div class="mini-foto-vacia">📷</div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= escapar($p["referencia"]) ?></strong></td>
                            <td><?= escapar($p["nombre"]) ?></td>
                            <td class="num-der"><?= (float) $p["precio"] > 0 ? formatoCOP($p["precio"]) : '<span style="color:#b00020;">sin precio</span>' ?></td>
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
                                        "precio" => (float) $p["precio"] > 0 ? rtrim(rtrim(number_format((float) $p["precio"], 2, ".", ""), "0"), ".") : "",
                                        "foto" => !empty($p["foto_v"]) ? "foto.php?id=" . (int) $p["id"] . "&l=" . $ProdLista . "&v=" . (int) $p["foto_v"] : "",
                                    ], JSON_UNESCAPED_UNICODE)) ?>"
                                    onclick="editarProducto<?= $S ?>(this)">Editar</button>

                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="prod_estado">
                                    <input type="hidden" name="lista" value="<?= $ProdLista ?>">
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

<!-- MODAL PRODUCTO (<?= $ProdLista ?>) -->
<div class="modal" id="modalProd<?= $S ?>">
    <div class="modal-contenido modal-scroll">
        <h3 id="tituloModalProd<?= $S ?>">Nuevo producto</h3>
        <form method="POST" action="admin.php" class="formulario-modal" enctype="multipart/form-data">
            <input type="hidden" name="accion" id="prodAccion<?= $S ?>" value="prod_crear">
            <input type="hidden" name="lista" value="<?= $ProdLista ?>">
            <input type="hidden" name="id" id="prodId<?= $S ?>" value="">
            <div>
                <label for="prodRef<?= $S ?>">Referencia (igual a Syscafe) *</label>
                <input type="text" id="prodRef<?= $S ?>" name="referencia" maxlength="60" required>
            </div>
            <div>
                <label for="prodNom<?= $S ?>">Nombre del producto *</label>
                <input type="text" id="prodNom<?= $S ?>" name="nombre" maxlength="255" required>
            </div>
            <div>
                <label for="prodPre<?= $S ?>">Precio <?= $ProdLista === "detal" ? "detal" : "al por mayor" ?> (COP) *</label>
                <input type="text" id="prodPre<?= $S ?>" name="precio" inputmode="decimal" placeholder="Ej: 12500" required>
            </div>
            <div>
                <label for="prodFoto<?= $S ?>">Foto del producto</label>
                <input type="file" id="prodFoto<?= $S ?>" name="foto" accept="image/jpeg,image/png,image/webp">
                <img id="prodPrev<?= $S ?>" class="prev-foto" alt="Vista previa">
                <label id="prodQuitarBox<?= $S ?>" style="display:none; margin-top:8px; font-weight:normal;">
                    <input type="checkbox" name="quitar_foto" value="1" style="width:auto;"> Quitar la foto actual
                </label>
                <small style="color:#667;">La foto se reduce automáticamente antes de subirla.</small>
            </div>
            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarModalProd<?= $S ?>()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var S = "<?= $S ?>";
        var $ = function (id) { return document.getElementById(id + S); };

        function mostrarPrev(url) {
            var img = $("prodPrev");
            if (url) { img.src = url; img.style.display = "block"; } else { img.removeAttribute("src"); img.style.display = "none"; }
        }

        window["nuevoProducto" + S] = function () {
            $("tituloModalProd").textContent = "Nuevo producto";
            $("prodAccion").value = "prod_crear";
            $("prodId").value = "";
            $("prodRef").value = "";
            $("prodNom").value = "";
            $("prodPre").value = "";
            $("prodFoto").value = "";
            $("prodQuitarBox").style.display = "none";
            mostrarPrev("");
            $("modalProd").style.display = "flex";
        };

        window["editarProducto" + S] = function (btn) {
            var d = JSON.parse(btn.dataset.p);
            $("tituloModalProd").textContent = "Editar producto";
            $("prodAccion").value = "prod_editar";
            $("prodId").value = d.id;
            $("prodRef").value = d.referencia;
            $("prodNom").value = d.nombre;
            $("prodPre").value = d.precio;
            $("prodFoto").value = "";
            $("prodQuitarBox").style.display = d.foto ? "block" : "none";
            $("prodQuitarBox").querySelector("input").checked = false;
            mostrarPrev(d.foto);
            $("modalProd").style.display = "flex";
        };

        window["cerrarModalProd" + S] = function () { $("modalProd").style.display = "none"; };

        // Reduce la foto en el navegador (máx. 800 px) antes de subirla
        $("prodFoto").addEventListener("change", function () {
            var input = this, f = input.files && input.files[0];
            if (!f) { return; }
            mostrarPrev(URL.createObjectURL(f));
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

        $("buscarProd").addEventListener("input", function () {
            var q = this.value.trim().toLowerCase();
            var visibles = 0;
            document.querySelectorAll("#tablaProd" + S + " tbody tr[data-busq]").forEach(function (tr) {
                var ok = q === "" || tr.dataset.busq.toLowerCase().indexOf(q) !== -1;
                tr.style.display = ok ? "" : "none";
                if (ok) { visibles++; }
            });
            $("contadorProd").textContent = visibles + " productos";
        });
    })();
</script>
