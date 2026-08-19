/**
 * Proxies Reprint requests to WordPress. For `file_fetch` and `sql_chunk`, it:
 *
 * - buffers at most 64 MiB of the upstream response;
 * - scans those bytes without decoding Content-Encoding;
 * - returns an HTML 403 when `fopen` or the test's MySQL error text is present;
 * - otherwise forwards the original response and headers unchanged.
 *
 * Other proxied responses stream through without inspection. Two fixture-only
 * routes send each clear-text marker through the same scanner.
 *
 * This models a reported CWAF failure. Public rules assign four points to the
 * PHP match and five to the MySQL match, with an outgoing limit of four. Rule
 * 214800 denies at that limit when point blocking is enabled; rule 214940 only
 * logs it. The report does not identify which disruptive rule issued its 403,
 * so this proxy models the threshold path to the same result.
 *
 * Reprint gzips export bodies inside PHP. Because this proxy does not decode
 * gzip, it does not find the clear-text markers and the client receives the
 * response. This is not a complete CWAF engine, and a firewall which decodes
 * gzip can behave differently.
 *
 * Report: https://wordpress.org/support/topic/conflict-with-strict_mod_security/
 * Rules: https://github.com/zcomtenten/cwaf/tree/f9a3f105768d72bb3ea1585fdc963176d1f41f73
 */
import http from 'node:http';
import { appendFileSync } from 'node:fs';

const [backendUrlString, responseLogPath] = process.argv.slice(2);
if (!backendUrlString || !responseLogPath) {
    throw new Error('Expected backend URL and response log path arguments');
}

const backendUrl = new URL(backendUrlString);
const maxInspectedResponseBytes = 64 * 1024 * 1024;

function inspectResponseBody(body) {
    const responseText = body.toString('latin1');
    const phpSourceMatch = responseText.includes('fopen');
    const mysqlLeakMatch = responseText.includes(
        'You have an error in your SQL syntax;',
    );
    const outboundPoints =
        (phpSourceMatch ? 4 : 0) +
        (mysqlLeakMatch ? 5 : 0);

    return {
        phpSourceMatch,
        mysqlLeakMatch,
        outboundPoints,
        blocked: outboundPoints >= 4,
    };
}

function writeResponseLog(record) {
    appendFileSync(responseLogPath, `${JSON.stringify(record)}\n`);
}

function sendInspectedResponse(response, details) {
    const inspection = inspectResponseBody(details.body);
    writeResponseLog({
        endpoint: details.endpoint,
        contentEncoding: details.headers['content-encoding'] || '',
        bodyBytes: details.body.length,
        ...inspection,
    });

    if (inspection.blocked) {
        response.writeHead(403, {
            'Content-Type': 'text/html; charset=utf-8',
            'X-App-Firewall': 'outbound-response',
        });
        response.end('<!doctype html><title>403 Forbidden</title><h1>Forbidden</h1>');
        return;
    }

    response.writeHead(
        details.statusCode,
        details.statusMessage,
        details.headers,
    );
    response.end(details.body);
}

const server = http.createServer((request, response) => {
    let clearResponseBody = null;
    if (request.url === '/__outbound-firewall-clear-php-response') {
        clearResponseBody = 'fopen';
    } else if (request.url === '/__outbound-firewall-clear-mysql-response') {
        clearResponseBody = 'You have an error in your SQL syntax;';
    }
    if (clearResponseBody !== null) {
        sendInspectedResponse(response, {
            endpoint: 'fixture_clear_response',
            statusCode: 200,
            statusMessage: 'OK',
            headers: { 'content-type': 'text/plain; charset=utf-8' },
            body: Buffer.from(clearResponseBody),
        });
        return;
    }

    const requestUrl = new URL(request.url, `http://${request.headers.host}`);
    const endpoint = requestUrl.searchParams.get('endpoint') || '';
    const inspectResponse = endpoint === 'file_fetch' || endpoint === 'sql_chunk';
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
        if (!inspectResponse) {
            response.writeHead(
                upstreamResponse.statusCode,
                upstreamResponse.statusMessage,
                upstreamResponse.headers,
            );
            upstreamResponse.pipe(response);
            return;
        }

        const chunks = [];
        let responseBytes = 0;
        upstreamResponse.on('data', (chunk) => {
            responseBytes += chunk.length;
            if (responseBytes > maxInspectedResponseBytes) {
                upstreamRequest.destroy(
                    new Error(
                        `Application firewall fixture response exceeded ` +
                        `${maxInspectedResponseBytes} bytes`,
                    ),
                );
                return;
            }
            chunks.push(chunk);
        });
        upstreamResponse.on('end', () => {
            sendInspectedResponse(response, {
                endpoint,
                statusCode: upstreamResponse.statusCode,
                statusMessage: upstreamResponse.statusMessage,
                headers: upstreamResponse.headers,
                body: Buffer.concat(chunks),
            });
        });
    });

    upstreamRequest.on('error', (error) => {
        if (!response.headersSent) {
            response.writeHead(502, { 'Content-Type': 'text/plain; charset=utf-8' });
        }
        response.end(
            `Application firewall fixture could not reach WordPress: ${error.message}`,
        );
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
