<div class="wfp-generate">

    {{-- ERROR ALERT: popped once when generation fails, then cleared. --}}
    @if ($errorMessage)
        <div
            wire:key="wfp-generate-error-{{ md5($errorMessage) }}"
            x-data
            x-init="
                if (window.smartAlert) {
                    smartAlert.error('WFP could not be generated', {{ \Illuminate\Support\Js::from($errorMessage) }});
                }
                $wire.clearError();
            "
            class="error-alert-sentinel"
            aria-hidden="true"
        ></div>
    @endif

    {{-- GENERATE + DOWNLOAD BUTTON: the school's WFP generated from its Monthly
         Expenditure Matrix when it has one, and the blank account-code form
         until then. --}}
    <button
        type="button"
        class="opcr-download-btn"
        wire:click="download"
        wire:loading.attr="disabled"
        wire:target="download"
        title="{{ $this->planYear !== null
            ? 'Generate your CY '.$this->planYear.' WFP from your Monthly Expenditure Matrix and download it'
            : 'Generate your school\'s WFP from the account-code list and download it' }}"
    >
        <span wire:loading.remove wire:target="download">
            <x-icon name="download" :size="16" />
            <span>Generate and Download WFP</span>
        </span>
        <span wire:loading wire:target="download">
            <x-icon name="file-spreadsheet" :size="16" />
            <span>Generating…</span>
        </span>
    </button>

    {{-- WHICH WORKBOOK: the filled form cannot be produced until the matrix has
         lines, so the caption says what is actually coming. --}}
    @if ($this->planYear !== null)
        <span class="wfp-generate__source">
            Built from your Monthly Expenditure Matrix (CY {{ $this->planYear }}).
        </span>
    @else
        <span class="wfp-generate__source">
            Built from your account-code list — complete the rest by hand.
        </span>
    @endif
</div>
