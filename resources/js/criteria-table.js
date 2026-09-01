/*
 * The acceptance criteria a quality inspection is walked against (Phase 4).
 *
 * SEPARATE FROM `checklistTable` DESPITE LOOKING LIKE IT. An HSE inspection question carries a
 * category and a score; a quality checkpoint carries the criterion the work has to meet, and no
 * score at all — because quality acceptance is not a percentage. Folding the two into one component
 * would mean every row of both screens quietly carried the other's empty columns.
 *
 * THERE IS NO RESULT COLUMN HERE. The measured value and the verdict are written on the inspection
 * screen, by the inspector, under a different permission. A criteria editor that could also record
 * outcomes would be the whole separation defeated in one file.
 *
 * Rows post as ordinary `items[i][field]` inputs, so the form request validates each cell by name
 * and the screen degrades to a plain form if the script never loads.
 */
export default function criteriaTable(initial = []) {
    return {
        rows: initial.length ? initial : [blankCriterion()],

        add() {
            this.rows.push(blankCriterion());
        },

        /**
         * How many rows state what "acceptable" actually means.
         *
         * Shown live because a checklist of descriptions with no criteria is the failure mode this
         * screen exists to prevent, and a number nobody has to count is the cheapest way to say so.
         */
        get statedCount() {
            return this.rows.filter((row) => String(row.acceptance_criteria || '').trim() !== '').length;
        },
    };
}

function blankCriterion() {
    return {
        reference: '',
        description: '',
        acceptance_criteria: '',
    };
}
