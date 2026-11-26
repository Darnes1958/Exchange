<style>
    .deal-create-compact .fi-header { display: none; }
    .deal-create-compact .fi-fo-field { --cols-spacing: 0.55rem; margin-block: 0.35rem; }
    .deal-create-compact .fi-sc-flex { gap: 0.5rem; }
    .deal-create-compact .fi-input-wrp { min-height: 2.25rem; }
    .deal-create-compact .fi-sc-component { row-gap: 0.45rem; }

    .fi-fo-date-time-picker .fi-async-component-content {
        display: flex;
        align-items: stretch;
        gap: 0.35rem;
        position: relative;
    }

    .fi-fo-date-time-picker .fi-fo-date-time-picker-trigger {
        width: auto;
        flex: 1 1 auto;
        min-width: 0;
    }

    .date-calendar-button {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        border: 1px solid rgb(209 213 219);
        border-radius: 0.5rem;
        background: #fff;
        color: rgb(55 65 81);
        cursor: pointer;
    }

    .date-calendar-button svg {
        width: 1.15rem;
        height: 1.15rem;
    }
</style>
<script>
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const current = event.target;

        if (! (current instanceof HTMLElement) || ! current.classList.contains('fi-fo-date-time-picker-trigger')) {
            return;
        }

        const container = current.closest('[role="dialog"]') || current.closest('form');

        if (! container) {
            return;
        }

        const next = nextEntryTarget(container, current);

        event.preventDefault();
        event.stopImmediatePropagation();

        if (! next) {
            return;
        }

        next.focus();

        if (typeof next.select === 'function' && next.tagName !== 'BUTTON') {
            next.select();
        }
    }, true);

    document.addEventListener('click', (event) => {
        const current = event.target;

        if (! (current instanceof Element) || ! current.closest('.fi-fo-date-time-picker-trigger')) {
            return;
        }

        event.stopImmediatePropagation();
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        const current = event.target;

        if (! (current instanceof HTMLElement)) {
            return;
        }

        if (current.closest('[role="listbox"], .flatpickr-calendar')) {
            return;
        }

        if (current.getAttribute('aria-expanded') === 'true') {
            return;
        }

        if (current.tagName === 'BUTTON' && current.getAttribute('type') === 'submit') {
            return;
        }

        const container = current.closest('[role="dialog"]') || current.closest('form');

        if (! container || ! container.querySelector('.fi-fo-field, .fi-fo-field-wrp')) {
            return;
        }

        const next = nextEntryTarget(container, current);

        if (! next) {
            return;
        }

        event.preventDefault();
        next.focus();

        if (typeof next.select === 'function' && next.tagName !== 'BUTTON') {
            next.select();
        }
    });

    document.addEventListener('blur', (event) => {
        const input = event.target;

        if (! (input instanceof HTMLInputElement) || input.dataset.numberFormat !== '1') {
            return;
        }

        const maxDecimals = Number(input.dataset.numberDecimals || 0) || null;
        const formatted = displayNumber(input.value, maxDecimals);

        if (formatted !== input.value) {
            input.value = formatted;
        }
    }, true);

    document.addEventListener('DOMContentLoaded', () => {
        focusInitialEntry();
        installDateButtons();
    });
    document.addEventListener('livewire:navigated', () => {
        focusInitialEntry();
        installDateButtons();
    });

    const dateButtonObserver = new MutationObserver(() => installDateButtons());
    dateButtonObserver.observe(document.documentElement, { childList: true, subtree: true });

    function displayNumber(value, maxDecimals) {
        const raw = String(value).replace(/,/g, '').trim();

        if (! /^-?\d+(\.\d+)?$/.test(raw)) {
            return value;
        }

        const negative = raw.startsWith('-');
        let [whole, fraction] = raw.replace('-', '').split('.');

        if (fraction !== undefined && maxDecimals) {
            fraction = (fraction + '000').slice(0, maxDecimals);
        }

        const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return (negative ? '-' : '') + grouped + (fraction ? '.' + fraction : '');
    }

    function installDateButtons() {
        document.querySelectorAll('.fi-fo-date-time-picker').forEach((picker) => {
            const host = picker.querySelector('.fi-async-component-content');
            const trigger = picker.querySelector('.fi-fo-date-time-picker-trigger');

            if (! host || ! trigger || host.querySelector('.date-calendar-button')) {
                return;
            }

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'date-calendar-button';
            button.tabIndex = -1;
            button.setAttribute('aria-label', 'فتح التقويم');
            button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2"></rect><path d="M8 3.5v3M16 3.5v3M3.5 10h17"></path></svg>';
            button.addEventListener('click', (clickEvent) => {
                clickEvent.preventDefault();
                clickEvent.stopPropagation();

                const data = window.Alpine?.$data(host);

                if (data && typeof data.togglePanelVisibility === 'function') {
                    data.togglePanelVisibility();
                }
            });
            host.appendChild(button);
        });
    }

    function focusInitialEntry() {
        window.setTimeout(() => {
            const field = document.querySelector('[data-initial-focus]');

            if (! (field instanceof HTMLElement) || field.disabled) {
                return;
            }

            field.focus();

            if (typeof field.select === 'function') {
                field.select();
            }
        }, 150);
    }

    function nextEntryTarget(container, current) {
        const selector = [
            'input:not([type="hidden"]):not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            'button[type="submit"]:not([disabled])',
            '[role="combobox"]:not([disabled])',
        ].join(', ');

        const visible = [...container.querySelectorAll(selector)].filter((element) => {
            if (element.closest('[aria-hidden="true"], [inert]')) {
                return false;
            }

            const rectangle = element.getBoundingClientRect();

            return rectangle.width > 0 && rectangle.height > 0;
        });

        const targets = [];
        const radioGroups = new Set();

        visible.forEach((element) => {
            if (element instanceof HTMLInputElement && element.type === 'radio') {
                if (radioGroups.has(element.name)) {
                    return;
                }

                radioGroups.add(element.name);
                targets.push(current instanceof HTMLInputElement && current.type === 'radio' && current.name === element.name ? current : element);

                return;
            }

            targets.push(element);
        });

        let index = targets.indexOf(current);

        if (index === -1 && current instanceof HTMLInputElement && current.type === 'radio') {
            index = targets.findIndex((element) => element instanceof HTMLInputElement && element.name === current.name);
        }

        if (index === -1) {
            return null;
        }

        return targets[index + 1] ?? null;
    }

    installDateButtons();
</script>
