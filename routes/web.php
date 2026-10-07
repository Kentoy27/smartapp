<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Support\OpcrfPartOne;
use App\Support\OpcrfTemplatePersonalizer;
use App\Support\OpcrfWorkbookTrim;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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

    // Districts & Schools: the superadmin's page for the org structure —
    // every district with its schools, and the manager that adds, renames and
    // removes them. The sidebar item points here; the dashboard keeps its own
    // summary card, and both render the same DistrictList component.
    Route::get('/districts', function () {
        abort_unless(Auth::user()?->is_superadmin, 404);

        return view('districts.index');
    })->name('districts.index');

    // Opcrf: a staff-facing page, reachable by regular users only —
    // superadmins have their own tooling, so the item is hidden from them.
    // This is the parts grid: every Part is listed with the state the
    // superadmin's schedule puts it in, open or locked.
    Route::get('/opcrf', function () {
        abort_if(Auth::user()?->is_superadmin, 404);

        return view('opcrf.parts');
    })->name('opcrf.index');

    // One OPCRF Part. Guarded server-side by the schedule: a Part outside its
    // access window (not yet open, closed, or disabled) redirects back to the
    // grid with the reason, and a Part already submitted stays readable after
    // the deadline passes. Part 1 carries the form; 2-4 open on their own
    // schedules.
    Route::get('/opcrf/part/{part}', function (int $part) {
        $blurbs = [
            1 => 'Your commitments, targets and accomplishments — pre-filled with your registered details.',
            2 => 'Leadership and core behavioural competencies.',
            3 => 'The summary of ratings and the agreement block.',
            4 => 'The office improvement plan and your individual development plan.',
        ];

        return view('opcrf.part', [
            'part' => $part,
            'partLabel' => 'Part '.$part,
            'partBlurb' => $blurbs[$part] ?? 'This Part of the OPCRF.',
            'schedule' => App\Support\OpcrfAccess::scheduleFor(
                App\Support\OpcrfAccess::currentYear(),
                $part
            ),
            'notice' => session('opcrfPartNotice'),
        ]);
    })->name('opcrf.part')->where('part', '[1-4]')->middleware('opcrf.part');

    // OPCRF SCHEDULE: the superadmin's calendar. Part access is decided from
    // these rows on every request, so this page is the control for it.
    Route::get('/opcrf-schedule', function () {
        abort_unless(Auth::user()?->is_superadmin, 404);

        return view('opcrf.schedule');
    })->name('opcrf.schedule');

    // Review Opcrf: the superadmin's window into every staff submission —
    // read-only review of the submitted OPCR (with a download of the
    // submitted file) and each submission's MOVs as a view-only record.
    Route::get('/opcrf-review', function () {
        abort_unless(Auth::user()?->is_superadmin, 404);

        return view('opcrf.review');
    })->name('opcrf.review');

    // The staff member's PERSONALIZED OPCRF template (built from
    // storage/forms/, never public/). The path must not collide with a
    // physical directory on disk (the old /forms/… did — the project root
    // has a forms/ folder, so PHP's built-in server answered 404 before
    // Laravel ever ran and browsers saved a broken opcrf-template.htm
    // instead of the file).
    //
    // The workbook is the official template with the account's own identity
    // written into its header block — the registered name, position, school
    // and division — and nothing else changed: same structure, styles,
    // merges and formulas, so the analyzer still reads it as the real form.
    // Term-gated as before (Part I only outside the school's final term).
    //
    // Staff only: a superadmin has no OPCRF of their own to fill in, and
    // their review downloads serve a submission's archived workbook instead.
    Route::get('/download/opcrf-template', function () {
        $user = Auth::user();

        abort_if($user?->is_superadmin, 404);
        abort_unless(is_file(OpcrfPartOne::templatePath()), 404);

        // The personalized template IS Part 1, so it answers to Part 1's
        // schedule. Re-checked here, on the server: the dashboard's button is
        // only a convenience, and a staff member outside Part 1's window must
        // not be able to fetch the form by typing the URL.
        if (! App\Support\OpcrfAccess::canAccess($user, App\Models\OpcrfSchedule::PART_ONE)) {
            $state = App\Support\OpcrfAccess::partState($user, App\Models\OpcrfSchedule::PART_ONE);

            return redirect()
                ->route('opcrf.index')
                ->with('opcrfPartNotice', [
                    'status' => $state['status'],
                    'message' => $state['message'],
                    'detail' => $state['detail'],
                ]);
        }

        try {
            // The bytes are built before the response is created: a streamed
            // callback must echo its output (its return value is ignored),
            // and anything else risks an empty 0-byte download.
            $bytes = OpcrfTemplatePersonalizer::personalizeFor($user);
        } catch (\RuntimeException $e) {
            // The shipped template is missing or unreadable: say so rather
            // than serving a 0-byte workbook the user cannot open.
            abort(404, $e->getMessage());
        }

        $filename = OpcrfTemplatePersonalizer::downloadNameFor($user);

        // Recorded so the upload that follows can be traced back to the
        // exact template it was answered on, and the dashboard can show
        // what was generated and when.
        App\Models\OpcrfTemplate::record($user, App\Models\OpcrfTemplate::currentVersion(), $filename);

        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
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

    // MOV uploads: the staff member's Means of Verification checklist —
    // Part → Category → MOV → the document they attached to it. A separate
    // page from the OPCR upload on purpose: this is the standing evidence
    // set, not the cycle's form.
    Route::get('/movs', function () {
        abort_if(Auth::user()?->is_superadmin, 404);

        return view('mov.index');
    })->name('mov.index');

    // A MOV document, opened in the browser. Images and PDFs render
    // inline; anything else (a .docx) is handed over as a download, because
    // no browser can display it.
    Route::get('/movs/{mov}/view', function (App\Models\UserMov $mov) {
        $disk = Storage::disk(config('mov.disk', 'local'));

        abort_unless($mov->canBeAccessedBy(Auth::user()), 403);
        abort_unless($mov->fileExists(), 404);

        $inline = str_starts_with((string) $mov->mime_type, 'image/')
            || $mov->mime_type === 'application/pdf'
            // Some upload paths report a generic type for a PDF, so the
            // name is consulted too: this only decides whether the browser
            // previews the file or downloads it.
            || in_array(
                strtolower(pathinfo((string) $mov->original_name, PATHINFO_EXTENSION)),
                ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
                true,
            );

        $response = $disk->response($mov->stored_path, $mov->downloadName());

        // The streamed/binary response defaults to "attachment" and ignores
        // a plain header, so the disposition is rebuilt here.
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $mov->downloadName(),
            ),
        );

        return $response;
    })->name('mov.view')->where('mov', '[0-9]+');

    // The same file as a download. Owner or superadmin only — the storage
    // path is never exposed and never lives under a public URL.
    Route::get('/movs/{mov}/download', function (App\Models\UserMov $mov) {
        $disk = Storage::disk(config('mov.disk', 'local'));

        abort_unless($mov->canBeAccessedBy(Auth::user()), 403);
        abort_unless($mov->fileExists(), 404);

        return $disk->download($mov->stored_path, $mov->downloadName());
    })->name('mov.download')->where('mov', '[0-9]+');
});
