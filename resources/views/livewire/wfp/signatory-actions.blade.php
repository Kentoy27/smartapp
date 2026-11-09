<section class="wfp-signatory-statuses" aria-label="WFP signatory status">
    @if ($successMessage || $errorMessage)
        <div class="{{ $errorMessage ? 'ac-import-error' : 'mem-banner mem-banner--notice' }}" role="status">
            {{ $errorMessage ?? $successMessage }}
            <button type="button" class="users-clear" wire:click="clearMessage" title="Dismiss">×</button>
        </div>
    @endif

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
                        'wfp-signatory-status__badge--pending' => in_array($status['state'], ['pending', 'returned'], true),
                        'wfp-signatory-status__badge--checked' => $status['state'] === 'checked',
                        'wfp-signatory-status__badge--certified' => $status['state'] === 'certified',
                        'wfp-signatory-status__badge--approved' => $status['state'] === 'approved',
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
</section>
