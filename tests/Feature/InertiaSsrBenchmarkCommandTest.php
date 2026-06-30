<?php

use App\Console\Commands\BenchmarkInertiaSsr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('inertia:ssr-benchmark', function () {
    it('uses Guzzle defaults for an unpatched gateway source', function () {
        $command = new BenchmarkInertiaSsr;

        expect($command->guzzleOptionsForGatewaySource('$response = Http::post($url, $page);'))->toBe([]);
    });

    it('uses curl HTTP negotiation when the installed gateway source has the fix', function () {
        $command = new BenchmarkInertiaSsr;

        expect($command->guzzleOptionsForGatewaySource('CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE'))->toBe([
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_NONE,
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

    it('posts the SSR payload once per measured run', function () {
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
        ])->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === $url
            && $request->method() === 'POST'
            && $request['component'] === 'welcome'
            && $request['url'] === '/');
    });

    it('returns JSON by default with a single summary', function () {
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
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and($payload['runs'])->toBe(2)
            ->and($payload['warmups'])->toBe(0)
            ->and($payload)->toHaveKey('http_gateway_fix_detected')
            ->and($payload['samples'][0])->toHaveKeys(['http_protocol', 'curl_http_version_label', 'curl_http_version_enum'])
            ->and($payload['samples'][0])->not->toHaveKey('mode')
            ->and($payload['summary'])->toHaveKeys(['runs', 'average_wall_ms', 'median_wall_ms', 'min_wall_ms', 'max_wall_ms', 'http_protocols'])
            ->and($payload['summary'])->not->toHaveKeys(['without_fix', 'with_fix']);

        Http::assertSentCount(2);
    });

    it('keeps curl enum metadata out of the summary', function () {
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
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and($payload['samples'][0])->toHaveKeys(['curl_http_version_label', 'curl_http_version_enum'])
            ->and($payload['summary'])->not->toHaveKeys(['curl_http_version_labels', 'curl_http_version_enums']);
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
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and($payload['warmups'])->toBe(1)
            ->and($payload['runs'])->toBe(2)
            ->and($payload['samples'])->toHaveCount(2)
            ->and($payload['summary']['runs'])->toBe(2);

        Http::assertSentCount(3);
    });

    it('keeps measured samples in run order', function () {
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
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(0)
            ->and(array_column($payload['samples'], 'run'))->toBe([1, 2]);
    });
});
