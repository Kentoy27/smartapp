<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_uses_the_app_base_path_and_supported_icon(): void
    {
        $response = $this->get(route('pwa.manifest'));

        $response->assertOk()
            ->assertHeader('content-type', 'application/json');

        $manifest = $response->json();
        $homePath = parse_url(route('home'), PHP_URL_PATH) ?: '/home';
        $basePath = rtrim(dirname($homePath), '/');
        $scope = $basePath === '' ? '/' : $basePath.'/';

        $this->assertSame(route('home'), $manifest['start_url']);
        $this->assertSame($scope, $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('192x192', $manifest['icons'][0]['sizes']);
        $this->assertSame('image/png', $manifest['icons'][0]['type']);
        $this->assertSame('any maskable', $manifest['icons'][1]['purpose']);
        $this->assertSame(192, getimagesize(public_path('pwa-icon-192.png'))[0]);
        $this->assertSame(512, getimagesize(public_path('pwa-icon-512.png'))[0]);
        $this->assertFileExists(public_path('smartapp-logo.png'));
    }

    public function test_service_worker_allows_control_of_the_app_base_path(): void
    {
        $basePath = rtrim(dirname(parse_url(route('home'), PHP_URL_PATH) ?: '/home'), '/');
        $scope = $basePath === '' ? '/' : $basePath.'/';

        $this->get(route('pwa.service-worker'))
            ->assertOk()
            ->assertHeader('service-worker-allowed', $scope)
            ->assertHeader('content-type', 'application/javascript; charset=utf-8');
    }

    public function test_android_app_download_is_served_as_an_installable_apk(): void
    {
        $apkPath = public_path('downloads/smartapp-android.apk');

        $this->assertFileExists($apkPath);
        $redirect = $this->get(route('app.android.download'));
        $redirect->assertRedirect();

        $response = $this->get($redirect->headers->get('Location'));
        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('content-disposition', 'attachment; filename=SmartApp-Android.apk')
            ->assertHeader('pragma', 'no-cache');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control', ''));
    }

    public function test_android_update_links_are_unique_and_expire_after_the_retry_window(): void
    {
        $firstResponse = $this->get(route('app.android.update'));
        $secondResponse = $this->get(route('app.android.update'));
        $firstLink = $firstResponse->headers->get('Location');
        $secondLink = $secondResponse->headers->get('Location');

        $this->assertNotSame($firstLink, $secondLink);
        $this->assertStringContainsString('/android-app/download/', $firstLink);

        $token = basename(parse_url($firstLink, PHP_URL_PATH));
        DB::table('android_app_download_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->update(['expires_at' => now()->subSecond()]);

        $this->get($firstLink)->assertNotFound();
    }

    public function test_android_version_endpoint_reports_the_required_app_version(): void
    {
        $response = $this->getJson(route('android-app.version'));
        $response->assertOk()
            ->assertJson([
                'required_version_code' => config('android_app.version_code'),
                'version_name' => config('android_app.version_name'),
                'force_update' => true,
                'update_url' => route('app.android.update'),
            ]);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control', '')
        );
    }
}
