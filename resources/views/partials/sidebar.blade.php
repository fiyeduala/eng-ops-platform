@php
    /** @var \App\Models\User $me */
    $me = auth()->user();
    $sections = app(\App\Support\Navigation::class)->for($me);
    $organization = $me->organization;
@endphp

{{--
    The sidebar shows only what the viewer holds permission for (§96). That is an affordance, not
    the authorization — every route behind these links re-checks server-side (§6, §84).
--}}
<aside x-cloak
       :class="mobileNav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
       class="fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col bg-shell-900 transition-transform duration-200 lg:static lg:translate-x-0">

    <div class="flex h-14 items-center gap-2.5 border-b border-shell-800 px-4">
        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">
            {{ mb_strtoupper(mb_substr($organization?->name ?? 'E', 0, 1)) }}
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-white">{{ $organization?->name ?? config('app.name') }}</p>
            <p class="truncate text-[11px] text-slate-500">{{ $organization?->industry }}</p>
        </div>
        <button type="button" @click="mobileNav = false"
                class="rounded-md p-1 text-slate-400 hover:text-white lg:hidden" aria-label="Close navigation">
            <x-icon name="x" class="h-4 w-4"/>
        </button>
    </div>

    <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4" aria-label="Main">
        @foreach ($sections as $section)
            <div>
                @if ($section['label'] !== '')
                    <p class="nav-heading">{{ $section['label'] }}</p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($section['items'] as $item)
                        <li>
                            <a href="{{ $item['url'] }}"
                               @if ($item['active']) aria-current="page" @endif
                               class="nav-link {{ $item['active'] ? 'nav-link-active' : '' }}">
                                <x-icon :name="$item['icon']" class="h-4 w-4 shrink-0"/>
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-shell-800 p-3">
        <a href="{{ route('profile.show') }}" class="nav-link">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-shell-700 text-[11px] font-semibold text-slate-200">
                {{ $me->initials() }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-slate-200">{{ $me->name }}</span>
                <span class="block truncate text-[11px] text-slate-500">{{ $me->positionTitle() ?? 'No position set' }}</span>
            </span>
        </a>
    </div>
</aside>
