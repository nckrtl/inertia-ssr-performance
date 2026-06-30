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
        {--runs=8 : Number of measured POST requests per mode after warmups.}
        {--warmups=0 : Number of warmup POST requests per mode to exclude from samples.}
        {--verify-tls : Verify TLS certificates. Local self-signed certs are skipped by default.}';

    protected $description = 'Compare unpatched and patched Inertia Vite SSR HTTP version behavior.';

    /**
     * @return array<string, mixed>
     */
    public function guzzleOptionsForMode(string $mode): array
    {
        return match ($mode) {
            'with_fix', 'negotiate' => [
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
                ],
            ],
            default => [],
        };
    }

    public function handle(): int
    {
        $runs = max(1, (int) $this->option('runs'));
        $warmups = max(0, (int) $this->option('warmups'));
        $url = $this->resolveUrl();
        $modes = $this->comparisonModes();
        $results = [];
        $hasError = false;

        for ($warmup = 1; $warmup <= $warmups; $warmup++) {
            foreach ($modes as $measurementMode) {
                $this->measure($url, $measurementMode, $warmup);
            }
        }

        for ($run = 1; $run <= $runs; $run++) {
            foreach ($modes as $measurementMode) {
                $result = $this->measure($url, $measurementMode, $run);
                $hasError = $hasError || isset($result['error']);
                $results[] = $result;
            }
        }

        $payload = [
            'url' => $url,
            'runs_per_mode' => $runs,
            'warmups_per_mode' => $warmups,
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
    private function measure(string $url, string $mode, int $run): array
    {
        $startedAt = hrtime(true);

        try {
            $request = Http::withOptions($this->guzzleOptionsForMode($mode));

            if (! $this->option('verify-tls')) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post($url, $this->pagePayload());
            $wallMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

            return $this->resultFromResponse($mode, $run, $response, $wallMilliseconds);
        } catch (Throwable $exception) {
            return [
                'mode' => $mode,
                'run' => $run,
                'error' => $exception->getMessage(),
                'wall_ms' => $this->milliseconds((hrtime(true) - $startedAt) / 1_000_000),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resultFromResponse(string $mode, int $run, Response $response, float $wallMilliseconds): array
    {
        $stats = $response->handlerStats();
        $curlHttpVersion = $stats['http_version'] ?? null;

        return [
            'mode' => $mode,
            'run' => $run,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'wall_ms' => $this->milliseconds($wallMilliseconds),
            'total_ms' => $this->secondsToMilliseconds($stats['total_time'] ?? null),
            'starttransfer_ms' => $this->secondsToMilliseconds($stats['starttransfer_time'] ?? null),
            'http_protocol' => $this->httpProtocol($curlHttpVersion),
            'curl_http_version_label' => $this->curlHttpVersionLabel($curlHttpVersion),
            'curl_http_version_enum' => $curlHttpVersion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pagePayload(): array
    {
        return [
            'component' => 'welcome',
            'props' => [
                'errors' => (object) [],
                'auth' => ['user' => null],
                'benchmarkName' => 'Inertia SSR performance probe',
                'ssrEndpoint' => '/__inertia_ssr',
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
     * @return array<string, array<string, mixed>>
     */
    private function summarize(array $results): array
    {
        $summary = [];

        foreach ($this->comparisonModes() as $mode) {
            $samples = array_values(array_filter(
                $results,
                fn (array $result) => $result['mode'] === $mode && isset($result['wall_ms']),
            ));

            if ($samples === []) {
                continue;
            }

            $wallTimes = array_column($samples, 'wall_ms');

            $summary[$mode] = [
                'runs' => count($samples),
                'average_wall_ms' => $this->milliseconds(array_sum($wallTimes) / count($wallTimes)),
                'median_wall_ms' => $this->median($wallTimes),
                'min_wall_ms' => $this->milliseconds((float) min($wallTimes)),
                'max_wall_ms' => $this->milliseconds((float) max($wallTimes)),
                'http_protocols' => array_values(array_unique(array_filter(array_column($samples, 'http_protocol')))),
            ];
        }

        return $summary;
    }

    /**
     * @return array<int, string>
     */
    private function comparisonModes(): array
    {
        return ['without_fix', 'with_fix'];
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
        return $this->curlHttpVersionMetadata($curlHttpVersion)['protocol'];
    }

    public function curlHttpVersionLabel(mixed $curlHttpVersion): ?string
    {
        return $this->curlHttpVersionMetadata($curlHttpVersion)['label'];
    }

    /**
     * @return array{protocol: ?string, label: ?string}
     */
    private function curlHttpVersionMetadata(mixed $curlHttpVersion): array
    {
        $version = $this->normalizeCurlHttpVersion($curlHttpVersion);

        if ($version === null) {
            return ['protocol' => null, 'label' => null];
        }

        $versions = [
            CURL_HTTP_VERSION_1_0 => ['protocol' => 'HTTP/1.0', 'label' => 'CURL_HTTP_VERSION_1_0'],
            CURL_HTTP_VERSION_1_1 => ['protocol' => 'HTTP/1.1', 'label' => 'CURL_HTTP_VERSION_1_1'],
            CURL_HTTP_VERSION_2_0 => ['protocol' => 'HTTP/2', 'label' => 'CURL_HTTP_VERSION_2_0'],
        ];

        $http3Version = defined('CURL_HTTP_VERSION_3') ? constant('CURL_HTTP_VERSION_3') : null;

        if (is_int($http3Version)) {
            $versions[$http3Version] = ['protocol' => 'HTTP/3', 'label' => 'CURL_HTTP_VERSION_3'];
        }

        if (isset($versions[$version])) {
            return $versions[$version];
        }

        return ['protocol' => "curl enum {$version}", 'label' => "curl enum {$version}"];
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
