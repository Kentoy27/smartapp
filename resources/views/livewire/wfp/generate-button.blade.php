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

        {{-- BOTH EXPORTS use this same selected year and generation path. --}}
        @if ($this->canEdit())
            @php($missingSignatures = $this->missingSignatureLabels)
            <div class="wfp-generate__exports">
                <button
                    type="button"
                    class="opcr-download-btn"
                    wire:click="download('with')"
                    @if ($missingSignatures !== [])
                        wire:confirm="These WFP sections have no applicable registered e-signature and will remain unsigned: {{ implode('; ', $missingSignatures) }}. Continue with the available signatures?"
                    @endif
                    wire:loading.attr="disabled"
                    wire:target="download"
                    title="{{ $this->planYear !== null
                        ? 'Generate your CY '.$this->planYear.' WFP from your Monthly Expenditure Matrix'
                        : 'Generate a blank CY '.$this->year.' WFP from the account-code list' }}"
                >
                    <span wire:loading.remove wire:target="download">
                        <x-icon name="download" :size="16" />
                        <span>Generate and Download WFP (With E-Signatures)</span>
                    </span>
                    <span wire:loading wire:target="download">
                        <x-icon name="file-spreadsheet" :size="16" />
                        <span>Generating…</span>
                    </span>
                </button>
                <button
                    type="button"
                    class="btn-ghost"
                    wire:click="download('without')"
                    wire:loading.attr="disabled"
                    wire:target="download"
                    title="Generate the same WFP with its electronic-signature spaces left blank"
                >
                    <span wire:loading.remove wire:target="download">
                        <x-icon name="download" :size="16" />
                        <span>Generate and Download WFP (Without E-Signatures)</span>
                    </span>
                    <span wire:loading wire:target="download">
                        <x-icon name="file-spreadsheet" :size="16" />
                        <span>Generating…</span>
                    </span>
                </button>
            </div>
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

    @if (auth()->user()?->school_id)
        @livewire('wfp-signatory-actions', [
            'schoolId' => auth()->user()->school_id,
            'year' => (int) $this->year,
        ], key('wfp-generate-signoffs-'.auth()->user()->school_id.'-'.$this->year))
    @endif
</div>
