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
