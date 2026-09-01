import './bootstrap';

import Alpine from 'alpinejs';
import documentEditor, { richTextField } from './editor';
import attendanceSheet from './attendance-sheet';
import checklistTable from './checklist-table';
import criteriaTable from './criteria-table';
import hazardTable from './hazard-table';
import precautionTable from './precaution-table';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';

Alpine.plugin(collapse);
Alpine.plugin(focus);

/*
 * Theme preference.
 *
 * Kept as a store rather than sprinkled x-data, so the toggle in the top bar and the class on
 * <html> can never disagree. The initial value is applied by an inline script in the layout head
 * — before first paint — because reading it here would produce a flash of the wrong theme.
 */
Alpine.store('theme', {
    dark: document.documentElement.classList.contains('dark'),

    toggle() {
        this.dark = !this.dark;
        document.documentElement.classList.toggle('dark', this.dark);

        try {
            localStorage.setItem('theme', this.dark ? 'dark' : 'light');
        } catch (e) {
            // Private browsing, or storage disabled. The preference simply will not persist.
        }
    },
});

/*
 * Confirm-before-submit for destructive forms.
 *
 * A convenience only. Nothing here is a security control: the server re-authorizes every
 * destructive action through a policy, because a confirm dialog is trivially skipped (§84).
 */
Alpine.data('confirmable', (message = 'Are you sure?') => ({
    open: false,
    message,

    confirm(event) {
        if (!this.open) {
            event.preventDefault();
            this.open = true;
        }
    },
}));

/*
 * The document editor (Phase 3).
 *
 * Registered as an Alpine component rather than mounted by a selector sweep, so the editor exists
 * only on the page that asks for it and receives its content, save URL and read-only flag as
 * ordinary props instead of reading them back out of the DOM.
 */
Alpine.data('documentEditor', documentEditor);

/*
 * The same editor inside an ordinary form — templates, and anything else administrative that is
 * saved with a Save button rather than autosaved against its own endpoint.
 */
Alpine.data('richTextField', richTextField);

/*
 * The hazard table on a risk assessment (Phase 4).
 *
 * Registered here for the same reason the editor is: the component receives its existing rows as a
 * prop from the Blade file, rather than a loose script tag reading them back out of the DOM.
 */
Alpine.data('hazardTable', hazardTable);

/*
 * The questions an HSE inspection is walked against (Phase 4).
 */
Alpine.data('checklistTable', checklistTable);

/*
 * The acceptance criteria a quality inspection is walked against (Phase 4). Deliberately not the
 * same component: one carries scores, the other carries the specification clause.
 */
Alpine.data('criteriaTable', criteriaTable);

/*
 * The attendance sheet on a toolbox talk (Phase 4).
 */
Alpine.data('attendanceSheet', attendanceSheet);

/*
 * The precaution template on a permit type (Phase 4).
 */
Alpine.data('precautionTable', precautionTable);

window.Alpine = Alpine;
Alpine.start();
