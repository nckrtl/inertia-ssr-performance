<?php

describe('project HTTPS configuration', function () {
    it('documents the HTTPS app URL as the demo entrypoint', function () {
        $agents = file_get_contents(base_path('AGENTS.md'));

        expect($agents)
            ->toContain('npm run dev:app')
            ->toContain('https://127.0.0.1:8000')
            ->not->toContain('php artisan serve --host=127.0.0.1 --port=8000');
    });

    it('provides an npm script for the HTTPS demo app', function () {
        $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($package['scripts']['dev:app'] ?? null)
            ->toBe('concurrently -k -n php,https,vite "php artisan serve --host=127.0.0.1 --port=8001" "node scripts/https-laravel-proxy.mjs" "npm run dev:https"');
    });
});
