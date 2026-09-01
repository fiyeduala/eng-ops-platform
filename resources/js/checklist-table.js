/*
 * The checklist a site inspection is walked against (Phase 4).
 *
 * A REPEATER AND NOTHING MORE. There is no scoring here and no results — the rows are questions,
 * and the answers are written on a different screen by a different person under a different
 * permission. Anything cleverer in this file would blur that line.
 *
 * The rows post as ordinary `items[i][field]` inputs, so the form request validates each cell by
 * name and the screen still works as a plain form if the script never loads.
 */
export default function checklistTable(initial = []) {
    return {
        rows: initial.length ? initial : [blankItem()],

        add() {
            this.rows.push(blankItem());
        },

        /** The total the walk will be marked out of, shown live so nobody has to add it up. */
        get totalPossible() {
            return this.rows.reduce((sum, row) => sum + (Number(row.score_possible) || 0), 0);
        },
    };
}

function blankItem() {
    return {
        reference: '',
        description: '',
        category: '',
        score_possible: '',
    };
}
