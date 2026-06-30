<?php

describe('project HTTPS configuration', function () {
    it('documents the HTTPS app URL as the demo entrypoint', function () {
        $agents = file_get_contents(base_path('AGENTS.md'));

        expect($agents)
            ->toContain('npm run dev:app')
            ->toContain('https://127.0.0.1:8000')
            ->toContain('php artisan inertia:ssr-benchmark https://127.0.0.1:5174/__inertia_ssr --runs=8')
            ->toContain('http_gateway_fix_detected')
            ->toContain('benchmarks the behavior of the installed')
            ->toContain('CURL_HTTP_VERSION_1_1')
            ->toContain('http_protocol')
            ->toContain('curl_http_version_label')
            ->toContain('curl_http_version_enum')
            ->not->toContain('--mode=compare')
            ->not->toContain('php artisan serve --host=127.0.0.1 --port=8000');
    });

    it('provides an npm script for the HTTPS demo app', function () {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($package['scripts']['dev:app'] ?? null)
            ->toBe('concurrently -k -n php,https,vite "php artisan serve --host=127.0.0.1 --port=8001" "node scripts/https-laravel-proxy.mjs" "npm run dev:https"');
    });

    it('matches the Hauser Vite-plus runtime shape', function () {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($package['devDependencies']['vite-plus'] ?? null)
            ->toBe('0.2.1')
            ->and($package['devDependencies']['vite'] ?? null)
            ->toBe('npm:@voidzero-dev/vite-plus-core@0.2.1')
            ->and($package['overrides']['vite'] ?? null)
            ->toBe('npm:@voidzero-dev/vite-plus-core@0.2.1')
            ->and($package['scripts']['dev'] ?? null)
            ->toBe('vp dev')
            ->and($package['scripts']['dev:https'] ?? null)
            ->toContain('VITE_DEV_SERVER_PORT=5174')
            ->toContain('vp dev --host 127.0.0.1 --port 5174 --strictPort')
            ->and($package['scripts']['build:ssr'] ?? null)
            ->toBe('vp build && vp build --ssr');
    });

    it('documents that the demo uses Vite-plus to match Hauser on Beast', function () {
        $agents = file_get_contents(base_path('AGENTS.md'));

        expect($agents)
            ->toContain('vite-plus@0.2.1')
            ->toContain('@voidzero-dev/vite-plus-core@0.2.1')
            ->toContain('Hauser on Beast')
            ->toContain('Beast/Linux');
    });

    it('configures the Vite-plus dev server origin and HMR host from the app URL', function () {
        $config = file_get_contents(base_path('vite.config.ts'));

        expect($config)
            ->toContain('const appUrl = env.VITE_APP_URL ?? env.APP_URL ??')
            ->toContain('const devServerOrigin =')
            ->toContain('origin: devServerOrigin')
            ->toContain('hmr: {')
            ->toContain('host: url.hostname');
    });
});
