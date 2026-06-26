/**
 * Preserve scroll position across GET auto-submit on reservation step forms.
 * Scoped via data-reservation-auto-scroll on the form element only.
 */

const SCROLL_OFFSET_PX = 80;
const GUEST_FEEDBACK_HIGHLIGHT_MS = 2500;

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

/**
 * Guest /guest/reserve only: scroll top validation or checkout feedback into view after POST redirect.
 */
export function scrollToGuestReservationFeedback() {
    const el = document.querySelector('[data-guest-reservation-feedback]');
    if (!el) {
        return false;
    }

    el.scrollIntoView({ behavior: 'smooth', block: 'start' });

    window.setTimeout(() => {
        const top = el.getBoundingClientRect().top + window.scrollY - SCROLL_OFFSET_PX;
        window.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        el.classList.add('ring-2', 'ring-red-400', 'ring-offset-2', 'rounded-md');
        window.setTimeout(() => {
            el.classList.remove('ring-2', 'ring-red-400', 'ring-offset-2', 'rounded-md');
        }, GUEST_FEEDBACK_HIGHLIGHT_MS);
    }, 100);

    return true;
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
        scrollToGuestFeedback: scrollToGuestReservationFeedback,
    };

    const onReady = () => {
        initReservationFormScrollForms();
        scrollToGuestReservationFeedback();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }
}
