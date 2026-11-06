<?php

namespace App\Http\Controllers;

use App\Models\AipPlan;
use App\Models\User;
use App\Support\AipXlsxExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AipUploadController extends Controller
{
    public function submitDraft(AipPlan $plan): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->managedBy($user), 404);
        abort_unless($plan->status === AipPlan::STATUS_DRAFT, 404);

        DB::transaction(fn () => $plan->submit($user));

        return redirect()
            ->route('aip.index')
            ->with('status', $plan->label().' submitted and is Pending Review.');
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessAip(), 404);

        $path = storage_path('forms/FY-2026-ANNUAL-IMPLEMENTATION-PLAN-1.docx');
        abort_unless(is_file($path), 404);

        return Response::download($path, 'AIP-Blank-Template.docx');
    }

    public function downloadPlan(AipPlan $plan): HttpResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->managedBy($user), 404);

        $bytes = AipXlsxExporter::bytes($plan, $user);

        return Response::make($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.AipXlsxExporter::downloadName($plan).'"',
        ]);
    }

    public function preview(AipPlan $plan)
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->managedBy($user), 404);

        $rows = $plan->form_data['rows'] ?? [];

        return view('aip.preview', [
            'plan' => $plan->load('school'),
            'rows' => is_array($rows) ? $rows : [],
        ]);
    }

    public function downloadSource(AipPlan $plan): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->managedBy($user), 404);
        abort_unless(
            is_string($plan->source_file_path)
                && Storage::disk('local')->exists($plan->source_file_path),
            404,
        );

        return Storage::disk('local')->download(
            $plan->source_file_path,
            $plan->original_file_name ? basename($plan->original_file_name) : basename($plan->source_file_path),
        );
    }
}
