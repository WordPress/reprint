/**
 * Local reverse proxy which models an application firewall around WordPress.
 *
 * Reprint requests are accepted only when they send the expected Referer,
 * User-Agent, and Accept-Language headers. It injects the planned potentially
 * transient HTTP errors, then streams later requests to the real E2E
 * WordPress site.
 */
import http from 'node:http';
import { appendFileSync } from 'node:fs';

const [backendUrlString, requestLogPath] = process.argv.slice(2);
if (!backendUrlString || !requestLogPath) {
    throw new Error('Expected backend URL and request log path arguments');
}

const backendUrl = new URL(backendUrlString);

// Return two different upstream errors before allowing each streaming endpoint
// through. This exercises every endpoint while staying below the three-failure
// limit. HTTP 400 has focused wire coverage.
const injectedStatusesByEndpoint = new Map([
    ['file_index', [408, 418]],
    ['file_fetch', [425, 429]],
    ['db_index', [500, 502]],
    ['sql_chunk', [503, 504]],
]);
const injectedResponseCounts = new Map();

function writeRequestLog(record) {
    appendFileSync(requestLogPath, `${JSON.stringify(record)}\n`);
}

const server = http.createServer((request, response) => {
    const requestUrl = new URL(request.url, `http://${request.headers.host}`);
    const contentType = request.headers['content-type'] || '';
    const isReprintRequest =
        requestUrl.searchParams.has('reprint-api') ||
        requestUrl.searchParams.has('site-export-api');
    const expectedReferer = `http://${request.headers.host}/wp-admin/upload.php`;
    const referer = request.headers.referer || '';
    const expectedUserAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) ' +
        'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    const userAgent = request.headers['user-agent'] || '';
    const expectedAcceptLanguage = 'en-US,en;q=0.9';
    const acceptLanguage = request.headers['accept-language'] || '';
    const allowed =
        !isReprintRequest ||
        (
            referer === expectedReferer &&
            userAgent === expectedUserAgent &&
            acceptLanguage === expectedAcceptLanguage
        );
    const endpoint = requestUrl.searchParams.get('endpoint') || '';
    const injectedStatuses = injectedStatusesByEndpoint.get(endpoint) || [];
    const injectedResponseCount = injectedResponseCounts.get(endpoint) || 0;
    const injectedStatus = allowed
        ? injectedStatuses[injectedResponseCount] || null
        : null;
    const action = !allowed
        ? 'blocked'
        : injectedStatus === null
            ? 'proxy'
            : 'http-error';

    if (injectedStatus !== null) {
        injectedResponseCounts.set(endpoint, injectedResponseCount + 1);
    }

    writeRequestLog({
        method: request.method,
        path: request.url,
        contentType,
        endpoint,
        referer,
        expectedReferer,
        userAgent,
        expectedUserAgent,
        acceptLanguage,
        expectedAcceptLanguage,
        isReprintRequest,
        allowed,
        action,
        injectedStatus,
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

    if (injectedStatus !== null) {
        // Drain uploads before responding so cURL observes the HTTP status
        // instead of an upload-side socket error.
        request.on('end', () => {
            response.writeHead(injectedStatus, {
                'Content-Type': 'text/html; charset=utf-8',
                'X-App-Firewall': 'potentially-transient-error',
            });
            response.end(
                `<!doctype html><title>HTTP ${injectedStatus}</title>` +
                '<h1>Temporary upstream response</h1>',
            );
        });
        request.resume();
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
