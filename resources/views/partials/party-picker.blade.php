{{--
    The counterparty on a register that has one: an RFI, a submittal, a transmittal, a contract.

    A NAME PLUS AN OPTIONAL LINK, never a link alone. Half the parties a real engineering company
    corresponds with are not on any register — a regulator, a joint-venture partner, a specialist
    consultant engaged for one report — and a form that insists on picking a record is a form people
    route around by inventing a "Miscellaneous" client. So the typed name is always available and
    always sufficient; the picker is an upgrade that makes "everything outstanding with Kestrel" a
    real question instead of a text search over however people spelled it.

    THE UNUSED SELECT IS DISABLED, NOT MERELY HIDDEN. A hidden select still posts its value, so
    switching from Client to Vendor with `x-show` alone would submit both ids and let the server
    take whichever arrived last. `x-bind:disabled` takes the field out of the submission entirely.

    Each list is scoped by its own register's view permission in RegisterController::partyOptions().
    A user holding neither permission gets the free-text field alone, which is exactly right.

    Expects: $typeName, $idName, $nameName, $label, $record, $clients, $vendors.
    Optional: $units (transmittals only), $help.
--}}
@php
    $units ??= [];
    $required ??= false;
    $currentType = old($typeName, $record?->getAttribute($typeName));
    $currentId = old($idName, $record?->getAttribute($idName));
    $currentName = old($nameName, $record?->getAttribute($nameName));
    $canLink = filled($clients) || filled($vendors) || filled($units);
@endphp

<div class="space-y-3" x-data="{ partyType: @js((string) ($currentType ?? '')) }">
    <x-field :label="$label" :name="$nameName" :help="$help ?? null" :required="$required">
        <x-input :name="$nameName" :value="$currentName" :required="$required" maxlength="200"
            :placeholder="$placeholder ?? 'Who it is addressed to'" />
    </x-field>

    @if ($canLink)
        <div class="grid gap-3 sm:grid-cols-2">
            <x-field label="Link to a register" :name="$typeName">
                <select name="{{ $typeName }}" x-model="partyType" class="input">
                    <option value="">Not linked</option>
                    @if (filled($clients))
                        <option value="client">Client</option>
                    @endif
                    @if (filled($vendors))
                        <option value="vendor">Vendor or subcontractor</option>
                    @endif
                    @if (filled($units))
                        <option value="org_unit">A unit of this company</option>
                    @endif
                </select>
            </x-field>

            <x-field label="Which one" :name="$idName">
                <div x-show="partyType === ''" x-cloak>
                    <p class="pt-2 text-sm text-slate-500 dark:text-slate-400">
                        The typed name above is enough.
                    </p>
                </div>

                @foreach (['client' => $clients, 'vendor' => $vendors, 'org_unit' => $units] as $key => $options)
                    @if (filled($options))
                        <div x-show="partyType === @js($key)" x-cloak>
                            <select name="{{ $idName }}" class="input"
                                x-bind:disabled="partyType !== @js($key)">
                                <option value="">Choose…</option>
                                @foreach ($options as $id => $name)
                                    <option value="{{ $id }}"
                                        @selected((string) $currentType === $key && (int) $currentId === (int) $id)>
                                        {{ $name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                @endforeach
            </x-field>
        </div>
    @endif
</div>
