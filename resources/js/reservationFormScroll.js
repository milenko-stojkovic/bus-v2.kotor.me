/**
 * Preserve scroll position across GET auto-submit on reservation step forms.
 * Scoped via data-reservation-auto-scroll on the form element only.
 */

const SCROLL_OFFSET_PX = 80;

function anchorStorageKey(scrollKey) {
    return `${scrollKey}_anchor`;
}

export function saveReservationScroll(scrollKey, sourceEl) {
    if (!scrollKey) {
        return;
    }

    let y = window.scrollY;
    let anchor = '';

    if (sourceEl && typeof sourceEl.getBoundingClientRect === 'function') {
        const rect = sourceEl.getBoundingClientRect();
        y = window.scrollY + rect.top - SCROLL_OFFSET_PX;
        anchor = sourceEl.id || sourceEl.name || '';
    }

    sessionStorage.setItem(scrollKey, String(Math.max(0, Math.round(y))));
    if (anchor) {
        sessionStorage.setItem(anchorStorageKey(scrollKey), anchor);
    } else {
        sessionStorage.removeItem(anchorStorageKey(scrollKey));
    }
}

export function restoreReservationScroll(scrollKey) {
    if (!scrollKey) {
        return false;
    }

    const raw = sessionStorage.getItem(scrollKey);
    if (raw === null) {
        return false;
    }

    const anchor = sessionStorage.getItem(anchorStorageKey(scrollKey));

    sessionStorage.removeItem(scrollKey);
    sessionStorage.removeItem(anchorStorageKey(scrollKey));

    const scrollToStored = () => {
        if (anchor) {
            const el =
                document.getElementById(anchor) ||
                document.querySelector(`[name="${CSS.escape(anchor)}"]`);

            if (el && typeof el.getBoundingClientRect === 'function') {
                const y = window.scrollY + el.getBoundingClientRect().top - SCROLL_OFFSET_PX;
                window.scrollTo({ top: Math.max(0, y), behavior: 'auto' });

                return;
            }
        }

        const y = Number(raw);
        if (!Number.isNaN(y)) {
            window.scrollTo({ top: y, behavior: 'auto' });
        }
    };

    requestAnimationFrame(scrollToStored);

    return true;
}

export function submitReservationForm(form, sourceEl) {
    if (!form) {
        return;
    }

    const scrollKey = form.getAttribute('data-reservation-auto-scroll');
    if (scrollKey) {
        saveReservationScroll(scrollKey, sourceEl ?? document.activeElement);
    }

    form.submit();
}

function initReservationFormScrollForms() {
    document.querySelectorAll('form[data-reservation-auto-scroll]').forEach((form) => {
        const scrollKey = form.getAttribute('data-reservation-auto-scroll');

        form.addEventListener('submit', (e) => {
            if (!scrollKey) {
                return;
            }
            saveReservationScroll(scrollKey, e.submitter ?? document.activeElement);
        });

        if (form.dataset.skipScrollRestore !== 'true') {
            restoreReservationScroll(scrollKey);
        }
    });
}

if (typeof window !== 'undefined') {
    window.ReservationFormScroll = {
        save: saveReservationScroll,
        restore: restoreReservationScroll,
        submit: submitReservationForm,
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReservationFormScrollForms);
    } else {
        initReservationFormScrollForms();
    }
}
