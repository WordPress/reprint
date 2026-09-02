/**
 * HMAC authentication client for the Reprint Server API.
 * JavaScript implementation matching the PHP Site_Export_HMAC_Client.
 *
 * Signature = HMAC-SHA256(nonce + timestamp + SHA256(body), secret)
 */
import { createHmac, createHash, randomBytes } from 'node:crypto';

export class HmacClient {
    constructor(secret) {
        this.secret = secret;
    }

    /**
     * Generate a cryptographically secure nonce (hex string, 32 chars).
     */
    generateNonce() {
        return randomBytes(16).toString('hex');
    }

    /**
     * Get current timestamp with microsecond precision.
     */
    getTimestamp() {
        return (Date.now() / 1000).toFixed(6);
    }

    /**
     * Compute SHA-256 hash of data.
     */
    sha256(data) {
        return createHash('sha256').update(data).digest('hex');
    }

    /**
     * Compute HMAC-SHA256 signature.
     */
    computeSignature(nonce, timestamp, contentHash) {
        if (!contentHash) {
            contentHash = this.sha256('');
        }
        const message = nonce + timestamp + contentHash;
        return createHmac('sha256', this.secret).update(message).digest('hex');
    }

    /**
     * Get all authentication headers for a request.
     * @param {string|Buffer} body - Request body (empty string for GET)
     * @returns {Object} Headers object
     */
    getAuthHeaders(body = '') {
        const nonce = this.generateNonce();
        const timestamp = this.getTimestamp();
        const bodyStr = typeof body === 'string' ? body : body.toString();
        const contentHash = this.sha256(bodyStr);
        const signature = this.computeSignature(nonce, timestamp, contentHash);

        return {
            'X-Auth-Signature': signature,
            'X-Auth-Nonce': nonce,
            'X-Auth-Timestamp': timestamp,
            'X-Auth-Content-Hash': contentHash,
        };
    }
}
