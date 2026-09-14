/**
 * Read an endpoint for the test proxies without consuming the forwarded body.
 * The client puts endpoint before file data. Retain at most 64 KiB while
 * reading that field, then put those exact bytes back into the request stream.
 */
export function readRequestEndpoint(request) {
    const queryEndpoint = new URL(request.url, 'http://localhost').searchParams.get('endpoint');
    if (queryEndpoint !== null || request.method !== 'POST') {
        return Promise.resolve(queryEndpoint);
    }
    const contentType = request.headers['content-type'] || '';
    return new Promise((resolve, reject) => {
        let prefix = Buffer.alloc(0);
        const cleanup = () => {
            request.pause();
            request.off('data', onData);
            request.off('end', onEnd);
            request.off('error', onError);
        };
        const onData = chunk => {
            prefix = Buffer.concat([prefix, chunk]);
            if (prefix.length > 64 * 1024) {
                onError(new Error('Test proxy requires endpoint within the first 64 KiB'));
                return;
            }
            const text = prefix.toString('utf8');
            let endpoint = null;
            if (contentType.startsWith('application/x-www-form-urlencoded')) {
                if (text.includes('&') || prefix.length === Number(request.headers['content-length'])) {
                    endpoint = new URLSearchParams(text).get('endpoint');
                }
            } else if (contentType.startsWith('multipart/form-data')) {
                endpoint = text.match(/\r\nContent-Disposition:[^\r\n]*\bname="endpoint"[^\r\n]*\r\n(?:[^\r\n]+\r\n)*\r\n([^\r\n]*)\r\n/i)?.[1] ?? null;
            } else if (contentType.startsWith('application/json')) {
                try {
                    endpoint = JSON.parse(text).endpoint ?? null;
                } catch {
                    // A JSON body can span several network reads.
                }
            }
            if (endpoint !== null) {
                cleanup();
                request.unshift(prefix);
                resolve(endpoint);
            }
        };
        const onEnd = () => {
            cleanup();
            if (prefix.length === 0) resolve(null);
            else reject(new Error('Test proxy received a POST body without an endpoint'));
        };
        const onError = error => {
            cleanup();
            reject(error);
        };
        request.on('data', onData);
        request.once('end', onEnd);
        request.once('error', onError);
    });
}
