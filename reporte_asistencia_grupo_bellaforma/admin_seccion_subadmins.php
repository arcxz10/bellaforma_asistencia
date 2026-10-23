<?php
/*
 * Sección «Subadministradores» del panel admin (se incluye dentro de admin.php, solo para el administrador principal).
 * Aquí se crean los usuarios que entran al panel y se marca a qué módulos puede entrar cada uno.
 */
require_once __DIR__ . "/admin_permisos.php";

$subadmins = [];
$errorSub = "";
if (adminEsPrincipal()) {
    try {
        adminAsegurarColumnas($conexion);
        $resSub = $conexion->query("SELECT id, usuario, nombre, activo, permisos FROM administradores WHERE rol = 'sub' ORDER BY id DESC");
        $subadmins = $resSub ? $resSub->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $exSub) {
        $errorSub = "No se pudo cargar la lista de subadministradores.";
    }
}
$modulosSub = adminModulos();
?>
<style>
    #subadmins .sub-chips { display: flex; flex-wrap: wrap; gap: 5px; max-width: 460px; }
    #subadmins .sub-chip { padding: 3px 9px; border-radius: 10px; background: #E8EBFA; color: #2B3A9F; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
    #modalSubadmin .modal-contenido { max-width: 640px; }
    #modalSubadmin .sub-modulos { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 8px; margin-top: 8px; }
    #modalSubadmin .sub-modulo { display: flex; align-items: center; gap: 9px; padding: 9px 11px; border: 1.5px solid #E2E8F0; border-radius: 10px; background: #F7F9FC; font-size: 13.5px; font-weight: 600; cursor: pointer; }
    #modalSubadmin .sub-modulo:hover { border-color: #3F51B5; }
    #modalSubadmin .sub-modulo input { width: 17px; height: 17px; margin: 0; flex: none; cursor: pointer; }
    #modalSubadmin .sub-modulo.marcado { border-color: #3F51B5; background: #EEF1FC; color: #243071; }
    #modalSubadmin .sub-atajos { display: flex; gap: 8px; margin-top: 6px; }
    #modalSubadmin .sub-nota { margin: 6px 0 0; font-size: 12.5px; color: #667; }
</style>

<section id="subadmins" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><svg class="ico" aria-hidden="true"><use href="#i-users-check"/></svg> Subadministradores</h2>
            <p>Usuarios que ingresan por el mismo login del panel con su correo y contraseña, pero solo ven lo que usted marque. Dentro de cada módulo marcado pueden ver, editar y eliminar; de lo demás, nada.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="subNuevo()">+ Nuevo subadministrador</button>
    </div>

    <?php if ($errorSub !== ""): ?>
        <div class="mensaje error"><?= escapar($errorSub) ?></div>
    <?php endif; ?>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nombre</th>
                    <th>Correo de ingreso</th>
                    <th>Puede entrar a</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$subadmins): ?>
                    <tr><td colspan="6" class="sin-resultados">Todavía no hay subadministradores. Cree el primero con el botón «Nuevo subadministrador».</td></tr>
                <?php else: ?>
                    <?php foreach ($subadmins as $i => $sa): ?>
                        <?php $modsSa = adminPermisosDesdeTexto($sa["permisos"] ?? ""); ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= escapar($sa["nombre"] ?? "") ?: "—" ?></td>
                            <td><?= escapar($sa["usuario"]) ?></td>
                            <td>
                                <div class="sub-chips">
                                    <?php foreach ($modsSa as $mk): ?>
                                        <span class="sub-chip"><?= escapar($modulosSub[$mk][0]) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!$modsSa): ?>—<?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ((int) $sa["activo"] === 1): ?>
                                    <span class="estado estado-activo">Activo</span>
                                <?php else: ?>
                                    <span class="estado estado-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn-editar btn-fila"
                                    data-s="<?= escapar(json_encode([
                                        "id" => (int) $sa["id"],
                                        "nombre" => $sa["nombre"] ?? "",
                                        "usuario" => $sa["usuario"],
                                        "modulos" => $modsSa,
                                    ], JSON_UNESCAPED_UNICODE)) ?>"
                                    onclick="subEditar(this)">Editar</button>

                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="subadm_estado">
                                    <input type="hidden" name="id" value="<?= (int) $sa["id"] ?>">
                                    <input type="hidden" name="activo" value="<?= (int) $sa["activo"] === 1 ? 0 : 1 ?>">
                                    <?php if ((int) $sa["activo"] === 1): ?>
                                        <button type="submit" class="btn-eliminar btn-fila" onclick="return bfConfirmarForm(this.form, {titulo: 'Desactivar subadministrador', texto: '¿Desactivar a este subadministrador? No podrá ingresar al panel.', si: 'Sí, desactivar', peligro: true})">Desactivar</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-activar btn-fila">Activar</button>
                                    <?php endif; ?>
                                </form>

                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="subadm_eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $sa["id"] ?>">
                                    <button type="submit" class="btn-eliminar btn-fila" onclick="return bfConfirmarForm(this.form, {titulo: 'Eliminar subadministrador', texto: '¿Eliminar a este subadministrador? Esta acción no se puede deshacer.', si: 'Sí, eliminar', peligro: true})">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- MODAL SUBADMINISTRADOR -->
<div class="modal" id="modalSubadmin">
    <div class="modal-contenido">
        <h3 id="tituloModalSub">Nuevo subadministrador</h3>
        <form method="POST" action="admin.php" class="formulario-modal">
            <input type="hidden" name="accion" id="sub_accion" value="subadm_crear">
            <input type="hidden" name="id" id="sub_id" value="">

            <div>
                <label for="sub_nombre">Nombre *</label>
                <input type="text" id="sub_nombre" name="nombre" maxlength="120" autocomplete="off" required>
            </div>
            <div>
                <label for="sub_usuario">Correo de ingreso *</label>
                <input type="email" id="sub_usuario" name="usuario" maxlength="100" autocomplete="off" required>
            </div>
            <div>
                <label for="sub_password" id="sub_password_label">Contraseña * (mínimo 6)</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="sub_password" name="password" autocomplete="off" minlength="6">
                    <button type="button" class="btn-editar" onclick="subGenerarClave()">Generar</button>
                </div>
            </div>

            <div>
                <label>Módulos a los que puede entrar *</label>
                <div class="sub-atajos">
                    <button type="button" class="btn-editar btn-fila" onclick="subMarcarTodos(true)">Marcar todos</button>
                    <button type="button" class="btn-editar btn-fila" onclick="subMarcarTodos(false)">Quitar todos</button>
                </div>
                <div class="sub-modulos" id="subModulos">
                    <?php foreach ($modulosSub as $clave => $datosMod): ?>
                        <label class="sub-modulo">
                            <input type="checkbox" name="modulos[]" value="<?= escapar($clave) ?>">
                            <span><?= escapar($datosMod[0]) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="sub-nota">En cada módulo marcado podrá ver, editar y eliminar. Lo que no marque no lo verá ni podrá usarlo.</p>
            </div>

            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="subCerrar()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById("modalSubadmin");
    var cajas = function () { return Array.prototype.slice.call(document.querySelectorAll("#subModulos input[type=checkbox]")); };

    function pintarMarcas() {
        cajas().forEach(function (c) { c.closest(".sub-modulo").classList.toggle("marcado", c.checked); });
    }
    document.getElementById("subModulos").addEventListener("change", pintarMarcas);

    window.subMarcarTodos = function (si) {
        cajas().forEach(function (c) { c.checked = si; });
        pintarMarcas();
    };
    window.subNuevo = function () {
        document.getElementById("tituloModalSub").textContent = "Nuevo subadministrador";
        document.getElementById("sub_accion").value = "subadm_crear";
        document.getElementById("sub_id").value = "";
        ["nombre", "usuario", "password"].forEach(function (c) { document.getElementById("sub_" + c).value = ""; });
        document.getElementById("sub_password").required = true;
        document.getElementById("sub_password_label").textContent = "Contraseña * (mínimo 6)";
        window.subMarcarTodos(false);
        modal.style.display = "flex";
    };
    window.subEditar = function (btn) {
        var d = JSON.parse(btn.dataset.s);
        document.getElementById("tituloModalSub").textContent = "Editar subadministrador";
        document.getElementById("sub_accion").value = "subadm_editar";
        document.getElementById("sub_id").value = d.id;
        document.getElementById("sub_nombre").value = d.nombre || "";
        document.getElementById("sub_usuario").value = d.usuario || "";
        document.getElementById("sub_password").value = "";
        document.getElementById("sub_password").required = false;
        document.getElementById("sub_password_label").textContent = "Nueva contraseña (dejar vacío para no cambiarla)";
        cajas().forEach(function (c) { c.checked = (d.modulos || []).indexOf(c.value) !== -1; });
        pintarMarcas();
        modal.style.display = "flex";
    };
    window.subCerrar = function () { modal.style.display = "none"; };
    window.subGenerarClave = function () {
        var c = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789", s = "";
        for (var i = 0; i < 8; i++) { s += c.charAt(Math.floor(Math.random() * c.length)); }
        document.getElementById("sub_password").value = s;
    };
    modal.addEventListener("click", function (e) { if (e.target === modal) { window.subCerrar(); } });
    modal.querySelector("form").addEventListener("submit", function (e) {
        if (!cajas().some(function (c) { return c.checked; })) {
            e.preventDefault();
            window.alert("Marque al menos un módulo al que pueda entrar.");
        }
    });
})();
</script>
