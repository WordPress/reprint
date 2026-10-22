import { createServer } from 'node:http';

const server = createServer((request, response) => {
    request.resume();
    response.writeHead(200, { 'Content-Type': 'text/plain', 'Keep-Alive': 'timeout=60' });
    response.end('ready');
    // Close in this process while the test process cannot handle socket events.
    const socket = request.socket;
    setTimeout(() => socket.end(), 100);
});
server.listen(0, '127.0.0.1', () => process.send(server.address().port));
