<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Models\AipPlan;
use App\Models\MonthlyExpenditureMatrix;
use App\Models\OpcrfMov;
use App\Models\OpcrfSchedule;
use App\Models\OpcrfSubmission;
use App\Models\OpcrfTemplate;
use App\Models\School;
use App\Models\User;
use App\Models\UserMov;
use App\Models\WfpImportLog;
use App\Models\WfpPlan;
use App\Models\WfpSubmission;
use App\Support\AipXlsxExporter;
use App\Support\MemXlsxExporter;
use App\Support\OpcrfAccess;
use App\Support\OpcrfPartOne;
use App\Support\OpcrfTemplatePersonalizer;
use App\Support\OpcrfWorkbookProgress;
use App\Support\OpcrfWorkbookTrim;
use App\Support\WfpAllotment;
use App\Support\WfpAllotmentXlsxExporter;
use App\Support\WfpPlanExporter;
use App\Support\WfpSetupExporter;
use App\Support\WfpTemplate;
use Illuminate\Filesystem\FilesystemAdapter;
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
    /* ------------------------------------------------------------------
     * Profile — the same page for every signed-in account.
     *
     * A digital signature belongs to an account rather than to a role, so a
     * user and a superadmin manage theirs identically here; there is no
     * separate "superadmin profile". The stored image lives on the private
     * `local` disk and is only readable through the route below, which
     * resolves the file from the caller's own row — no path is ever accepted
     * from the client, so one account can never read another's signature.
     * (The one deliberate second reader is a reviewer: an OPCRF submission
     * shows its ratee's signature through the submission's own route.)
     * ----------------------------------------------------------------- */

    Route::get('/profile', function () {
        return view('profile.index');
    })->name('profile.index');

    Route::get('/profile/signature', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->hasSignature(), 404);

        // The account's own file, via the account: the headers (inline, no
        // shared caching) and the row-only path rule live in
        // User::signatureImageResponse(), shared with the OPCRF route below.
        return $user->signatureImageResponse();
    })->name('profile.signature');

    Route::get('/app', function () {
        return view('procurement.app.index');
    })->name('procurement.app.index');

    /* ------------------------------------------------------------------
     * AIP (Annual Implementation Plan) — School Head authoring, Superadmin
     * review.
     *
     * Every AIP route re-checks the role AND, for a plan's own pages, that
     * the caller may see it (the owning School Head always; a reviewer once
     * it is submitted). The Livewire components re-check the same gates on
     * every action, so hiding a menu item is never the boundary.
     * ----------------------------------------------------------------- */

    Route::get('/aip', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessAip(), 404);

        return view('aip.index');
    })->name('aip.index');

    // The superadmin's review queue. Declared before /aip/{plan} so the
    // literal path is never read as a plan id (it would not match [0-9]+
    // anyway, but the order makes the intent explicit).
    Route::get('/aip-review', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canReviewAip(), 404);

        return view('aip.review');
    })->name('aip.review');

    Route::get('/aip/{plan}', function (AipPlan $plan) {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->managedBy($user), 404);

        return view('aip.edit', ['plan' => $plan]);
    })->name('aip.plans.edit')->where('plan', '[0-9]+');

    // The plan as the printed DepEd form, for the browser's Print → Save as
    // PDF. The owner may print their own at any time; a reviewer may print
    // one that has been submitted.
    Route::get('/aip/{plan}/print', function (AipPlan $plan) {
        $user = Auth::user();
        abort_unless($plan->canBeViewedBy($user), 404);

        return view('aip.print', ['plan' => $plan]);
    })->name('aip.plans.print')->where('plan', '[0-9]+');

    // The plan as an .xlsx built from its own contents. Bytes are built
    // BEFORE the response (a streamed callback's return value is discarded
    // and would risk a 0-byte download).
    Route::get('/aip/{plan}/export', function (AipPlan $plan) {
        $user = Auth::user();
        abort_unless($plan->canBeViewedBy($user), 404);

        $bytes = AipXlsxExporter::bytes($plan, $user);

        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.AipXlsxExporter::downloadName($plan).'"');
    })->name('aip.plans.export')->where('plan', '[0-9]+');

    Route::get('/users', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        return view('users.index');
    })->name('users.index');

    // Districts & Schools: the Administrator's page for the org structure —
    // every district with its schools, and the manager that adds, renames and
    // removes them. The sidebar item points here; the dashboard keeps its own
    // summary card, and both render the same DistrictList component.
    Route::get('/districts', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        return view('districts.index');
    })->name('districts.index');

    // ACCOUNT CLASSIFICATIONS: the master data behind the RCA / UACS reference
    // table (Account Title, RCA Code, UACS Sub-Object / Object Code, Covering
    // PPAS). Administrator-only — this master data belongs to the Administrator
    // side, so a superadmin gets the same 404 a non-admin would. The component
    // re-checks the same gate on every create/update/delete, so hiding the menu
    // item is never the boundary.
    Route::get('/admin/account-classifications', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        return view('account-classifications.index');
    })->name('account-classifications.index');

    // The CSV import template — the header row plus one worked example, so an
    // administrator can see the expected columns before filling their own file.
    // Same administrative gate as the module itself.
    Route::get('/admin/account-classifications/template', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        $csv = implode("\n", [
            'Account Title,RCA Code,UACS Sub-Object Code,UACS Object Code,Covering PPAS',
            'Office Supplies Expenses,50203010,00,5020301000,PPAS 1',
        ])."\n";

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="account-classifications-template.csv"');
    })->name('account-classifications.template');

    /* ------------------------------------------------------------------
     * WFP Setup — the administrator's lookup library and activity
     * templates for the Work and Financial Plan.
     *
     * Administrator-only: the same gate as Account Classifications — a
     * superadmin gets the same 404. Every route re-checks the gate, so
     * hiding the menu item is never the boundary.
     * ----------------------------------------------------------------- */

    Route::get('/admin/wfp-setup', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        return view('wfp.setup.index');
    })->name('wfp.setup.index');

    Route::get('/admin/wfp-setup/lookups/{category}', function (string $category) {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);
        abort_unless(is_array(config('wfp_lookups.categories.'.$category)), 404);

        $spec = config('wfp_lookups.categories.'.$category);

        return view('wfp.setup.lookups', [
            'category' => $category,
            'spec' => $spec,
        ]);
    })->name('wfp.setup.lookups');

    // Blank import template for one lookup category: the header row plus a
    // worked example the administrator deletes before filling their own.
    Route::get('/admin/wfp-setup/lookups/{category}/template', function (string $category) {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        $csv = WfpSetupExporter::lookupTemplateCsv($category);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="wfp-'.$category.'-template.csv"');
    })->name('wfp.setup.lookups.template');

    // Every row of one lookup category, active and retired, so an
    // administrator can keep a working copy and re-import it.
    Route::get('/admin/wfp-setup/lookups/{category}/export', function (string $category) {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        $csv = WfpSetupExporter::lookupCsv($category);

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="wfp-'.$category.'.csv"');
    })->name('wfp.setup.lookups.export');

    Route::get('/admin/wfp-setup/templates', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        return view('wfp.setup.templates');
    })->name('wfp.setup.templates');

    // Blank import template for activity templates.
    Route::get('/admin/wfp-setup/templates/template', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        $csv = WfpSetupExporter::templateTemplateCsv();

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="wfp-activity-templates-template.csv"');
    })->name('wfp.setup.templates.template');

    // Every activity template, exported so it can be re-imported.
    Route::get('/admin/wfp-setup/templates/export', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        $csv = WfpSetupExporter::templateCsv();

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="wfp-activity-templates.csv"');
    })->name('wfp.setup.templates.export');

    // Row-level error report for a committed import.
    Route::get('/admin/wfp-setup/imports/{log}/errors', function (int $log) {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageUsersAndSchools(), 404);

        $record = WfpImportLog::query()->findOrFail($log);
        $csv = WfpSetupExporter::errorReportCsv((array) ($record->errors ?? []));

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="wfp-import-'.$record->category.'-errors.csv"');
    })->name('wfp.setup.imports.errors');

    // Opcrf: a staff-facing page, reachable by regular users only —
    // superadmins have their own tooling, and the Bookkeeper's module is the
    // WFP alone, so the item is hidden from both. This is the parts grid:
    // every Part is listed with the state the superadmin's schedule puts it
    // in, open or locked.
    Route::get('/opcrf', function () {
        $user = Auth::user();
        abort_if($user instanceof User && ! $user->canAccessStaffPages(), 404);

        return view('opcrf.parts');
    })->name('opcrf.index');

    // One OPCRF Part. Guarded server-side by the schedule: a Part outside its
    // access window (not yet open, closed, or disabled) redirects back to the
    // grid with the reason, and a Part already submitted stays reachable after
    // the deadline passes. Part 1 access opens the dashboard, where the user
    // can download their personalized template; Parts 2-4 open on their own
    // schedules.
    Route::get('/opcrf/part/{part}', function (int $part) {
        if ($part === OpcrfSchedule::PART_ONE) {
            return redirect()->route('home');
        }

        $blurbs = [
            2 => 'Leadership and core behavioural competencies.',
            3 => 'The summary of ratings and the agreement block.',
            4 => 'The office improvement plan and your individual development plan.',
        ];

        return view('opcrf.part', [
            'part' => $part,
            'partLabel' => 'Part '.$part,
            'partBlurb' => $blurbs[$part] ?? 'This Part of the OPCRF.',
            'schedule' => OpcrfAccess::scheduleFor(
                OpcrfAccess::currentYear(),
                $part
            ),
            'notice' => session('opcrfPartNotice'),
        ]);
    })->name('opcrf.part')->where('part', '[1-4]')->middleware('opcrf.part');

    // OPCRF SCHEDULE: the superadmin's calendar. Part access is decided from
    // these rows on every request, so this page is the control for it.
    Route::get('/opcrf-schedule', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->hasAdminAccess(), 404);

        return view('opcrf.schedule');
    })->name('opcrf.schedule');

    // Review Opcrf: the superadmin's window into every staff submission —
    // read-only review of the submitted OPCR (with a download of the
    // submitted file) and each submission's MOVs as a view-only record.
    Route::get('/opcrf-review', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->hasAdminAccess(), 404);

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
    // The Bookkeeper has no OPCRF of their own either.
    Route::get('/download/opcrf-template', function () {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(404);
        }

        abort_if(! $user->canAccessStaffPages(), 404);
        abort_unless(is_file(OpcrfPartOne::templatePath()), 404);

        // The full workbook is also needed during the shared Parts 2–4 term.
        // Re-check both entry points here; the dashboard and Part pages are
        // only conveniences, not authorization boundaries.
        $partOneAccessible = OpcrfAccess::canAccess($user, OpcrfSchedule::PART_ONE);
        $includeAllParts = OpcrfPartOne::fullTemplateAvailable();

        if (! $partOneAccessible && ! $includeAllParts) {
            $state = OpcrfAccess::partState($user, OpcrfSchedule::PART_ONE);

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
            $templateBytes = OpcrfPartOne::templateBytes($includeAllParts);
            $partOneSubmissions = OpcrfSubmission::query()
                ->where('user_id', $user->id)
                ->where('opcrf_part', OpcrfSubmission::PART_ONE);
            $savedPartOne = $includeAllParts
                ? (clone $partOneSubmissions)->approved()->latest('id')->first()
                : null;
            $savedPartOne ??= $partOneSubmissions->latest('id')->first();
            $savedWorkbookPath = $savedPartOne?->downloadPath();
            $hasSavedWorkbook = $savedPartOne?->hasFile()
                && $savedWorkbookPath !== null
                && Storage::disk('local')->exists($savedWorkbookPath);

            if ($hasSavedWorkbook) {
                $templateBytes = OpcrfWorkbookProgress::mergePartOne(
                    $templateBytes,
                    Storage::disk('local')->path($savedWorkbookPath)
                );
            }

            $bytes = OpcrfTemplatePersonalizer::personalize(
                $templateBytes,
                OpcrfTemplatePersonalizer::identityFor($user),
                $user->id,
                (string) config('opcrf.template.version', '2026.1')
            );

            if ($savedPartOne !== null) {
                $bytes = OpcrfWorkbookProgress::mergeSubmissionData(
                    $bytes,
                    [
                        'employee_name' => $savedPartOne->employee_name,
                        'position' => $savedPartOne->position,
                        'review_period' => $savedPartOne->review_period,
                        'division_office' => $savedPartOne->division_office,
                        'objectives' => $savedPartOne->objectives,
                        'accomplishments' => $savedPartOne->accomplishments,
                        'self_rating' => $savedPartOne->self_rating,
                    ],
                    ! $hasSavedWorkbook
                );
            }
        } catch (RuntimeException $e) {
            // The shipped template is missing or unreadable: say so rather
            // than serving a 0-byte workbook the user cannot open.
            abort(404, $e->getMessage());
        }

        $filename = OpcrfTemplatePersonalizer::downloadNameFor($user);

        // Recorded so the upload that follows can be traced back to the
        // exact template it was answered on, and the dashboard can show
        // what was generated and when.
        OpcrfTemplate::record($user, OpcrfTemplate::currentVersion(), $filename);

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
    // The response is ALWAYS the staff member's own workbook (or the copy the
    // superadmin attached while approving) — never a sheet synthesized from the
    // recorded fields: that would hand the reviewer a plain stand-in instead of
    // the actual OPCRF. When the submission has a signature-stamped copy of
    // that workbook, the stamped one is what comes back: the account's digital
    // signature is encoded into it, and that is the document the review is
    // about. Submissions that predate file archiving (or were typed by hand)
    // have no original document, so the route 404s and the review modal says so
    // — the approval panel is where the official copy is attached.
    Route::get('/opcrf-submissions/{submission}/download', function (OpcrfSubmission $submission) {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(404);
        }

        abort_unless($submission->canBeDownloadedBy($user), 404);
        abort_unless($submission->hasFile(), 404);

        $storedPath = Storage::disk('local')->path((string) $submission->downloadPath());

        // The owner's official copy follows the same part rule as their
        // template: Part I only until the school year's final term. The
        // superadmin's review download is always the archived file as
        // stored — the trimmer needs a real workbook, so anything it
        // cannot read (or a workbook with no PART I tab) is served as-is.
        $bytes = null;

        if (! $user->hasAdminAccess() && ! OpcrfPartOne::windowOpen()) {
            try {
                $bytes = OpcrfWorkbookTrim::keepPart(
                    $storedPath,
                    (string) config('opcrf.part_one.sheet', 'PART I')
                );
            } catch (RuntimeException) {
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

    // The ratee's digital signature, for the signer block the review modal
    // renders: the same account signature the stamped workbook carries, shown
    // where the document signs. The boundary is the workbook's own — whoever
    // may download the stamped copy may see the signature it encodes, and
    // nobody else — and it is served only while that stamped copy exists, so
    // a signature is never readable on its own.
    Route::get('/opcrf-submissions/{submission}/signature', function (OpcrfSubmission $submission) {
        $user = Auth::user();

        if (!$user instanceof User) {
            abort(404);
        }

        abort_unless($submission->canBeDownloadedBy($user), 404);
        abort_unless($submission->hasSignedFile(), 404);

        $ratee = $submission->user;
        abort_unless($ratee instanceof User && $ratee->hasSignature(), 404);

        return $ratee->signatureImageResponse();
    })->name('opcrf.submission.signature')->where('submission', '[0-9]+');

    // A reviewer signature can only be fetched for a compliance action on
    // this submission, by an administrator allowed to review the workbook.
    Route::get('/opcrf-submissions/{submission}/review-signature/{review}', function (
        OpcrfSubmission $submission,
        \App\Models\OpcrfReview $review
    ) {
        $viewer = Auth::user();

        abort_unless($viewer instanceof User && $viewer->hasAdminAccess(), 404);
        abort_unless($submission->canBeReviewedBy($viewer), 404);
        abort_unless((int) $review->opcrf_submission_id === (int) $submission->id, 404);
        abort_unless(in_array($review->action, [
            \App\Models\OpcrfReview::ACTION_COMPLIANCE,
            \App\Models\OpcrfReview::ACTION_COMPLIANCE_FINAL,
        ], true), 404);
        abort_unless($submission->hasSignedFile(), 404);

        $reviewer = $review->reviewer;
        abort_unless($reviewer instanceof User && $reviewer->hasSignature(), 404);

        return $reviewer->signatureImageResponse();
    })->name('opcrf.review.signature')->where(['submission' => '[0-9]+', 'review' => '[0-9]+']);

    // MOV evidence files. The owner always downloads their own; a superadmin
    // only for submissions routed to them (or unassigned ones) — routing is
    // the same boundary the review list uses.
    Route::get('/opcrf-movs/{mov}/download', function (OpcrfMov $mov) {
        $user = Auth::user();

        abort_if(! $mov->submission->canBeReviewedBy($user), 403);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('local');

        abort_unless($disk->exists($mov->stored_path), 404);

        return $disk->download($mov->stored_path, $mov->original_name);
    })->name('opcrf.movs.download')->where('mov', '[0-9]+');

    /* ------------------------------------------------------------------
     * WFP (Work and Financial Plan) — the School Head (SH) writes it, the
     * school's Bookkeeper reads it.
     *
     * The /wfp page's action is "Generate and Download WFP": the workbook is
     * produced server-side from the account-code master list (no upload), so
     * there is no template/download route. Every WFP route re-checks
     * Auth::user()->canAccessWfp(): the module is unreachable for the SDS
     * Viewer and the Super Admin even by typing the URL directly (they get a
     * 404). The Bookkeeper reaches the module's pages read-only; every write
     * re-checks canManageWfp() (or the model's own managedBy()), so a
     * Bookkeeper can never edit a school's plan. The Livewire components
     * re-check the same gates on every action.
     * ----------------------------------------------------------------- */

    Route::get('/wfp', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);

        return view('wfp.index');
    })->name('wfp.index');

    /* ------------------------------------------------------------------
     * WFP Uploads — the ADMIN page: every school's uploaded Work and
     * Financial Plan, as its own destination for the sidebar.
     *
     * The same list the dashboard card shows, reachable on its own URL.
     * Admin-only: the School Head's /wfp workspace stays their own side of
     * the workflow, and the Livewire component re-checks the gate too.
     * ----------------------------------------------------------------- */

    Route::get('/wfp/submissions', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canReviewWfp(), 404);

        return view('wfp.submissions');
    })->name('wfp.submissions.index');

    /* ------------------------------------------------------------------
     * School Allotments — the Budget office sets the annual allotment
     * budget each School Head may consume through the school's Monthly
     * Expenditure Matrices. The ceiling is stored per school and year; the
     * consumption is derived from the matrices, never posted here.
     *
     * Budget-role and administrative accounts only. A School Head reads
     * their ceiling on the Per Line Item Expenditure report instead; they
     * never set it. The component re-checks the same gate on every action.
     * ----------------------------------------------------------------- */
    Route::get('/budget/allotments', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageAllotments(), 404);

        return view('budget.allotments');
    })->name('budget.allotments.index');

    /* ------------------------------------------------------------------
     * Monthly Expenditure — the Budget office reads every school's Monthly
     * Expenditure Matrix line by line: the objective, the activity, what it
     * consumes and the account title/code it is charged to.
     *
     * The page is READ-ONLY. The School Head owns the lines in the matrix
     * editor; the Budget office and the school Bookkeeper check them against
     * the plan and the allotment, and never edit a school's plan. Scope is
     * the Budget role and the Bookkeeper (canCheckLineItems()) — an SH,
     * administrator or superadmin gets the same 404. The component re-checks
     * the gate on mount.
     * ----------------------------------------------------------------- */
    Route::get('/budget/line-items', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckLineItems(), 404);

        return view('budget.line-items');
    })->name('budget.lineitems.index');

    /* ------------------------------------------------------------------
     * Per Line Item Expenditure — the Budget office's copy of one school's
     * annual MOOE allotment report.
     *
     * It is the same printed form the School Head reads on /wfp/line-items,
     * with a school picker because the Budget office and the school
     * Bookkeeper oversee every school. The page is READ-ONLY and derived on
     * every load from the school's Monthly Expenditure Matrices
     * (WfpAllotment); nothing here edits a school's plan. The Bookkeeper's
     * copy also carries the "Checked as to the Correctness of Account Titles
     * and Codes" sign-off (a component action). Scope is the Budget role and
     * the Bookkeeper — the route guard and the component both enforce
     * canCheckLineItems().
     * ----------------------------------------------------------------- */

    Route::get('/budget/per-line-item', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckLineItems(), 404);

        return view('budget.per-line-item');
    })->name('budget.per-line-item.index');

    // The same report as an .xlsx for the school in ?school=. The year is
    // resolved against that school's own matrices, so a crafted ?year= can
    // never report on a year the school has nothing in. No school, or one
    // that no longer exists, has no report to export: it is a 404.
    Route::get('/budget/per-line-item/export', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canCheckLineItems(), 404);

        $schoolId = (int) request()->query('school');
        $school = $schoolId > 0 ? School::query()->find($schoolId) : null;
        abort_if($school === null, 404);

        $year = WfpAllotment::resolveYear($school->id, (int) request()->query('year'));

        // The form's "Prepared by" is the school's School Head, not the
        // Budget officer reading this copy.
        $preparedBy = User::query()
            ->where('school_id', $school->id)
            ->where('role', 'user')
            ->orderBy('name')
            ->orderBy('username')
            ->first();

        $bytes = WfpAllotmentXlsxExporter::bytes($school->id, $year, $preparedBy);

        // Bytes are built BEFORE the response (a streamed callback's return
        // value is discarded and would risk a 0-byte download).
        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.WfpAllotmentXlsxExporter::downloadName($year, $school->name).'"');
    })->name('budget.per-line-item.export');

    /* ------------------------------------------------------------------
     * An uploaded WFP workbook — the archived file behind the superadmin's
     * "WFP Uploads" dashboard list.
     *
     * The owner downloads their own copy; an administrator downloads any
     * school's (that is what the list is for). The row must point at a file
     * that actually exists — a path to nothing 404s rather than handing
     * over an empty body.
     * ----------------------------------------------------------------- */

    Route::get('/wfp-submissions/{submission}/download', function (WfpSubmission $submission) {
        $user = Auth::user();
        abort_unless($user instanceof User, 404);
        abort_unless($submission->canBeDownloadedBy($user), 404);
        abort_unless($submission->hasFile(), 404);

        return Storage::disk('local')->download(
            $submission->file_path,
            $submission->fileDownloadName()
        );
    })->name('wfp.submission.download')->where('submission', '[0-9]+');

    /* ------------------------------------------------------------------
     * MEM → WFP generation — the School Head (SH) works here, scoped to
     * their school; the school's Bookkeeper reads the same pages.
     *
     * The Monthly Expenditure Matrix is the source of truth; the WFP is
     * generated from it. Every route re-checks the WFP gate AND that the
     * model belongs to the caller's own school — an account of another
     * school gets a 404 even with a crafted id. Reading a matrix uses
     * canBeViewedBy(); writing (the component's own actions) uses
     * managedBy(). `/template` is declared before `{matrix}` so "template"
     * is never read as an id (it would not match `[0-9]+` anyway, but the
     * order makes the intent explicit).
     * ----------------------------------------------------------------- */

    Route::get('/wfp/matrices', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);

        return view('wfp.matrices.index');
    })->name('wfp.matrices.index');

    // The CSV template is an authoring aid — it exists to bring new lines
    // into a matrix — so it follows the write gate, not the read one.
    Route::get('/wfp/matrices/template', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canManageWfp(), 404);

        $csv = implode("\n", [
            'Section,Objective,Activity Description,Resource Kind,Quantity,Unit,Unit Cost,Account Title,Account Code,Funding Source,Organizational Classification,Activity MOVs',
            'operations,Improved learner outcomes,Procure office supplies,MOOE,10,ream,250.00,Office Supplies Expenses,5020301000,MOOE,School,PO and RIS',
        ])."\n";

        return response($csv)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="mem-template.csv"');
    })->name('wfp.matrices.template');

    // The matrix as the printed DepEd form — a .xlsx BUILT from the lines as
    // they stand (a MEM has no archived file to serve). Declared before
    // {matrix} for the same reason /template is. Reading the form is reading
    // the plan, so the view gate (canBeViewedBy) applies, not the write one.
    Route::get('/wfp/matrices/{matrix}/export', function (MonthlyExpenditureMatrix $matrix) {
        $user = Auth::user();
        abort_unless($user instanceof User && $matrix->canBeViewedBy($user), 404);

        $bytes = MemXlsxExporter::bytes($matrix, $user);

        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.MemXlsxExporter::downloadName($matrix).'"');
    })->name('wfp.matrices.export')->where('matrix', '[0-9]+');

    // The editor page: the School Head's write surface, opened read-only by
    // the school's Bookkeeper. The write gate lives inside the component —
    // mount() only opens it (canBeViewedBy), and every mutating action
    // re-checks managedBy().
    Route::get('/wfp/matrices/{matrix}', function (MonthlyExpenditureMatrix $matrix) {
        $user = Auth::user();
        abort_unless($user instanceof User && $matrix->canBeViewedBy($user), 404);

        return view('wfp.matrices.edit', ['matrix' => $matrix]);
    })->name('wfp.matrices.edit')->where('matrix', '[0-9]+');

    /* ------------------------------------------------------------------
     * Per Line Item Expenditure — the School Head reads their own school's
     * copy; the school's Bookkeeper reads the same copy (and signs the
     * check on it — a component action, not a route).
     *
     * The annual MOOE allotment the school's Monthly Expenditure Matrices
     * add up to, read as the printed form: a month-by-month budget column
     * beside the year's lines rolled up per account code, with each line's
     * share of the grand total. It is DERIVED on every load — there is no
     * report to generate and nothing to go stale. Scope is the signed-in
     * account's own school, and the guard is the same canAccessWfp() gate
     * every other WFP route uses.
     * ----------------------------------------------------------------- */

    Route::get('/wfp/line-items', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);

        return view('wfp.line-items');
    })->name('wfp.lineitems.index');

    // The same report as an .xlsx — the printed form with auto-fit columns.
    // The year comes from the query string, and is resolved against the
    // caller's OWN school's matrices: a crafted ?year= reports on the newest
    // year the school actually has rather than on another school's figures.
    // An SH with no school has no report to export, so it is a 404.
    Route::get('/wfp/line-items/export', function () {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canAccessWfp(), 404);
        abort_if($user->school_id === null, 404);

        $schoolId = (int) $user->school_id;
        $year = WfpAllotment::resolveYear($schoolId, (int) request()->query('year'));
        $bytes = WfpAllotmentXlsxExporter::bytes($schoolId, $year, $user);

        // Bytes are built BEFORE the response (a streamed callback's return
        // value is discarded and would risk a 0-byte download).
        return response($bytes)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.WfpAllotmentXlsxExporter::downloadName($year).'"');
    })->name('wfp.lineitems.export');

    // The generated-plan page: the School Head's editor, opened read-only by
    // the school's Bookkeeper. Like the matrix page, mount() uses the view
    // gate and every mutating action re-checks managedBy().
    Route::get('/wfp/plans/{plan}', function (WfpPlan $plan) {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->canBeViewedBy($user), 404);

        return view('wfp.plan', ['plan' => $plan]);
    })->name('wfp.plans.edit')->where('plan', '[0-9]+');

    // The generated WFP as a patched copy of the official template. The
    // patching itself lives in WfpPlanExporter, which the "Generate and
    // Download WFP" button shares — both must hand back the same workbook.
    // Bytes are built BEFORE the response (a streamed callback's return value
    // is discarded and would risk a 0-byte download).
    Route::get('/wfp/plans/{plan}/export', function (WfpPlan $plan) {
        $user = Auth::user();
        abort_unless($user instanceof User && $plan->canBeViewedBy($user), 404);
        abort_unless(WfpTemplate::exists(), 404);

        try {
            $bytes = WfpPlanExporter::bytes($plan);
        } catch (RuntimeException $e) {
            // The template could not be read: say so rather than emit a
            // guessed workbook the SH cannot open.
            abort(404, $e->getMessage());
        }

        $filename = 'WFP-'.$plan->year.'.xlsx';

        return response($bytes)
            ->header('Content-Type', WfpPlanExporter::CONTENT_TYPE)
            ->header('Content-Length', (string) strlen($bytes))
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    })->name('wfp.plans.export')->where('plan', '[0-9]+');

    // MOV uploads: the staff member's Means of Verification checklist —
    // Part → Category → MOV → the document they attached to it. A separate
    // page from the OPCR upload on purpose: this is the standing evidence
    // set, not the cycle's form.
    Route::get('/movs', function () {
        $user = Auth::user();
        abort_if($user instanceof User && ! $user->canAccessStaffPages(), 404);

        return view('mov.index');
    })->name('mov.index');

    // A MOV document, opened in the browser. Images and PDFs render
    // inline; anything else (a .docx) is handed over as a download, because
    // no browser can display it.
    Route::get('/movs/{mov}/view', function (UserMov $mov) {
        /** @var FilesystemAdapter $disk */
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
    Route::get('/movs/{mov}/download', function (UserMov $mov) {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('mov.disk', 'local'));

        abort_unless($mov->canBeAccessedBy(Auth::user()), 403);
        abort_unless($mov->fileExists(), 404);

        return $disk->download($mov->stored_path, $mov->downloadName());
    })->name('mov.download')->where('mov', '[0-9]+');
});