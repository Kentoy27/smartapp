{{-- WFP SIGNATORY ACTIONS — the three checkpoints (Bookkeeper check, Budget
     certification, final approval) with the sign buttons for the roles that
     may act on them.

     One root element for both renderings, BEFORE any @if: Livewire derives a
     nested child's tag from the first markup in the rendered HTML, and an
     @if first would wrap it in a <!--[if BLOCK]--> comment and break
     re-renders ("Invalid Livewire child tag name").

     · full (default) — the card panel used across full-width surfaces;
     · compact — the narrow stack for a table's Sign-offs column: one pill
       per checkpoint (the tooltip carries the full label) plus the sign
       buttons, so Budget/Bookkeeper can sign straight from the row. --}}
<section
    @class(['wfp-signatory-statuses' => ! $compact, 'wfp-signatory-inline' => $compact])
    aria-label="WFP signatory status"
>
    @if ($successMessage || $errorMessage)
        @if ($compact)
            <div class="wfp-signatory-inline__msg {{ $errorMessage ? 'wfp-signatory-inline__msg--error' : '' }}" role="status">
                {{ $errorMessage ?? $successMessage }}
                <button type="button" class="users-clear" wire:click="clearMessage" title="Dismiss">×</button>
            </div>
        @else
            <div class="{{ $errorMessage ? 'ac-import-error' : 'mem-banner mem-banner--notice' }}" role="status">
                {{ $errorMessage ?? $successMessage }}
                <button type="button" class="users-clear" wire:click="clearMessage" title="Dismiss">×</button>
            </div>
        @endif
    @endif

    @if ($compact)
        <div class="wfp-signatory-inline__pills">
            @foreach ($statuses as $status)
                @php($short = match ($status['key']) {
                    'checking' => 'Check',
                    'certification' => 'Certify',
                    default => 'Approve',
                })
                @php($pillTitle = $status['label'].' — '.($status['name']
                    ? $status['name'].($status['date'] ? ' · '.$status['date']->format('M j, Y') : '')
                    : $status['pending_text']))
                @php($done = in_array($status['state'], ['checked', 'certified', 'approved'], true))

                <span
                    @class([
                        'wfp-signatory-inline__pill',
                        'wfp-signatory-inline__pill--done' => $done,
                        'wfp-signatory-inline__pill--returned' => $status['state'] === 'returned',
                    ])
                    title="{{ $pillTitle }}"
                    aria-label="{{ $short }}: {{ $status['badge'] }}"
                >
                    @if ($done)<x-icon name="check" :size="11" />@endif
                    <span>{{ $short }}</span>
                </span>
            @endforeach
        </div>

        {{-- THE SIGN ACTIONS: the Bookkeeper signs the check and Budget signs
             the certification straight from the row. --}}
        @foreach ($statuses as $status)
            @if ($status['key'] === 'checking' && auth()->user()?->canCheckWfpReport())
                <button
                    type="button"
                    class="allotment-certify"
                    wire:click="signCheck"
                    wire:confirm="Sign the account-title and code check for CY {{ $year }}?"
                    wire:loading.attr="disabled"
                    wire:target="signCheck"
                    @disabled(! $canCheck)
                >
                    <x-icon name="check-check" :size="13" />
                    <span>{{ $checking ? 'Re-sign check' : 'Sign check' }}</span>
                </button>
            @elseif ($status['key'] === 'certification' && auth()->user()?->canCertifyAllotments())
                <button
                    type="button"
                    class="allotment-certify"
                    wire:click="signCertification"
                    wire:confirm="Sign the allotment-availability certification for CY {{ $year }}?"
                    wire:loading.attr="disabled"
                    wire:target="signCertification"
                    @disabled(! $canCertify)
                >
                    <x-icon name="check-check" :size="13" />
                    <span>Sign certification</span>
                </button>
            @endif
        @endforeach
        @if ($hasSubmittablePlan)
            <button
                type="button"
                class="allotment-certify"
                wire:click="submitForApproval"
                wire:confirm="Submit this WFP to the Schools Division Superintendent for final approval?"
                wire:loading.attr="disabled"
                wire:target="submitForApproval"
                @disabled(! $canSubmit)
                title="{{ $canSubmit
                    ? 'Send the current, fully reviewed plan to the Superadmin'
                    : 'Requires a current plan and both Budget sign-offs' }}"
            >
                <x-icon name="send" :size="13" />
                <span>Submit to Superadmin</span>
            </button>
        @endif
    @else
        <div class="card-head">
            <div class="card-title">Signatory Status · CY {{ $year }}</div>
        </div>

        <div class="wfp-signatory-statuses__list">
            @foreach ($statuses as $status)
                <div class="wfp-signatory-status">
                    <div class="wfp-signatory-status__header">
                        <span class="wfp-signatory-status__label">{{ $status['label'] }}</span>
                        <span @class([
                            'wfp-signatory-status__badge',
                            'wfp-signatory-status__badge--pending' => $status['state'] === 'pending',
                            'wfp-signatory-status__badge--checked' => $status['state'] === 'checked',
                            'wfp-signatory-status__badge--certified' => $status['state'] === 'certified',
                            'wfp-signatory-status__badge--approved' => $status['state'] === 'approved',
                            'wfp-signatory-status__badge--returned' => $status['state'] === 'returned',
                        ])>{{ $status['badge'] }}</span>
                    </div>

                    @if ($status['name'])
                        <div class="wfp-signatory-status__meta">{{ $status['name'] }}</div>
                        <div class="wfp-signatory-status__meta">{{ $status['position'] }}</div>
                        @if ($status['date'])
                            <div class="wfp-signatory-status__meta">{{ $status['date']->format('M j, Y g:i A') }}</div>
                        @endif
                    @else
                        <div class="wfp-signatory-status__meta">{{ $status['pending_text'] }}</div>
                    @endif

                    @if ($status['key'] === 'checking' && auth()->user()?->canCheckWfpReport())
                        <button
                            type="button"
                            class="allotment-certify"
                            wire:click="signCheck"
                            wire:confirm="Sign the account-title and code check for CY {{ $year }}?"
                            wire:loading.attr="disabled"
                            wire:target="signCheck"
                            @disabled(! $canCheck)
                        >
                            <x-icon name="check-check" :size="14" />
                            <span>{{ $checking ? 'Re-sign check' : 'Sign check' }}</span>
                        </button>
                    @elseif ($status['key'] === 'certification' && auth()->user()?->canCertifyAllotments())
                        <button
                            type="button"
                            class="allotment-certify"
                            wire:click="signCertification"
                            wire:confirm="Sign the allotment-availability certification for CY {{ $year }}?"
                            wire:loading.attr="disabled"
                            wire:target="signCertification"
                            @disabled(! $canCertify)
                        >
                            <x-icon name="check-check" :size="14" />
                            <span>Sign certification</span>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
        @if ($hasSubmittablePlan)
            <button
                type="button"
                class="allotment-certify"
                wire:click="submitForApproval"
                wire:confirm="Submit this WFP to the Schools Division Superintendent for final approval?"
                wire:loading.attr="disabled"
                wire:target="submitForApproval"
                @disabled(! $canSubmit)
                title="{{ $canSubmit
                    ? 'Send the current, fully reviewed plan to the Superadmin'
                    : 'Requires a current plan and both Budget sign-offs' }}"
            >
                <x-icon name="send" :size="14" />
                <span>Submit to Superadmin</span>
            </button>
        @endif
    @endif
</section>
