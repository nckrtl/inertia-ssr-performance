<?php

describe('installed Inertia HTTP gateway baseline', function () {
    it('keeps the package gateway unpatched for this repro baseline', function () {
        $gateway = file_get_contents(base_path('vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php'));

        expect($gateway)
            ->toContain('$response = Http::post($url, $page);')
            ->not->toContain('Http::withOptions')
            ->not->toContain('CURLOPT_HTTP_VERSION')
            ->not->toContain('CURL_HTTP_VERSION_NONE');
    });
});
