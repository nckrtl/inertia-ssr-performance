<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class BenchmarkInertiaSsr extends Command
{
    protected $signature = 'inertia:ssr-benchmark
        {url? : Full Vite SSR URL. Defaults to the current public/hot URL when Vite is running.}
        {--runs=8 : Number of measured POST requests after warmups.}
        {--warmups=0 : Number of warmup POST requests to exclude from samples.}
        {--response-kb=0 : Extra rendered SSR response size in KiB.}
        {--verify-tls : Verify TLS certificates. Local self-signed certs are skipped by default.}';

    protected $description = 'Benchmark the Inertia Vite dev SSR endpoint behavior.';

    public function installedVitePluginHasSetNoDelay(): bool
    {
        return $this->vitePluginSourceHasSetNoDelay($this->installedVitePluginSource());
    }

    private function installedVitePluginSource(): string
    {
        $pluginFile = base_path('node_modules/@inertiajs/vite/dist/index.js');

        if (! is_file($pluginFile)) {
            return '';
        }

        return (string) file_get_contents($pluginFile);
    }

    public function vitePluginSourceHasSetNoDelay(string $source): bool
    {
        return str_contains($source, 'setNoDelay(true)');
    }

    public function handle(): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $warmups = max(0, (int) $this->option('warmups'));
        $responseKilobytes = max(0, (int) $this->option('response-kb'));
        $url = $this->resolveUrl();
        $viteSetNoDelayDetected = $this->installedVitePluginHasSetNoDelay();
        $results = [];
        $hasError = false;

        for ($warmup = 1; $warmup <= $warmups; $warmup++) {
            $this->measure($url, $warmup, $responseKilobytes);
        }

        for ($run = 1; $run <= $runs; $run++) {
            $result = $this->measure($url, $run, $responseKilobytes);
            $hasError = $hasError || isset($result['error']);
            $results[] = $result;
        }

        $payload = [
            'url' => $url,
            'runs' => $runs,
            'warmups' => $warmups,
            'response_kb' => $responseKilobytes,
            'vite_set_no_delay_detected' => $viteSetNoDelayDetected,
            'tls_verification' => (bool) $this->option('verify-tls'),
            'samples' => $results,
            'summary' => $this->summarize($results),
        ];

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    private function resolveUrl(): string
    {
        if ($url = $this->argument('url')) {
            return (string) $url;
        }

        $hotFile = public_path('hot');

        if (is_file($hotFile)) {
            return rtrim(trim((string) file_get_contents($hotFile)), '/').'/__inertia_ssr';
        }

        return 'https://127.0.0.1:5174/__inertia_ssr';
    }

    /**
     * @return array<string, mixed>
     */
    private function measure(string $url, int $run, int $responseKilobytes): array
    {
        $startedAt = hrtime(true);

        try {
            $request = Http::withOptions([]);

            if (! $this->option('verify-tls')) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post($url, $this->pagePayload($responseKilobytes));
            $wallMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            return $this->resultFromResponse($run, $response, $wallMilliseconds);
        } catch (Throwable $exception) {
            return [
                'run' => $run,
                'error' => $exception->getMessage(),
                'wall_ms' => $this->milliseconds((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultFromResponse(int $run, Response $response, float $wallMilliseconds): array
    {
        $stats = $response->handlerStats();
        $curlHttpVersion = $stats['http_version'] ?? null;

        return [
            'run' => $run,
            'status' => $response->status(),
            'wall_ms' => $this->milliseconds($wallMilliseconds),
            'total_ms' => $this->secondsToMilliseconds($stats['total_time'] ?? null),
            'starttransfer_ms' => $this->secondsToMilliseconds($stats['starttransfer_time'] ?? null),
            'response_bytes' => strlen($response->body()),
            'http_protocol' => $this->httpProtocol($curlHttpVersion),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(int $responseKilobytes): array
    {
        return [
            'component' => 'welcome',
            'props' => [
                'errors' => (object) [],
                'auth' => ['user' => null],
                'benchmarkName' => 'Inertia SSR performance probe',
                'ssrEndpoint' => '/__inertia_ssr',
                'ssrResponseKilobytes' => $responseKilobytes,
                'name' => config('app.name', 'Laravel'),
                'sidebarOpen' => true,
            ],
            'url' => '/',
            'version' => 'benchmark',
            'sharedProps' => ['errors', 'auth'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function summarize(array $results): array
    {
        $samples = array_values(array_filter(
            $results,
            fn (array $result) => isset($result['wall_ms']),
        ));

        if ($samples === []) {
            return [
                'runs' => 0,
                'guzzle_http_protocol' => null,
            ];
        }

        $wallTimes = array_column($samples, 'wall_ms');
        $protocols = array_values(array_unique(array_filter(array_column($samples, 'http_protocol'))));
        $responseBytes = array_values(array_filter(
            array_column($samples, 'response_bytes'),
            fn (mixed $bytes): bool => is_numeric($bytes),
        ));

        return [
            'runs' => count($samples),
            'average_wall_ms' => $this->milliseconds(array_sum($wallTimes) / count($wallTimes)),
            'median_wall_ms' => $this->median($wallTimes),
            'min_wall_ms' => $this->milliseconds((float) min($wallTimes)),
            'max_wall_ms' => $this->milliseconds((float) max($wallTimes)),
            'response_bytes' => $responseBytes[0] ?? null,
            'guzzle_http_protocol' => $protocols[0] ?? null,
        ];
    }

    private function secondsToMilliseconds(mixed $seconds): ?float
    {
        if (! is_numeric($seconds)) {
            return null;
        }

        return $this->milliseconds((float) $seconds * 1000);
    }

    private function milliseconds(float $milliseconds): float
    {
        return round($milliseconds, 2);
    }

    public function httpProtocol(mixed $curlHttpVersion): ?string
    {
        $version = $this->normalizeCurlHttpVersion($curlHttpVersion);

        if ($version === null) {
            return null;
        }

        $versions = [
            CURL_HTTP_VERSION_1_0 => 'HTTP/1.0',
            CURL_HTTP_VERSION_1_1 => 'HTTP/1.1',
            CURL_HTTP_VERSION_2_0 => 'HTTP/2',
        ];

        $http3Version = defined('CURL_HTTP_VERSION_3') ? constant('CURL_HTTP_VERSION_3') : null;

        if (is_int($http3Version)) {
            $versions[$http3Version] = 'HTTP/3';
        }

        if (isset($versions[$version])) {
            return $versions[$version];
        }

        return "curl enum {$version}";
    }

    private function normalizeCurlHttpVersion(mixed $curlHttpVersion): ?int
    {
        if (is_int($curlHttpVersion)) {
            return $curlHttpVersion;
        }

        if (is_string($curlHttpVersion) && preg_match('/^-?\d+$/', $curlHttpVersion) === 1) {
            return (int) $curlHttpVersion;
        }

        return null;
    }

    /**
     * @param  array<int, float|int>  $values
     */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);

        $count = count($values);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $this->milliseconds((float) $values[$middle]);
        }

        return $this->milliseconds(((float) $values[$middle - 1] + (float) $values[$middle]) / 2);
    }
}
