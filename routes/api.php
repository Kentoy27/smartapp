<?php

use App\Http\Controllers\Api\AccountClassificationController;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Illuminate\Http\Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * Account Classifications API — Administrator-only master data.
 *
 * These endpoints use the application's existing (session) authentication via
 * the `web` + `auth` middleware rather than a second token system, and each
 * action independently re-checks access inside the controller: only the
 * Administrator role may call them (guests get 401, everyone else — including
 * superadmins — gets 403). The `pageSize` query parameter paginates the list;
 * `search`, `status` and `sort` filter it.
 */
Route::middleware(['web', 'auth'])->prefix('admin')->group(function () {
    Route::get('account-classifications', [AccountClassificationController::class, 'index']);
    Route::post('account-classifications', [AccountClassificationController::class, 'store']);
    // Bulk import (CSV upload or a `rows` array) — defined before the {id}
    // routes so "import" is never read as an id.
    Route::post('account-classifications/import', [AccountClassificationController::class, 'import']);
    Route::get('account-classifications/{accountClassification}', [AccountClassificationController::class, 'show']);
    Route::put('account-classifications/{accountClassification}', [AccountClassificationController::class, 'update']);
    Route::delete('account-classifications/{accountClassification}', [AccountClassificationController::class, 'destroy']);
    Route::post('account-classifications/{accountClassification}/activate', [AccountClassificationController::class, 'activate']);
    Route::post('account-classifications/{accountClassification}/deactivate', [AccountClassificationController::class, 'deactivate']);
});
