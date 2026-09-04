<?php

declare(strict_types=1);

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\ChangeRequestController;
use App\Http\Controllers\ClientContactController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CorrectiveActionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliverableController;
use App\Http\Controllers\DocumentCategoryController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentVersionController;
use App\Http\Controllers\DrawingController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HseInspectionController;
use App\Http\Controllers\HseObservationController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\InvestigationController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MemoController;
use App\Http\Controllers\NcrController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrgUnitController;
use App\Http\Controllers\PermitController;
use App\Http\Controllers\PermitTypeController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectDepartmentController;
use App\Http\Controllers\ProjectMemberController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\QualityInspectionController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\RfiController;
use App\Http\Controllers\RfqController;
use App\Http\Controllers\RiskAssessmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockItemController;
use App\Http\Controllers\SubmittalController;
use App\Http\Controllers\TaskChecklistItemController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskDependencyController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\ToolboxTalkController;
use App\Http\Controllers\TrainingCourseController;
use App\Http\Controllers\TrainingRecordController;
use App\Http\Controllers\TransmittalController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorContactController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WarehouseController;
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

    // --- deliverables --------------------------------------------------------------------------
    //
    // The engineering registers all follow the same shape, and the shape is deliberate: `view` opens
    // the register, `create`/`edit`/`delete` do the obvious, and every LIFECYCLE act — issuing,
    // answering, reviewing, deciding — sits behind its own permission. That separation is the whole
    // point. A graduate engineer who may raise and edit an RFI is not thereby a person who may
    // answer one on the company's behalf, and one `rfi.edit` covering both would make the register
    // useless as a record of who committed the organization to what (§10, §59).
    //
    // Every route here is a FIRST filter only. The controller re-authorizes the bound record against
    // its policy, so swapping the id in the URL for one in another project — or another tenant —
    // fails at the policy even though the middleware has already passed (§84, §94).

    Route::middleware('permission:deliverable.view')->group(function () {
        Route::get('deliverables', [DeliverableController::class, 'index'])->name('deliverables.index');
        Route::get('deliverables/{deliverable}', [DeliverableController::class, 'show'])
            ->whereNumber('deliverable')->name('deliverables.show');
    });

    Route::get('deliverables/create', [DeliverableController::class, 'create'])
        ->middleware('permission:deliverable.create')->name('deliverables.create');
    Route::post('deliverables', [DeliverableController::class, 'store'])
        ->middleware('permission:deliverable.create')->name('deliverables.store');

    Route::whereNumber('deliverable')->group(function () {
        Route::get('deliverables/{deliverable}/edit', [DeliverableController::class, 'edit'])
            ->middleware('permission:deliverable.edit')->name('deliverables.edit');
        Route::put('deliverables/{deliverable}', [DeliverableController::class, 'update'])
            ->middleware('permission:deliverable.edit')->name('deliverables.update');
        Route::delete('deliverables/{deliverable}', [DeliverableController::class, 'destroy'])
            ->middleware('permission:deliverable.delete')->name('deliverables.destroy');

        // Progress is reported by the person doing the work, so it rides on `edit` rather than on a
        // heavier authority. What stops it being abused is reach, not the verb: the policy still
        // asks whether this actor may touch THIS deliverable.
        Route::post('deliverables/{deliverable}/progress', [DeliverableController::class, 'reportProgress'])
            ->middleware('permission:deliverable.edit')->name('deliverables.progress');

        // Issuing is the claim that the work is finished and has left the building. It is behind
        // `review`, because "I have done it" and "it is fit to go out" are different statements and
        // frequently different people.
        Route::post('deliverables/{deliverable}/issue', [DeliverableController::class, 'issue'])
            ->middleware('permission:deliverable.review')->name('deliverables.issue');

        Route::post('deliverables/{deliverable}/document', [DeliverableController::class, 'attachDocument'])
            ->middleware('permission:deliverable.edit')->name('deliverables.document');
        Route::post('deliverables/{deliverable}/transition', [DeliverableController::class, 'transition'])
            ->middleware('permission:deliverable.edit')->name('deliverables.transition');
    });

    // --- drawings ------------------------------------------------------------------------------

    Route::middleware('permission:drawing.view')->group(function () {
        Route::get('drawings', [DrawingController::class, 'index'])->name('drawings.index');
        Route::get('drawings/{drawing}', [DrawingController::class, 'show'])
            ->whereNumber('drawing')->name('drawings.show');
    });

    Route::get('drawings/create', [DrawingController::class, 'create'])
        ->middleware('permission:drawing.create')->name('drawings.create');
    Route::post('drawings', [DrawingController::class, 'store'])
        ->middleware('permission:drawing.create')->name('drawings.store');

    Route::whereNumber('drawing')->group(function () {
        Route::get('drawings/{drawing}/edit', [DrawingController::class, 'edit'])
            ->middleware('permission:drawing.edit')->name('drawings.edit');
        Route::put('drawings/{drawing}', [DrawingController::class, 'update'])
            ->middleware('permission:drawing.edit')->name('drawings.update');
        Route::delete('drawings/{drawing}', [DrawingController::class, 'destroy'])
            ->middleware('permission:drawing.delete')->name('drawings.destroy');

        Route::post('drawings/{drawing}/revise', [DrawingController::class, 'revise'])
            ->middleware('permission:drawing.edit')->name('drawings.revise');
        Route::post('drawings/{drawing}/document', [DrawingController::class, 'attachDocument'])
            ->middleware('permission:drawing.edit')->name('drawings.document');
        Route::post('drawings/{drawing}/transition', [DrawingController::class, 'transition'])
            ->middleware('permission:drawing.edit')->name('drawings.transition');

        // Opening the file needs `drawing.download` here AND the document's own `view` inside the
        // controller. Neither check is redundant: this one says the register may hand out files at
        // all, the other says this actor may see THIS file. Dropping the second would turn the
        // drawing register into a way around document confidentiality (§67).
        Route::get('drawings/{drawing}/file', [DrawingController::class, 'openFile'])
            ->middleware('permission:drawing.download')->name('drawings.file');
    });

    // --- RFIs and technical queries -------------------------------------------------------------

    Route::middleware('permission:rfi.view')->group(function () {
        Route::get('rfis', [RfiController::class, 'index'])->name('rfis.index');
        Route::get('rfis/{rfi}', [RfiController::class, 'show'])
            ->whereNumber('rfi')->name('rfis.show');
    });

    Route::get('rfis/create', [RfiController::class, 'create'])
        ->middleware('permission:rfi.create')->name('rfis.create');
    Route::post('rfis', [RfiController::class, 'store'])
        ->middleware('permission:rfi.create')->name('rfis.store');

    Route::whereNumber('rfi')->group(function () {
        Route::get('rfis/{rfi}/edit', [RfiController::class, 'edit'])
            ->middleware('permission:rfi.edit')->name('rfis.edit');
        Route::put('rfis/{rfi}', [RfiController::class, 'update'])
            ->middleware('permission:rfi.edit')->name('rfis.update');
        Route::delete('rfis/{rfi}', [RfiController::class, 'destroy'])
            ->middleware('permission:rfi.delete')->name('rfis.destroy');

        Route::post('rfis/{rfi}/send', [RfiController::class, 'send'])
            ->middleware('permission:rfi.submit')->name('rfis.send');

        // Answering is not editing, and this is the line that says so. `rfi.respond` is held by the
        // people qualified to state the organization's technical position; `rfi.edit` is held by
        // anybody who can fix a typo in the question.
        Route::post('rfis/{rfi}/respond', [RfiController::class, 'respond'])
            ->middleware('permission:rfi.respond')->name('rfis.respond');

        // Rewriting an answer already given is the heaviest act in this register, so it sits behind
        // the heaviest permission it has and demands a written reason (§59).
        Route::post('rfis/{rfi}/amend', [RfiController::class, 'amend'])
            ->middleware('permission:rfi.close')->name('rfis.amend');
        Route::post('rfis/{rfi}/close', [RfiController::class, 'close'])
            ->middleware('permission:rfi.close')->name('rfis.close');

        Route::post('rfis/{rfi}/transition', [RfiController::class, 'transition'])
            ->middleware('permission:rfi.edit')->name('rfis.transition');
    });

    // --- submittals ----------------------------------------------------------------------------

    Route::middleware('permission:submittal.view')->group(function () {
        Route::get('submittals', [SubmittalController::class, 'index'])->name('submittals.index');
        Route::get('submittals/{submittal}', [SubmittalController::class, 'show'])
            ->whereNumber('submittal')->name('submittals.show');
    });

    Route::get('submittals/create', [SubmittalController::class, 'create'])
        ->middleware('permission:submittal.create')->name('submittals.create');
    Route::post('submittals', [SubmittalController::class, 'store'])
        ->middleware('permission:submittal.create')->name('submittals.store');

    Route::whereNumber(['submittal', 'item'])->group(function () {
        Route::get('submittals/{submittal}/edit', [SubmittalController::class, 'edit'])
            ->middleware('permission:submittal.edit')->name('submittals.edit');
        Route::put('submittals/{submittal}', [SubmittalController::class, 'update'])
            ->middleware('permission:submittal.edit')->name('submittals.update');
        Route::delete('submittals/{submittal}', [SubmittalController::class, 'destroy'])
            ->middleware('permission:submittal.delete')->name('submittals.destroy');

        // The picker lists documents through `document.view`, so it can never enumerate anything the
        // actor could not already open by navigating to it (§61).
        Route::get('submittals/{submittal}/picker', [SubmittalController::class, 'documentPicker'])
            ->middleware('permission:submittal.edit')->name('submittals.picker');
        Route::post('submittals/{submittal}/items', [SubmittalController::class, 'addItem'])
            ->middleware('permission:submittal.edit')->name('submittals.items.store');
        Route::delete('submittals/{submittal}/items/{item}', [SubmittalController::class, 'removeItem'])
            ->middleware('permission:submittal.edit')->name('submittals.items.destroy');

        // Coding a line is the reviewer's act, not the submitter's: "Approved as noted" against one
        // item of a package is a technical decision, so it takes `submittal.review` even though the
        // thing being written is a column on a child row.
        Route::post('submittals/{submittal}/items/{item}/code', [SubmittalController::class, 'codeItem'])
            ->middleware('permission:submittal.review')->name('submittals.items.code');

        Route::post('submittals/{submittal}/submit', [SubmittalController::class, 'submit'])
            ->middleware('permission:submittal.submit')->name('submittals.submit');
        Route::post('submittals/{submittal}/review', [SubmittalController::class, 'review'])
            ->middleware('permission:submittal.review')->name('submittals.review');

        // A resubmittal is a NEW record that remembers its parent, so it needs the authority to
        // create one — plus, in the policy, the right to see what is being resubmitted.
        Route::post('submittals/{submittal}/resubmit', [SubmittalController::class, 'resubmit'])
            ->middleware('permission:submittal.create')->name('submittals.resubmit');

        Route::post('submittals/{submittal}/transition', [SubmittalController::class, 'transition'])
            ->middleware('permission:submittal.edit')->name('submittals.transition');
    });

    // --- change requests -----------------------------------------------------------------------

    Route::middleware('permission:change_request.view')->group(function () {
        Route::get('change-requests', [ChangeRequestController::class, 'index'])
            ->name('change-requests.index');
        Route::get('change-requests/{change_request}', [ChangeRequestController::class, 'show'])
            ->whereNumber('change_request')->name('change-requests.show');
    });

    Route::get('change-requests/create', [ChangeRequestController::class, 'create'])
        ->middleware('permission:change_request.create')->name('change-requests.create');
    Route::post('change-requests', [ChangeRequestController::class, 'store'])
        ->middleware('permission:change_request.create')->name('change-requests.store');

    Route::whereNumber('change_request')->group(function () {
        Route::get('change-requests/{change_request}/edit', [ChangeRequestController::class, 'edit'])
            ->middleware('permission:change_request.edit')->name('change-requests.edit');
        Route::put('change-requests/{change_request}', [ChangeRequestController::class, 'update'])
            ->middleware('permission:change_request.edit')->name('change-requests.update');
        Route::delete('change-requests/{change_request}', [ChangeRequestController::class, 'destroy'])
            ->middleware('permission:change_request.delete')->name('change-requests.destroy');

        Route::post('change-requests/{change_request}/submit', [ChangeRequestController::class, 'submit'])
            ->middleware('permission:change_request.submit')->name('change-requests.submit');

        // The one route in the engineering registers with separation of duties behind it. The
        // middleware asks whether this person may approve changes at all; ChangeRequestPolicy::decide
        // then refuses when they are the author, because nobody approves their own money (§10).
        Route::post('change-requests/{change_request}/decide', [ChangeRequestController::class, 'decide'])
            ->middleware('permission:change_request.approve')->name('change-requests.decide');

        Route::post('change-requests/{change_request}/close', [ChangeRequestController::class, 'close'])
            ->middleware('permission:change_request.review')->name('change-requests.close');
        Route::post('change-requests/{change_request}/transition', [ChangeRequestController::class, 'transition'])
            ->middleware('permission:change_request.edit')->name('change-requests.transition');
    });

    /*
    |----------------------------------------------------------------------------------------------
    | Health, safety and environment
    |----------------------------------------------------------------------------------------------
    |
    | THE SHAPE OF THIS SECTION IS THE PHASE'S ARGUMENT. Every lifecycle act is a POST to its own
    | URL behind its own permission — issue a permit, disposition a non-conformance, sign for a
    | toolbox talk — and not one of them can be reached by editing a form. A `PUT` that could set
    | `issued_at` is a forged authorisation, and on this half of the application that is somebody's
    | life (§84).
    |
    | THE NESTED CREATE ROUTES ARE THE OTHER HALF. An investigation is opened AT an incident, a
    | corrective action is raised AT the record that needs it, an NCR is raised AT the inspection
    | that found it. The parent is bound from the URL and authorized as a record; the link is never
    | accepted as a posted id, because a posted id is an assertion about a record the poster may
    | never have been allowed to see (§94).
    |
    | AND IT IS THE SAME SHAPE THE WORKFLOW ENGINE USES. `create_record` reaches the same services
    | these routes reach, which is what lets a configured stage open an investigation and its
    | corrective action with no code — the exit criterion for this phase.
    */

    // --- incidents -----------------------------------------------------------------------------

    Route::middleware('permission:hse_incident.view')->group(function () {
        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])
            ->whereNumber('incident')->name('incidents.show');
    });

    Route::get('incidents/create', [IncidentController::class, 'create'])
        ->middleware('permission:hse_incident.create')->name('incidents.create');
    Route::post('incidents', [IncidentController::class, 'store'])
        ->middleware('permission:hse_incident.create')->name('incidents.store');

    Route::whereNumber('incident')->group(function () {
        Route::get('incidents/{incident}/edit', [IncidentController::class, 'edit'])
            ->middleware('permission:hse_incident.edit')->name('incidents.edit');
        Route::put('incidents/{incident}', [IncidentController::class, 'update'])
            ->middleware('permission:hse_incident.edit')->name('incidents.update');
        Route::delete('incidents/{incident}', [IncidentController::class, 'destroy'])
            ->middleware('permission:hse_incident.delete')->name('incidents.destroy');

        // The people involved. `hse_incident.edit` reaches the rows; the MEDICAL columns on them are
        // gated separately by IncidentPersonPolicy::viewMedical, which asks for
        // `hse_incident.view_confidential`. Middleware cannot make that distinction — it is per-field
        // and per-row — so it is made in the controller and again in the views (§68).
        Route::post('incidents/{incident}/people', [IncidentController::class, 'storePerson'])
            ->middleware('permission:hse_incident.edit')->name('incidents.people.store');
        Route::put('incidents/{incident}/people/{person}', [IncidentController::class, 'updatePerson'])
            ->whereNumber('person')->middleware('permission:hse_incident.edit')->name('incidents.people.update');
        Route::delete('incidents/{incident}/people/{person}', [IncidentController::class, 'destroyPerson'])
            ->whereNumber('person')->middleware('permission:hse_incident.edit')->name('incidents.people.destroy');

        // Declaring an incident reportable to a regulator is not editing it. It is the statement
        // that a legal duty has been discharged, and it takes the authority that opens an
        // investigation rather than the one that fixes a typo in the description.
        Route::post('incidents/{incident}/reportable', [IncidentController::class, 'markReportable'])
            ->middleware('permission:hse_incident.investigate')->name('incidents.reportable');

        Route::post('incidents/{incident}/close', [IncidentController::class, 'close'])
            ->middleware('permission:hse_incident.close')->name('incidents.close');
        Route::post('incidents/{incident}/reopen', [IncidentController::class, 'reopen'])
            ->middleware('permission:hse_incident.close')->name('incidents.reopen');
        Route::post('incidents/{incident}/transition', [IncidentController::class, 'transition'])
            ->middleware('permission:hse_incident.edit')->name('incidents.transition');

        // Entering the approval route. Behind `edit` rather than a permission of its own: putting
        // your own report forward is authorship, not a separate authority (see submitForApproval).
        Route::post('incidents/{incident}/submit', [IncidentController::class, 'submit'])
            ->middleware('permission:hse_incident.edit')->name('incidents.submit');

        // Opened AT the incident, and only here — there is no top-level "new investigation" screen,
        // because an investigation with no incident behind it investigates nothing. The controller
        // refuses a second one, which is what makes the workflow's auto-action idempotent when a
        // stage is re-entered.
        Route::post('incidents/{incident}/investigation', [InvestigationController::class, 'store'])
            ->middleware('permission:hse_incident.investigate')->name('incidents.investigations.store');

        Route::post('incidents/{incident}/actions', [CorrectiveActionController::class, 'storeForIncident'])
            ->middleware('permission:corrective_action.create')->name('incidents.actions.store');
    });

    // --- investigations ------------------------------------------------------------------------
    //
    // Governed by `hse_incident` throughout: an investigation is the incident's own paperwork, and a
    // separate module would mean an organization could grant the right to investigate without the
    // right to read what was being investigated (§32).

    Route::middleware('permission:hse_incident.view')->group(function () {
        Route::get('investigations', [InvestigationController::class, 'index'])->name('investigations.index');
        Route::get('investigations/{investigation}', [InvestigationController::class, 'show'])
            ->whereNumber('investigation')->name('investigations.show');
    });

    Route::whereNumber('investigation')->middleware('permission:hse_incident.investigate')->group(function () {
        Route::get('investigations/{investigation}/edit', [InvestigationController::class, 'edit'])
            ->name('investigations.edit');
        Route::put('investigations/{investigation}', [InvestigationController::class, 'update'])
            ->name('investigations.update');
        Route::delete('investigations/{investigation}', [InvestigationController::class, 'destroy'])
            ->name('investigations.destroy');

        Route::post('investigations/{investigation}/complete', [InvestigationController::class, 'complete'])
            ->name('investigations.complete');
        Route::post('investigations/{investigation}/reopen', [InvestigationController::class, 'reopen'])
            ->name('investigations.reopen');
        Route::post('investigations/{investigation}/transition', [InvestigationController::class, 'transition'])
            ->name('investigations.transition');
    });

    Route::post('investigations/{investigation}/actions', [CorrectiveActionController::class, 'storeForInvestigation'])
        ->whereNumber('investigation')
        ->middleware('permission:corrective_action.create')->name('investigations.actions.store');

    // --- corrective actions --------------------------------------------------------------------

    Route::middleware('permission:corrective_action.view')->group(function () {
        Route::get('corrective-actions', [CorrectiveActionController::class, 'index'])
            ->name('corrective-actions.index');
        Route::get('corrective-actions/{corrective_action}', [CorrectiveActionController::class, 'show'])
            ->whereNumber('corrective_action')->name('corrective-actions.show');
    });

    Route::get('corrective-actions/create', [CorrectiveActionController::class, 'create'])
        ->middleware('permission:corrective_action.create')->name('corrective-actions.create');
    Route::post('corrective-actions', [CorrectiveActionController::class, 'store'])
        ->middleware('permission:corrective_action.create')->name('corrective-actions.store');

    Route::whereNumber('corrective_action')->group(function () {
        Route::get('corrective-actions/{corrective_action}/edit', [CorrectiveActionController::class, 'edit'])
            ->middleware('permission:corrective_action.edit')->name('corrective-actions.edit');
        Route::put('corrective-actions/{corrective_action}', [CorrectiveActionController::class, 'update'])
            ->middleware('permission:corrective_action.edit')->name('corrective-actions.update');
        Route::delete('corrective-actions/{corrective_action}', [CorrectiveActionController::class, 'destroy'])
            ->middleware('permission:corrective_action.delete')->name('corrective-actions.destroy');

        Route::post('corrective-actions/{corrective_action}/assign', [CorrectiveActionController::class, 'assign'])
            ->middleware('permission:corrective_action.assign')->name('corrective-actions.assign');

        // Completing is the ASSIGNEE's act and takes only `edit` — the policy then narrows it to
        // the person the action was given to. Verifying that it worked is somebody else's, and it
        // has its own permission for exactly that reason: an action closed by the person who was
        // asked to do it proves nothing at all.
        Route::post('corrective-actions/{corrective_action}/complete', [CorrectiveActionController::class, 'complete'])
            ->middleware('permission:corrective_action.edit')->name('corrective-actions.complete');
        Route::post('corrective-actions/{corrective_action}/verify', [CorrectiveActionController::class, 'verify'])
            ->middleware('permission:corrective_action.verify')->name('corrective-actions.verify');

        // Withdrawal, not closure. The status reached is `cancelled`, and it is deliberately not
        // counted anywhere as a success.
        Route::post('corrective-actions/{corrective_action}/cancel', [CorrectiveActionController::class, 'cancel'])
            ->middleware('permission:corrective_action.close')->name('corrective-actions.cancel');

        Route::post(
            'corrective-actions/{corrective_action}/transition',
            [CorrectiveActionController::class, 'transition'],
        )->middleware('permission:corrective_action.edit')->name('corrective-actions.transition');
    });

    // --- observations --------------------------------------------------------------------------

    Route::middleware('permission:hse_observation.view')->group(function () {
        Route::get('hse-observations', [HseObservationController::class, 'index'])
            ->name('hse-observations.index');
        Route::get('hse-observations/{hse_observation}', [HseObservationController::class, 'show'])
            ->whereNumber('hse_observation')->name('hse-observations.show');
    });

    Route::get('hse-observations/create', [HseObservationController::class, 'create'])
        ->middleware('permission:hse_observation.create')->name('hse-observations.create');
    Route::post('hse-observations', [HseObservationController::class, 'store'])
        ->middleware('permission:hse_observation.create')->name('hse-observations.store');

    Route::whereNumber('hse_observation')->group(function () {
        Route::get('hse-observations/{hse_observation}/edit', [HseObservationController::class, 'edit'])
            ->middleware('permission:hse_observation.edit')->name('hse-observations.edit');
        Route::put('hse-observations/{hse_observation}', [HseObservationController::class, 'update'])
            ->middleware('permission:hse_observation.edit')->name('hse-observations.update');
        Route::delete('hse-observations/{hse_observation}', [HseObservationController::class, 'destroy'])
            ->middleware('permission:hse_observation.delete')->name('hse-observations.destroy');

        Route::post('hse-observations/{hse_observation}/close', [HseObservationController::class, 'close'])
            ->middleware('permission:hse_observation.close')->name('hse-observations.close');
        Route::post('hse-observations/{hse_observation}/reopen', [HseObservationController::class, 'reopen'])
            ->middleware('permission:hse_observation.close')->name('hse-observations.reopen');
        Route::post('hse-observations/{hse_observation}/transition', [HseObservationController::class, 'transition'])
            ->middleware('permission:hse_observation.edit')->name('hse-observations.transition');

        Route::post('hse-observations/{hse_observation}/actions', [CorrectiveActionController::class, 'storeForObservation'])
            ->middleware('permission:corrective_action.create')->name('hse-observations.actions.store');
    });

    // --- risk assessments ----------------------------------------------------------------------

    Route::middleware('permission:hse_risk.view')->group(function () {
        Route::get('risk-assessments', [RiskAssessmentController::class, 'index'])
            ->name('risk-assessments.index');
        Route::get('risk-assessments/{risk_assessment}', [RiskAssessmentController::class, 'show'])
            ->whereNumber('risk_assessment')->name('risk-assessments.show');
    });

    Route::get('risk-assessments/create', [RiskAssessmentController::class, 'create'])
        ->middleware('permission:hse_risk.create')->name('risk-assessments.create');
    Route::post('risk-assessments', [RiskAssessmentController::class, 'store'])
        ->middleware('permission:hse_risk.create')->name('risk-assessments.store');

    Route::whereNumber('risk_assessment')->group(function () {
        Route::get('risk-assessments/{risk_assessment}/edit', [RiskAssessmentController::class, 'edit'])
            ->middleware('permission:hse_risk.edit')->name('risk-assessments.edit');
        Route::put('risk-assessments/{risk_assessment}', [RiskAssessmentController::class, 'update'])
            ->middleware('permission:hse_risk.edit')->name('risk-assessments.update');
        Route::delete('risk-assessments/{risk_assessment}', [RiskAssessmentController::class, 'destroy'])
            ->middleware('permission:hse_risk.delete')->name('risk-assessments.destroy');

        // The hazard table is the assessment's own content, so it takes the assessment's own edit
        // permission. There is no separate hazard permission and there should not be: a hazard row
        // has no meaning apart from the assessment it sits in.
        Route::get('risk-assessments/{risk_assessment}/hazards', [RiskAssessmentController::class, 'editHazards'])
            ->middleware('permission:hse_risk.edit')->name('risk-assessments.hazards.edit');
        Route::put('risk-assessments/{risk_assessment}/hazards', [RiskAssessmentController::class, 'updateHazards'])
            ->middleware('permission:hse_risk.edit')->name('risk-assessments.hazards.update');

        Route::post('risk-assessments/{risk_assessment}/review', [RiskAssessmentController::class, 'review'])
            ->middleware('permission:hse_risk.edit')->name('risk-assessments.review');

        // Nobody approves their own, and an assessment with no hazards on it cannot be approved at
        // all. Both refusals are in the policy and again in the service.
        Route::post('risk-assessments/{risk_assessment}/approve', [RiskAssessmentController::class, 'approve'])
            ->middleware('permission:hse_risk.approve')->name('risk-assessments.approve');

        Route::post('risk-assessments/{risk_assessment}/transition', [RiskAssessmentController::class, 'transition'])
            ->middleware('permission:hse_risk.edit')->name('risk-assessments.transition');
    });

    // --- HSE inspections -----------------------------------------------------------------------

    Route::middleware('permission:hse_inspection.view')->group(function () {
        Route::get('hse-inspections', [HseInspectionController::class, 'index'])
            ->name('hse-inspections.index');
        Route::get('hse-inspections/{hse_inspection}', [HseInspectionController::class, 'show'])
            ->whereNumber('hse_inspection')->name('hse-inspections.show');
    });

    Route::get('hse-inspections/create', [HseInspectionController::class, 'create'])
        ->middleware('permission:hse_inspection.create')->name('hse-inspections.create');
    Route::post('hse-inspections', [HseInspectionController::class, 'store'])
        ->middleware('permission:hse_inspection.create')->name('hse-inspections.store');

    Route::whereNumber('hse_inspection')->group(function () {
        Route::get('hse-inspections/{hse_inspection}/edit', [HseInspectionController::class, 'edit'])
            ->middleware('permission:hse_inspection.edit')->name('hse-inspections.edit');
        Route::put('hse-inspections/{hse_inspection}', [HseInspectionController::class, 'update'])
            ->middleware('permission:hse_inspection.edit')->name('hse-inspections.update');
        Route::delete('hse-inspections/{hse_inspection}', [HseInspectionController::class, 'destroy'])
            ->middleware('permission:hse_inspection.delete')->name('hse-inspections.destroy');

        Route::get('hse-inspections/{hse_inspection}/items', [HseInspectionController::class, 'editItems'])
            ->middleware('permission:hse_inspection.edit')->name('hse-inspections.items.edit');
        Route::put('hse-inspections/{hse_inspection}/items', [HseInspectionController::class, 'updateItems'])
            ->middleware('permission:hse_inspection.edit')->name('hse-inspections.items.update');

        // Carrying out the walk is `edit` — the person who does the inspecting is the person who
        // maintains it — but the service freezes the checklist the moment this succeeds, so `edit`
        // stops meaning "may rewrite the questions" at exactly that point (§59).
        Route::post('hse-inspections/{hse_inspection}/perform', [HseInspectionController::class, 'perform'])
            ->middleware('permission:hse_inspection.edit')->name('hse-inspections.perform');
        Route::post('hse-inspections/{hse_inspection}/close', [HseInspectionController::class, 'close'])
            ->middleware('permission:hse_inspection.close')->name('hse-inspections.close');
        Route::post('hse-inspections/{hse_inspection}/transition', [HseInspectionController::class, 'transition'])
            ->middleware('permission:hse_inspection.edit')->name('hse-inspections.transition');

        Route::post('hse-inspections/{hse_inspection}/actions', [CorrectiveActionController::class, 'storeForInspection'])
            ->middleware('permission:corrective_action.create')->name('hse-inspections.actions.store');
    });

    // --- toolbox talks -------------------------------------------------------------------------

    Route::middleware('permission:toolbox_talk.view')->group(function () {
        Route::get('toolbox-talks', [ToolboxTalkController::class, 'index'])->name('toolbox-talks.index');
        Route::get('toolbox-talks/{toolbox_talk}', [ToolboxTalkController::class, 'show'])
            ->whereNumber('toolbox_talk')->name('toolbox-talks.show');
    });

    Route::get('toolbox-talks/create', [ToolboxTalkController::class, 'create'])
        ->middleware('permission:toolbox_talk.create')->name('toolbox-talks.create');
    Route::post('toolbox-talks', [ToolboxTalkController::class, 'store'])
        ->middleware('permission:toolbox_talk.create')->name('toolbox-talks.store');

    Route::whereNumber('toolbox_talk')->group(function () {
        Route::get('toolbox-talks/{toolbox_talk}/edit', [ToolboxTalkController::class, 'edit'])
            ->middleware('permission:toolbox_talk.edit')->name('toolbox-talks.edit');
        Route::put('toolbox-talks/{toolbox_talk}', [ToolboxTalkController::class, 'update'])
            ->middleware('permission:toolbox_talk.edit')->name('toolbox-talks.update');
        Route::delete('toolbox-talks/{toolbox_talk}', [ToolboxTalkController::class, 'destroy'])
            ->middleware('permission:toolbox_talk.delete')->name('toolbox-talks.destroy');

        Route::post('toolbox-talks/{toolbox_talk}/record', [ToolboxTalkController::class, 'record'])
            ->middleware('permission:toolbox_talk.edit')->name('toolbox-talks.record');

        // `toolbox_talk.view` ONLY, and that is the point of the route. The people signing are
        // labourers and subcontractors who hold nothing else in this application; the policy then
        // narrows it to their own row and refuses a second signature. A supervisor cannot sign on
        // their behalf here — entering the crew list is `edit`, and those rows carry a name with no
        // signature, which is an honest record of what happened.
        Route::post('toolbox-talks/{toolbox_talk}/attendees/{attendee}/sign', [ToolboxTalkController::class, 'sign'])
            ->whereNumber('attendee')->middleware('permission:toolbox_talk.view')->name('toolbox-talks.attendees.sign');

        Route::post('toolbox-talks/{toolbox_talk}/transition', [ToolboxTalkController::class, 'transition'])
            ->middleware('permission:toolbox_talk.edit')->name('toolbox-talks.transition');
    });

    // --- permits to work -----------------------------------------------------------------------
    //
    // NINE LIFECYCLE ROUTES AND FOUR DIFFERENT PERMISSIONS BEHIND THEM. This is the most heavily
    // separated record in the application, and every separation is one somebody died to establish:
    // the person who wants the work done never authorises it, the person who authorises it never
    // accepts it on the crew's behalf, and anybody who can see a live permit can stop the work
    // under it.

    Route::middleware('permission:permit.view')->group(function () {
        Route::get('permits', [PermitController::class, 'index'])->name('permits.index');
        Route::get('permits/{permit}', [PermitController::class, 'show'])
            ->whereNumber('permit')->name('permits.show');
    });

    Route::get('permits/create', [PermitController::class, 'create'])
        ->middleware('permission:permit.create')->name('permits.create');
    Route::post('permits', [PermitController::class, 'store'])
        ->middleware('permission:permit.create')->name('permits.store');

    Route::whereNumber('permit')->group(function () {
        Route::get('permits/{permit}/edit', [PermitController::class, 'edit'])
            ->middleware('permission:permit.edit')->name('permits.edit');
        Route::put('permits/{permit}', [PermitController::class, 'update'])
            ->middleware('permission:permit.edit')->name('permits.update');
        Route::delete('permits/{permit}', [PermitController::class, 'destroy'])
            ->middleware('permission:permit.delete')->name('permits.destroy');

        // One route per line of the checklist, and it takes `permit.review` rather than `edit`:
        // confirming a precaution is a statement that somebody physically checked the thing, and it
        // attaches their name to it. The question after an incident is never "was the checklist
        // completed" — it is "who confirmed the space had been purged, and when".
        Route::post('permits/{permit}/precautions/{precaution}/confirm', [PermitController::class, 'confirmPrecaution'])
            ->whereNumber('precaution')->middleware('permission:permit.review')->name('permits.precautions.confirm');

        Route::post('permits/{permit}/gas-test', [PermitController::class, 'recordGasTest'])
            ->middleware('permission:permit.edit')->name('permits.gas-test');

        // Issue and resume are the same authority — putting work under authorisation — so both take
        // `permit.approve`. Rejection is separated from it because a company may want a supervisor
        // who can refuse a permit without being able to grant one.
        Route::post('permits/{permit}/issue', [PermitController::class, 'issue'])
            ->middleware('permission:permit.approve')->name('permits.issue');
        Route::post('permits/{permit}/reject', [PermitController::class, 'reject'])
            ->middleware('permission:permit.reject')->name('permits.reject');
        Route::post('permits/{permit}/resume', [PermitController::class, 'resume'])
            ->middleware('permission:permit.approve')->name('permits.resume');

        // Acceptance is the crew's, and the policy refuses the issuer.
        Route::post('permits/{permit}/accept', [PermitController::class, 'accept'])
            ->middleware('permission:permit.review')->name('permits.accept');

        // DELIBERATELY THE WIDEST ROUTE IN THE MODULE. Anybody with edit reach on a permit can stop
        // the work under it. Requiring the issuer's authority would mean work continuing while
        // somebody goes looking for them, and that trade is not one this application makes.
        Route::post('permits/{permit}/suspend', [PermitController::class, 'suspend'])
            ->middleware('permission:permit.edit')->name('permits.suspend');

        Route::post('permits/{permit}/close', [PermitController::class, 'close'])
            ->middleware('permission:permit.close')->name('permits.close');
        Route::post('permits/{permit}/cancel', [PermitController::class, 'cancel'])
            ->middleware('permission:permit.close')->name('permits.cancel');

        Route::post('permits/{permit}/transition', [PermitController::class, 'transition'])
            ->middleware('permission:permit.edit')->name('permits.transition');
    });

    // --- permit types (configuration) ----------------------------------------------------------
    //
    // `permit.manage` for every write, not `permit.edit`. `precaution_template` is the checklist
    // that gets copied onto every future confined-space permit, so a permit issuer doing their
    // day-to-day work must not be able to quietly take the gas test off it.

    Route::middleware('permission:permit.view')->group(function () {
        Route::get('permit-types', [PermitTypeController::class, 'index'])->name('permit-types.index');
        Route::get('permit-types/{permit_type}', [PermitTypeController::class, 'show'])
            ->whereNumber('permit_type')->name('permit-types.show');
    });

    Route::middleware('permission:permit.manage')->group(function () {
        Route::get('permit-types/create', [PermitTypeController::class, 'create'])->name('permit-types.create');
        Route::post('permit-types', [PermitTypeController::class, 'store'])->name('permit-types.store');

        Route::whereNumber('permit_type')->group(function () {
            Route::get('permit-types/{permit_type}/edit', [PermitTypeController::class, 'edit'])
                ->name('permit-types.edit');
            Route::put('permit-types/{permit_type}', [PermitTypeController::class, 'update'])
                ->name('permit-types.update');
            Route::delete('permit-types/{permit_type}', [PermitTypeController::class, 'destroy'])
                ->name('permit-types.destroy');
        });
    });

    // --- training ------------------------------------------------------------------------------

    Route::middleware('permission:hse_training.view')->group(function () {
        Route::get('training-courses', [TrainingCourseController::class, 'index'])
            ->name('training-courses.index');
        Route::get('training-courses/{training_course}', [TrainingCourseController::class, 'show'])
            ->whereNumber('training_course')->name('training-courses.show');
    });

    Route::middleware('permission:hse_training.manage')->group(function () {
        Route::get('training-courses/create', [TrainingCourseController::class, 'create'])
            ->name('training-courses.create');
        Route::post('training-courses', [TrainingCourseController::class, 'store'])
            ->name('training-courses.store');

        Route::whereNumber('training_course')->group(function () {
            Route::get('training-courses/{training_course}/edit', [TrainingCourseController::class, 'edit'])
                ->name('training-courses.edit');
            Route::put('training-courses/{training_course}', [TrainingCourseController::class, 'update'])
                ->name('training-courses.update');
            Route::delete('training-courses/{training_course}', [TrainingCourseController::class, 'destroy'])
                ->name('training-courses.destroy');
        });
    });

    Route::middleware('permission:hse_training.view')->group(function () {
        Route::get('training-records', [TrainingRecordController::class, 'index'])
            ->name('training-records.index');
        Route::get('training-records/{training_record}', [TrainingRecordController::class, 'show'])
            ->whereNumber('training_record')->name('training-records.show');
    });

    Route::get('training-records/create', [TrainingRecordController::class, 'create'])
        ->middleware('permission:hse_training.create')->name('training-records.create');
    Route::post('training-records', [TrainingRecordController::class, 'store'])
        ->middleware('permission:hse_training.create')->name('training-records.store');

    Route::whereNumber('training_record')->group(function () {
        Route::get('training-records/{training_record}/edit', [TrainingRecordController::class, 'edit'])
            ->middleware('permission:hse_training.edit')->name('training-records.edit');
        Route::put('training-records/{training_record}', [TrainingRecordController::class, 'update'])
            ->middleware('permission:hse_training.edit')->name('training-records.update');
        Route::delete('training-records/{training_record}', [TrainingRecordController::class, 'destroy'])
            ->middleware('permission:hse_training.delete')->name('training-records.destroy');

        // Renewing IS creating — a new certificate that remembers the old one, which stays exactly
        // as it was. The certificate somebody held in 2024 was valid in 2024, and that is precisely
        // what an investigation asks about two years later (§59).
        Route::post('training-records/{training_record}/renew', [TrainingRecordController::class, 'renew'])
            ->middleware('permission:hse_training.create')->name('training-records.renew');

        Route::post('training-records/{training_record}/transition', [TrainingRecordController::class, 'transition'])
            ->middleware('permission:hse_training.edit')->name('training-records.transition');
    });

    /*
    |----------------------------------------------------------------------------------------------
    | Quality
    |----------------------------------------------------------------------------------------------
    */

    // --- quality inspections -------------------------------------------------------------------

    Route::middleware('permission:quality_inspection.view')->group(function () {
        Route::get('quality-inspections', [QualityInspectionController::class, 'index'])
            ->name('quality-inspections.index');
        Route::get('quality-inspections/{quality_inspection}', [QualityInspectionController::class, 'show'])
            ->whereNumber('quality_inspection')->name('quality-inspections.show');
    });

    Route::get('quality-inspections/create', [QualityInspectionController::class, 'create'])
        ->middleware('permission:quality_inspection.create')->name('quality-inspections.create');
    Route::post('quality-inspections', [QualityInspectionController::class, 'store'])
        ->middleware('permission:quality_inspection.create')->name('quality-inspections.store');

    Route::whereNumber('quality_inspection')->group(function () {
        Route::get('quality-inspections/{quality_inspection}/edit', [QualityInspectionController::class, 'edit'])
            ->middleware('permission:quality_inspection.edit')->name('quality-inspections.edit');
        Route::put('quality-inspections/{quality_inspection}', [QualityInspectionController::class, 'update'])
            ->middleware('permission:quality_inspection.edit')->name('quality-inspections.update');
        Route::delete('quality-inspections/{quality_inspection}', [QualityInspectionController::class, 'destroy'])
            ->middleware('permission:quality_inspection.delete')->name('quality-inspections.destroy');

        Route::get('quality-inspections/{quality_inspection}/items', [QualityInspectionController::class, 'editItems'])
            ->middleware('permission:quality_inspection.edit')->name('quality-inspections.items.edit');
        Route::put('quality-inspections/{quality_inspection}/items', [QualityInspectionController::class, 'updateItems'])
            ->middleware('permission:quality_inspection.edit')->name('quality-inspections.items.update');

        // REQUESTING IS BROAD, INSPECTING IS NARROW. Any site engineer raises a request for
        // inspection; recording the verdict takes `quality_inspection.approve`, and the policy
        // refuses it to whoever raised the request. A hold point that releases itself is not one.
        Route::post('quality-inspections/{quality_inspection}/inspect', [QualityInspectionController::class, 'inspect'])
            ->middleware('permission:quality_inspection.approve')->name('quality-inspections.inspect');

        Route::post(
            'quality-inspections/{quality_inspection}/transition',
            [QualityInspectionController::class, 'transition'],
        )->middleware('permission:quality_inspection.edit')->name('quality-inspections.transition');

        // A rejection is not an NCR. Whether the finding becomes a formal non-conformance — with a
        // disposition, a cost and a verification — is a separate decision, raised here.
        Route::post('quality-inspections/{quality_inspection}/ncrs', [NcrController::class, 'storeForInspection'])
            ->middleware('permission:ncr.create')->name('quality-inspections.ncrs.store');
    });

    // --- non-conformance reports ---------------------------------------------------------------

    Route::middleware('permission:ncr.view')->group(function () {
        Route::get('ncrs', [NcrController::class, 'index'])->name('ncrs.index');
        Route::get('ncrs/{ncr}', [NcrController::class, 'show'])
            ->whereNumber('ncr')->name('ncrs.show');
    });

    Route::get('ncrs/create', [NcrController::class, 'create'])
        ->middleware('permission:ncr.create')->name('ncrs.create');
    Route::post('ncrs', [NcrController::class, 'store'])
        ->middleware('permission:ncr.create')->name('ncrs.store');

    Route::whereNumber('ncr')->group(function () {
        Route::get('ncrs/{ncr}/edit', [NcrController::class, 'edit'])
            ->middleware('permission:ncr.edit')->name('ncrs.edit');
        Route::put('ncrs/{ncr}', [NcrController::class, 'update'])
            ->middleware('permission:ncr.edit')->name('ncrs.update');
        Route::delete('ncrs/{ncr}', [NcrController::class, 'destroy'])
            ->middleware('permission:ncr.delete')->name('ncrs.destroy');

        Route::post('ncrs/{ncr}/issue', [NcrController::class, 'issue'])
            ->middleware('permission:ncr.submit')->name('ncrs.issue');

        // RESPONDING IS NOT DISPOSITIONING, and the split is the whole point of the register. The
        // party who did the work answers the finding (`review`, which the author may legitimately
        // hold); the engineering decision about what happens to the work is `approve`, and
        // NcrPolicy refuses it to whoever raised the non-conformance at any scope.
        Route::post('ncrs/{ncr}/respond', [NcrController::class, 'respond'])
            ->middleware('permission:ncr.review')->name('ncrs.respond');
        Route::post('ncrs/{ncr}/disposition', [NcrController::class, 'disposition'])
            ->middleware('permission:ncr.approve')->name('ncrs.disposition');

        // Verification is `review` again and is NOT gated on the author, because by then the
        // question is whether the disposition was actually carried out — a factual check the
        // originator is often the only person who can make, having seen the original defect.
        Route::post('ncrs/{ncr}/verify', [NcrController::class, 'verify'])
            ->middleware('permission:ncr.review')->name('ncrs.verify');

        Route::post('ncrs/{ncr}/close', [NcrController::class, 'close'])
            ->middleware('permission:ncr.close')->name('ncrs.close');
        Route::post('ncrs/{ncr}/transition', [NcrController::class, 'transition'])
            ->middleware('permission:ncr.edit')->name('ncrs.transition');

        Route::post('ncrs/{ncr}/actions', [CorrectiveActionController::class, 'storeForNcr'])
            ->middleware('permission:corrective_action.create')->name('ncrs.actions.store');
    });

    // --- clients -------------------------------------------------------------------------------

    Route::middleware('permission:client.view')->group(function () {
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [ClientController::class, 'show'])
            ->whereNumber('client')->name('clients.show');
    });

    Route::get('clients/create', [ClientController::class, 'create'])
        ->middleware('permission:client.create')->name('clients.create');
    Route::post('clients', [ClientController::class, 'store'])
        ->middleware('permission:client.create')->name('clients.store');

    Route::whereNumber('client')->group(function () {
        Route::get('clients/{client}/edit', [ClientController::class, 'edit'])
            ->middleware('permission:client.edit')->name('clients.edit');
        Route::put('clients/{client}', [ClientController::class, 'update'])
            ->middleware('permission:client.edit')->name('clients.update');
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])
            ->middleware('permission:client.delete')->name('clients.destroy');

        Route::post('clients/{client}/transition', [ClientController::class, 'transition'])
            ->middleware('permission:client.edit')->name('clients.transition');

        // Naming the relationship owner GRANTS VISIBILITY — `relationship_owner_id` is what the
        // `assigned` access scope reads — so it is separated from `client.edit` in the same way
        // and for the same reason role assignment is separated from editing a user (§10, §84).
        Route::post('clients/{client}/owner', [ClientController::class, 'assignOwner'])
            ->middleware('permission:client.manage')->name('clients.owner.assign');

        Route::post('clients/{client}/contacts', [ClientContactController::class, 'store'])
            ->middleware('permission:client.edit')->name('clients.contacts.store');
    });

    Route::whereNumber('contact')->group(function () {
        Route::put('client-contacts/{contact}', [ClientContactController::class, 'update'])
            ->middleware('permission:client.edit')->name('client-contacts.update');
        Route::delete('client-contacts/{contact}', [ClientContactController::class, 'destroy'])
            ->middleware('permission:client.edit')->name('client-contacts.destroy');
    });

    // --- vendors -------------------------------------------------------------------------------

    Route::middleware('permission:vendor.view')->group(function () {
        Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
        Route::get('vendors/{vendor}', [VendorController::class, 'show'])
            ->whereNumber('vendor')->name('vendors.show');
    });

    Route::get('vendors/create', [VendorController::class, 'create'])
        ->middleware('permission:vendor.create')->name('vendors.create');
    Route::post('vendors', [VendorController::class, 'store'])
        ->middleware('permission:vendor.create')->name('vendors.store');

    Route::whereNumber('vendor')->group(function () {
        Route::get('vendors/{vendor}/edit', [VendorController::class, 'edit'])
            ->middleware('permission:vendor.edit')->name('vendors.edit');
        Route::put('vendors/{vendor}', [VendorController::class, 'update'])
            ->middleware('permission:vendor.edit')->name('vendors.update');
        Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])
            ->middleware('permission:vendor.delete')->name('vendors.destroy');

        Route::post('vendors/{vendor}/transition', [VendorController::class, 'transition'])
            ->middleware('permission:vendor.edit')->name('vendors.transition');

        // THE FRAUD SURFACE. `vendor.bank` guards the GET as well as the PUT, because serving the
        // form pre-filled hands over the account number the permission exists to protect — and the
        // permission covers reading for the same reason. Invoice-redirection fraud does not need
        // write access to be profitable; it needs somebody's real details and a plausible email.
        Route::get('vendors/{vendor}/bank', [VendorController::class, 'editBankDetails'])
            ->middleware('permission:vendor.bank')->name('vendors.bank.edit');
        Route::put('vendors/{vendor}/bank', [VendorController::class, 'updateBankDetails'])
            ->middleware('permission:vendor.bank')->name('vendors.bank.update');

        // Barring a company from work, and letting it back. Both demand a reason, both are
        // recorded, and neither is reachable through the ordinary edit form.
        Route::post('vendors/{vendor}/blacklist', [VendorController::class, 'blacklist'])
            ->middleware('permission:vendor.blacklist')->name('vendors.blacklist');
        Route::delete('vendors/{vendor}/blacklist', [VendorController::class, 'liftBlacklist'])
            ->middleware('permission:vendor.blacklist')->name('vendors.blacklist.lift');

        // Filing the assessment is clerical; ACCEPTING it is the status transition above, gated on
        // vendor.manage. A coordinator files the certificate, a manager approves the supplier.
        Route::post('vendors/{vendor}/prequalification', [VendorController::class, 'prequalify'])
            ->middleware('permission:vendor.manage')->name('vendors.prequalify');

        Route::post('vendors/{vendor}/evaluations', [VendorController::class, 'evaluate'])
            ->middleware('permission:vendor.evaluate')->name('vendors.evaluate');

        Route::post('vendors/{vendor}/contacts', [VendorContactController::class, 'store'])
            ->middleware('permission:vendor.edit')->name('vendors.contacts.store');
    });

    Route::whereNumber('contact')->group(function () {
        Route::put('vendor-contacts/{contact}', [VendorContactController::class, 'update'])
            ->middleware('permission:vendor.edit')->name('vendor-contacts.update');
        Route::delete('vendor-contacts/{contact}', [VendorContactController::class, 'destroy'])
            ->middleware('permission:vendor.edit')->name('vendor-contacts.destroy');
    });

    // --- contracts -----------------------------------------------------------------------------
    //
    // One register for both directions. `permission:contract.view` reaches the pages; the policy
    // then decides each record, and ContractPolicy::viewFinancials() decides separately whether the
    // reader sees the value. A contract defaults to CONFIDENTIAL, so route middleware here is the
    // outermost of three filters rather than the meaningful one (§6, §84).

    Route::middleware('permission:contract.view')->group(function () {
        Route::get('contracts', [ContractController::class, 'index'])->name('contracts.index');
        Route::get('contracts/{contract}', [ContractController::class, 'show'])
            ->whereNumber('contract')->name('contracts.show');
    });

    Route::get('contracts/create', [ContractController::class, 'create'])
        ->middleware('permission:contract.create')->name('contracts.create');
    Route::post('contracts', [ContractController::class, 'store'])
        ->middleware('permission:contract.create')->name('contracts.store');

    Route::whereNumber('contract')->group(function () {
        Route::get('contracts/{contract}/edit', [ContractController::class, 'edit'])
            ->middleware('permission:contract.edit')->name('contracts.edit');
        Route::put('contracts/{contract}', [ContractController::class, 'update'])
            ->middleware('permission:contract.edit')->name('contracts.update');
        Route::delete('contracts/{contract}', [ContractController::class, 'destroy'])
            ->middleware('permission:contract.delete')->name('contracts.destroy');

        Route::post('contracts/{contract}/transition', [ContractController::class, 'transition'])
            ->middleware('permission:contract.edit')->name('contracts.transition');

        // Retention is money held back from somebody who has already done the work. Moving it takes
        // `contract.manage`, not `contract.edit`, because the balance is what a subcontractor will
        // eventually be paid — and the service refuses to release more than is held.
        Route::post('contracts/{contract}/retention', [ContractController::class, 'moveRetention'])
            ->middleware('permission:contract.manage')->name('contracts.retention.move');
    });

    // --- purchase requests ---------------------------------------------------------------------
    //
    // The first link in the chain, and the shape every register below repeats: `view` reaches the
    // pages, `create` reaches the form, and the DECISIONS get routes and permissions of their own.
    // Submit, approve and reject are separate endpoints rather than a generic status move because
    // each does more than change a word — it stamps who and when, and those stamps are what the
    // segregation rule is later measured against. A single `transition` route still exists for the
    // moves that are only moves (§6, §84).

    Route::middleware('permission:purchase_request.view')->group(function () {
        Route::get('purchase-requests', [PurchaseRequestController::class, 'index'])
            ->name('purchase-requests.index');
        Route::get('purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'show'])
            ->whereNumber('purchase_request')->name('purchase-requests.show');
    });

    Route::get('purchase-requests/create', [PurchaseRequestController::class, 'create'])
        ->middleware('permission:purchase_request.create')->name('purchase-requests.create');
    Route::post('purchase-requests', [PurchaseRequestController::class, 'store'])
        ->middleware('permission:purchase_request.create')->name('purchase-requests.store');

    Route::whereNumber('purchase_request')->group(function () {
        Route::get('purchase-requests/{purchase_request}/edit', [PurchaseRequestController::class, 'edit'])
            ->middleware('permission:purchase_request.edit')->name('purchase-requests.edit');
        Route::put('purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'update'])
            ->middleware('permission:purchase_request.edit')->name('purchase-requests.update');
        Route::delete('purchase-requests/{purchase_request}', [PurchaseRequestController::class, 'destroy'])
            ->middleware('permission:purchase_request.delete')->name('purchase-requests.destroy');

        Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])
            ->middleware('permission:purchase_request.submit')->name('purchase-requests.submit');
        // `approve` and `reject` are held by somebody other than the requester. That is the whole
        // of segregation of duties as far as routing is concerned; the service adds the rest.
        Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])
            ->middleware('permission:purchase_request.approve')->name('purchase-requests.approve');
        Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])
            ->middleware('permission:purchase_request.reject')->name('purchase-requests.reject');
        Route::post('purchase-requests/{purchase_request}/transition', [PurchaseRequestController::class, 'transition'])
            ->middleware('permission:purchase_request.edit')->name('purchase-requests.transition');
    });

    // --- enquiries (RFQ) -----------------------------------------------------------------------
    //
    // The invitation list gets endpoints of its own rather than riding on the enquiry form, because
    // each addition and removal is an event with a time on it and an unsuccessful bidder is
    // entitled to ask when they were asked. The route only reaches the area; RfqPolicy::invite()
    // makes the distinction that matters — editing the list before issue is ordinary editing, while
    // adding a supplier AFTER issue gives them less time than everybody else and takes `manage`.

    Route::middleware('permission:rfq.view')->group(function () {
        Route::get('rfqs', [RfqController::class, 'index'])->name('rfqs.index');
        Route::get('rfqs/{rfq}', [RfqController::class, 'show'])
            ->whereNumber('rfq')->name('rfqs.show');
    });

    Route::get('rfqs/create', [RfqController::class, 'create'])
        ->middleware('permission:rfq.create')->name('rfqs.create');
    Route::post('rfqs', [RfqController::class, 'store'])
        ->middleware('permission:rfq.create')->name('rfqs.store');

    Route::whereNumber('rfq')->group(function () {
        Route::get('rfqs/{rfq}/edit', [RfqController::class, 'edit'])
            ->middleware('permission:rfq.edit')->name('rfqs.edit');
        Route::put('rfqs/{rfq}', [RfqController::class, 'update'])
            ->middleware('permission:rfq.edit')->name('rfqs.update');
        Route::delete('rfqs/{rfq}', [RfqController::class, 'destroy'])
            ->middleware('permission:rfq.delete')->name('rfqs.destroy');

        Route::post('rfqs/{rfq}/issue', [RfqController::class, 'issue'])
            ->middleware('permission:rfq.issue')->name('rfqs.issue');
        Route::post('rfqs/{rfq}/award', [RfqController::class, 'award'])
            ->middleware('permission:rfq.manage')->name('rfqs.award');

        Route::middleware('permission:rfq.edit,rfq.manage')->group(function () {
            Route::post('rfqs/{rfq}/invitations', [RfqController::class, 'invite'])
                ->name('rfqs.invitations.store');
            Route::delete('rfqs/{rfq}/invitations/{invitation}', [RfqController::class, 'uninvite'])
                ->whereNumber('invitation')->name('rfqs.invitations.destroy');
            Route::post('rfqs/{rfq}/invitations/{invitation}/decline', [RfqController::class, 'decline'])
                ->whereNumber('invitation')->name('rfqs.invitations.decline');
        });

        Route::post('rfqs/{rfq}/transition', [RfqController::class, 'transition'])
            ->middleware('permission:rfq.edit')->name('rfqs.transition');

        // The comparison hangs off the enquiry but belongs to the quotation register: it is a
        // reading of quotations, gated on `quotation.compare`, and the controller re-checks both
        // that the reader may open THIS enquiry and that each quotation is one they may see (§61).
        Route::get('rfqs/{rfq}/comparison', [QuotationController::class, 'compare'])
            ->middleware('permission:quotation.compare')->name('rfqs.comparison');
    });

    // --- quotations ----------------------------------------------------------------------------
    //
    // A quotation defaults to CONFIDENTIAL, so `quotation.view` here is the outermost of three
    // filters: the route, then the policy on each record, then Gate 5 on confidentiality.
    //
    // Scoring and comparing share `quotation.compare`, because they are one job: an evaluation
    // panel reads the table and writes its marks in the same sitting, and a permission that let
    // somebody mark a bid they could not see beside the others would be worse than useless.

    Route::middleware('permission:quotation.view')->group(function () {
        Route::get('quotations', [QuotationController::class, 'index'])->name('quotations.index');
        Route::get('quotations/{quotation}', [QuotationController::class, 'show'])
            ->whereNumber('quotation')->name('quotations.show');
    });

    Route::get('quotations/create', [QuotationController::class, 'create'])
        ->middleware('permission:quotation.create')->name('quotations.create');
    Route::post('quotations', [QuotationController::class, 'store'])
        ->middleware('permission:quotation.create')->name('quotations.store');

    Route::whereNumber('quotation')->group(function () {
        Route::get('quotations/{quotation}/edit', [QuotationController::class, 'edit'])
            ->middleware('permission:quotation.edit')->name('quotations.edit');
        Route::put('quotations/{quotation}', [QuotationController::class, 'update'])
            ->middleware('permission:quotation.edit')->name('quotations.update');
        Route::delete('quotations/{quotation}', [QuotationController::class, 'destroy'])
            ->middleware('permission:quotation.delete')->name('quotations.destroy');

        // The evaluator is the signed-in user and nothing else. There is no field on this form that
        // could name somebody, and the service writes only rows already belonging to them (§84).
        Route::post('quotations/{quotation}/scores', [QuotationController::class, 'score'])
            ->middleware('permission:quotation.compare')->name('quotations.scores.store');

        Route::post('quotations/{quotation}/transition', [QuotationController::class, 'transition'])
            ->middleware('permission:quotation.edit')->name('quotations.transition');
    });

    // --- purchase orders -----------------------------------------------------------------------
    //
    // `issue` is its own permission rather than something `approve` grants for free: approval is
    // internal authority and issue is the moment the supplier has the document, and a company that
    // wants one person to do both says so by granting both. Cancelling an issued order is
    // `purchase_order.manage`, narrower than raising one, because somebody outside the company is
    // already acting on it.

    Route::middleware('permission:purchase_order.view')->group(function () {
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])
            ->name('purchase-orders.index');
        Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
            ->whereNumber('purchase_order')->name('purchase-orders.show');
    });

    Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])
        ->middleware('permission:purchase_order.create')->name('purchase-orders.create');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('permission:purchase_order.create')->name('purchase-orders.store');

    Route::whereNumber('purchase_order')->group(function () {
        Route::get('purchase-orders/{purchase_order}/edit', [PurchaseOrderController::class, 'edit'])
            ->middleware('permission:purchase_order.edit')->name('purchase-orders.edit');
        Route::put('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])
            ->middleware('permission:purchase_order.edit')->name('purchase-orders.update');
        Route::delete('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy'])
            ->middleware('permission:purchase_order.delete')->name('purchase-orders.destroy');

        Route::post('purchase-orders/{purchase_order}/submit', [PurchaseOrderController::class, 'submit'])
            ->middleware('permission:purchase_order.submit')->name('purchase-orders.submit');
        Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])
            ->middleware('permission:purchase_order.approve')->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchase_order}/reject', [PurchaseOrderController::class, 'reject'])
            ->middleware('permission:purchase_order.reject')->name('purchase-orders.reject');
        Route::post('purchase-orders/{purchase_order}/issue', [PurchaseOrderController::class, 'issue'])
            ->middleware('permission:purchase_order.issue')->name('purchase-orders.issue');
        Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])
            ->middleware('permission:purchase_order.manage')->name('purchase-orders.cancel');

        Route::post('purchase-orders/{purchase_order}/transition', [PurchaseOrderController::class, 'transition'])
            ->middleware('permission:purchase_order.edit')->name('purchase-orders.transition');
    });

    // --- goods receipts ------------------------------------------------------------------------
    //
    // `goods_receipt.edit` covers entering, confirming, inspecting and disputing, because all four
    // are the storekeeper's own account of what turned up. Accepting is `goods_receipt.approve`:
    // it is the point at which the company says the supplier has performed, and it is the figure
    // an invoice will later be matched against.

    Route::middleware('permission:goods_receipt.view')->group(function () {
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])
            ->name('goods-receipts.index');
        Route::get('goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'show'])
            ->whereNumber('goods_receipt')->name('goods-receipts.show');
    });

    Route::get('goods-receipts/create', [GoodsReceiptController::class, 'create'])
        ->middleware('permission:goods_receipt.create')->name('goods-receipts.create');
    Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])
        ->middleware('permission:goods_receipt.create')->name('goods-receipts.store');

    Route::whereNumber('goods_receipt')->group(function () {
        Route::get('goods-receipts/{goods_receipt}/edit', [GoodsReceiptController::class, 'edit'])
            ->middleware('permission:goods_receipt.edit')->name('goods-receipts.edit');
        Route::put('goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'update'])
            ->middleware('permission:goods_receipt.edit')->name('goods-receipts.update');
        Route::delete('goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'destroy'])
            ->middleware('permission:goods_receipt.delete')->name('goods-receipts.destroy');

        Route::middleware('permission:goods_receipt.edit')->group(function () {
            Route::post('goods-receipts/{goods_receipt}/confirm', [GoodsReceiptController::class, 'confirm'])
                ->name('goods-receipts.confirm');
            Route::post('goods-receipts/{goods_receipt}/inspect', [GoodsReceiptController::class, 'inspect'])
                ->name('goods-receipts.inspect');
            Route::post('goods-receipts/{goods_receipt}/dispute', [GoodsReceiptController::class, 'dispute'])
                ->name('goods-receipts.dispute');
            Route::post('goods-receipts/{goods_receipt}/transition', [GoodsReceiptController::class, 'transition'])
                ->name('goods-receipts.transition');
        });

        Route::post('goods-receipts/{goods_receipt}/accept', [GoodsReceiptController::class, 'accept'])
            ->middleware('permission:goods_receipt.approve')->name('goods-receipts.accept');
    });

    // --- warehouses ----------------------------------------------------------------------------
    //
    // A store is a place, not a dropdown: it has an address, a keeper, and a rule about whether it
    // may go negative. `warehouse.suspend` is its own grant because suspending a store stops every
    // movement in it — a supervisor's decision during a stocktake, not a clerical one. There is no
    // separate close permission: closing is `warehouse.manage`, and the service refuses while any
    // stock remains on hand.

    Route::middleware('permission:warehouse.view')->group(function () {
        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])
            ->whereNumber('warehouse')->name('warehouses.show');
    });

    Route::get('warehouses/create', [WarehouseController::class, 'create'])
        ->middleware('permission:warehouse.create')->name('warehouses.create');
    Route::post('warehouses', [WarehouseController::class, 'store'])
        ->middleware('permission:warehouse.create')->name('warehouses.store');

    Route::whereNumber('warehouse')->group(function () {
        Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])
            ->middleware('permission:warehouse.edit')->name('warehouses.edit');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])
            ->middleware('permission:warehouse.edit')->name('warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])
            ->middleware('permission:warehouse.delete')->name('warehouses.destroy');

        Route::post('warehouses/{warehouse}/activate', [WarehouseController::class, 'activate'])
            ->middleware('permission:warehouse.edit')->name('warehouses.activate');
        Route::post('warehouses/{warehouse}/suspend', [WarehouseController::class, 'suspend'])
            ->middleware('permission:warehouse.suspend')->name('warehouses.suspend');
        Route::post('warehouses/{warehouse}/close', [WarehouseController::class, 'close'])
            ->middleware('permission:warehouse.manage')->name('warehouses.close');
        Route::post('warehouses/{warehouse}/transition', [WarehouseController::class, 'transition'])
            ->middleware('permission:warehouse.edit')->name('warehouses.transition');
    });

    // --- stock catalogue -----------------------------------------------------------------------
    //
    // What the company holds, as a list of things rather than a list of quantities. Editing an item
    // is `stock_item.edit`; discontinuing one is `stock_item.manage`, because an item that is still
    // on a shelf somewhere should not disappear from the catalogue on a clerk's afternoon.

    Route::middleware('permission:stock_item.view')->group(function () {
        Route::get('stock-items', [StockItemController::class, 'index'])->name('stock-items.index');
        Route::get('stock-items/{stock_item}', [StockItemController::class, 'show'])
            ->whereNumber('stock_item')->name('stock-items.show');
    });

    Route::get('stock-items/create', [StockItemController::class, 'create'])
        ->middleware('permission:stock_item.create')->name('stock-items.create');
    Route::post('stock-items', [StockItemController::class, 'store'])
        ->middleware('permission:stock_item.create')->name('stock-items.store');

    Route::whereNumber('stock_item')->group(function () {
        Route::get('stock-items/{stock_item}/edit', [StockItemController::class, 'edit'])
            ->middleware('permission:stock_item.edit')->name('stock-items.edit');
        Route::put('stock-items/{stock_item}', [StockItemController::class, 'update'])
            ->middleware('permission:stock_item.edit')->name('stock-items.update');
        Route::delete('stock-items/{stock_item}', [StockItemController::class, 'destroy'])
            ->middleware('permission:stock_item.delete')->name('stock-items.destroy');

        Route::post('stock-items/{stock_item}/activate', [StockItemController::class, 'activate'])
            ->middleware('permission:stock_item.edit')->name('stock-items.activate');
        Route::post('stock-items/{stock_item}/discontinue', [StockItemController::class, 'discontinue'])
            ->middleware('permission:stock_item.manage')->name('stock-items.discontinue');
        Route::post('stock-items/{stock_item}/transition', [StockItemController::class, 'transition'])
            ->middleware('permission:stock_item.edit')->name('stock-items.transition');
    });

    // --- the stock ledger ----------------------------------------------------------------------
    //
    // EVERY VERB HAS ITS OWN URL AND ITS OWN PERMISSION. There is no `stock/movements` taking a
    // `type` field, because that single endpoint would collapse receiving, issuing, transferring
    // and adjusting into one grant, and the whole point of separating them is that they are done by
    // different people for different reasons (§84).
    //
    // NO EDIT ROUTE AND NO DELETE ROUTE, deliberately, matching the model, the policy and the
    // permission catalogue. A movement is corrected by `reverse`, which writes a new row pointing
    // at the old one, and there is no URL that would let anybody do otherwise (§59).
    //
    // `stock.view_value` gates no route of its own — it is asked inside the controller and the
    // views, because it decides which COLUMNS a person sees rather than which pages.

    Route::middleware('permission:stock.view')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock/new/{verb}', [StockController::class, 'create'])
            ->whereIn('verb', ['receive', 'issue', 'return', 'transfer', 'count'])
            ->name('stock.create');
        Route::get('stock/{movement}', [StockController::class, 'show'])
            ->whereNumber('movement')->name('stock.show');
    });

    Route::middleware('permission:stock.create')->group(function () {
        Route::post('stock/receive', [StockController::class, 'receive'])->name('stock.receive');
        Route::post('stock/issue', [StockController::class, 'issue'])->name('stock.issue');
        Route::post('stock/return', [StockController::class, 'returnToStore'])->name('stock.return');
    });

    Route::post('stock/transfer', [StockController::class, 'transfer'])
        ->middleware('permission:stock.transfer')->name('stock.transfer');
    Route::post('stock/count', [StockController::class, 'adjust'])
        ->middleware('permission:stock.adjust')->name('stock.count');
    Route::post('stock/{movement}/reverse', [StockController::class, 'reverse'])
        ->middleware('permission:stock.reverse')
        ->whereNumber('movement')->name('stock.reverse');

    // --- material requests ---------------------------------------------------------------------
    //
    // Asking the store, as opposed to asking the company to buy. `issue` is separated from
    // `approve` for the same four-eyes reason procurement separates ordering from receiving: the
    // person who agreed the site needs it should not also be the person who says it went out.
    // Closing is `material_request.manage` because it releases the reservation and can be done
    // while lines remain unissued.

    Route::middleware('permission:material_request.view')->group(function () {
        Route::get('material-requests', [MaterialRequestController::class, 'index'])
            ->name('material-requests.index');
        Route::get('material-requests/{material_request}', [MaterialRequestController::class, 'show'])
            ->whereNumber('material_request')->name('material-requests.show');
    });

    Route::get('material-requests/create', [MaterialRequestController::class, 'create'])
        ->middleware('permission:material_request.create')->name('material-requests.create');
    Route::post('material-requests', [MaterialRequestController::class, 'store'])
        ->middleware('permission:material_request.create')->name('material-requests.store');

    Route::whereNumber('material_request')->group(function () {
        Route::get('material-requests/{material_request}/edit', [MaterialRequestController::class, 'edit'])
            ->middleware('permission:material_request.edit')->name('material-requests.edit');
        Route::put('material-requests/{material_request}', [MaterialRequestController::class, 'update'])
            ->middleware('permission:material_request.edit')->name('material-requests.update');
        Route::delete('material-requests/{material_request}', [MaterialRequestController::class, 'destroy'])
            ->middleware('permission:material_request.delete')->name('material-requests.destroy');

        Route::post('material-requests/{material_request}/submit', [MaterialRequestController::class, 'submit'])
            ->middleware('permission:material_request.submit')->name('material-requests.submit');

        // Approving and rejecting are one permission: the same person answering the same question
        // either way. The status graph agrees, and the policy defers to it.
        Route::middleware('permission:material_request.approve')->group(function () {
            Route::post('material-requests/{material_request}/approve', [MaterialRequestController::class, 'approve'])
                ->name('material-requests.approve');
            Route::post('material-requests/{material_request}/reject', [MaterialRequestController::class, 'reject'])
                ->name('material-requests.reject');
        });

        Route::post('material-requests/{material_request}/issue', [MaterialRequestController::class, 'issue'])
            ->middleware('permission:material_request.issue')->name('material-requests.issue');
        Route::post('material-requests/{material_request}/close', [MaterialRequestController::class, 'close'])
            ->middleware('permission:material_request.manage')->name('material-requests.close');
        Route::post('material-requests/{material_request}/transition', [MaterialRequestController::class, 'transition'])
            ->middleware('permission:material_request.edit')->name('material-requests.transition');
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
