@if (session('status'))
    <div x-data="{ show: true }" x-show="show" x-transition
         class="mb-4 flex items-start gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200">
        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0"/>
        <p class="flex-1">{{ session('status') }}</p>
        <button type="button" @click="show = false" class="shrink-0 opacity-60 hover:opacity-100" aria-label="Dismiss">
            <x-icon name="x" class="h-4 w-4"/>
        </button>
    </div>
@endif

@if (session('warning'))
    <div class="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
        <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
        <p class="flex-1">{{ session('warning') }}</p>
    </div>
@endif

{{--
    Only the summary appears here; each field renders its own message next to the input. Showing
    the same errors twice trains people to ignore both.
--}}
@if ($errors->any() && $errors->count() > 1)
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200">
        <div class="flex items-center gap-2 font-medium">
            <x-icon name="alert" class="h-4 w-4 shrink-0"/>
            {{ trans_choice('There is :count problem with this form.|There are :count problems with this form.', $errors->count(), ['count' => $errors->count()]) }}
        </div>
    </div>
@endif
