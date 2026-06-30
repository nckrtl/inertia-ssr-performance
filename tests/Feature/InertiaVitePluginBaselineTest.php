<?php

describe('installed Inertia Vite plugin baseline', function () {
    it('keeps the package Vite plugin unpatched for this repro baseline', function () {
        $plugin = file_get_contents(base_path('node_modules/@inertiajs/vite/dist/index.js'));

        expect($plugin)
            ->toContain('server.middlewares.use(SSR_ENDPOINT')
            ->not->toContain('setNoDelay(true)');
    });
});
