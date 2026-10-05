/**
 * Strict query firewall: strips query parameters whose values contain
 * anything but letters, digits, and underscores, and forwards the rest. This
 * models the reported firewall, which objected to base64 characters in
 * values, not a complete WAF engine. Request bodies and
 * streaming responses pass through unchanged. Cursor headers are stripped
 * to exercise continuation from the request parameters alone.
 */
import http from 'node:http';
import { appendFileSync } from 'node:fs';

const PERMITTED_VALUE = /^[A-Za-z0-9_]*$/;

const [backend, logPath] = process.argv.slice(2);
const backendUrl = new URL(backend);
const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const stripped = [...url.searchParams.entries()]
        .filter(([, value]) => !PERMITTED_VALUE.test(value))
        .map(([key]) => key);
    for (const key of stripped) {
        url.searchParams.delete(key);
    }
    // URLSearchParams writes a bare marker as "reprint-api=". Keep the client's form.
    const forwardedPath = url.pathname + (url.search ? '?' + url.search.slice(1).replace(/=(&|$)/g, '$1') : '');
    appendFileSync(logPath, JSON.stringify({
        method: request.method,
        path: request.url,
        forwardedPath,
        endpoint: url.searchParams.get('endpoint'),
        stripped,
    }) + '\n');
    const headers = { ...request.headers, host: backendUrl.host };
    delete headers['x-export-cursor'];
    const upstream = http.request({
        hostname: backendUrl.hostname,
        port: backendUrl.port,
        path: forwardedPath,
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
