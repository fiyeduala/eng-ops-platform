<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OptionListItemRequest;
use App\Http\Requests\OptionListRequest;
use App\Models\OptionList;
use App\Models\OptionListItem;
use App\Services\OptionListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The option-list editor — the company's own words, for everything that is not a status.
 *
 * ONE PAGE PER LIST, WITH ITS OPTIONS ON IT, for the same reason the status editor works that way:
 * a list is nothing but its contents, and an administrator adding "Piling" to Disciplines should
 * not have to navigate to a second screen to find out what is already there.
 *
 * As everywhere in this phase, the rules live in OptionListService and this controller owes the
 * two things a service cannot see: authorization (§6), and the fact that a path segment is not
 * evidence (§84) — an item bound from the URL must belong to the list in the URL, or the audit
 * entry would name one list while another was edited.
 */
class OptionListController extends Controller
{
    public function __construct(
        private readonly OptionListService $service,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', OptionList::class);

        return view('configuration.option-lists.index', [
            'lists' => $this->service->lists(),
            'canManage' => $request->user()->can('create', OptionList::class),
        ]);
    }

    public function show(Request $request, OptionList $optionList): View
    {
        $this->authorize('view', $optionList);

        return view('configuration.option-lists.show', [
            'list' => $optionList,
            'items' => $optionList->items()->get(),
            'canManage' => $request->user()->can('update', $optionList),
        ]);
    }

    public function store(OptionListRequest $request): RedirectResponse
    {
        $this->authorize('create', OptionList::class);

        try {
            $list = $this->service->createList($request->listAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['configuration' => $e->getMessage()]);
        }

        return redirect()->route('configuration.option-lists.show', $list)
            ->with('status', '"'.$list->name.'" has been created. Add the options it should offer.');
    }

    public function update(OptionListRequest $request, OptionList $optionList): RedirectResponse
    {
        $this->authorize('update', $optionList);

        return $this->attempt($optionList, fn () => $this->service
            ->updateList($optionList, $request->listAttributes(), $request->user())
            ->name.' has been updated.');
    }

    /**
     * Delete a list nothing offers.
     *
     * AUTHORIZED ON `update`, NOT `delete`, for the reason set out at length in
     * StatusConfigController::destroy(): both map to `custom_field.manage`, so the authority
     * question is identical, and folding "a field depends on this" into a 403 would answer a
     * question the administrator did not ask. The referential refusal comes back as a sentence.
     */
    public function destroy(Request $request, OptionList $optionList): RedirectResponse
    {
        $this->authorize('update', $optionList);

        $name = $optionList->name;

        try {
            $this->service->deleteList($optionList, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['configuration' => $e->getMessage()]);
        }

        return redirect()->route('configuration.option-lists.index')
            ->with('status', '"'.$name.'" has been deleted.');
    }

    // ------------------------------------------------------------------ options

    public function storeItem(OptionListItemRequest $request, OptionList $optionList): RedirectResponse
    {
        $this->authorize('update', $optionList);

        return $this->attempt($optionList, fn () => '"'.$this->service
            ->createItem($optionList, $request->itemAttributes(), $request->user())
            ->label.'" has been added.');
    }

    public function updateItem(
        OptionListItemRequest $request,
        OptionList $optionList,
        OptionListItem $item,
    ): RedirectResponse {
        $this->authorize('update', $optionList);

        $this->sameList($optionList, $item);

        return $this->attempt($optionList, fn () => '"'.$this->service
            ->updateItem($item, $request->itemAttributes(), $request->user())
            ->label.'" has been updated.');
    }

    public function destroyItem(Request $request, OptionList $optionList, OptionListItem $item): RedirectResponse
    {
        $this->authorize('update', $optionList);

        $this->sameList($optionList, $item);

        $label = $item->label;

        return $this->attempt($optionList, function () use ($item, $request, $label) {
            $this->service->deleteItem($item, $request->user());

            return '"'.$label.'" has been removed.';
        });
    }

    public function reorderItems(Request $request, OptionList $optionList): RedirectResponse
    {
        $this->authorize('update', $optionList);

        $validated = $request->validate([
            'order' => ['required', 'array', 'max:500'],
            'order.*' => ['integer'],
        ]);

        return $this->attempt($optionList, function () use ($optionList, $validated, $request) {
            $this->service->reorderItems($optionList, $validated['order'], $request->user());

            return 'The order has been saved.';
        });
    }

    // ------------------------------------------------------------------ plumbing

    /**
     * Run a write and turn a refusal into something an administrator can act on.
     *
     * Deliberately without withInput(), for the reason given in StatusConfigController: this page
     * carries one form per option plus an "add" form, all sharing field names, so repopulating
     * from old() would push the rejected row's text into every other row on the screen.
     *
     * @param  callable():string  $write
     */
    private function attempt(OptionList $list, callable $write): RedirectResponse
    {
        try {
            $message = $write();
        } catch (\DomainException $e) {
            return back()->withErrors(['configuration' => $e->getMessage()]);
        }

        return redirect()->route('configuration.option-lists.show', $list)->with('status', $message);
    }

    /**
     * The bound option has to belong to the list in the URL.
     *
     * Both are this company's — model binding saw to that — but "this company's" is not the
     * question. Without this, an id from the Disciplines list pasted into the Incident Causes URL
     * would be edited by somebody working on incident causes, and the activity entry would name
     * the wrong list while it happened (§84).
     */
    private function sameList(OptionList $list, OptionListItem $item): void
    {
        if ((int) $item->option_list_id !== (int) $list->getKey()) {
            throw new NotFoundHttpException;
        }

        // Having just proved they belong together, hand the item its list rather than letting the
        // service reach for it. Two models bound from one URL are one query, not two, and under
        // preventLazyLoading() the second would not be a query at all — it would be an exception.
        $item->setRelation('list', $list);
    }
}
