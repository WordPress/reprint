/**
 * Request signers for the Reprint Server API.
 *
 * Both classes sign the newline-joined message the PHP clients build: the
 * algorithm label, the key id (keys only), nonce, timestamp, uppercase
 * method, and request target (path?query). The request target's query names
 * the endpoint, so a signature covers the endpoint it was made for. Any change
 * to Site_Export_HMAC_Client::build_message() or
 * PublicKeyClient::build_message() must be mirrored here.
 *
 * HmacClient matches the PHP Site_Export_HMAC_Client. KeySigner matches the
 * PHP PublicKeyClient and is what the harness signs with by default, because
 * a host with openssl_verify() accepts key signatures only. Neither signature
 * covers the request body.
 */
import { createHash, createHmac, createPrivateKey, createPublicKey, randomBytes, sign } from 'node:crypto';

/** The URL of one endpoint, the same URL Utils::endpoint_url() builds. */
export function endpointUrl(apiUrl, endpoint) {
    const trimmed = apiUrl.replace(/[?&]+$/, '');
    return `${trimmed}${trimmed.includes('?') ? '&' : '?'}endpoint=${encodeURIComponent(endpoint)}`;
}

export class HmacClient {
    static ALGORITHM = 'reprint-hmac-sha256-v2';

    constructor(secret) {
        this.secret = secret;
    }

    /** @param {{method?: string, url: string}} options url is the full request URL, endpoint included. */
    getAuthHeaders({ method = 'POST', url } = {}) {
        if (typeof url !== 'string' || url === '') {
            throw new Error('HmacClient needs the full request URL: the signature covers its path and query.');
        }
        const nonce = randomBytes(16).toString('hex');
        const timestamp = (Date.now() / 1000).toFixed(6);
        const message = [HmacClient.ALGORITHM, nonce, timestamp, method.toUpperCase(), KeySigner.requestTarget(url)].join('\n');
        return {
            'X-Auth-Signature': createHmac('sha256', this.secret).update(message).digest('hex'),
            'X-Auth-Nonce': nonce,
            'X-Auth-Timestamp': timestamp,
        };
    }

    getEnvelopeAuthHeaders(method, url) {
        return this.getAuthHeaders({ method, url });
    }
}

/**
 * Signs requests the way packages/reprint-server/src/class-public-key-client.php does.
 * No body is signed: TLS protects the request.
 */
export class KeySigner {
    static ALGORITHM = 'reprint-rsa-sha256-v1';

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

    buildMessage(nonce, timestamp, method, requestTarget) {
        return [
            KeySigner.ALGORITHM,
            this.keyId,
            nonce,
            timestamp,
            method.toUpperCase(),
            requestTarget,
        ].join('\n');
    }

    /**
     * Headers for one request.
     *
     * @param {{method?: string, url: string}} options method defaults to POST.
     */
    getAuthHeaders({ method = 'POST', url } = {}) {
        if (typeof url !== 'string' || url === '') {
            throw new Error('KeySigner needs the full request URL: the signature covers its path and query.');
        }
        const nonce = randomBytes(16).toString('hex');
        const timestamp = (Date.now() / 1000).toFixed(6);
        const message = this.buildMessage(nonce, timestamp, method, KeySigner.requestTarget(url));
        // sign('sha256') on an RSA key is PKCS#1 v1.5, the same as
        // openssl_sign(..., OPENSSL_ALGO_SHA256) on the PHP side.
        const signature = sign('sha256', Buffer.from(message), this.privateKey).toString('base64');
        return {
            'X-Auth-Key-Id': this.keyId,
            'X-Auth-Signature': signature,
            'X-Auth-Nonce': nonce,
            'X-Auth-Timestamp': timestamp,
        };
    }

    /** Headers for a push request: the same signature as any other request. */
    getEnvelopeAuthHeaders(method, url) {
        return this.getAuthHeaders({ method, url });
    }
}
