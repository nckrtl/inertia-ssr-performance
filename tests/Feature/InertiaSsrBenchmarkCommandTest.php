<?php

use App\Console\Commands\BenchmarkInertiaSsr;
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

    it('posts the SSR payload for default and negotiate measurements', function () {
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
            '--mode' => 'both',
            '--json' => true,
        ])->assertSuccessful();

        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->url() === $url
            && $request->method() === 'POST'
            && $request['component'] === 'welcome'
            && $request['url'] === '/');
    });
});
