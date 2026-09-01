<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\ClientService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who we work for.
 *
 * THE SHORTEST REGISTER CONTROLLER IN THE APPLICATION, and it should stay that way. A client record
 * cannot lose the company money by being edited — there are no bank details to redirect, no
 * prequalification to lapse and nobody to bar. What it does is give projects and contracts something
 * real to hang off, which is the §90 payoff: `projects.client_id` now points at a record instead of
 * repeating a name in a text column on every job.
 *
 * `assignOwner()` IS THE ONE GUARDED ACT. `relationship_owner_id` is the column
 * Client::accessAssigneeColumns() reads, so handing somebody an account HANDS THEM VISIBILITY of it.
 * Left on the edit form, a user whose scope is "assigned to me" could widen their own reach by
 * naming themselves across the register — which is why it sits behind `client.manage` instead
 * (§84, and see ClientPolicy for the same argument from the other side).
 */
class ClientController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ClientService $clients,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'client';
    }

    protected function modelClass(): string
    {
        return Client::class;
    }

    protected function routePrefix(): string
    {
        return 'clients';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Client::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'relationshipOwner:id,name',
        ])->withCount(['contacts', 'projects']);

        $query->search((string) $request->query('q', ''));

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        foreach (['client_type', 'industry'] as $column) {
            if ($value = (string) $request->query($column, '')) {
                $query->where($column, $value);
            }
        }

        $view = (string) $request->query('view', 'active');

        match ($view) {
            'active' => $query->active(),
            'mine' => $query->where('relationship_owner_id', $user->id),
            default => $query,
        };

        /** @var LengthAwarePaginator $clients */
        $clients = $query->orderBy('name')->paginate(30)->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'statuses' => $this->statuses->optionsFor('client'),
            'types' => $this->lists->for('client.types', Client::DEFAULT_TYPES),
            'industries' => $this->lists->for('client.industries', Client::DEFAULT_INDUSTRIES),
            'views' => [
                'active' => 'Active',
                'mine' => 'My accounts',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
                'client_type' => $request->query('client_type'),
                'industry' => $request->query('industry'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Client::class),
        ]);
    }

    public function show(Request $request, Client $client): View
    {
        $this->authorize('view', $client);

        $user = $request->user();

        $client->loadMissing([
            'status', 'orgUnit', 'creator:id,name', 'relationshipOwner:id,name',
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('name'),
        ]);

        // Both lists are filtered by what the reader may open, not by what the client owns. A
        // relationship manager with no project reach should see the account without seeing a
        // roll-call of every job on it, and a contract is confidential by default (§61, §68).
        $projects = $client->projects()
            ->with('status:id,label,color')
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($project) => $user->can('view', $project))
            ->values();

        $contracts = $client->contracts()
            ->with('status:id,label,color')
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($contract) => $user->can('view', $contract))
            ->values();

        return view('clients.show', [
            'client' => $client,
            'projects' => $projects,
            'contracts' => $contracts,
            'timeline' => $this->activity->timelineFor($client)->limit(25)->get(),
            'transitions' => $this->statuses->available('client', $client->status_id, $user),
            'workflowInstance' => $client->currentWorkflowInstance(),
            'people' => $user->can('assignOwner', $client) ? $this->userOptions($user) : [],
            'canEdit' => $user->can('update', $client),
            'canDelete' => $user->can('delete', $client),
            'canAssignOwner' => $user->can('assignOwner', $client),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Client::class);

        return view('clients.create', [
            'client' => new Client([
                'confidentiality' => Confidentiality::Internal->value,
                'is_active' => true,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $data = $request->clientAttributes();

        $this->assertPlacementIsReachable($data);

        $client = $this->clients->create($data, $request->user());

        return redirect($this->showRoute($client))
            ->with('status', $client->displayName().' has been added to the register.');
    }

    public function edit(Request $request, Client $client): View
    {
        $this->authorize('update', $client);

        return view('clients.edit', [
            'client' => $client,
            ...$this->formOptions($request),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $data = $request->clientAttributes();

        $this->assertPlacementIsReachable($data);

        $this->clients->update($client, $data, $request->user());

        return redirect($this->showRoute($client))
            ->with('status', $client->displayName().' has been updated.');
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $name = $client->displayName();

        $this->recordDeletion($client, $request->user(), 'client');

        $client->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been removed from the register.');
    }

    /**
     * Hand the account to somebody — or to nobody.
     *
     * The user is resolved THROUGH THE TENANT-SCOPED QUERY rather than by findOrFail on the raw id,
     * so an id belonging to another company does not resolve and the request fails closed instead of
     * quietly stitching two organizations together through a foreign key (§75, §84).
     */
    public function assignOwner(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('assignOwner', $client);

        $validated = $request->validate([
            'relationship_owner_id' => ['nullable', 'integer'],
        ]);

        $ownerId = $validated['relationship_owner_id'] ?? null;
        $owner = $ownerId === null ? null : User::query()->find($ownerId);

        if ($ownerId !== null && $owner === null) {
            return back()->withErrors(['relationship_owner_id' => 'That person is not on your organization.']);
        }

        $this->clients->assignOwner($client, $owner, $request->user());

        return back()->with('status', $owner === null
            ? 'The account no longer has a named owner.'
            : $owner->name.' now owns the '.$client->name.' account.');
    }

    public function transition(Request $request, Client $client): RedirectResponse
    {
        return $this->moveStatus($request, $client);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'units' => $this->unitOptions($request->user()),
            'confidentialities' => Confidentiality::options(),
            'types' => $this->lists->for('client.types', Client::DEFAULT_TYPES),
            'industries' => $this->lists->for('client.industries', Client::DEFAULT_INDUSTRIES),
            'nextNumber' => $this->numbers->preview('client'),
        ];
    }
}
