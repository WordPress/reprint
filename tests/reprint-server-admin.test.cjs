const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { runInNewContext } = require('node:vm');

test('generating tokens replaces the field with fresh random bytes and keeps the visibility toggle in sync', () => {
    const token = { value: 'saved-token', type: 'password', focus() {}, select() {} };
    const toggle = new EventTarget();
    toggle.dataset = { showLabel: 'Show token', hideLabel: 'Hide token' };
    toggle.setAttribute = (name, value) => { toggle[name] = value; };
    toggle.click = () => toggle.dispatchEvent(new Event('click'));
    const generate = new EventTarget();
    generate.dataset = { generatedMessage: 'Save the generated token.' };
    const announcements = [];
    const wp = { a11y: { speak: message => announcements.push(message) } };
    runInNewContext(readFileSync(join(__dirname, '../reprint-server-wp/wordpress/reprint-server.js'), 'utf8'), {
        document: {
            getElementById: id => id === 'reprint_server_connection_token' ? token : null,
            querySelector: selector => ({
                '.reprint-server-toggle-token': toggle,
                '.reprint-server-generate-token': generate,
            })[selector] || null,
        },
        window: { crypto: webcrypto, wp },
        wp,
    });

    assert.equal(token.value, 'saved-token');
    generate.dispatchEvent(new Event('click'));
    const firstToken = token.value;
    assert.match(firstToken, /^[0-9a-f]{64}$/);
    assert.equal(token.type, 'text');
    assert.equal(toggle['aria-pressed'], 'true');
    assert.equal(toggle['aria-label'], 'Hide token');

    generate.dispatchEvent(new Event('click'));
    assert.match(token.value, /^[0-9a-f]{64}$/);
    assert.notEqual(token.value, firstToken);
    assert.equal(token.type, 'text');
    assert.deepEqual(announcements, [generate.dataset.generatedMessage, generate.dataset.generatedMessage]);

    toggle.click();
    assert.equal(token.type, 'password');
    assert.equal(toggle['aria-pressed'], 'false');
    assert.equal(toggle['aria-label'], 'Show token');
});
