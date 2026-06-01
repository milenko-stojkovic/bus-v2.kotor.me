/**
 * Hybrid date input: visible dd/mm/yyyy + hidden Y-m-d + native date picker (calendar).
 */
function parseDisplayToIso(raw, min, max) {
    const trimmed = String(raw || '').trim();
    if (trimmed === '') {
        return '';
    }

    const match = trimmed.match(/^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$/);
    if (!match) {
        return null;
    }

    const day = match[1].padStart(2, '0');
    const month = match[2].padStart(2, '0');
    const year = match[3];
    const iso = `${year}-${month}-${day}`;

    const parts = iso.split('-').map((v) => parseInt(v, 10));
    const dt = new Date(parts[0], parts[1] - 1, parts[2]);
    if (
        dt.getFullYear() !== parts[0]
        || dt.getMonth() !== parts[1] - 1
        || dt.getDate() !== parts[2]
    ) {
        return null;
    }

    if (min && iso < min) {
        return null;
    }
    if (max && iso > max) {
        return null;
    }

    return iso;
}

function isoToDisplay(iso) {
    if (!iso || typeof iso !== 'string') {
        return '';
    }
    const parts = iso.split('-');
    if (parts.length !== 3) {
        return '';
    }
    const [year, month, day] = parts;
    if (!year || !month || !day) {
        return '';
    }

    return `${day.padStart(2, '0')}/${month.padStart(2, '0')}/${year}`;
}

function openNativeDatePicker(picker) {
    if (!picker) {
        return;
    }
    try {
        if (typeof picker.showPicker === 'function') {
            picker.showPicker();
            return;
        }
    } catch {
        // showPicker may throw if not user-gesture; fall through
    }
    picker.focus();
    picker.click();
}

function bindIsoDateInput(wrapper) {
    const hidden = wrapper.querySelector('input[type="hidden"][data-iso-date-hidden]');
    const display = wrapper.querySelector('[data-iso-date-display]');
    const picker = wrapper.querySelector('input[type="date"][data-iso-date-picker]');
    const calendarBtn = wrapper.querySelector('[data-iso-date-calendar-btn]');
    if (!hidden || !display || display.dataset.isoDateBound === '1') {
        return;
    }
    display.dataset.isoDateBound = '1';

    const min = hidden.dataset.min || '';
    const max = hidden.dataset.max || '';
    const invalidMessage = display.placeholder || 'dd/mm/yyyy';

    const applyIso = (iso, { dispatchChange = true } = {}) => {
        const normalized = iso || '';
        const prev = hidden.value;
        hidden.value = normalized;
        display.value = normalized ? isoToDisplay(normalized) : '';
        if (picker) {
            picker.value = normalized;
        }
        display.setCustomValidity('');
        if (dispatchChange && prev !== normalized) {
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const syncDisplayFromHidden = () => {
        applyIso(hidden.value, { dispatchChange: false });
    };

    const syncHiddenFromDisplay = () => {
        const trimmed = String(display.value).trim();
        const iso = parseDisplayToIso(display.value, min, max);
        if (iso === null) {
            if (trimmed !== '') {
                display.setCustomValidity(invalidMessage);
                return false;
            }
            applyIso('');
            return true;
        }
        applyIso(iso);
        return true;
    };

    display.addEventListener('blur', syncHiddenFromDisplay);
    display.addEventListener('change', syncHiddenFromDisplay);

    if (picker) {
        picker.addEventListener('change', () => {
            const iso = picker.value || '';
            if (iso === '') {
                applyIso('');
                return;
            }
            if ((min && iso < min) || (max && iso > max)) {
                return;
            }
            applyIso(iso);
        });
    }

    if (calendarBtn && picker) {
        calendarBtn.addEventListener('click', () => {
            syncHiddenFromDisplay();
            if (hidden.value) {
                picker.value = hidden.value;
            }
            openNativeDatePicker(picker);
        });
    }

    const formId = hidden.getAttribute('form');
    const form = hidden.form || (formId ? document.getElementById(formId) : null);
    if (form) {
        form.addEventListener('submit', (event) => {
            if (!syncHiddenFromDisplay()) {
                event.preventDefault();
                display.reportValidity();
            }
        });
    }

    syncDisplayFromHidden();
}

export function initIsoDateInputs(root = document) {
    root.querySelectorAll('[data-iso-date-input]').forEach(bindIsoDateInput);
}

document.addEventListener('DOMContentLoaded', () => initIsoDateInputs());
