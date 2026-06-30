import { Head } from '@inertiajs/react';

type WelcomeProps = {
    benchmarkName: string;
    ssrEndpoint: string;
};

export default function Welcome({ benchmarkName, ssrEndpoint }: WelcomeProps) {
    return (
        <>
            <Head title="SSR performance probe" />

            <main className="min-h-screen bg-zinc-50 px-6 py-10 text-zinc-950">
                <section className="mx-auto flex min-h-[calc(100vh-5rem)] w-full max-w-4xl flex-col justify-center gap-10">
                    <div className="space-y-4">
                        <p className="text-sm font-medium tracking-[0.18em] text-red-600 uppercase">
                            Inertia Laravel HTTPS SSR
                        </p>
                        <h1 className="max-w-3xl text-4xl leading-tight font-semibold sm:text-6xl">
                            {benchmarkName}
                        </h1>
                        <p className="max-w-2xl text-lg leading-8 text-zinc-600">
                            A minimal Laravel React starter for comparing the
                            default Guzzle request path with curl HTTP version
                            negotiation against Vite's development SSR endpoint.
                        </p>
                    </div>

                    <dl className="grid gap-px overflow-hidden rounded-lg border border-zinc-200 bg-zinc-200 sm:grid-cols-3">
                        <div className="bg-white p-5">
                            <dt className="text-xs font-medium text-zinc-500 uppercase">
                                Framework
                            </dt>
                            <dd className="mt-2 text-base font-semibold">
                                Laravel + React
                            </dd>
                        </div>
                        <div className="bg-white p-5">
                            <dt className="text-xs font-medium text-zinc-500 uppercase">
                                SSR endpoint
                            </dt>
                            <dd className="mt-2 font-mono text-sm break-all">
                                {ssrEndpoint}
                            </dd>
                        </div>
                        <div className="bg-white p-5">
                            <dt className="text-xs font-medium text-zinc-500 uppercase">
                                Package
                            </dt>
                            <dd className="mt-2 text-base font-semibold">
                                inertiajs/inertia-laravel
                            </dd>
                        </div>
                    </dl>
                </section>
            </main>
        </>
    );
}
