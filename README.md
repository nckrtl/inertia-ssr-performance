# Inertia SSR Performance Repro

This repository is `nckrtl/inertia-ssr-performance`: a Laravel React/Inertia
starter app for measuring HTTPS Vite SSR request behavior in
`inertiajs/inertia-laravel`.

The frontend runtime intentionally matches Hauser on Beast by using
`vite-plus@0.2.1` with `vite` aliased to
`@voidzero-dev/vite-plus-core@0.2.1`.

Use npm only. The local HTTPS certificate and key are already committed in
`certs/` for this demo repo.

## Run The App

```bash
composer install
npm install
php artisan migrate
npm run dev:app
```

Open:

```text
https://127.0.0.1:8000
```

Accept the browser warning for the committed self-signed local certificate.

`npm run dev:app` starts Laravel on an internal HTTP port, exposes it through a
local HTTPS proxy on port 8000, and starts Vite-plus HTTPS on port 5174.
Vite-plus should print `Inertia SSR dev endpoint: /__inertia_ssr`.

## Benchmark

```bash
php artisan inertia:ssr-benchmark https://127.0.0.1:5174/__inertia_ssr --runs=8
```

The command emits JSON by default and benchmarks the behavior of the installed
`vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php` file.

On Beast/Linux, the unpatched gateway consistently shows
`"http_gateway_fix_detected": false`, HTTP/1.1, and the obvious ~40ms delay.
Once the `HttpGateway.php` fix is applied, rerunning the same benchmark should
show `"http_gateway_fix_detected": true`, HTTP/2, and timings near ~3-4ms.

On macOS, the unpatched request also stays on HTTP/1.1, but it does not show the
same consistent wait. Use Beast/Linux runs as the PR performance evidence.

Plain HTTP control:

```bash
npm run dev:http
php artisan inertia:ssr-benchmark http://127.0.0.1:5173/__inertia_ssr --runs=8
```

Plain HTTP should stay fast on HTTP/1.1. The fix is only for HTTPS SSR URLs,
where Vite's HTTPS dev server can use HTTP/2.

## Patch Target

For this repro, keep the installed vendor file unpatched while collecting the
baseline:

```text
vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php
```

Baseline:

```php
$response = Http::post($url, $page);
```

Fix:

```php
$options = Str::startsWith($url, 'https://')
    ? ['version' => '2.0']
    : [];

$response = Http::withOptions($options)->post($url, $page);
```

This uses Guzzle's supported `version` option. The raw curl option that means
"let curl figure it out" is not used here because Guzzle deprecates that
conflicting option and rejects it in Guzzle 8.

## Verify

```bash
composer test
npm run lint:check
npm run types:check
npm run build:ssr
```
