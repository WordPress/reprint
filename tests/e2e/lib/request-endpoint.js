/** Read the endpoint a test proxy forwards. Every request names it in the query. */
export function readRequestEndpoint(request) {
    return Promise.resolve(new URL(request.url, 'http://localhost').searchParams.get('endpoint'));
}
