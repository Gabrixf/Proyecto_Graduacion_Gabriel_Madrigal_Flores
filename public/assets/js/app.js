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
