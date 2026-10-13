/* Comportamientos del portal, sin dependencias: contraseña, menús, pestañas,
   filtro de predios y la selección de vigencias con el valor exacto en vivo. */
(function () {
    'use strict';

    var $ = function (sel, raiz) { return (raiz || document).querySelector(sel); };
    var $$ = function (sel, raiz) { return Array.prototype.slice.call((raiz || document).querySelectorAll(sel)); };

    // ---------- Mostrar u ocultar la contraseña ----------
    $$('.ver-clave').forEach(function (b) {
        b.addEventListener('click', function () {
            var input = b.parentNode.querySelector('input');
            var ver = input.type === 'password';
            input.type = ver ? 'text' : 'password';
            b.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    // Botón de enviar con estado "trabajando" (evita doble clic).
    $$('form[data-ocupado]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = $('button[type="submit"]', f);
            if (b) { b.disabled = true; b.classList.add('ocupado'); b.setAttribute('aria-busy', 'true'); }
        });
    });

    // Página de error de un PDF (abierta en pestaña nueva).
    $$('[data-cerrar-pestana]').forEach(function (b) {
        b.addEventListener('click', function () { window.close(); });
    });

    // ---------- Aviso efímero (el contenedor existe desde el inicio para
    // que los lectores de pantalla lean el primer mensaje) ----------
    var toast = document.createElement('div');
    toast.className = 'toast';
    toast.setAttribute('role', 'status');
    document.body.appendChild(toast);
    function avisar(texto) {
        toast.textContent = texto;
        toast.classList.add('visible');
        clearTimeout(toast._espera);
        toast._espera = setTimeout(function () { toast.classList.remove('visible'); }, 6000);
    }

    // ---------- Menús desplegables (Imprimir / Pagar) ----------
    function cerrarMenus(excepto) {
        $$('[data-menu]').forEach(function (m) {
            if (m === excepto) { return; }
            var lista = $('.menu-lista', m);
            if (lista && !lista.hidden) {
                lista.hidden = true;
                $('[data-menu-boton]', m).setAttribute('aria-expanded', 'false');
            }
        });
    }
    $$('[data-menu]').forEach(function (m) {
        var boton = $('[data-menu-boton]', m);
        var lista = $('.menu-lista', m);
        boton.addEventListener('click', function (ev) {
            ev.stopPropagation();
            var abrir = lista.hidden;
            cerrarMenus(m);
            lista.hidden = !abrir;
            boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            if (abrir) {
                var primero = $('[role="menuitem"]:not(:disabled)', lista);
                if (primero) { primero.focus(); }
            }
        });
        lista.addEventListener('keydown', function (ev) {
            var items = $$('[role="menuitem"]:not(:disabled)', lista);
            var i = items.indexOf(document.activeElement);
            if (ev.key === 'ArrowDown') { ev.preventDefault(); items[(i + 1) % items.length].focus(); }
            if (ev.key === 'ArrowUp') { ev.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
            if (ev.key === 'Escape') { lista.hidden = true; boton.setAttribute('aria-expanded', 'false'); boton.focus(); }
        });
        // Salir del menú con Tab lo cierra.
        m.addEventListener('focusout', function (ev) {
            if (!ev.relatedTarget || !m.contains(ev.relatedTarget)) {
                lista.hidden = true;
                boton.setAttribute('aria-expanded', 'false');
            }
        });
        $$('[role="menuitem"]', lista).forEach(function (it) {
            it.addEventListener('click', function () { setTimeout(function () { cerrarMenus(); }, 0); });
        });
    });
    document.addEventListener('click', function () { cerrarMenus(); });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { cerrarMenus(); } });

    // ---------- Pestañas accesibles (flechas, Inicio y Fin) ----------
    $$('[data-pestanas]').forEach(function (caja) {
        var botones = $$('[role="tab"]', caja);
        function activar(b, enfocar) {
            botones.forEach(function (x) {
                var si = x === b;
                x.setAttribute('aria-selected', si ? 'true' : 'false');
                x.tabIndex = si ? 0 : -1;
                document.getElementById(x.getAttribute('aria-controls')).hidden = !si;
            });
            if (enfocar) { b.focus(); }
        }
        botones.forEach(function (b, i) {
            b.addEventListener('click', function () { activar(b, true); });
            b.addEventListener('keydown', function (ev) {
                var destino = null;
                if (ev.key === 'ArrowRight') { destino = botones[(i + 1) % botones.length]; }
                if (ev.key === 'ArrowLeft') { destino = botones[(i - 1 + botones.length) % botones.length]; }
                if (ev.key === 'Home') { destino = botones[0]; }
                if (ev.key === 'End') { destino = botones[botones.length - 1]; }
                if (destino) { ev.preventDefault(); activar(destino, true); }
            });
        });
    });

    // ---------- Filtro de "Mis predios" ----------
    var filtro = $('[data-filtro]');
    if (filtro) {
        var estado = 'todos';
        var texto = $('[data-filtro-texto]', filtro);
        var tarjetas = $$('.predio-tarjeta');
        var vacio = $('[data-filtro-vacio]');
        var normal = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[-\s]/g, ''); };
        var aplicar = function () {
            var q = normal(texto.value);
            var visibles = 0;
            tarjetas.forEach(function (t) {
                var si = (estado === 'todos' || t.classList.contains(estado)) && (q === '' || normal(t.getAttribute('data-texto')).indexOf(q) >= 0);
                t.hidden = !si;
                if (si) { visibles++; }
            });
            if (vacio) { vacio.hidden = visibles > 0; }
        };
        $$('[data-filtro-estado]', filtro).forEach(function (b) {
            b.addEventListener('click', function () {
                estado = b.getAttribute('data-filtro-estado');
                $$('[data-filtro-estado]', filtro).forEach(function (x) {
                    x.classList.toggle('activo', x === b);
                    x.setAttribute('aria-pressed', x === b ? 'true' : 'false');
                });
                aplicar();
            });
        });
        texto.addEventListener('input', aplicar);
    }

    // ---------- Vigencias: selección y valor exacto ----------
    var tabla = $('[data-vigencias]');
    var form = $('[data-form-pago]');
    if (!tabla || !form) { return; }

    var filas = $$('tbody tr', tabla).filter(function (f) { return $('[data-casilla]', f); });
    var todas = $('[data-todas]', tabla);
    var totales = $$('[data-total], [data-total-pie], [data-total-desglose]');
    var resumen = $('[data-resumen-seleccion]');
    var anuncio = $('[data-anuncio]');
    var textoVigencias = $('[data-vigencias-texto]');
    var accionesPago = $$('[data-requiere-cotizacion]', form);
    var pedido = 0, espera = null, ultimo = null;
    var bloque = function (f) { return f.getAttribute('data-bloque'); };

    form.addEventListener('submit', function (ev) {
        var accion = ev.submitter && ev.submitter.getAttribute('formaction');
        if (accion === 'pagar.php') { return; }
        avisar(accion === 'estado-cuenta.php'
            ? 'Preparando el estado de cuenta: se abrirá en una pestaña nueva.'
            : 'Generando tu factura: se abrirá en una pestaña nueva. Revisa que el navegador no la haya bloqueado.');
    });

    function habilitarPago(si) {
        accionesPago.forEach(function (b) { b.disabled = !si; });
    }
    function mostrarTotal(texto, clase) {
        totales.forEach(function (n) {
            n.textContent = texto;
            n.classList.toggle('calculando', clase === 'calculando');
            n.classList.toggle('error', clase === 'error');
        });
    }
    function marcadas() {
        return filas.map(function (f, i) { return $('[data-casilla]', f).checked ? i : -1; })
                    .filter(function (i) { return i >= 0; });
    }
    function quitarReintentar() {
        var r = $('[data-reintentar]');
        if (r) { r.remove(); }
    }
    function ofrecerReintentar() {
        quitarReintentar();
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'boton boton-contorno boton-chico reintentar';
        b.setAttribute('data-reintentar', '');
        b.textContent = 'Reintentar';
        b.addEventListener('click', function () { if (ultimo) { cotizar(ultimo[0], ultimo[1]); } });
        var total = $('.valor-bloque-total');
        if (total) { total.appendChild(b); }
    }

    // El valor real lo da el procedimiento oficial: el descuento depende de
    // los años que se pagan juntos, así que no basta sumar las filas.
    function cotizar(desde, hasta) {
        var mio = ++pedido;
        ultimo = [desde, hasta];
        quitarReintentar();
        habilitarPago(false);
        mostrarTotal('Calculando…', 'calculando');
        var datos = new FormData();
        datos.append('csrf', $('[name="csrf"]', form).value);
        datos.append('id', form.getAttribute('data-predio'));
        datos.append('desde', desde);
        datos.append('hasta', hasta);
        fetch('cotizar.php', { method: 'POST', body: datos, credentials: 'same-origin' })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) { return { r: r, j: j }; });
            })
            .then(function (x) {
                if (mio !== pedido) { return; }
                if (x.j.sinSesion) { location.href = './'; return; }
                if (!x.r.ok || !x.j.ok) {
                    var e = new Error(x.j.mensaje || 'No pudimos calcular el valor. Intenta de nuevo.');
                    e.delServidor = !!x.j.mensaje;
                    throw e;
                }
                mostrarTotal(x.j.total, null);
                var fecha = $('[data-fecha]');
                if (fecha) { fecha.textContent = x.j.hasta; }
                Object.keys(x.j.desglose || {}).forEach(function (k) {
                    var dd = $('[data-desglose="' + k + '"]');
                    if (dd) { dd.textContent = x.j.desglose[k]; }
                });
                x.j.filas.forEach(function (f) {
                    var tr = $('tr[data-anio="' + f.anio + '"]', tabla);
                    if (!tr) { return; }
                    ['impuesto', 'intereses', 'sobretasa', 'descuento', 'otros', 'total'].forEach(function (c) {
                        var td = $('[data-campo="' + c + '"]', tr);
                        if (td && td.textContent !== f[c]) {
                            td.textContent = f[c];
                            td.classList.remove('cambio');
                            void td.offsetWidth;
                            td.classList.add('cambio');
                        }
                    });
                });
                if (x.j.calculado === false) {
                    avisar('No pudimos calcular los intereses de hoy: las cifras son las del último cálculo. La factura se recalcula al generarla.');
                }
                if (anuncio) {
                    anuncio.textContent = 'Total a pagar ' + x.j.total + ' por las vigencias ' + (desde === hasta ? desde : desde + ' a ' + hasta) + '.';
                }
                habilitarPago(true);
            })
            .catch(function (e) {
                if (mio !== pedido) { return; }
                mostrarTotal('No se pudo calcular', 'error');
                avisar(e.delServidor ? e.message : 'No pudimos calcular el valor. Revisa tu conexión e intenta de nuevo.');
                ofrecerReintentar();
            });
    }

    function pintar(sinCotizar) {
        var idx = marcadas();
        filas.forEach(function (f, i) { f.classList.toggle('seleccionada', idx.indexOf(i) >= 0); });
        if (todas) {
            todas.checked = idx.length === filas.length;
            todas.indeterminate = idx.length > 0 && idx.length < filas.length;
        }
        if (resumen) {
            resumen.textContent = idx.length + ' de ' + filas.length + ' vigencia' + (filas.length === 1 ? '' : 's') + ' seleccionada' + (idx.length === 1 ? '' : 's');
        }
        if (!idx.length) {
            pedido++;
            clearTimeout(espera);
            quitarReintentar();
            habilitarPago(false);
            mostrarTotal('$ 0', null);
            $$('[data-desglose]').forEach(function (dd) { dd.textContent = '$ 0'; });
            if (textoVigencias) { textoVigencias.textContent = 'Marca al menos una vigencia para ver el valor a pagar.'; }
            return;
        }
        var desde = filas[idx[0]].getAttribute('data-anio');
        var hasta = filas[idx[idx.length - 1]].getAttribute('data-anio');
        $('[data-desde]', form).value = desde;
        $('[data-hasta]', form).value = hasta;
        if (textoVigencias) {
            textoVigencias.innerHTML = '';
            textoVigencias.appendChild(document.createTextNode(desde === hasta ? 'Pagar vigencia ' : 'Pagar vigencias '));
            var fuerte = document.createElement('strong');
            fuerte.textContent = desde === hasta ? desde : desde + ' – ' + hasta;
            textoVigencias.appendChild(fuerte);
        }
        if (!sinCotizar) {
            clearTimeout(espera);
            habilitarPago(false);
            mostrarTotal('Calculando…', 'calculando');
            espera = setTimeout(function () { cotizar(desde, hasta); }, 350);
        }
    }

    // Se pagan años seguidos y dentro de un mismo bloque (sin saltar años que
    // no están pendientes).
    filas.forEach(function (f, i) {
        $('[data-casilla]', f).addEventListener('change', function (ev) {
            var idx = marcadas();
            if (ev.target.checked) {
                var otro = idx.some(function (j) { return bloque(filas[j]) !== bloque(f); });
                if (otro) {
                    // Otro bloque: se paga en una factura aparte.
                    filas.forEach(function (g, j) { $('[data-casilla]', g).checked = bloque(g) === bloque(f) && j <= i; });
                    avisar('Las vigencias que no son seguidas se pagan en facturas separadas.');
                    idx = marcadas();
                }
            }
            if (idx.length) {
                var a = Math.min.apply(null, idx), b = Math.max.apply(null, idx);
                if (!ev.target.checked && i > a && i < b) {
                    // Desmarcar un año del medio recorta el rango hasta ese año.
                    b = i - 1;
                }
                filas.forEach(function (g, j) { $('[data-casilla]', g).checked = j >= a && j <= b; });
            }
            pintar();
        });
    });
    if (todas) {
        todas.addEventListener('change', function () {
            filas.forEach(function (f) { $('[data-casilla]', f).checked = todas.checked; });
            pintar();
        });
    }
    pintar(true);
})();
