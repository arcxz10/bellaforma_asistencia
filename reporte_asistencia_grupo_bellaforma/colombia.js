/* Departamentos y municipios de Colombia (data/colombia.json) */
(function () {
    var cache = null;

    function norm(t) {
        return (t || "").toString().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9 ]/g, " ").replace(/\s+/g, " ").trim();
    }

    window.cargarColombia = function () {
        if (cache) { return Promise.resolve(cache); }
        return fetch("data/colombia.json").then(function (r) { return r.json(); }).then(function (d) { cache = d; return d; });
    };

    function opcion(valor, texto) {
        var o = document.createElement("option");
        o.value = valor; o.textContent = texto;
        return o;
    }

    function llenarMunicipios(selMuni, lista, valor) {
        selMuni.innerHTML = "";
        selMuni.appendChild(opcion("", lista ? "Seleccione municipio" : "Primero elija departamento"));
        (lista || []).forEach(function (m) { selMuni.appendChild(opcion(m, m)); });
        if (valor) {
            var hallado = false;
            Array.prototype.forEach.call(selMuni.options, function (o) { if (norm(o.value) === norm(valor)) { selMuni.value = o.value; hallado = true; } });
            if (!hallado) { selMuni.appendChild(opcion(valor, valor)); selMuni.value = valor; }
        }
    }

    // Prepara los dos selects. Devuelve una promesa.
    window.colombiaInit = function (selDepto, selMuni) {
        return cargarColombia().then(function (data) {
            selDepto.innerHTML = "";
            selDepto.appendChild(opcion("", "Seleccione departamento"));
            Object.keys(data).forEach(function (d) { selDepto.appendChild(opcion(d, d)); });
            llenarMunicipios(selMuni, null, "");
            selDepto.addEventListener("change", function () { llenarMunicipios(selMuni, data[selDepto.value], ""); });
            return data;
        });
    };

    // Selecciona departamento y municipio (tolera diferencias de tildes/mayúsculas)
    window.colombiaSet = function (selDepto, selMuni, depto, muni) {
        return cargarColombia().then(function (data) {
            var d = "";
            var nd = norm(depto);
            if (nd) {
                Object.keys(data).forEach(function (k) {
                    var nk = norm(k);
                    if (!d && (nk === nd || nk.indexOf(nd) === 0 || nd.indexOf(nk) === 0)) { d = k; }
                });
                if (!d && nd.indexOf("bogota") === 0) { d = "Bogotá D.C."; }
            }
            selDepto.value = d;
            if (!d && depto) {
                selDepto.appendChild(opcion(depto, depto));
                selDepto.value = depto;
            }
            llenarMunicipios(selMuni, data[d] || null, muni || "");
        });
    };
})();
