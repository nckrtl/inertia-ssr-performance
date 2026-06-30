# Inertia SSR Performance Repro

This repository is `nckrtl/inertia-ssr-performance`: a standard Laravel
React/Inertia starter app for measuring HTTPS Vite SSR request behavior in
`inertiajs/inertia-laravel`.

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
local HTTPS proxy on port 8000, and starts Vite HTTPS on port 5174. Vite should
print `Inertia SSR dev endpoint: /__inertia_ssr`.

## Compare HTTP Version Behavior

```bash
php artisan inertia:ssr-benchmark https://127.0.0.1:5174/__inertia_ssr --runs=8 --mode=both --json
```

The command compares:

- `default`: Laravel HTTP client / Guzzle defaults.
- `negotiate`: `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE`.

PHP curl handler stats use `http_version: 2` for HTTP/1.1 and
`http_version: 3` for HTTP/2.

Plain HTTP Vite control:

```bash
npm run dev:http
php artisan inertia:ssr-benchmark http://127.0.0.1:5173/__inertia_ssr --runs=8 --mode=both --json
```

## Patch Target

The relevant package file is:

```text
vendor/inertiajs/inertia-laravel/src/Ssr/HttpGateway.php
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
