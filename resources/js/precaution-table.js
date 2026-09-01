/*
 * The precaution template on a permit type (Phase 4).
 *
 * A REPEATER AND NOTHING MORE. These rows are configuration: they are COPIED onto every future
 * permit of this type and never referenced backwards, so nothing edited here can reach a permit
 * somebody has already signed. That is what makes this screen safe to change, and it is a property
 * of PermitService rather than of this file — there is deliberately no cleverness here to blur it.
 *
 * Rows post as ordinary `precautions[i][description]` and `precautions[i][is_required]` inputs, so
 * the form request validates each cell by name and the screen still works if the script never runs.
 */
export default function precautionTable(initial = []) {
    return {
        rows: initial.length ? initial : [blankPrecaution()],

        add() {
            this.rows.push(blankPrecaution());
        },

        remove(index) {
            this.rows.splice(index, 1);

            if (this.rows.length === 0) {
                this.rows.push(blankPrecaution());
            }
        },

        /** How many lines an issuer will actually be blocked by. Shown so nobody has to count. */
        get requiredCount() {
            return this.rows.filter((row) => row.is_required && String(row.description).trim() !== '').length;
        },
    };
}

function blankPrecaution() {
    return {
        description: '',
        is_required: true,
    };
}
