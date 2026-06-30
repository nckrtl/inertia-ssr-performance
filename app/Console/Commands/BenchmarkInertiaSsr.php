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
        {--runs=8 : Number of POST requests per mode.}
        {--mode=both : Measurement mode: default, negotiate, or both.}
        {--verify-tls : Verify TLS certificates. Local self-signed certs are skipped by default.}
        {--json : Emit machine-readable JSON.}';

    protected $description = 'Compare default and negotiated HTTP version behavior for Inertia Vite SSR requests.';

    /**
     * @return array<string, mixed>
     */
    public function guzzleOptionsForMode(string $mode): array
    {
        return match ($mode) {
            'negotiate' => [
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
                ],
            ],
            default => [],
        };
    }

    public function handle(): int
    {
        $mode = (string) $this->option('mode');

        if (! in_array($mode, ['default', 'negotiate', 'both'], true)) {
            $this->error('The --mode option must be default, negotiate, or both.');

            return self::INVALID;
        }

        $runs = max(1, (int) $this->option('runs'));
        $url = $this->resolveUrl();
        $modes = $mode === 'both' ? ['default', 'negotiate'] : [$mode];
        $results = [];
        $hasError = false;

        foreach ($modes as $measurementMode) {
            for ($run = 1; $run <= $runs; $run++) {
                $result = $this->measure($url, $measurementMode, $run);
                $hasError = $hasError || isset($result['error']);
                $results[] = $result;
            }
        }

        $payload = [
            'url' => $url,
            'runs_per_mode' => $runs,
            'tls_verification' => (bool) $this->option('verify-tls'),
            'samples' => $results,
            'summary' => $this->summarize($results),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $hasError ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            ['mode', 'run', 'status', 'wall_ms', 'total_ms', 'starttransfer_ms', 'http_version'],
            array_map(fn (array $result) => [
                $result['mode'],
                $result['run'],
                $result['status'] ?? 'error',
                $result['wall_ms'] ?? null,
                $result['total_ms'] ?? null,
                $result['starttransfer_ms'] ?? null,
                $result['http_version'] ?? 'n/a',
            ], $results),
        );

        $this->line('');
        $this->line('PHP curl http_version values: 2 = HTTP/1.1, 3 = HTTP/2.');

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

        return [
            'mode' => $mode,
            'run' => $run,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'wall_ms' => $this->milliseconds($wallMilliseconds),
            'total_ms' => $this->secondsToMilliseconds($stats['total_time'] ?? null),
            'starttransfer_ms' => $this->secondsToMilliseconds($stats['starttransfer_time'] ?? null),
            'http_version' => $stats['http_version'] ?? null,
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

        foreach (['default', 'negotiate'] as $mode) {
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
                'http_versions' => array_values(array_unique(array_filter(array_column($samples, 'http_version')))),
            ];
        }

        return $summary;
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
}
