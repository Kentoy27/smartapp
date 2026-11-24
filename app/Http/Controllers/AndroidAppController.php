<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AndroidAppController extends Controller
{
    public function version(): JsonResponse
    {
        return response()->json([
            'required_version_code' => config('android_app.version_code'),
            'version_name' => config('android_app.version_name'),
            'force_update' => true,
            'update_url' => route('app.android.update'),
        ])->header('Cache-Control', 'no-store');
    }

    public function update(): RedirectResponse
    {
        $apkPath = public_path('downloads/smartapp-android.apk');
        abort_unless(is_file($apkPath), 404);

        $now = now();
        DB::table('android_app_download_tokens')
            ->where('expires_at', '<', $now)
            ->delete();

        $token = bin2hex(random_bytes(32));
        DB::table('android_app_download_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'created_at' => $now,
            'expires_at' => $now->copy()->addMinutes(
                config('android_app.download_link_ttl_minutes')
            ),
        ]);

        return redirect()->route('app.android.token-download', ['token' => $token]);
    }

    public function download(string $token): BinaryFileResponse
    {
        $isValid = DB::table('android_app_download_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->exists();
        abort_unless($isValid, 404);

        $apkPath = public_path('downloads/smartapp-android.apk');
        abort_unless(is_file($apkPath), 404);

        $response = response()->download($apkPath, 'SmartApp-Android.apk');
        $response->setPrivate();
        $response->headers->set('Content-Type', 'application/vnd.android.package-archive');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
