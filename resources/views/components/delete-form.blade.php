@props(['action', 'label' => 'Delete', 'confirm' => 'This cannot be undone. Continue?'])

{{--
    A destructive action, behind a two-step confirmation.

    The confirmation is a courtesy. The server re-authorizes through a policy and re-checks the
    referential guards, because a POST can be replayed without ever rendering this markup (§84).
--}}

<form method="POST" action="{{ $action }}" x-data="{ armed: false }"
      @submit="if (!armed) { $event.preventDefault(); armed = true; }">
    @csrf
    @method('DELETE')

    <button type="submit" class="btn btn-sm" :class="armed ? 'btn-danger' : 'btn-secondary'">
        <x-icon name="trash" class="h-3.5 w-3.5"/>
        <span x-text="armed ? @js($confirm) : @js($label)">{{ $label }}</span>
    </button>
</form>
