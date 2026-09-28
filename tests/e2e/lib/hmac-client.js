/**
 * Request signers for the Reprint Server API.
 *
 * HmacClient matches the PHP Site_Export_HMAC_Client:
 *   Signature = HMAC-SHA256(nonce + timestamp + SHA256(body), secret)
 *
 * KeySigner matches the PHP PublicKeyClient and is what the harness signs
 * with by default, because a host with openssl_verify() accepts key
 * signatures only.
 */
import { createHash, createHmac, createPrivateKey, createPublicKey, randomBytes, sign } from 'node:crypto';

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

/**
 * Signs requests the way packages/reprint-server/src/class-public-key-client.php does.
 * Mirror any change to the signed message there; the server verifies both.
 *
 * The signed message is the newline-joined list: algorithm, key id, nonce,
 * timestamp, content hash, uppercase method, request target (path?query),
 * and the X-Export-Cursor value or an empty string.
 */
export class KeySigner {
    static ALGORITHM = 'reprint-rsa-sha256-v1';
    static UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * @param {string} privateKeyPem RSA private key in PEM form.
     */
    constructor(privateKeyPem) {
        this.privateKey = createPrivateKey(privateKeyPem);
        const spkiPem = createPublicKey(this.privateKey).export({ type: 'spki', format: 'pem' });
        // One-line base64 of the SPKI DER bytes, the form the server stores.
        this.publicKey = spkiPem.replace(/-----[^-]+-----|\s+/g, '');
        // Key id: the first 16 hex characters of SHA-256 over the DER bytes.
        this.keyId = createHash('sha256').update(Buffer.from(this.publicKey, 'base64')).digest('hex').slice(0, 16);
    }

    getKeyId() {
        return this.keyId;
    }

    /** One-line base64 public key, the form public-keys.php lists. */
    getPublicKey() {
        return this.publicKey;
    }

    /** Path and query of a full URL: what the server receives as REQUEST_URI. */
    static requestTarget(url) {
        const parsedUrl = new URL(url);
        return parsedUrl.pathname + parsedUrl.search;
    }

    buildMessage(nonce, timestamp, contentHash, method, requestTarget, cursor) {
        return [
            KeySigner.ALGORITHM,
            this.keyId,
            nonce,
            timestamp,
            contentHash,
            method.toUpperCase(),
            requestTarget,
            cursor ?? '',
        ].join('\n');
    }

    signHeaders(contentHash, method, url, cursor) {
        if (typeof url !== 'string' || url === '') {
            throw new Error('KeySigner needs the full request URL: the signature covers its path and query.');
        }
        const nonce = randomBytes(16).toString('hex');
        const timestamp = (Date.now() / 1000).toFixed(6);
        const message = this.buildMessage(nonce, timestamp, contentHash, method, KeySigner.requestTarget(url), cursor);
        // sign('sha256') on an RSA key is PKCS#1 v1.5, the same as
        // openssl_sign(..., OPENSSL_ALGO_SHA256) on the PHP side.
        const signature = sign('sha256', Buffer.from(message), this.privateKey).toString('base64');
        return {
            'X-Auth-Key-Id': this.keyId,
            'X-Auth-Signature': signature,
            'X-Auth-Nonce': nonce,
            'X-Auth-Timestamp': timestamp,
            'X-Auth-Content-Hash': contentHash,
        };
    }

    /**
     * Headers for a body-signed request.
     *
     * @param {string|Buffer} body Raw content to hash: the JSON or form body,
     *   the uploaded file's contents for a multipart upload, '' for GET.
     * @param {{method?: string, url: string, cursor?: string|null}} options
     *   method defaults to POST; cursor is the X-Export-Cursor value sent
     *   with the request, or null.
     */
    getAuthHeaders(body = '', options = {}) {
        const contentHash = createHash('sha256').update(body).digest('hex');
        return this.signHeaders(contentHash, options.method || 'POST', options.url, options.cursor ?? null);
    }

    /**
     * Headers for a push request whose streamed body is not signed: the
     * content hash is the UNSIGNED-PAYLOAD literal.
     */
    getEnvelopeAuthHeaders(method, url, cursor = null) {
        return this.signHeaders(KeySigner.UNSIGNED_PAYLOAD, method, url, cursor);
    }
}
