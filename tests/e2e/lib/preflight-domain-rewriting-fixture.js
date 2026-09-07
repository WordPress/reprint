/**
 * Models a host preview: replace the site's domain so links and assets stay
 * on the preview without changing the stored site URL or waiting for DNS.
 * For https://example.com/blog, the replacement is example.com, not the full
 * URL. Like the observed Hostinger filter, this rewrites JSON but leaves
 * octet-stream responses alone. A test-controlled Content-Type override models
 * the server's former JSON header; the body comes from real WordPress. This is
 * not a complete Hostinger proxy.
 */
import http from 'node:http';
import { appendFileSync, readFileSync } from 'node:fs';

const [backendUrlString, contentTypePath, requestLogPath] = process.argv.slice(2);
const backendUrl = new URL(backendUrlString);
const previewDomain = 'preview.example.test';
const maxPreflightBytes = 1024 * 1024;

const server = http.createServer((request, response) => {
    const endpoint = new URL(request.url, backendUrl).searchParams.get('endpoint');
    appendFileSync(requestLogPath, `${JSON.stringify({ endpoint })}\n`);
    const upstreamRequest = http.request({
        hostname: backendUrl.hostname,
        port: backendUrl.port,
        method: request.method,
        path: request.url,
        headers: {
            ...request.headers,
            host: backendUrl.host,
            'accept-encoding': 'identity',
        },
    }, (upstreamResponse) => {
        if (endpoint !== 'preflight') {
            response.writeHead(upstreamResponse.statusCode, upstreamResponse.headers);
            upstreamResponse.pipe(response);
            return;
        }

        const chunks = [];
        let responseBytes = 0;
        upstreamResponse.on('data', (chunk) => {
            responseBytes += chunk.length;
            if (responseBytes > maxPreflightBytes) {
                upstreamRequest.destroy(new Error('Preflight fixture response exceeded 1 MiB'));
                return;
            }
            chunks.push(chunk);
        });
        upstreamResponse.on('end', () => {
            const headers = { ...upstreamResponse.headers };
            const overrideContentType = readFileSync(contentTypePath, 'utf-8');
            if (overrideContentType) {
                headers['content-type'] = overrideContentType;
            }
            let body = Buffer.concat(chunks);
            const rewritten = headers['content-type'].startsWith('application/json');
            if (rewritten) {
                const text = body.toString('utf-8');
                const homeDomain = new URL(JSON.parse(text).database.wp.home).hostname;
                body = Buffer.from(text.replaceAll(homeDomain, previewDomain));
            }
            delete headers['transfer-encoding'];
            headers['content-length'] = body.length;
            response.writeHead(upstreamResponse.statusCode, headers);
            response.end(body);
        });
    });

    upstreamRequest.on('error', (error) => {
        response.writeHead(502, { 'Content-Type': 'text/plain' });
        response.end(`Preview fixture could not reach WordPress: ${error.message}`);
    });
    request.pipe(upstreamRequest);
});

server.listen(0, '127.0.0.1', () => {
    process.send?.({ port: server.address().port });
});

process.on('SIGTERM', () => {
    server.close(() => process.exit(0));
});
