<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\TrainingCourseRequest;
use App\Models\TrainingCourse;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The course catalogue — configuration, like the permit types beside it.
 *
 * `validity_months` IS THE ONLY FIELD THAT DOES ANY WORK HERE, and it does it exactly once. It is
 * what turns a completion date into an expiry date, and the expiry date is the only reason anybody
 * opens the training register. TrainingCourse::expiryFrom() is applied at the moment a certificate
 * is recorded and the result is stored on that record, so shortening a course's validity from three
 * years to two says something about the courses run from today — it does not re-date the tickets
 * people are already carrying. That is why every write here folds onto `hse_training.manage`
 * (§59: the fact on somebody's card is not an administrator's to revise).
 *
 * NULL VALIDITY MEANS "NEVER EXPIRES", NOT "UNDECIDED". An induction does not lapse. The form must
 * say so plainly, because the alternative reading turns the sixty-day expiry report into noise.
 *
 * `is_mandatory` IS A REPORTING FLAG AND NOTHING REFUSES WORK BECAUSE OF IT. A platform that
 * blocked a person from being named on a permit for want of a ticket would be worked around inside
 * a week, and the workaround would be worse than the gap. The register's job is to make the gap
 * visible to the supervisor who decides.
 */
class TrainingCourseController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly OptionList $lists,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrainingCourse::class);

        $user = $request->user();

        $courses = $this->compiler
            ->apply(TrainingCourse::query(), $user, 'hse_training.view')
            ->when(
                ($category = (string) $request->query('category', '')) !== '',
                fn ($q) => $q->where('category', $category),
            )
            ->withCount('records')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return view('training_courses.index', [
            'courses' => $courses,
            'categories' => $this->categoryOptions(),
            'filters' => ['category' => $request->query('category')],
            'canManage' => $user->can('create', TrainingCourse::class),
        ]);
    }

    public function show(Request $request, TrainingCourse $trainingCourse): View
    {
        $this->authorize('view', $trainingCourse);

        $user = $request->user();

        return view('training_courses.show', [
            'course' => $trainingCourse,
            'recordCount' => $trainingCourse->records()->count(),
            'timeline' => $this->activity->timelineFor($trainingCourse)->limit(20)->get(),
            'canEdit' => $user->can('update', $trainingCourse),
            'canDelete' => $user->can('delete', $trainingCourse),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', TrainingCourse::class);

        return view('training_courses.create', [
            'course' => new TrainingCourse([
                'is_active' => true,
                'is_mandatory' => false,
                'validity_months' => 36,
            ]),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(TrainingCourseRequest $request): RedirectResponse
    {
        $this->authorize('create', TrainingCourse::class);

        $user = $request->user();

        $course = new TrainingCourse($request->courseAttributes());
        $course->organization_id = $user->organization_id;
        $course->save();

        $this->activity->record(
            $course,
            'created',
            $user->name.' added the '.$course->displayName().' course.',
            ['validity_months' => $course->validity_months],
            $user,
        );

        return redirect()->route('training-courses.show', $course)
            ->with('status', $course->displayName().' has been added.');
    }

    public function edit(Request $request, TrainingCourse $trainingCourse): View
    {
        $this->authorize('update', $trainingCourse);

        return view('training_courses.edit', [
            'course' => $trainingCourse,
            'categories' => $this->categoryOptions(),
        ]);
    }

    /**
     * Amend the course.
     *
     * A change to `validity_months` is recorded explicitly, both figures, because it is the one
     * change on this screen that anybody will later want to date. Nothing already issued moves —
     * but from here on, every certificate recorded against this course gets a different expiry, and
     * "why do the 2027 tickets run out sooner than the 2025 ones" deserves an answer in the log
     * rather than a guess.
     */
    public function update(TrainingCourseRequest $request, TrainingCourse $trainingCourse): RedirectResponse
    {
        $this->authorize('update', $trainingCourse);

        $before = $trainingCourse->validity_months;

        $trainingCourse->fill($request->courseAttributes())->save();

        $context = [];

        if ((int) $before !== (int) $trainingCourse->validity_months) {
            $context = [
                'validity_months_before' => $before,
                'validity_months_after' => $trainingCourse->validity_months,
                'applies_to' => 'certificates recorded from now on',
            ];
        }

        $this->activity->record(
            $trainingCourse,
            'updated',
            $request->user()->name.' updated the '.$trainingCourse->displayName().' course.',
            $context,
            $request->user(),
        );

        return redirect()->route('training-courses.show', $trainingCourse)
            ->with('status', $trainingCourse->displayName().' has been updated.');
    }

    public function destroy(Request $request, TrainingCourse $trainingCourse): RedirectResponse
    {
        $this->authorize('delete', $trainingCourse);

        $name = $trainingCourse->displayName();

        $this->activity->record(
            $trainingCourse,
            'deleted',
            $request->user()->name.' deleted the unused '.$name.' course.',
            [],
            $request->user(),
        );

        $trainingCourse->delete();

        return redirect()->route('training-courses.index')
            ->with('status', $name.' has been deleted.');
    }

    /** @return array<string,string> */
    private function categoryOptions(): array
    {
        return $this->lists->for('training_course.categories', TrainingCourse::DEFAULT_CATEGORIES);
    }
}
