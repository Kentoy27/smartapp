<div class="wfp-manager">

    {{-- The Bookkeeper reads this page the way the SH works it, minus the
         writing: the generate and upload controls are the School Head's
         alone, so a read-only reader is told why they are not offered. --}}
    @unless (auth()->user()?->canManageWfp())
        <div class="card">
            <div class="card-head">
                <div class="card-title">WFP — read-only</div>
            </div>
            <p class="card-text">
                Your school’s Work and Financial Plan. The School Head generates and
                uploads the workbook; you read the plan to check the account titles
                and codes on the Monthly Expenditure Matrix and the annual
                Per Line Item Expenditure report.
            </p>
        </div>
    @endunless

    {{-- GENERATE CARD: the page's one action — build the year's workbook and
         download it. The generate button names the source and the year. --}}
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
                </span>
            </div>
            <livewire:wfp-generate-button :key="'wfp-generate-page'" />

            {{-- UPLOAD: beside the download, the School Head hands back
                 their completed workbook. The component validates the file
                 server-side and records one row per school, which is what
                 the superadmin's dashboard list reads. It is the SH's alone,
                 so a read-only reader never mounts it. --}}
            @if (auth()->user()?->canManageWfp())
                <livewire:wfp-upload :key="'wfp-upload-page'" />
            @endif
        </div>
    </div>

    {{-- MY UPLOADS: the School Head's own list of the workbooks they have
         handed back, each with a download. Refreshes on `wfp-uploaded`.
         Only a writing account has uploads of its own to list. --}}
    @if (auth()->user()?->canManageWfp())
        <livewire:wfp-my-uploads-list :key="'wfp-my-uploads-page'" />
    @endif
</div>
