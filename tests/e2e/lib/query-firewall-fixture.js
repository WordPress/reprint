/**
 * Strict query firewall: only the API routing marker may reach WordPress in a URL.
 * This models the reported base64 query rejection, not a complete WAF engine.
 * Request bodies and streaming responses pass through unchanged. Cursor headers
 * are stripped to exercise continuation from the request parameters alone.
 */
import http from 'node:http';
import { appendFileSync } from 'node:fs';

const [backend, logPath] = process.argv.slice(2);
const backendUrl = new URL(backend);
const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const blocked = [...url.searchParams.keys()].some(
        key => !['reprint-api', 'site-export-api'].includes(key),
    );
    appendFileSync(logPath, JSON.stringify({
        method: request.method,
        path: request.url,
        endpoint: url.searchParams.get('endpoint'),
        blocked,
    }) + '\n');
    if (blocked) {
        request.resume();
        response.writeHead(403, { 'Content-Type': 'text/html', 'X-Query-Firewall': 'blocked' });
        response.end('<!doctype html><title>Site homepage</title>');
        return;
    }
    const headers = { ...request.headers, host: backendUrl.host };
    delete headers['x-export-cursor'];
    const upstream = http.request({
        hostname: backendUrl.hostname,
        port: backendUrl.port,
        path: request.url,
        method: request.method,
        headers,
    }, upstreamResponse => {
        response.writeHead(upstreamResponse.statusCode, upstreamResponse.headers);
        upstreamResponse.pipe(response);
    });
    upstream.on('error', error => {
        response.writeHead(502);
        response.end(error.message);
    });
    request.pipe(upstream);
});
server.listen(0, '127.0.0.1', () => process.send({ port: server.address().port }));
process.on('SIGTERM', () => server.close(() => process.exit(0)));
