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
function initSolicitudFormDinamico() {
    const tipoSelect = document.getElementById('tipo');
    if (!tipoSelect) {
        return;
    }
    const campoHoras    = document.getElementById('campoHoras');
    const campoDias     = document.getElementById('campoDias');
    const fechaInicio   = document.getElementById('fecha_inicio');
    const fechaFin      = document.getElementById('fecha_fin');
    const diasTexto     = document.getElementById('diasCalculados');
    const checkWrapper  = document.getElementById('checkMedioDia');
    const medioDia      = document.getElementById('medioDia');
    const saldoInfo     = document.getElementById('saldoInfo');
    const saldoEstimado = document.getElementById('saldoEstimado');

    function actualizarVisibilidad() {
        const esHorasExtra = tipoSelect.value === 'horas_extra';
        if (campoHoras) {
            campoHoras.classList.toggle('d-none', !esHorasExtra);
        }
        if (campoDias) {
            campoDias.classList.toggle('d-none', esHorasExtra || tipoSelect.value === '');
        }
        if (saldoInfo) {
            saldoInfo.classList.toggle('d-none', tipoSelect.value !== 'vacaciones');
        }
        actualizarDias();
    }

    function actualizarDias() {
        if (!campoDias || campoDias.classList.contains('d-none') || !diasTexto) {
            return;
        }
        const ini = fechaInicio ? fechaInicio.value : '';
        const fin = fechaFin ? fechaFin.value : '';
        if (!ini || !fin) {
            diasTexto.textContent = 'Seleccione las fechas para calcular los días.';
            if (checkWrapper) {
                checkWrapper.classList.add('d-none');
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
        const dias = esMedioDia ? 0.5 : (Math.round((new Date(fin) - new Date(ini)) / 86400000) + 1);
        diasTexto.textContent = 'Está solicitando ' + dias + ' día(s).';

        if (saldoEstimado && campoDias.dataset.saldo) {
            const saldoActual = parseFloat(campoDias.dataset.saldo);
            if (!isNaN(saldoActual)) {
                saldoEstimado.textContent = (saldoActual - dias).toString();
            }
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
