<?php

declare(strict_types=1);

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentCategoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentVersionController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MemoController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrgUnitController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDepartmentController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TaskChecklistItemController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskDependencyController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TransmittalController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\WorkflowStageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Route-level `permission:` middleware is the FIRST filter, never the only one (§6, §84). It
| answers "may this kind of user reach this area at all". Which specific records they may then
| see or touch is decided by a policy in the controller, because a route cannot know what the id
| in the URL refers to. Every route below that takes an id has a corresponding authorize() call.
|
| The `permission:` names are the coarsest ability of the group. A route guarded by
| `permission:employee.view` still refuses an individual employee the viewer cannot reach.
|
*/

// ---------------------------------------------------------------------------- unauthenticated

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'send'])->name('password.email');

    // Signed: the link cannot be edited to name a different account. The token is verified on top
    // of the signature, so a valid signature alone opens nothing.
    Route::get('reset-password/{user}/{token}', [PasswordResetController::class, 'edit'])
        ->middleware('signed')
        ->name('password.reset');

    Route::post('reset-password', [PasswordResetController::class, 'update'])->name('password.store');
});

// ---------------------------------------------------------------- authenticated, password stale

// Reachable while `must_change_password` is set, so a user forced to change theirs is not locked
// out of the two pages that let them comply.
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');
});

// ------------------------------------------------------------------------------- the application

Route::middleware(['auth', 'password.current'])->group(function () {

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Your own record. No permission middleware on purpose — everyone may see and maintain their
    // own profile, and the controller only ever addresses $request->user().
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

    // Your own bell — same reasoning as the profile above, and the same absence of permission
    // middleware. There is no `notification.view` permission because a notification is not a record
    // anybody administers; see NotificationPolicy for why granting one would be a mistake.
    //
    // `notifications/{notification}` is deliberately not a show route. Opening a notification means
    // going to the record it is about, and `open` is where that record is authorized again at the
    // moment of the click before any redirect happens.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/preferences', [NotificationController::class, 'preferences'])
        ->name('notifications.preferences');
    Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences'])
        ->name('notifications.preferences.update');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])
        ->whereNumber('notification')->name('notifications.open');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification')->name('notifications.read');

    // --- organization structure ------------------------------------------------------------

    Route::get('organization', [OrganizationController::class, 'edit'])
        ->middleware('permission:organization.view,organization.configure')
        ->name('organization.edit');
    Route::put('organization', [OrganizationController::class, 'update'])
        ->middleware('permission:organization.configure')
        ->name('organization.update');

    Route::middleware('permission:org_unit.view')->group(function () {
        Route::get('org-units', [OrgUnitController::class, 'index'])->name('org-units.index');
        Route::get('org-units/{org_unit}', [OrgUnitController::class, 'show'])
            ->whereNumber('org_unit')->name('org-units.show');
    });

    Route::get('org-units/create', [OrgUnitController::class, 'create'])
        ->middleware('permission:org_unit.create')->name('org-units.create');
    Route::post('org-units', [OrgUnitController::class, 'store'])
        ->middleware('permission:org_unit.create')->name('org-units.store');
    Route::get('org-units/{org_unit}/edit', [OrgUnitController::class, 'edit'])
        ->middleware('permission:org_unit.edit')->name('org-units.edit');
    Route::put('org-units/{org_unit}', [OrgUnitController::class, 'update'])
        ->middleware('permission:org_unit.edit')->name('org-units.update');
    Route::delete('org-units/{org_unit}', [OrgUnitController::class, 'destroy'])
        ->middleware('permission:org_unit.delete')->name('org-units.destroy');

    // --- people ------------------------------------------------------------------------------

    Route::middleware('permission:employee.view')->group(function () {
        Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('employees/{employee}', [EmployeeController::class, 'show'])
            ->whereNumber('employee')->name('employees.show');
    });

    Route::get('employees/create', [EmployeeController::class, 'create'])
        ->middleware('permission:employee.create')->name('employees.create');
    Route::post('employees', [EmployeeController::class, 'store'])
        ->middleware('permission:employee.create')->name('employees.store');
    Route::get('employees/{employee}/edit', [EmployeeController::class, 'edit'])
        ->middleware('permission:employee.edit')->name('employees.edit');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('permission:employee.edit')->name('employees.update');
    Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware('permission:employee.delete')->name('employees.destroy');

    // --- reference data ----------------------------------------------------------------------

    Route::get('positions', [PositionController::class, 'index'])
        ->middleware('permission:position.view')->name('positions.index');
    Route::get('positions/create', [PositionController::class, 'create'])
        ->middleware('permission:position.create')->name('positions.create');
    Route::post('positions', [PositionController::class, 'store'])
        ->middleware('permission:position.create')->name('positions.store');
    Route::get('positions/{position}/edit', [PositionController::class, 'edit'])
        ->middleware('permission:position.edit')->name('positions.edit');
    Route::put('positions/{position}', [PositionController::class, 'update'])
        ->middleware('permission:position.edit')->name('positions.update');
    Route::delete('positions/{position}', [PositionController::class, 'destroy'])
        ->middleware('permission:position.delete')->name('positions.destroy');

    Route::get('locations', [LocationController::class, 'index'])
        ->middleware('permission:location.view')->name('locations.index');
    Route::get('locations/create', [LocationController::class, 'create'])
        ->middleware('permission:location.create')->name('locations.create');
    Route::post('locations', [LocationController::class, 'store'])
        ->middleware('permission:location.create')->name('locations.store');
    Route::get('locations/{location}/edit', [LocationController::class, 'edit'])
        ->middleware('permission:location.edit')->name('locations.edit');
    Route::put('locations/{location}', [LocationController::class, 'update'])
        ->middleware('permission:location.edit')->name('locations.update');
    Route::delete('locations/{location}', [LocationController::class, 'destroy'])
        ->middleware('permission:location.delete')->name('locations.destroy');

    // --- projects ------------------------------------------------------------------------------
    //
    // Everything below /projects/{project}/… uses scoped bindings, so a nested id must belong to
    // the project in the path. /projects/7/team/93 where member 93 is on project 8 is a 404, not
    // an authorization near-miss — the IDOR case §94 asks to be tested, closed at the router.

    Route::middleware('permission:project.view')->group(function () {
        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::get('projects/{project}', [ProjectController::class, 'show'])
            ->whereNumber('project')->name('projects.show');
    });

    Route::get('projects/create', [ProjectController::class, 'create'])
        ->middleware('permission:project.create')->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])
        ->middleware('permission:project.create')->name('projects.store');
    Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])
        ->middleware('permission:project.edit')->name('projects.edit');
    Route::put('projects/{project}', [ProjectController::class, 'update'])
        ->middleware('permission:project.edit')->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])
        ->middleware('permission:project.delete')->name('projects.destroy');

    // Status is not a form field. It moves through the configured whitelist, and the endpoint
    // re-asks StatusMachine with the same actor that drew the buttons (§63, §84).
    Route::post('projects/{project}/status', [ProjectController::class, 'transition'])
        ->middleware('permission:project.edit')->name('projects.status');

    // Its own permission: "we are finished with this" is a different decision from "correct this
    // description", and §14 wants a finished project archived rather than deleted.
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive'])
        ->middleware('permission:project.archive')->name('projects.archive');
    Route::post('projects/{project}/unarchive', [ProjectController::class, 'unarchive'])
        ->middleware('permission:project.archive')->name('projects.unarchive');

    // The team. Every row here is a visibility grant, which is why assigning has its own
    // permission and its own two-question policy rather than folding into project.edit (§14).
    Route::scopeBindings()->group(function () {
        Route::get('projects/{project}/team', [ProjectMemberController::class, 'index'])
            ->middleware('permission:project_member.view')->name('projects.team');
        Route::post('projects/{project}/team', [ProjectMemberController::class, 'store'])
            ->middleware('permission:project_member.assign')->name('projects.team.store');
        Route::put('projects/{project}/team/{member}', [ProjectMemberController::class, 'update'])
            ->middleware('permission:project_member.manage')->name('projects.team.update');
        Route::delete('projects/{project}/team/{member}', [ProjectMemberController::class, 'destroy'])
            ->middleware('permission:project_member.remove')->name('projects.team.destroy');

        // Step 1 of the §13 cascade. Guarded by project.manage: reshaping which departments are
        // committed to a job changes who can staff it.
        Route::get('projects/{project}/departments', [ProjectDepartmentController::class, 'index'])
            ->middleware('permission:project.view')->name('projects.departments');
        Route::post('projects/{project}/departments', [ProjectDepartmentController::class, 'store'])
            ->middleware('permission:project.manage')->name('projects.departments.store');
        Route::put('projects/{project}/departments/{department}', [ProjectDepartmentController::class, 'update'])
            ->middleware('permission:project.manage')->name('projects.departments.update');
        Route::delete('projects/{project}/departments/{department}', [ProjectDepartmentController::class, 'destroy'])
            ->middleware('permission:project.manage')->name('projects.departments.destroy');
    });

    // --- tasks -----------------------------------------------------------------------------------
    //
    // `task.view` on the read routes is the coarsest question — "does this person deal in tasks at
    // all". Which tasks they then reach is the two-axis union the compiler works out, and every
    // route below that takes an id calls a policy with the record in hand.

    Route::middleware('permission:task.view')->group(function () {
        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('tasks/{task}', [TaskController::class, 'show'])
            ->whereNumber('task')->name('tasks.show');
    });

    Route::get('tasks/create', [TaskController::class, 'create'])
        ->middleware('permission:task.create')->name('tasks.create');
    Route::post('tasks', [TaskController::class, 'store'])
        ->middleware('permission:task.create')->name('tasks.store');
    Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])
        ->middleware('permission:task.edit')->name('tasks.edit');
    Route::put('tasks/{task}', [TaskController::class, 'update'])
        ->middleware('permission:task.edit')->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
        ->middleware('permission:task.delete')->name('tasks.destroy');

    // Assignment is a GRANT, not an edit: `task.view:assigned` is a real permission, so writing
    // assignee_id hands that account sight of the record. Its own route, its own permission, and a
    // policy that asks about the candidate as well as the task (§14, §84).
    Route::post('tasks/{task}/assignee', [TaskController::class, 'assign'])
        ->middleware('permission:task.assign,task.reassign')->name('tasks.assign');

    // Status is not a form field. It moves through the configured whitelist, and the endpoint
    // re-asks StatusMachine with the same actor that drew the buttons (§63, §84).
    Route::post('tasks/{task}/status', [TaskController::class, 'transition'])
        ->middleware('permission:task.edit,task.complete,task.verify,task.manage')
        ->name('tasks.status');

    // Following grants nothing — it is a notification subscription (see the Follower model), and
    // the notification layer re-checks each recipient's reach at send time (§68). Guarded by
    // task.view because you may only subscribe to what you can already see.
    Route::post('tasks/{task}/follow', [TaskController::class, 'follow'])
        ->middleware('permission:task.view')->name('tasks.follow');
    Route::delete('tasks/{task}/follow', [TaskController::class, 'unfollow'])
        ->name('tasks.unfollow');

    // Checklists and dependencies have no permissions of their own; they borrow the task's, which
    // is why these are guarded by task.edit and authorized against the parent record. Scoped
    // bindings make /tasks/7/checklist/{item-of-task-8} a 404 at the router (§94).
    //
    // The child parameters are named for the relation Laravel will look for: `{checklistItem}`
    // resolves through Task::checklistItems() and `{dependency}` through Task::dependencies(). A
    // shorter name like `{item}` would send the router looking for a Task::items() that does not
    // exist, and the scoping — which is the IDOR control here — would fail at the first request.
    Route::scopeBindings()->group(function () {
        Route::post('tasks/{task}/checklist', [TaskChecklistItemController::class, 'store'])
            ->middleware('permission:task.edit')->name('tasks.checklist.store');
        Route::put('tasks/{task}/checklist/{checklistItem}', [TaskChecklistItemController::class, 'update'])
            ->middleware('permission:task.edit')->name('tasks.checklist.update');
        Route::post('tasks/{task}/checklist/{checklistItem}/toggle', [TaskChecklistItemController::class, 'toggle'])
            ->middleware('permission:task.edit')->name('tasks.checklist.toggle');
        Route::delete('tasks/{task}/checklist/{checklistItem}', [TaskChecklistItemController::class, 'destroy'])
            ->middleware('permission:task.edit')->name('tasks.checklist.destroy');

        Route::post('tasks/{task}/dependencies', [TaskDependencyController::class, 'store'])
            ->middleware('permission:task.edit')->name('tasks.dependencies.store');
        Route::delete('tasks/{task}/dependencies/{dependency}', [TaskDependencyController::class, 'destroy'])
            ->middleware('permission:task.edit')->name('tasks.dependencies.destroy');
    });

    // --- communication ---------------------------------------------------------------------------
    //
    // Memos, announcements and meetings share a shape: a coarse `*.view` on the read routes, a
    // separate permission for the act that makes the record visible to other people, and a policy
    // call with the record in hand on everything that takes an id.
    //
    // The act of publication is the one to look at. `memo.issue`, `announcement.publish` and
    // `meeting.circulate` are not edit permissions and are never folded into one: issuing a memo
    // sends it to a distribution list, publishing a notice puts it in front of a department, and
    // circulating minutes releases a document the room could not previously read. Somebody who may
    // draft is not thereby somebody who may send (§14).

    Route::middleware('permission:memo.view')->group(function () {
        Route::get('memos', [MemoController::class, 'index'])->name('memos.index');
        Route::get('memos/{memo}', [MemoController::class, 'show'])
            ->whereNumber('memo')->name('memos.show');
    });

    Route::get('memos/create', [MemoController::class, 'create'])
        ->middleware('permission:memo.create')->name('memos.create');
    Route::post('memos', [MemoController::class, 'store'])
        ->middleware('permission:memo.create')->name('memos.store');
    Route::get('memos/{memo}/edit', [MemoController::class, 'edit'])
        ->middleware('permission:memo.edit')->name('memos.edit');
    Route::put('memos/{memo}', [MemoController::class, 'update'])
        ->middleware('permission:memo.edit')->name('memos.update');
    Route::delete('memos/{memo}', [MemoController::class, 'destroy'])
        ->middleware('permission:memo.delete')->name('memos.destroy');

    // Addressing is a GRANT. For a restricted memo MemoService writes a record_permissions row, so
    // this endpoint hands somebody sight of the document — hence its own route and a policy that
    // asks about each candidate as well as the memo (§84).
    Route::post('memos/{memo}/recipients', [MemoController::class, 'address'])
        ->middleware('permission:memo.edit')->name('memos.recipients.store');

    Route::scopeBindings()->group(function () {
        // `{recipient}` resolves through Memo::recipients(), so a recipient row belonging to
        // another memo is a 404 at the router rather than a delete (§94).
        Route::delete('memos/{memo}/recipients/{recipient}', [MemoController::class, 'unaddress'])
            ->middleware('permission:memo.edit')->name('memos.recipients.destroy');
    });

    Route::post('memos/{memo}/status', [MemoController::class, 'transition'])
        ->middleware('permission:memo.issue')->name('memos.status');

    Route::middleware('permission:announcement.view')->group(function () {
        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('announcements/{announcement}', [AnnouncementController::class, 'show'])
            ->whereNumber('announcement')->name('announcements.show');
    });

    Route::get('announcements/create', [AnnouncementController::class, 'create'])
        ->middleware('permission:announcement.create')->name('announcements.create');
    Route::post('announcements', [AnnouncementController::class, 'store'])
        ->middleware('permission:announcement.create')->name('announcements.store');
    Route::get('announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])
        ->middleware('permission:announcement.edit')->name('announcements.edit');
    Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])
        ->middleware('permission:announcement.edit')->name('announcements.update');
    Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
        ->middleware('permission:announcement.delete')->name('announcements.destroy');

    Route::post('announcements/{announcement}/status', [AnnouncementController::class, 'transition'])
        ->middleware('permission:announcement.publish')->name('announcements.status');

    Route::middleware('permission:meeting.view')->group(function () {
        Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::get('meetings/{meeting}', [MeetingController::class, 'show'])
            ->whereNumber('meeting')->name('meetings.show');
    });

    Route::get('meetings/create', [MeetingController::class, 'create'])
        ->middleware('permission:meeting.create')->name('meetings.create');
    Route::post('meetings', [MeetingController::class, 'store'])
        ->middleware('permission:meeting.create')->name('meetings.store');
    Route::get('meetings/{meeting}/edit', [MeetingController::class, 'edit'])
        ->middleware('permission:meeting.edit')->name('meetings.edit');
    Route::put('meetings/{meeting}', [MeetingController::class, 'update'])
        ->middleware('permission:meeting.edit')->name('meetings.update');
    Route::delete('meetings/{meeting}', [MeetingController::class, 'destroy'])
        ->middleware('permission:meeting.delete')->name('meetings.destroy');

    // Inviting is a grant for the same reason addressing is: a participant row is an assignee
    // column, and an assignee column is reach.
    Route::post('meetings/{meeting}/participants', [MeetingController::class, 'invite'])
        ->middleware('permission:meeting.edit,meeting.manage')->name('meetings.participants.store');

    // Recording what happened is `meeting.record_minutes`, deliberately not `meeting.edit`: the
    // secretary who writes the minutes is rarely the administrator who books the room, and letting
    // one imply the other would let anybody who can move a meeting rewrite what was decided at it.
    Route::get('meetings/{meeting}/minutes', [MeetingController::class, 'minutes'])
        ->middleware('permission:meeting.record_minutes')->name('meetings.minutes');
    Route::put('meetings/{meeting}/minutes', [MeetingController::class, 'storeMinutes'])
        ->middleware('permission:meeting.record_minutes')->name('meetings.minutes.store');
    Route::post('meetings/{meeting}/attendance', [MeetingController::class, 'attendance'])
        ->middleware('permission:meeting.record_minutes')->name('meetings.attendance');
    Route::post('meetings/{meeting}/decisions', [MeetingController::class, 'storeDecision'])
        ->middleware('permission:meeting.record_minutes')->name('meetings.decisions.store');

    // An action item is a Task, so this needs BOTH permissions — minuting the meeting and opening
    // work. The controller asks for both, and the morph link is stamped server-side (§90).
    Route::post('meetings/{meeting}/actions', [MeetingController::class, 'raiseAction'])
        ->middleware('permission:meeting.record_minutes')->name('meetings.actions.store');

    Route::scopeBindings()->group(function () {
        // `{participant}` resolves through Meeting::participants().
        Route::delete('meetings/{meeting}/participants/{participant}', [MeetingController::class, 'uninvite'])
            ->middleware('permission:meeting.edit,meeting.manage')->name('meetings.participants.destroy');

        // Sending your own apologies needs no management permission — only `meeting.view`, and the
        // policy then refuses anybody else's row.
        Route::post('meetings/{meeting}/participants/{participant}/respond', [MeetingController::class, 'respond'])
            ->middleware('permission:meeting.view')->name('meetings.participants.respond');
    });

    // Holding, rescheduling, circulating and cancelling all arrive here. Which one a status id
    // means is resolved from the configured whitelist, and the controller asks the matching policy
    // question before the service acts (§63, §84).
    Route::post('meetings/{meeting}/status', [MeetingController::class, 'transition'])
        ->middleware('permission:meeting.edit,meeting.circulate,meeting.manage')->name('meetings.status');

    // The calendar has no permission of its own, by design: it is a VIEW over meetings, tasks and
    // project dates, each fetched through its own scope-compiled query with its own ability. A
    // `calendar.view` permission would be a second, weaker door onto records that already have
    // one (§90). The guard here is only "you deal in at least one of the two things it shows".
    Route::get('calendar', [CalendarController::class, 'index'])
        ->middleware('permission:meeting.view,task.view')->name('calendar.index');

    // --- documents -----------------------------------------------------------------------------
    //
    // The register, its revisions, and the two ways a document leaves the platform. Note how many
    // separate permissions appear here for one module: viewing a controlled procedure on screen,
    // taking the file away, and printing it are three different acts with three different
    // consequences, so they are three different abilities (§67). The middleware is only the first
    // gate — DocumentPolicy re-answers all of them with the record in hand.

    Route::middleware('permission:document.view')->group(function () {
        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
    });

    Route::get('documents/create', [DocumentController::class, 'create'])
        ->middleware('permission:document.create')->name('documents.create');
    Route::post('documents', [DocumentController::class, 'store'])
        ->middleware('permission:document.create')->name('documents.store');

    Route::get('documents/{document}', [DocumentController::class, 'show'])
        ->middleware('permission:document.view')->whereNumber('document')->name('documents.show');
    Route::get('documents/{document}/edit', [DocumentController::class, 'edit'])
        ->middleware('permission:document.edit')->whereNumber('document')->name('documents.edit');
    Route::put('documents/{document}', [DocumentController::class, 'update'])
        ->middleware('permission:document.edit')->whereNumber('document')->name('documents.update');
    Route::delete('documents/{document}', [DocumentController::class, 'destroy'])
        ->middleware('permission:document.delete')->whereNumber('document')->name('documents.destroy');

    // Check-out is guarded by `document.edit` rather than a lock permission of its own: taking the
    // lock IS the act of starting to edit, and a separate permission would let somebody hold a
    // document they could never change.
    Route::post('documents/{document}/check-out', [DocumentController::class, 'checkOut'])
        ->middleware('permission:document.edit')->whereNumber('document')->name('documents.check-out');
    Route::post('documents/{document}/check-in', [DocumentController::class, 'checkIn'])
        ->middleware('permission:document.edit,document.manage')->whereNumber('document')->name('documents.check-in');

    Route::post('documents/{document}/submit', [DocumentController::class, 'submit'])
        ->middleware('permission:document.submit')->whereNumber('document')->name('documents.submit');
    Route::post('documents/{document}/withdraw', [DocumentController::class, 'withdraw'])
        ->middleware('permission:document.submit,document.manage')->whereNumber('document')->name('documents.withdraw');

    Route::post('documents/{document}/archive', [DocumentController::class, 'archive'])
        ->middleware('permission:document.archive')->whereNumber('document')->name('documents.archive');
    Route::post('documents/{document}/restore', [DocumentController::class, 'restore'])
        ->middleware('permission:document.restore')->whereNumber('document')->name('documents.restore');

    // Revisions. Nested, and every method re-checks the nesting — `/documents/1/versions/97` where
    // 97 belongs to another document is a 404, which is §94's IDOR case for this module.
    Route::whereNumber(['document', 'version'])->group(function () {
        Route::get('documents/{document}/versions/create', [DocumentVersionController::class, 'create'])
            ->middleware('permission:document.edit')->name('documents.versions.create');
        Route::post('documents/{document}/versions', [DocumentVersionController::class, 'store'])
            ->middleware('permission:document.edit')->name('documents.versions.store');

        Route::get('documents/{document}/versions/{version}', [DocumentVersionController::class, 'show'])
            ->middleware('permission:document.view')->name('documents.versions.show');

        // The editor, and its autosave. Both `document.edit`; the policy additionally refuses while
        // somebody else holds the lock, and the service refuses on an approved revision whoever asks.
        Route::get('documents/{document}/versions/{version}/edit', [DocumentVersionController::class, 'edit'])
            ->middleware('permission:document.edit')->name('documents.versions.edit');
        Route::put('documents/{document}/versions/{version}', [DocumentVersionController::class, 'update'])
            ->middleware('permission:document.edit')->name('documents.versions.update');

        Route::post('documents/{document}/versions/{version}/current', [DocumentVersionController::class, 'makeCurrent'])
            ->middleware('permission:document.manage')->name('documents.versions.current');
        Route::delete('documents/{document}/versions/{version}', [DocumentVersionController::class, 'destroy'])
            ->middleware('permission:document.delete')->name('documents.versions.destroy');

        // Getting it out. Nothing is served from a public disk and nothing is guessable: these
        // three stream from private storage after the policy has answered (§67).
        Route::get('documents/{document}/versions/{version}/download', [DocumentVersionController::class, 'download'])
            ->middleware('permission:document.download')->name('documents.versions.download');
        Route::get('documents/{document}/versions/{version}/docx', [DocumentVersionController::class, 'exportDocx'])
            ->middleware('permission:document.download')->name('documents.versions.docx');
        Route::get('documents/{document}/versions/{version}/pdf', [DocumentVersionController::class, 'exportPdf'])
            ->middleware('permission:document.print')->name('documents.versions.pdf');
    });

    // Registers. Administration: one `manage` permission covers the lot, because anybody trusted to
    // define how a company numbers its documents is trusted with all of it.
    Route::middleware('permission:document_category.view')->group(function () {
        Route::get('document-categories', [DocumentCategoryController::class, 'index'])
            ->name('document-categories.index');
        Route::get('document-categories/{document_category}', [DocumentCategoryController::class, 'show'])
            ->whereNumber('document_category')->name('document-categories.show');
    });

    Route::middleware('permission:document_category.manage')->group(function () {
        Route::get('document-categories/create', [DocumentCategoryController::class, 'create'])
            ->name('document-categories.create');
        Route::post('document-categories', [DocumentCategoryController::class, 'store'])
            ->name('document-categories.store');
        Route::get('document-categories/{document_category}/edit', [DocumentCategoryController::class, 'edit'])
            ->whereNumber('document_category')->name('document-categories.edit');
        Route::put('document-categories/{document_category}', [DocumentCategoryController::class, 'update'])
            ->whereNumber('document_category')->name('document-categories.update');
        Route::delete('document-categories/{document_category}', [DocumentCategoryController::class, 'destroy'])
            ->whereNumber('document_category')->name('document-categories.destroy');
    });

    // --- transmittals --------------------------------------------------------------------------

    Route::middleware('permission:transmittal.view')->group(function () {
        Route::get('transmittals', [TransmittalController::class, 'index'])->name('transmittals.index');
        Route::get('transmittals/{transmittal}', [TransmittalController::class, 'show'])
            ->whereNumber('transmittal')->name('transmittals.show');

        // The cover sheet is the deliverable, and reading it is reading the transmittal.
        Route::get('transmittals/{transmittal}/cover-sheet', [TransmittalController::class, 'coverSheet'])
            ->whereNumber('transmittal')->name('transmittals.cover-sheet');
    });

    Route::get('transmittals/create', [TransmittalController::class, 'create'])
        ->middleware('permission:transmittal.create')->name('transmittals.create');
    Route::post('transmittals', [TransmittalController::class, 'store'])
        ->middleware('permission:transmittal.create')->name('transmittals.store');

    Route::whereNumber('transmittal')->group(function () {
        Route::get('transmittals/{transmittal}/edit', [TransmittalController::class, 'edit'])
            ->middleware('permission:transmittal.edit')->name('transmittals.edit');
        Route::put('transmittals/{transmittal}', [TransmittalController::class, 'update'])
            ->middleware('permission:transmittal.edit')->name('transmittals.update');
        Route::delete('transmittals/{transmittal}', [TransmittalController::class, 'destroy'])
            ->middleware('permission:transmittal.delete')->name('transmittals.destroy');

        // The item picker lists documents through `document.download`, so it obeys exactly the same
        // authorization as the register and can never become a way to enumerate what somebody
        // cannot open (§61).
        Route::get('transmittals/{transmittal}/picker', [TransmittalController::class, 'documentPicker'])
            ->middleware('permission:transmittal.edit')->name('transmittals.picker');
        Route::post('transmittals/{transmittal}/items', [TransmittalController::class, 'addItem'])
            ->middleware('permission:transmittal.edit')->name('transmittals.items.store');
        Route::delete('transmittals/{transmittal}/items/{item}', [TransmittalController::class, 'removeItem'])
            ->middleware('permission:transmittal.edit')->whereNumber('item')->name('transmittals.items.destroy');

        // Issuing is the one-way door, and its own authority: `transmittal.edit` is not
        // `transmittal.issue`, because sending documents to a client is not the same act as
        // correcting a typo in the cover note.
        Route::post('transmittals/{transmittal}/issue', [TransmittalController::class, 'issue'])
            ->middleware('permission:transmittal.issue')->name('transmittals.issue');
        Route::post('transmittals/{transmittal}/acknowledge', [TransmittalController::class, 'acknowledge'])
            ->middleware('permission:transmittal.acknowledge')->name('transmittals.acknowledge');
        Route::post('transmittals/{transmittal}/cancel', [TransmittalController::class, 'cancel'])
            ->middleware('permission:transmittal.manage')->name('transmittals.cancel');
    });

    // --- approvals -----------------------------------------------------------------------------
    //
    // One inbox, every module. `approval.view` is deliberately broad — everyone who can be asked to
    // approve anything holds it — because what a person actually sees is decided twice inside the
    // controller: the engine narrows to the stages they are assigned, and each row's SUBJECT is then
    // put through its own policy (§61, §68).

    Route::middleware('permission:approval.view')->group(function () {
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::get('approvals/{approval}', [ApprovalController::class, 'show'])
            ->whereNumber('approval')->name('approvals.show');
    });

    // Recording a decision. The body carries no actor and no stage id — both come from the session
    // and the bound route model, and the policy re-checks them against the frozen assignee list
    // before anything is written (§84).
    Route::post('approvals/{approval}/decide', [ApprovalController::class, 'decide'])
        ->middleware('permission:approval.act,approval.delegate')
        ->whereNumber('approval')->name('approvals.decide');

    // Administrative, and deliberately not available to the people on the stage: being asked to
    // approve something does not give you authority over who approves it instead.
    Route::post('approvals/{approval}/reassign', [ApprovalController::class, 'reassign'])
        ->middleware('permission:approval.reassign')
        ->whereNumber('approval')->name('approvals.reassign');
    Route::post('approvals/instances/{instance}/cancel', [ApprovalController::class, 'cancel'])
        ->middleware('permission:approval.reassign')
        ->whereNumber('instance')->name('approvals.cancel');

    // --- workflow definitions ------------------------------------------------------------------
    //
    // Phase 3's exit criteria, as routes: a workflow defined entirely through the UI routes a
    // document through approval. Publishing is separated from editing because a published route is
    // live configuration — new records start taking it the moment it goes out.

    Route::middleware('permission:workflow.view')->group(function () {
        Route::get('workflows', [WorkflowController::class, 'index'])->name('workflows.index');
        Route::get('workflows/{workflow}', [WorkflowController::class, 'show'])
            ->whereNumber('workflow')->name('workflows.show');
    });

    Route::get('workflows/create', [WorkflowController::class, 'create'])
        ->middleware('permission:workflow.create')->name('workflows.create');
    Route::post('workflows', [WorkflowController::class, 'store'])
        ->middleware('permission:workflow.create')->name('workflows.store');

    Route::whereNumber(['workflow', 'stage'])->group(function () {
        Route::get('workflows/{workflow}/edit', [WorkflowController::class, 'edit'])
            ->middleware('permission:workflow.edit')->name('workflows.edit');
        Route::put('workflows/{workflow}', [WorkflowController::class, 'update'])
            ->middleware('permission:workflow.edit')->name('workflows.update');
        Route::delete('workflows/{workflow}', [WorkflowController::class, 'destroy'])
            ->middleware('permission:workflow.delete')->name('workflows.destroy');

        Route::post('workflows/{workflow}/publish', [WorkflowController::class, 'publish'])
            ->middleware('permission:workflow.publish')->name('workflows.publish');
        Route::post('workflows/{workflow}/version', [WorkflowController::class, 'version'])
            ->middleware('permission:workflow.publish')->name('workflows.version');
        Route::post('workflows/{workflow}/deactivate', [WorkflowController::class, 'deactivate'])
            ->middleware('permission:workflow.publish')->name('workflows.deactivate');

        // Stages. Guarded by the route's own permission and then by WorkflowStagePolicy, which
        // delegates to the parent workflow — one rule, one place.
        Route::get('workflows/{workflow}/stages/create', [WorkflowStageController::class, 'create'])
            ->middleware('permission:workflow.edit')->name('workflows.stages.create');
        Route::post('workflows/{workflow}/stages', [WorkflowStageController::class, 'store'])
            ->middleware('permission:workflow.edit')->name('workflows.stages.store');
        Route::get('workflows/{workflow}/stages/{stage}/edit', [WorkflowStageController::class, 'edit'])
            ->middleware('permission:workflow.edit')->name('workflows.stages.edit');
        Route::put('workflows/{workflow}/stages/{stage}', [WorkflowStageController::class, 'update'])
            ->middleware('permission:workflow.edit')->name('workflows.stages.update');
        Route::delete('workflows/{workflow}/stages/{stage}', [WorkflowStageController::class, 'destroy'])
            ->middleware('permission:workflow.edit')->name('workflows.stages.destroy');

        // Drag-and-drop. Answers JSON, takes the whole ordered list, and refuses a partial one.
        Route::post('workflows/{workflow}/stages/reorder', [WorkflowStageController::class, 'reorder'])
            ->middleware('permission:workflow.edit')->name('workflows.stages.reorder');
    });

    // --- templates -----------------------------------------------------------------------------

    Route::middleware('permission:template.view')->group(function () {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('templates/{template}', [TemplateController::class, 'show'])
            ->whereNumber('template')->name('templates.show');

        // Rendered with the placeholder names standing and a PREVIEW watermark — never against a
        // live record, which would put that record's content behind a template permission (§61).
        Route::get('templates/{template}/preview', [TemplateController::class, 'preview'])
            ->whereNumber('template')->name('templates.preview');
    });

    Route::middleware('permission:template.manage')->group(function () {
        Route::get('templates/create', [TemplateController::class, 'create'])->name('templates.create');
        Route::post('templates', [TemplateController::class, 'store'])->name('templates.store');

        Route::whereNumber('template')->group(function () {
            Route::get('templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
            Route::put('templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
            Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])
                ->name('templates.duplicate');
            Route::post('templates/{template}/activate', [TemplateController::class, 'activate'])
                ->name('templates.activate');
            Route::delete('templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
        });
    });

    // --- administration ------------------------------------------------------------------------

    Route::middleware('permission:user.view,user.manage')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])
            ->whereNumber('user')->name('users.show');
    });

    Route::get('users/create', [UserController::class, 'create'])
        ->middleware('permission:user.create')->name('users.create');
    Route::post('users', [UserController::class, 'store'])
        ->middleware('permission:user.create')->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])
        ->middleware('permission:user.edit')->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])
        ->middleware('permission:user.edit')->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:user.delete')->name('users.destroy');

    // Separated from user.edit on purpose (§10): granting access is a different authority from
    // correcting a phone number, and the two are held by different people.
    Route::put('users/{user}/roles', [UserController::class, 'updateRoles'])
        ->middleware('permission:role.assign')->name('users.roles.update');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])
        ->middleware('permission:user.reset_password')->name('users.password.reset');

    Route::middleware('permission:role.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('roles/{role}', [RoleController::class, 'show'])
            ->whereNumber('role')->name('roles.show');
    });

    Route::get('roles/create', [RoleController::class, 'create'])
        ->middleware('permission:role.create')->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])
        ->middleware('permission:role.create')->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])
        ->middleware('permission:role.edit')->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:role.edit')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:role.delete')->name('roles.destroy');

    Route::get('settings', [SettingController::class, 'index'])
        ->middleware('permission:setting.view,setting.manage')->name('settings.index');
    Route::put('settings', [SettingController::class, 'update'])
        ->middleware('permission:setting.manage')->name('settings.update');

    Route::middleware('permission:audit_log.view')->group(function () {
        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('audit/{audit_log}', [AuditLogController::class, 'show'])
            ->whereNumber('audit_log')->name('audit.show');
    });
});
