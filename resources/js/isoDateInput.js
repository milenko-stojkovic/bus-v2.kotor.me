/**
 * Visible dd/mm/yyyy text field + hidden Y-m-d for forms (agency panel).
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

function bindIsoDateInput(wrapper) {
    const hidden = wrapper.querySelector('input[type="hidden"][data-iso-date-hidden]');
    const display = wrapper.querySelector('[data-iso-date-display]');
    if (!hidden || !display || display.dataset.isoDateBound === '1') {
        return;
    }
    display.dataset.isoDateBound = '1';

    const min = hidden.dataset.min || '';
    const max = hidden.dataset.max || '';

    const syncDisplayFromHidden = () => {
        display.value = isoToDisplay(hidden.value);
    };

    const syncHiddenFromDisplay = () => {
        const iso = parseDisplayToIso(display.value, min, max);
        if (iso === null && String(display.value).trim() !== '') {
            return;
        }
        const prev = hidden.value;
        hidden.value = iso;
        if (prev !== iso) {
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (iso !== '') {
            display.value = isoToDisplay(iso);
        }
    };

    display.addEventListener('blur', syncHiddenFromDisplay);
    display.addEventListener('change', syncHiddenFromDisplay);

    const formId = hidden.getAttribute('form');
    const form = hidden.form || (formId ? document.getElementById(formId) : null);
    if (form) {
        form.addEventListener('submit', () => syncHiddenFromDisplay());
    }

    syncDisplayFromHidden();
}

export function initIsoDateInputs(root = document) {
    root.querySelectorAll('[data-iso-date-input]').forEach(bindIsoDateInput);
}

document.addEventListener('DOMContentLoaded', () => initIsoDateInputs());
