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
php artisan inertia:ssr-benchmark https://127.0.0.1:5174/__inertia_ssr --runs=8 --warmups=2 --mode=compare --json
```

The default comparison intentionally shows one HTTP/1.1 result and one
negotiated result:

- `http11`: forced HTTP/1.1 with `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1`.
- `negotiate`: proposed patch behavior with `CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE`.

The large regression was observed with this Vite-plus stack on Beast/Linux. A
macOS loopback run can still show little or no degradation even though the
runtime versions match. For PR evidence, prefer a Beast/Linux run when the
result needs to show the obvious ~40ms HTTP/1.1 path versus the ~3ms negotiated
HTTP/2 path.

The JSON output includes readable `http_protocol` / `http_protocols` fields,
cURL constant names as `curl_http_version_label` /
`curl_http_version_labels`, and the raw cURL enum as
`curl_http_version_enum` / `curl_http_version_enums`. Do not read the enum as
the HTTP protocol number: `CURL_HTTP_VERSION_1_1` is enum value `2`, while
`CURL_HTTP_VERSION_2_0` is enum value `3`.

Use `--mode=default` when you want to inspect the current unpatched package
baseline. It usually reports the same `http_protocol: "HTTP/1.1"` and
`curl_http_version_label: "CURL_HTTP_VERSION_1_1"` as `http11`, which is why
the normal comparison keeps it out of the summary. Use `--mode=all` only when
you intentionally want `default`, `http11`, and `negotiate` together.

Plain HTTP Vite control:

```bash
npm run dev:http
php artisan inertia:ssr-benchmark http://127.0.0.1:5173/__inertia_ssr --runs=8 --warmups=2 --mode=compare --json
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
