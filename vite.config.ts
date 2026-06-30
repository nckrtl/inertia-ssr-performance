import fs from 'node:fs';

import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.VITE_APP_URL ?? env.APP_URL ?? 'https://127.0.0.1:8000';
    const url = new URL(appUrl);
    const devServerPort = env.VITE_DEV_SERVER_PORT ?? (env.VITE_DEV_SERVER_CERT ? '5174' : '5173');
    const devServerProtocol = env.VITE_DEV_SERVER_CERT ? 'https:' : 'http:';
    const devServerOrigin = `${devServerProtocol}//${url.hostname}:${devServerPort}`;
    const certPath = env.VITE_DEV_SERVER_CERT;
    const keyPath = env.VITE_DEV_SERVER_KEY;

    return {
        server: {
            origin: devServerOrigin,
            cors: {
                origin: [url.origin, devServerOrigin],
            },
            hmr: {
                host: url.hostname,
            },
            ...(certPath && keyPath
                ? {
                      https: {
                          cert: fs.readFileSync(certPath),
                          key: fs.readFileSync(keyPath),
                      },
                  }
                : {}),
        },
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600],
                    }),
                ],
            }),
            inertia(),
            react({
                babel: {
                    plugins: ['babel-plugin-react-compiler'],
                },
            }),
            tailwindcss(),
            wayfinder({
                formVariants: true,
            }),
        ],
    };
});
