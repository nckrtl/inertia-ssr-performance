<?php

use App\Console\Commands\BenchmarkInertiaSsr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('inertia:ssr-benchmark', function () {
    it('applies curl HTTP negotiation in negotiate mode', function () {
        $command = new BenchmarkInertiaSsr;

        expect($command->guzzleOptionsForMode('negotiate'))->toBe([
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
            ],
        ]);
    });

    it('applies forced HTTP 1.1 in http11 mode', function () {
        $command = new BenchmarkInertiaSsr;

        expect($command->guzzleOptionsForMode('http11'))->toBe([
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            ],
        ]);
    });

    it('names curl HTTP version enums without confusing them for protocol numbers', function () {
        $command = new BenchmarkInertiaSsr;

        expect($command->httpProtocol(CURL_HTTP_VERSION_1_1))->toBe('HTTP/1.1')
            ->and($command->curlHttpVersionLabel(CURL_HTTP_VERSION_1_1))->toBe('CURL_HTTP_VERSION_1_1')
            ->and($command->httpProtocol(CURL_HTTP_VERSION_2_0))->toBe('HTTP/2')
            ->and($command->curlHttpVersionLabel(CURL_HTTP_VERSION_2_0))->toBe('CURL_HTTP_VERSION_2_0');
    });

    it('posts the SSR payload for default, http11, and negotiate measurements', function () {
        $url = 'https://127.0.0.1:5174/__inertia_ssr';

        Http::fake([
            $url => Http::response([
                'head' => ['<title>SSR probe</title>'],
                'body' => '<div>SSR probe</div>',
            ]),
        ]);

        $this->artisan('inertia:ssr-benchmark', [
            'url' => $url,
            '--runs' => 2,
            '--mode' => 'all',
            '--json' => true,
        ])->assertSuccessful();

        Http::assertSentCount(6);
        Http::assertSent(fn ($request) => $request->url() === $url
            && $request->method() === 'POST'
            && $request['component'] === 'welcome'
            && $request['url'] === '/');
    });

    it('compares http11 and negotiate without duplicating the default baseline', function () {
        $url = 'https://127.0.0.1:5174/__inertia_ssr';

        Http::fake([
            $url => Http::response([
                'head' => ['<title>SSR probe</title>'],
                'body' => '<div>SSR probe</div>',
            ]),
        ]);

        $status = Artisan::call('inertia:ssr-benchmark', [
            'url' => $url,
            '--runs' => 2,
            '--mode' => 'compare',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and(array_keys($payload['summary']))->toBe(['http11', 'negotiate'])
            ->and($payload['warmups_per_mode'])->toBe(0)
            ->and($payload['samples'][0])->toHaveKeys(['http_protocol', 'curl_http_version_label', 'curl_http_version_enum'])
            ->and($payload['summary']['http11'])->toHaveKeys(['average_wall_ms', 'median_wall_ms', 'min_wall_ms', 'max_wall_ms', 'http_protocols', 'curl_http_version_labels', 'curl_http_version_enums'])
            ->and($payload['summary']['negotiate'])->toHaveKeys(['average_wall_ms', 'median_wall_ms', 'min_wall_ms', 'max_wall_ms', 'http_protocols', 'curl_http_version_labels', 'curl_http_version_enums']);

        Http::assertSentCount(4);
    });

    it('excludes warmup requests from measured samples', function () {
        $url = 'https://127.0.0.1:5174/__inertia_ssr';

        Http::fake([
            $url => Http::response([
                'head' => ['<title>SSR probe</title>'],
                'body' => '<div>SSR probe</div>',
            ]),
        ]);

        $status = Artisan::call('inertia:ssr-benchmark', [
            'url' => $url,
            '--runs' => 2,
            '--warmups' => 1,
            '--mode' => 'compare',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and($payload['warmups_per_mode'])->toBe(1)
            ->and($payload['runs_per_mode'])->toBe(2)
            ->and($payload['samples'])->toHaveCount(4)
            ->and($payload['summary']['http11']['runs'])->toBe(2)
            ->and($payload['summary']['negotiate']['runs'])->toBe(2);

        Http::assertSentCount(6);
    });

    it('interleaves measured samples to avoid mode order bias', function () {
        $url = 'https://127.0.0.1:5174/__inertia_ssr';

        Http::fake([
            $url => Http::response([
                'head' => ['<title>SSR probe</title>'],
                'body' => '<div>SSR probe</div>',
            ]),
        ]);

        $status = Artisan::call('inertia:ssr-benchmark', [
            'url' => $url,
            '--runs' => 2,
            '--mode' => 'compare',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and(array_column($payload['samples'], 'mode'))->toBe([
                'http11',
                'negotiate',
                'http11',
                'negotiate',
            ]);
    });
});
