/**
 * app.js — JavaScript global del sistema de nómina Lubrimotos
 * Vanilla JS únicamente (sin frameworks).
 */

'use strict';

// ── Auto-dismiss de alertas flash después de 5 s ──────────
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert.alert-dismissible');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            bsAlert.close();
        }, 5000);
    });
});

// ── Formato de moneda colones (₡) ────────────────────────
function formatColones(valor) {
    return '₡' + parseFloat(valor).toLocaleString('es-CR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ── Solicitudes: campo Horas/Días condicional según tipo ──
// Para vacaciones, además muestra una barra con los días que se pueden escoger,
// limita las fechas del calendario y bloquea el envío si se pasa del saldo.
function initSolicitudFormDinamico() {
    const tipoSelect = document.getElementById('tipo');
    if (!tipoSelect) {
        return;
    }
    const campoHoras     = document.getElementById('campoHoras');
    const campoDias      = document.getElementById('campoDias');
    const fechaInicio    = document.getElementById('fecha_inicio');
    const fechaFin       = document.getElementById('fecha_fin');
    const diasTexto      = document.getElementById('diasCalculados');
    const checkWrapper   = document.getElementById('checkMedioDia');
    const medioDia       = document.getElementById('medioDia');
    const barra          = document.getElementById('saldoBarra');
    const barraRelleno   = document.getElementById('saldoBarraRelleno');
    const barraTexto     = document.getElementById('saldoBarraTexto');
    const btnEnviar      = document.getElementById('btnEnviar');

    const saldo     = campoDias ? parseFloat(campoDias.dataset.saldo) : NaN;
    const finPrueba = campoDias ? (campoDias.dataset.finPrueba || '') : '';
    let feriados = new Set();
    try {
        feriados = new Set(JSON.parse((campoDias && campoDias.dataset.feriados) || '[]'));
    } catch (e) { /* sin feriados: se cuenta solo sin domingos */ }

    const esVacaciones = () => tipoSelect.value === 'vacaciones';
    const fmt = (n) => (Math.round(n * 10) / 10).toString();

    function sumarDias(iso, n) {
        const d = new Date(iso + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }

    // Misma regla que DiasHabilesService: lunes a sábado, sin feriados.
    function esHabil(iso) {
        return new Date(iso + 'T00:00:00Z').getUTCDay() !== 0 && !feriados.has(iso);
    }

    function contarHabiles(ini, fin) {
        let total = 0;
        for (let d = ini; d <= fin; d = sumarDias(d, 1)) {
            if (esHabil(d)) {
                total++;
            }
        }
        return total;
    }

    // Último día al que se llega tomando `n` días hábiles desde `ini`.
    function finTrasHabiles(ini, n) {
        let d = ini;
        let tomados = esHabil(d) ? 1 : 0;
        while (tomados < n) {
            d = sumarDias(d, 1);
            if (esHabil(d)) {
                tomados++;
            }
        }
        return d;
    }

    // Calendario: vacaciones no pueden iniciar en el período de prueba ni
    // terminar más allá de los días disponibles contados desde el inicio.
    function actualizarLimitesFechas() {
        if (fechaInicio) {
            if (esVacaciones() && finPrueba) {
                fechaInicio.min = finPrueba;
            } else {
                fechaInicio.removeAttribute('min');
            }
        }
        if (!fechaFin) {
            return;
        }
        if (fechaInicio && fechaInicio.value) {
            fechaFin.min = fechaInicio.value;
        } else {
            fechaFin.removeAttribute('min');
        }
        if (esVacaciones() && !isNaN(saldo) && saldo >= 0.5 && fechaInicio && fechaInicio.value) {
            fechaFin.max = finTrasHabiles(fechaInicio.value, Math.max(Math.floor(saldo), 1));
        } else {
            fechaFin.removeAttribute('max');
        }
    }

    // Barra de saldo; devuelve true si la selección excede los días disponibles.
    function actualizarBarra(dias) {
        const visible = esVacaciones() && !isNaN(saldo);
        if (barra) {
            barra.classList.toggle('d-none', !visible);
        }
        if (!visible || !barraRelleno || !barraTexto) {
            return false;
        }
        const excede = dias > saldo || saldo <= 0;
        const pct = saldo > 0 ? Math.min(100, Math.round((dias / saldo) * 100)) : 100;
        const poco = saldo > 0 && (saldo - dias) / saldo < 0.2;
        barraRelleno.style.width = pct + '%';
        barraRelleno.className = 'progress-bar ' + (excede ? 'bg-danger' : (poco ? 'bg-warning' : 'bg-success'));
        if (saldo <= 0) {
            barraTexto.textContent = 'No tiene días de vacaciones disponibles.';
        } else if (excede) {
            barraTexto.textContent = 'Disponibles: ' + fmt(saldo) + ' · Selecciona: ' + fmt(dias)
                + ' · Se pasa por ' + fmt(dias - saldo) + ' día(s).';
        } else {
            barraTexto.textContent = 'Disponibles: ' + fmt(saldo) + ' · Selecciona: ' + fmt(dias)
                + ' · Quedarían: ' + fmt(saldo - dias);
        }
        return excede;
    }

    function actualizarVisibilidad() {
        const esHorasExtra = tipoSelect.value === 'horas_extra';
        if (campoHoras) {
            campoHoras.classList.toggle('d-none', !esHorasExtra);
        }
        if (campoDias) {
            campoDias.classList.toggle('d-none', esHorasExtra || tipoSelect.value === '');
        }
        actualizarDias();
    }

    function actualizarDias() {
        actualizarLimitesFechas();
        if (!campoDias || campoDias.classList.contains('d-none') || !diasTexto) {
            const excedeOculto = actualizarBarra(0);
            if (btnEnviar) {
                btnEnviar.disabled = excedeOculto;
            }
            return;
        }
        const ini = fechaInicio ? fechaInicio.value : '';
        const fin = fechaFin ? fechaFin.value : '';
        if (!ini || !fin) {
            diasTexto.textContent = 'Seleccione las fechas para calcular los días.';
            if (checkWrapper) {
                checkWrapper.classList.add('d-none');
            }
            // Sin fechas todavía: la barra solo informa el saldo, sin bloquear el envío.
            actualizarBarra(0);
            if (btnEnviar) {
                btnEnviar.disabled = esVacaciones() && !isNaN(saldo) && saldo <= 0;
            }
            return;
        }
        const mismoDia = ini === fin;
        if (checkWrapper) {
            checkWrapper.classList.toggle('d-none', !mismoDia);
        }
        if (!mismoDia && medioDia) {
            medioDia.checked = false;
        }
        const esMedioDia = mismoDia && medioDia && medioDia.checked;
        const dias = esMedioDia ? 0.5 : contarHabiles(ini, fin);
        diasTexto.textContent = 'Está solicitando ' + dias + ' día(s) hábil(es) (no cuentan domingos ni feriados).';

        const excede = actualizarBarra(dias);
        if (btnEnviar) {
            btnEnviar.disabled = excede;
        }
    }

    tipoSelect.addEventListener('change', actualizarVisibilidad);
    if (fechaInicio) {
        fechaInicio.addEventListener('change', actualizarDias);
    }
    if (fechaFin) {
        fechaFin.addEventListener('change', actualizarDias);
    }
    if (medioDia) {
        medioDia.addEventListener('change', actualizarDias);
    }

    actualizarVisibilidad();
}

document.addEventListener('DOMContentLoaded', initSolicitudFormDinamico);

// ── Mi asistencia: al tocar un día con registro, muestra su detalle debajo del calendario ──
function initCalendarioAsistencia() {
    const cal = document.getElementById('calAsistencia');
    const panel = document.getElementById('detalleDia');
    if (!cal || !panel) {
        return;
    }
    const campo = (nombre) => panel.querySelector('[data-campo="' + nombre + '"]');

    cal.addEventListener('click', function (e) {
        const dia = e.target.closest('button.lb-dia');
        if (!dia) {
            return;
        }
        cal.querySelectorAll('.lb-dia--activo').forEach((d) => d.classList.remove('lb-dia--activo'));
        dia.classList.add('lb-dia--activo');
        campo('fecha').textContent = dia.dataset.fecha;
        campo('entrada').textContent = dia.dataset.entrada;
        campo('salida').textContent = dia.dataset.salida;
        campo('horas').textContent = dia.dataset.horas;
        campo('manual').classList.toggle('d-none', dia.dataset.manual !== '1');
        panel.classList.remove('d-none');
    });
}

document.addEventListener('DOMContentLoaded', initCalendarioAsistencia);

// ── Mi asistencia: si sale ≥15 min después del horario, pide justificación antes de marcar ──
function initSalidaTarde() {
    const form = document.getElementById('formSalida');
    const modalEl = document.getElementById('modalSalidaTarde');
    if (!form || !modalEl) {
        return;
    }
    const aMin = (hhmm) => { const [h, m] = hhmm.split(':').map(Number); return h * 60 + m; };
    const motivo = document.getElementById('motivoSalida');
    modalEl.querySelectorAll('input[name="opcionSalida"]').forEach((r) => r.addEventListener('change', () => {
        motivo.classList.toggle('d-none', r.value !== 'horas_extra' || !r.checked);
    }));

    form.addEventListener('submit', function (e) {
        if (form.dataset.confirmado === '1') {
            return;
        }
        const ahora = new Date();
        const minAhora = ahora.getHours() * 60 + ahora.getMinutes();
        const salida = form.dataset.salidaHorario;
        let extra = 0;
        let texto = '';
        if (salida) {
            extra = minAhora - aMin(salida);
            texto = 'Tu horario de hoy termina a las ' + salida + ' y estás marcando ' + Math.floor(extra / 60) + ' h ' + (extra % 60) + ' min después. ¿Por qué saliste tarde?';
        } else if (form.dataset.entrada) {
            const trabajados = minAhora - aMin(form.dataset.entrada);
            extra = trabajados - 8 * 60;
            texto = 'Hoy no tienes horario; trabajaste ' + Math.floor(trabajados / 60) + ' h ' + (trabajados % 60) + ' min y lo que pasa de 8 h cuenta como horas extra. ¿Por qué?';
        }
        if (extra < 15) {
            const boton = form.querySelector('button');
            if (boton) {
                boton.disabled = true;
            }
            return;
        }
        e.preventDefault();
        document.getElementById('salidaTardeTexto').textContent = texto;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });

    document.getElementById('btnConfirmarSalida').addEventListener('click', function () {
        const opcion = modalEl.querySelector('input[name="opcionSalida"]:checked').value;
        if (opcion === 'horas_extra' && motivo.value.trim() === '') {
            motivo.classList.remove('d-none');
            motivo.focus();
            return;
        }
        form.querySelector('input[name="opcion"]').value = opcion;
        form.querySelector('input[name="motivo"]').value = motivo.value.trim();
        form.dataset.confirmado = '1';
        this.disabled = true;
        form.submit();
    });
}

document.addEventListener('DOMContentLoaded', initSalidaTarde);

// ── Contratos: fecha de vencimiento y preparación técnica según el tipo (Art. 26 y 27) ──
function initContratoVencimiento() {
    document.querySelectorAll('[data-contrato-vencimiento]').forEach(function (bloque) {
        const form = bloque.closest('form');
        const tipo = form ? form.querySelector('select[name="tipo_contrato"]') : null;
        if (!tipo) {
            return;
        }
        const tecnica = form.querySelector('[data-contrato-tecnica]');
        const fecha   = bloque.querySelector('input[name="fecha_fin"]');

        function actualizar() {
            const esPlazo = tipo.value === 'plazo_fijo';
            const esObra  = tipo.value === 'obra_determinada';
            bloque.hidden = !(esPlazo || esObra);
            fecha.required = esPlazo || esObra;
            bloque.querySelectorAll('[data-etiqueta-plazo], [data-ayuda-plazo]').forEach(function (el) { el.hidden = esObra; });
            bloque.querySelectorAll('[data-etiqueta-obra], [data-ayuda-obra]').forEach(function (el) { el.hidden = !esObra; });
            if (tecnica) {
                tecnica.hidden = !esPlazo;
            }
        }

        tipo.addEventListener('change', actualizar);
        actualizar();
    });
}

document.addEventListener('DOMContentLoaded', initContratoVencimiento);
