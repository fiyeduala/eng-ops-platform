<?php

declare(strict_types=1);

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\LocationController;
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
use App\Http\Controllers\UserController;
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
