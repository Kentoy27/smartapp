<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Support\OpcrfPartOne;
use App\Support\OpcrfWorkbookTrim;
use App\Support\WfpTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->intended('/home');
    }

    return redirect()->route('login');
});

Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');
Route::get('/logout', function () {
    return redirect()->route('home');
})->middleware('auth');

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('google.redirect')->middleware('guest');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

Route::get('/home', [DashboardController::class, 'index'])->middleware('auth')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/users', function () {
        abort_unless(Auth::user()?->is_superadmin, 404);

        return view('users.index');
    })->name('users.index');

    // Opcrf: a staff-facing page, reachable by regular users only —
    // superadmins have their own tooling, so the item is hidden from them.
    Route::get('/opcrf', function () {
        abort_if(Auth::user()?->is_superadmin, 404);

        return view('opcrf.index');
    })->name('opcrf.index');

    // Review Opcrf: the superadmin's window into every staff submission —
    // read-only review of the submitted OPCR (with a download of the
    // submitted file) and each submission's MOVs as a view-only record.
    Route::get('/opcrf-review', function () {
        abort_unless(Auth::user()?->is_superadmin, 404);

        return view('opcrf.review');
    })->name('opcrf.review');

    // The downloadable OPCRF template (built from storage/forms/, never
    // public/). The path must not collide with a physical directory on
    // disk (the old /forms/… did — the project root has a forms/ folder,
    // so PHP's built-in server answered 404 before Laravel ever ran and
    // browsers saved a broken opcrf-template.htm instead of the file).
    //
    // Term-gated: outside the school year's final term the staff member
    // gets the template rebuilt with only its PART I sheet; inside the
    // window they get the complete workbook (all four parts).
    Route::get('/download/opcrf-template', function () {
        abort_unless(is_file(OpcrfPartOne::templatePath()), 404);

        // The bytes are built before the response is created: a streamed
        // callback must echo its output (its return value is ignored), and
        // anything else risks an empty 0-byte download.
        $bytes = OpcrfPartOne::templateBytes();

        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.OpcrfPartOne::templateDownloadName().'"');
    })->name('opcrf.template');

    // A submission's workbook. The superadmin may fetch any submission
    // (download-and-review is the whole point); the owning staff member may
    // fetch their own once it is approved — the stored file is then the
    // official approved copy.
    //
    // The response is ALWAYS the literal file the staff member uploaded (or
    // the corrected copy the superadmin attached while approving) — never a
    // sheet synthesized from the recorded fields: that would hand the
    // reviewer a plain stand-in instead of the actual OPCRF. Submissions
    // that predate file archiving (or were typed by hand) have no original
    // document, so the route 404s and the review modal says so — the
    // approval panel is where the official copy is attached.
    Route::get('/opcrf-submissions/{submission}/download', function (App\Models\OpcrfSubmission $submission) {
        $user = Auth::user();

        abort_unless($submission->canBeDownloadedBy($user), 404);
        abort_unless($submission->hasFile(), 404);

        $storedPath = Storage::disk('local')->path($submission->file_path);

        // The owner's official copy follows the same part rule as their
        // template: Part I only until the school year's final term. The
        // superadmin's review download is always the archived file as
        // stored — the trimmer needs a real workbook, so anything it
        // cannot read (or a workbook with no PART I tab) is served as-is.
        $bytes = null;

        if (! $user->is_superadmin && ! OpcrfPartOne::windowOpen()) {
            try {
                $bytes = OpcrfWorkbookTrim::keepPart(
                    $storedPath,
                    (string) config('opcrf.part_one.sheet', 'PART I')
                );
            } catch (\RuntimeException) {
                $bytes = null; // fall through to the archived bytes
            }
        }

        // Same rule as the template route: bytes first, then the response —
        // a streamed callback's return value is discarded.
        $bytes = $bytes ?? (string) file_get_contents($storedPath);

        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.$submission->fileDownloadName().'"');
    })->name('opcrf.submission.download')->where('submission', '[0-9]+');

    // MOV evidence files. The owner always downloads their own; a superadmin
    // only for submissions routed to them (or unassigned ones) — routing is
    // the same boundary the review list uses.
    Route::get('/opcrf-movs/{mov}/download', function (App\Models\OpcrfMov $mov) {
        $user = Auth::user();

        abort_if(! $mov->submission->canBeReviewedBy($user), 403);

        abort_unless(Storage::disk('local')->exists($mov->stored_path), 404);

        return Storage::disk('local')->download($mov->stored_path, $mov->original_name);
    })->name('opcrf.movs.download')->where('mov', '[0-9]+');

    /* ------------------------------------------------------------------
     * WFP (Work and Financial Plan) — School Head (SH) only.
     *
     * Every WFP route re-checks Auth::user()->canAccessWfp(): the module,
     * template, and uploaded files are unreachable for the SDS Viewer and
     * the Super Admin even by typing the URL directly (they get a 404). The
     * Livewire components re-check the same gate on every action.
     * ----------------------------------------------------------------- */

    Route::get('/wfp', function () {
        abort_unless(Auth::user()?->canAccessWfp(), 404);

        return view('wfp.index');
    })->name('wfp.index');

    // The official WFP template, served from storage/forms/ (never public/).
    // Bytes first, then the response — a streamed callback's return value is
    // discarded and would risk a 0-byte download.
    Route::get('/download/wfp-template', function () {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
        abort_unless(WfpTemplate::exists(), 404);

        $bytes = WfpTemplate::bytes();

        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.WfpTemplate::fileName().'"');
    })->name('wfp.template');

    // A user's uploaded WFP. Owner-only — the model gate re-checks ownership,
    // so another account's file id is unreachable.
    Route::get('/wfp-uploads/{wfp}/download', function (App\Models\WfpSubmission $wfp) {
        abort_unless(Auth::user()?->canAccessWfp(), 404);
        abort_unless($wfp->canBeManagedBy(Auth::user()), 404);
        abort_unless($wfp->hasFile(), 404);

        return Storage::disk('local')->download($wfp->file_path, $wfp->fileDownloadName());
    })->name('wfp.download')->where('wfp', '[0-9]+');
});