# Inertia Vite SSR Performance Repro

This repository is `nckrtl/inertia-ssr-performance`: a Laravel React/Inertia
starter app for measuring HTTPS Vite dev SSR request behavior in
`@inertiajs/vite`.

The frontend runtime uses `vite-plus@0.2.1` with `vite` aliased to
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

Render extra SSR response bytes with:

```bash
php artisan inertia:ssr-benchmark https://127.0.0.1:5174/__inertia_ssr --runs=8 --response-kb=64
```

The command emits JSON by default and benchmarks the running Vite SSR endpoint.
It keeps Laravel/Guzzle on its default HTTP/1.1 path so the result shows whether
the Vite server-side fix is applied.

On Linux, the unpatched Vite endpoint consistently shows
`"vite_set_no_delay_detected": false`, HTTP/1.1, and the obvious ~40ms delay
with small SSR responses.
Once the `@inertiajs/vite` fix is applied and Vite is restarted, rerunning the
same benchmark should show `"vite_set_no_delay_detected": true`, still HTTP/1.1,
and timings near ~3-4ms.

Response size changes TCP packetization. Use `--response-kb` to compare small
and larger rendered SSR responses. The command includes `response_kb` in the
top-level JSON and `response_bytes` in each sample and summary.

On macOS, the unpatched request also stays on HTTP/1.1, but it does not show the
same consistent wait. Use Linux runs as the PR performance evidence.

Production SSR was checked separately on Linux. The standalone Inertia SSR
server at `http://127.0.0.1:13714/render` stayed fast on HTTP/1.1, so this issue
is specific to the HTTPS Vite dev SSR endpoint.

Each sample includes `http_protocol` and `response_bytes`. The summary repeats
the observed protocol as `guzzle_http_protocol` so the benchmark result stays
compact.

## Patch Target

For this repro, keep the installed Vite plugin unpatched while collecting the
baseline:

```text
node_modules/@inertiajs/vite/dist/index.js
```

The PR behavior under test is adding `socket.setNoDelay(true)` to the Vite dev
server when the Inertia SSR endpoint is registered:

```js
configureServer(server) {
  devServer = server;
  server.httpServer?.on('connection', (socket) => socket.setNoDelay(true));

  if (!entry) {
    return;
  }
}
```

This keeps the Laravel/Guzzle request on HTTP/1.1, but removes the Linux wait in
the HTTPS Vite dev SSR path.

## Verify

```bash
composer test
npm run lint:check
npm run types:check
npm run build:ssr
```
