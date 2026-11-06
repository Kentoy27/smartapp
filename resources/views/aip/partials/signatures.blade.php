@php($submitterSignature = $plan->submitterSignature())
@php($reviewerSignatures = $plan->reviewerSignatures())
<div class="aip-template-signatures aip-template-signatures--review">
    <div class="aip-template-signature aip-template-signature--review">
        <span class="aip-template-signature__label">Prepared by:</span>
        <div class="aip-template-signature__image">
            @if ($submitterSignature)
                <img class="aip-review-signature" src="{{ $submitterSignature['url'] }}" alt="Digital signature of {{ $submitterSignature['name'] }}">
            @endif
        </div>
        <span class="aip-review-signature-date">{{ $submitterSignature && $submitterSignature['signed_at'] !== '' ? 'Submitted '.$submitterSignature['signed_at'] : '' }}</span>
        <strong class="aip-template-signature__name">{{ $submitterSignature['name'] ?? $plan->creator?->name }}</strong>
        <span>{{ ($submitterSignature['position'] ?? null) ?: ($plan->creator?->position ?: 'Position/Designation') }}</span>
    </div>
    @foreach (['reviewed_by' => 'Reviewed by:', 'recommending_approval' => 'Recommending Approval', 'approved_by' => 'Approved:'] as $slot => $label)
        @php($signatory = config('aip.template.'.$slot))
        <div class="aip-template-signature aip-template-signature--review">
            <span class="aip-template-signature__label">{{ $label }}</span>
            <div class="aip-template-signature__image">
                @if (isset($reviewerSignatures[$slot]))
                    <img class="aip-review-signature" src="{{ $reviewerSignatures[$slot]['url'] }}" alt="Digital signature of {{ $reviewerSignatures[$slot]['name'] }}">
                @endif
            </div>
            <span class="aip-review-signature-date">{{ $reviewerSignatures[$slot]['signed_at'] ?? '' }}</span>
            <strong class="aip-template-signature__name">{{ $signatory['name'] }}</strong>
            <span>{{ $signatory['role'] }}</span>
        </div>
    @endforeach
</div>
