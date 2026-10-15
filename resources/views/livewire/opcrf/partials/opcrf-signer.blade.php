{{-- ONE SIGNER'S SLOT in a "signed after the review" block: the digital
     signature (when there is one to show), then the printed name, then the
     role label — the same stack the workbook itself carries where the form
     is signed.

     Expects: $signer (the analyzer's {ref, role, name}), $keyPrefix (the
     wire:key prefix, $rateeSignature, and $reviewSignatures keyed by normalized
     signer role for signatures added during review. --}}
@php
    $roleKey = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) $signer['role']));
    $signature = $roleKey === 'ratee'
        ? ($rateeSignature ?? null)
        : (($reviewSignatures ?? [])[$roleKey] ?? null);
@endphp
<div
    class="opcrf-sheet-signer {{ $signature !== null ? 'opcrf-sheet-signer--signed' : '' }}"
    wire:key="{{ $keyPrefix }}-{{ $signer['ref'] }}"
>
    @if ($signature !== null)
        <span class="opcrf-sheet-signer-sig">
            <img
                src="{{ $signature['url'] }}"
                alt="Digital signature of {{ $signature['name'] !== '' ? $signature['name'] : 'the ratee' }}"
            >
        </span>
    @endif

    <span class="opcrf-sheet-signer-name {{ $signer['name'] === '' ? 'is-empty' : '' }}">
        {{ $signer['name'] !== '' ? $signer['name'] : '—' }}
    </span>
    <span class="opcrf-sheet-signer-role">{{ $signer['role'] }}</span>
</div>
