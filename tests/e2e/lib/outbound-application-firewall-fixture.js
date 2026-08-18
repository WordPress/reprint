/**
 * Local reverse proxy which models one reported CWAF response-body setup.
 *
 * Rule 214620 adds three points for PHP source-like text, rule 218140 adds two
 * points for MySQL error text, and rule 214940 replaces the response when the
 * total reaches four. This is not a complete CWAF engine. It applies that
 * reported score to the exact bytes emitted by WordPress, before the Reprint
 * client decodes Content-Encoding.
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
        (phpSourceMatch ? 3 : 0) +
        (mysqlLeakMatch ? 2 : 0);

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
    if (request.url === '/__outbound-firewall-clear-response') {
        sendInspectedResponse(response, {
            endpoint: 'fixture_clear_response',
            statusCode: 200,
            statusMessage: 'OK',
            headers: { 'content-type': 'text/plain; charset=utf-8' },
            body: Buffer.from(
                'fopen: You have an error in your SQL syntax;',
            ),
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
