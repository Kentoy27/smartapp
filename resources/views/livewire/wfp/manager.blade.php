<div class="wfp-manager">

    {{-- GENERATE CARD: the page's one action — build the workbook from the
         account-code list and download it. --}}
    <div class="card">
        <div class="card-head">
            <div class="card-title">WFP Template</div>
        </div>
        <div class="opcr-template-row">
            <span class="opcr-file-badge" aria-hidden="true">
                <x-icon name="file-spreadsheet" :size="22" />
            </span>
            <div class="opcr-file-meta">
                <span class="opcr-file-name">{{ $fileName }}</span>
                <span class="opcr-file-sub">
                    {{ $templateDescription }}
                    @if ($accountCount > 0)
                        · {{ $accountCount }} account code{{ $accountCount === 1 ? '' : 's' }} will be written
                    @endif
                </span>
            </div>
            <livewire:wfp-generate-button :key="'wfp-generate-page'" />

            {{-- UPLOAD: beside the download, the School Head hands back
                 their completed workbook. The component validates the file
                 server-side and records one row per school, which is what
                 the superadmin's dashboard list reads. --}}
            <livewire:wfp-upload :key="'wfp-upload-page'" />
        </div>
    </div>
</div>
