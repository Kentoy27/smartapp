{{-- THE THREE WFP CHECKPOINTS, COMPACT: one pill per sign-off, with the
     full label and signer carried by the tooltip and aria-label. Shared by
     the sign-off card's table-cell mode and the final-approval queue, so both
     surfaces describe the same saved state from the same markup. --}}
@props(['statuses' => []])

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
