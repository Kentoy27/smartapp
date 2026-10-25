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

    <div class="wfp-generate__controls">
        {{-- YEAR: which planning year's WFP to generate. The years offered are
             the school's own — its plans and the years its matrices carry lines
             for — so the form is always built from that year's data, never
             another year's numbers under this year's title. --}}
        <label class="users-per-page">
            <span>Year</span>
            <select wire:model.live="year" aria-label="Planning year to generate the WFP for">
                @foreach ($this->years as $option)
                    <option value="{{ $option }}">CY {{ $option }}</option>
                @endforeach
            </select>
        </label>

        {{-- GENERATE + DOWNLOAD BUTTON: the school's WFP generated from its
             Monthly Expenditure Matrix when it has one, and the blank
             account-code form until then. Generating is the School Head's
             alone, so a read-only reader (the school's Bookkeeper) gets the
             button disabled rather than a dead click. --}}
        @if ($this->canEdit())
            <button
                type="button"
                class="opcr-download-btn"
                wire:click="download"
                wire:loading.attr="disabled"
                wire:target="download"
                title="{{ $this->planYear !== null
                    ? 'Generate your CY '.$this->planYear.' WFP from your Monthly Expenditure Matrix and download it'
                    : 'Generate a blank CY '.$this->year.' WFP from the account-code list and download it' }}"
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
        @else
            <button
                type="button"
                class="opcr-download-btn"
                disabled
                title="Read-only — only the School Head generates the WFP"
            >
                <x-icon name="download" :size="16" />
                <span>Generate and Download WFP</span>
            </button>
        @endif
    </div>

    {{-- WHICH WORKBOOK: the filled form cannot be produced until the matrix has
         lines, so the caption says what is actually coming. --}}
    @if ($this->planYear !== null)
        <span class="wfp-generate__source">
            Built from your Monthly Expenditure Matrix (CY {{ $this->planYear }}).
        </span>
    @else
        <span class="wfp-generate__source">
            Built from your {{ $this->accountCount }} account code{{ $this->accountCount === 1 ? '' : 's' }} — complete the rest by hand.
        </span>
    @endif
</div>
