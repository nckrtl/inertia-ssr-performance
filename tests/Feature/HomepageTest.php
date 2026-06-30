<?php

use Inertia\Testing\AssertableInertia as Assert;

describe('homepage', function () {
    it('renders the SSR benchmark landing page', function () {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('welcome')
                ->where('benchmarkName', 'Inertia SSR performance probe')
                ->where('ssrEndpoint', '/__inertia_ssr'));
    });
});
