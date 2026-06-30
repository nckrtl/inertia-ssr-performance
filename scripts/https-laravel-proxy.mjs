import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';

const targetHost = '127.0.0.1';
const targetPort = 8001;
const listenPort = 8000;

const server = https.createServer(
    {
        cert: fs.readFileSync('certs/localhost.pem'),
        key: fs.readFileSync('certs/localhost-key.pem'),
    },
    (clientRequest, clientResponse) => {
        const proxyRequest = http.request(
            {
                hostname: targetHost,
                port: targetPort,
                method: clientRequest.method,
                path: clientRequest.url,
                headers: {
                    ...clientRequest.headers,
                    host: `127.0.0.1:${listenPort}`,
                    'x-forwarded-host': `127.0.0.1:${listenPort}`,
                    'x-forwarded-port': String(listenPort),
                    'x-forwarded-proto': 'https',
                    'x-forwarded-ssl': 'on',
                },
            },
            (proxyResponse) => {
                clientResponse.writeHead(
                    proxyResponse.statusCode ?? 502,
                    proxyResponse.headers,
                );
                proxyResponse.pipe(clientResponse);
            },
        );

        proxyRequest.on('error', (error) => {
            clientResponse.writeHead(502, { 'content-type': 'text/plain' });
            clientResponse.end(
                `Laravel backend is unavailable: ${error.message}`,
            );
        });

        clientRequest.pipe(proxyRequest);
    },
);

server.listen(listenPort, targetHost, () => {
    console.log(`HTTPS Laravel proxy: https://${targetHost}:${listenPort}`);
    console.log(`Forwarding to http://${targetHost}:${targetPort}`);
});
