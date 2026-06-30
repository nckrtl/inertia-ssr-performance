# Inertia SSR Performance Repro

This repository is `nckrtl/inertia-ssr-performance`: a Laravel React/Inertia
starter app for measuring HTTPS Vite SSR request behavior in
`inertiajs/inertia-laravel`. The frontend runtime intentionally matches
Hauser on Beast by using `vite-plus@0.2.1` with `vite` aliased to
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

## Compare HTTP Version Behavior

```bash
php artisan inertia:ssr-benchmark https://127.0.0.1:5174/__inertia_ssr --runs=8
```

The command emits JSON by default and benchmarks the behavior of the installed
`vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php` file.

The performance issue this repo demonstrates is Linux-specific in the observed
environment. On Beast/Linux, the unpatched gateway consistently shows
`"http_gateway_fix_detected": false`, HTTP/1.1, and the obvious ~40ms delay. Once
the `HttpGateway.php` fix is applied, rerunning the same benchmark should show
`"http_gateway_fix_detected": true`, HTTP/2, and timings near ~3-4ms. On this
macOS machine, the same protocol selection is visible, but the HTTP/1.1 path can
show little or no degradation. Use macOS runs only to verify protocol selection;
use Beast/Linux runs as the PR performance evidence.

The JSON output includes readable `http_protocol` / `http_protocols` fields.
Individual samples also include `curl_http_version_label` and
`curl_http_version_enum` for debugging. The summary intentionally omits those
cURL enum fields to keep the output compact. Do not read the enum as the
HTTP protocol number: `CURL_HTTP_VERSION_1_1` is enum value `2`, while
`CURL_HTTP_VERSION_2_0` is enum value `3`.

Plain HTTP Vite control:

```bash
npm run dev:http
php artisan inertia:ssr-benchmark http://127.0.0.1:5173/__inertia_ssr --runs=8
```

## Patch Target

The relevant package file is:

```text
vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php
```

For this repro, keep that installed vendor file unpatched while collecting the
baseline. It should still contain:

```php
$response = Http::post($url, $page);
```

The PR behavior under test is changing the SSR POST from:

```php
$response = Http::post($url, $page);
```

to:

```php
$options = str_starts_with($url, 'https://')
    ? ['curl' => [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE]]
    : [];

$response = Http::withOptions($options)->post($url, $page);
```

## Verify

```bash
composer test
npm run lint:check
npm run types:check
npm run build:ssr
```
