/**
 * Local reverse proxy which models an application firewall around WordPress.
 *
 * Multipart file_fetch requests are accepted only when their Referer points to
 * the same origin's WordPress Media Library page. All accepted requests are
 * streamed to the real E2E WordPress site.
 */
import http from 'node:http';
import { appendFileSync } from 'node:fs';

const [backendUrlString, requestLogPath] = process.argv.slice(2);
if (!backendUrlString || !requestLogPath) {
    throw new Error('Expected backend URL and request log path arguments');
}

const backendUrl = new URL(backendUrlString);

function writeRequestLog(record) {
    appendFileSync(requestLogPath, `${JSON.stringify(record)}\n`);
}

const server = http.createServer((request, response) => {
    const requestUrl = new URL(request.url, `http://${request.headers.host}`);
    const contentType = request.headers['content-type'] || '';
    const isMultipartFileFetch =
        request.method === 'POST' &&
        requestUrl.searchParams.get('endpoint') === 'file_fetch' &&
        contentType.startsWith('multipart/form-data;');
    const expectedReferer = `http://${request.headers.host}/wp-admin/upload.php`;
    const referer = request.headers.referer || '';
    const allowed = !isMultipartFileFetch || referer === expectedReferer;

    writeRequestLog({
        method: request.method,
        path: request.url,
        contentType,
        referer,
        expectedReferer,
        isMultipartFileFetch,
        allowed,
        userAgent: request.headers['user-agent'] || '',
    });

    if (!allowed) {
        request.resume();
        response.writeHead(403, {
            'Content-Type': 'text/html; charset=utf-8',
            'X-App-Firewall': 'blocked',
        });
        response.end('<!doctype html><title>403 Forbidden</title><h1>Forbidden</h1>');
        return;
    }

    const upstreamRequest = http.request({
        protocol: backendUrl.protocol,
        hostname: backendUrl.hostname,
        port: backendUrl.port,
        method: request.method,
        path: request.url,
        headers: {
            ...request.headers,
            host: backendUrl.host,
        },
    }, (upstreamResponse) => {
        response.writeHead(
            upstreamResponse.statusCode,
            upstreamResponse.statusMessage,
            upstreamResponse.headers,
        );
        upstreamResponse.pipe(response);
    });

    upstreamRequest.on('error', (error) => {
        if (!response.headersSent) {
            response.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
        }
        response.end(`Application firewall fixture could not reach WordPress: ${error.message}`);
    });

    request.pipe(upstreamRequest);
});

server.listen(0, '127.0.0.1', () => {
    const address = server.address();
    process.send?.({ port: address.port });
});

process.on('SIGTERM', () => {
    server.close(() => process.exit(0));
});
