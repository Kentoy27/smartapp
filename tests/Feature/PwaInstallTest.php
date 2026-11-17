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
}
