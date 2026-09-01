/*
 * The attendance sheet on a toolbox talk (Phase 4).
 *
 * MOST PEOPLE ON A SITE ARE NOT USERS OF THIS SYSTEM. Every row therefore has a free-text name
 * beside the optional user picker, and the name is what the row is really keyed on — a sheet that
 * could only record employees would be missing exactly the subcontract and agency labour most
 * likely to be hurt.
 *
 * Rows post as ordinary `attendees[i][field]` inputs. Nothing here is trusted: the service decides
 * which linked user ids belong to the tenant and refuses the rest.
 */
export default function attendanceSheet(initial = []) {
    return {
        rows: initial.length ? initial : [blankAttendee(), blankAttendee(), blankAttendee()],

        add() {
            this.rows.push(blankAttendee());
        },

        /** Rows carrying a name — the only ones the service will keep. */
        get named() {
            return this.rows.filter((row) => String(row.person_name || '').trim() !== '').length;
        },
    };
}

function blankAttendee() {
    return {
        user_id: '',
        person_name: '',
        company: '',
        trade: '',
    };
}
