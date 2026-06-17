import './bootstrap';

import Alpine from 'alpinejs';
import './dailyCapacityCharts';
import './isoDateInput';
import './reservationFormScroll';

window.Alpine = Alpine;

Alpine.start();

// Dupli klik na "Plati": onemogući submit dugme nakon prvog klika
document.addEventListener('DOMContentLoaded', function () {
    var selector = 'form[action*="checkout"], form[data-disable-double-submit]';
    document.querySelectorAll(selector).forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
            }
        });
    });
});
