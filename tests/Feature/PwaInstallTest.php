<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaInstallTest extends TestCase
{
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

        $response = $this->get(route('app.android.download'));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('content-disposition', 'attachment; filename=SmartApp-Android.apk')
            ->assertHeader('pragma', 'no-cache');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control', ''));
    }
}
