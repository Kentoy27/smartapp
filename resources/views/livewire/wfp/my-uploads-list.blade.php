{{-- MY WFP UPLOADS: the School Head's own list at the foot of /wfp — the Work
     and Financial Plan workbooks they have handed back, newest first.

     The school-side counterpart of the superadmin's "WFP Uploads" card:
     scoped to the signed-in School Head's own uploads, each with a download
     that serves their archived copy. Refreshes on the `wfp-uploaded` event, so
     a workbook uploaded a moment ago lands here without a reload. --}}
<div class="card wfp-uploads-list wfp-my-uploads">
    <div class="card-head">
        <div class="card-title">My WFP Uploads</div>
        <span class="wfp-uploads-count">
            {{ $this->submissions()->count() }} {{ $this->submissions()->count() === 1 ? 'upload' : 'uploads' }}
        </span>
    </div>

    @if ($this->submissions()->isEmpty())
        <p class="card-text">
            No WFP uploads yet. Generate and download your WFP above, complete
            it, then upload it back — it will be listed here with a download
            link.
        </p>
    @else
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th>School year</th>
                        <th>Size</th>
                        <th>Uploaded</th>
                        <th class="users-actions-col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->submissions() as $submission)
                        <tr wire:key="wfp-my-upload-{{ $submission->id }}">
                            <td>
                                <span class="cell-strong">{{ $submission->original_file_name }}</span>
                                @if ($submission->status !== null)
                                    <span class="cell-sub">{{ ucfirst($submission->status) }}</span>
                                @endif
                            </td>
                            <td>{{ $submission->school_year ?? '—' }}</td>
                            <td>{{ $submission->human_size }}</td>
                            <td>{{ $submission->uploaded_at?->format('M j, Y g:i A') ?? '—' }}</td>
                            <td class="users-actions-col">
                                @if ($submission->hasFile())
                                    <a
                                        href="{{ route('wfp.submission.download', $submission) }}"
                                        class="btn-ghost"
                                        title="Download your WFP"
                                    >
                                        <x-icon name="download" :size="14" />
                                        <span>Download</span>
                                    </a>
                                @else
                                    <span class="cell-sub">File missing</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
