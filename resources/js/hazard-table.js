/*
 * The hazard table on a risk assessment (Phase 4).
 *
 * ARITHMETIC IN FRONT OF THE WRITER, NOTHING ELSE. Likelihood times severity is shown twice —
 * before controls and after — because watching the second number fail to come down is the entire
 * point of the exercise, and a writer who only sees it after saving does not notice. The server
 * recalculates both on save and stores its own answer; nothing computed here is trusted (§84).
 *
 * The rows are posted as ordinary `hazards[i][field]` inputs rather than as JSON, so the form
 * request validates each cell by name and the screen degrades to a plain form if the script
 * never loads.
 */
export default function hazardTable(initial = []) {
    return {
        rows: initial.length ? initial : [blankHazard()],

        add() {
            this.rows.push(blankHazard());
        },

        remove(index) {
            this.rows.splice(index, 1);
        },

        /** An em dash rather than a zero: an unscored hazard is not a hazard scored nil. */
        score(likelihood, severity) {
            return likelihood && severity ? likelihood * severity : '—';
        },

        /**
         * Whether the controls actually moved the number.
         *
         * Only ever a warning. A residual score equal to the raw one is occasionally the honest
         * answer — some hazards cannot be reduced further and the sheet should say so — so this
         * prompts and never blocks.
         */
        notReduced(row) {
            const before = row.likelihood * row.severity;
            const after = row.residual_likelihood * row.residual_severity;

            return Boolean(before) && Boolean(after) && after >= before;
        },
    };
}

function blankHazard() {
    return {
        hazard: '',
        who_is_at_risk: '',
        existing_controls: '',
        additional_controls: '',
        likelihood: null,
        severity: null,
        residual_likelihood: null,
        residual_severity: null,
        responsible_id: '',
        target_date: '',
    };
}
