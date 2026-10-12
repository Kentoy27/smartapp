<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountClassification;
use App\Support\AccountClassificationImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Account Classification master-data API.
 *
 * Administrative only. Every endpoint re-checks the caller's access with the
 * application's own rule (User::hasAdminAccess()) rather than trusting
 * anything the client sent, and answers 403 to a signed-in non-administrator
 * (the `auth` middleware answers 401 to a guest before we get here).
 */
class AccountClassificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $perPage = max(1, min((int) $request->integer('pageSize', 10), 100));
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'title');

        $query = AccountClassification::query()
            ->when($search !== '', function ($query) use ($search) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('account_title', 'like', $term)
                        ->orWhere('rca_code', 'like', $term)
                        ->orWhere('uacs_sub_object_code', 'like', $term)
                        ->orWhere('uacs_object_code', 'like', $term)
                        ->orWhere('covering_ppas', 'like', $term);
                });
            })
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'inactive', fn ($query) => $query->inactive());

        $query = match ($sort) {
            'rca' => $query->orderBy('rca_code'),
            'object' => $query->orderBy('uacs_object_code'),
            'recent' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query->orderBy('account_title'),
        };

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        $attributes = $this->validatedAttributes($request);

        if (AccountClassification::duplicateExists($attributes)) {
            return $this->duplicateResponse();
        }

        $classification = AccountClassification::create([
            ...$attributes,
            'is_active' => (bool) $request->boolean('is_active', true),
        ]);

        $classification->recordAudit(AccountClassification::AUDIT_CREATE, Auth::user(), null, $classification->auditSnapshot());

        return response()->json(['data' => $classification], 201);
    }

    public function show(Request $request, AccountClassification $accountClassification): JsonResponse
    {
        $this->authorizeAdmin();

        return response()->json(['data' => $accountClassification]);
    }

    public function update(Request $request, AccountClassification $accountClassification): JsonResponse
    {
        $this->authorizeAdmin();

        $attributes = $this->validatedAttributes($request);

        if (AccountClassification::duplicateExists($attributes, $accountClassification->id)) {
            return $this->duplicateResponse();
        }

        $before = $accountClassification->auditSnapshot();

        $accountClassification->fill($attributes);
        $accountClassification->is_active = $request->boolean('is_active', $accountClassification->is_active);
        $accountClassification->save();

        $accountClassification->recordAudit(AccountClassification::AUDIT_UPDATE, Auth::user(), $before, $accountClassification->auditSnapshot());

        return response()->json(['data' => $accountClassification]);
    }

    /**
     * Bulk import from a CSV upload (`file`) or a JSON `rows` array. Every row
     * is validated before anything is written; only valid rows are inserted and
     * the response reports what was imported, skipped, and rejected.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorizeAdmin();

        if ($request->hasFile('file')) {
            $request->validate([
                'file' => [
                    'required', 'file', 'max:'.AccountClassificationImporter::MAX_SIZE_KB,
                    'extensions:'.AccountClassificationImporter::EXTENSIONS,
                ],
            ]);

            try {
                $analysis = AccountClassificationImporter::analyzeCsv($request->file('file')->getRealPath());
            } catch (\RuntimeException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        } elseif (is_array($request->input('rows'))) {
            $rows = [];

            foreach (array_values($request->input('rows')) as $index => $row) {
                $rows[] = ['line' => $index + 1, 'data' => (array) $row];
            }

            $analysis = AccountClassificationImporter::analyzeRows($rows);
        } else {
            return response()->json([
                'message' => 'Provide a CSV file in the "file" field or a "rows" array.',
            ], 422);
        }

        $report = AccountClassificationImporter::commit($analysis['valid'], Auth::user());

        return response()->json([
            'data' => $report,
            'validCount' => $analysis['validCount'],
            'invalidCount' => $analysis['invalidCount'],
            'invalid' => array_values(array_filter(
                $analysis['rows'],
                static fn (array $row) => ! $row['valid']
            )),
        ]);
    }

    /**
     * Retire a classification (soft delete): `is_active = false`, never a hard
     * delete, so historical records that reference it stay valid.
     */
    public function destroy(Request $request, AccountClassification $accountClassification): JsonResponse
    {
        $this->authorizeAdmin();

        $before = $accountClassification->auditSnapshot();

        $accountClassification->is_active = false;
        $accountClassification->save();

        $accountClassification->recordAudit(AccountClassification::AUDIT_DEACTIVATE, Auth::user(), $before, $accountClassification->auditSnapshot());

        return response()->json(['data' => $accountClassification]);
    }

    public function activate(Request $request, AccountClassification $accountClassification): JsonResponse
    {
        $this->authorizeAdmin();

        if (! $accountClassification->is_active) {
            $before = $accountClassification->auditSnapshot();

            $accountClassification->is_active = true;
            $accountClassification->save();

            $accountClassification->recordAudit(AccountClassification::AUDIT_ACTIVATE, Auth::user(), $before, $accountClassification->auditSnapshot());
        }

        return response()->json(['data' => $accountClassification]);
    }

    public function deactivate(Request $request, AccountClassification $accountClassification): JsonResponse
    {
        $this->authorizeAdmin();

        if ($accountClassification->is_active) {
            $before = $accountClassification->auditSnapshot();

            $accountClassification->is_active = false;
            $accountClassification->save();

            $accountClassification->recordAudit(AccountClassification::AUDIT_DEACTIVATE, Auth::user(), $before, $accountClassification->auditSnapshot());
        }

        return response()->json(['data' => $accountClassification]);
    }

    /**
     * Validate and trim the writable fields. Codes keep exactly what was typed
     * (only surrounding whitespace is removed), never reformatted.
     *
     * @return array<string, string|null>
     */
    private function validatedAttributes(Request $request): array
    {
        $validated = $request->validate([
            'account_title' => ['required', 'string', 'max:255'],
            'rca_code' => ['required', 'string', 'max:32'],
            'uacs_sub_object_code' => ['required', 'string', 'max:32'],
            'uacs_object_code' => ['required', 'string', 'max:32'],
            'covering_ppas' => ['nullable', 'string', 'max:255'],
        ]);

        $coveringPpas = trim((string) ($validated['covering_ppas'] ?? ''));

        return [
            'account_title' => trim($validated['account_title']),
            'rca_code' => trim($validated['rca_code']),
            'uacs_sub_object_code' => trim($validated['uacs_sub_object_code']),
            'uacs_object_code' => trim($validated['uacs_object_code']),
            'covering_ppas' => $coveringPpas !== '' ? $coveringPpas : null,
        ];
    }

    private function duplicateResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => [
                'account_title' => ['An active classification with this title and these codes already exists.'],
            ],
        ], 422);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Auth::user()?->hasAdminAccess(), 403);
    }
}
