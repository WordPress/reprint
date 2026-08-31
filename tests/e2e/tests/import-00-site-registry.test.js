import { describe, it } from 'vitest';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const REGISTRY = createRequire(import.meta.url)('../site-registry.json');

describe('E2E site registry', () => {
    it('assigns a different port to every site', () => {
        const siteByPort = new Map();

        for (const [site, config] of Object.entries(REGISTRY.sites)) {
            assert.equal(
                siteByPort.has(config.port),
                false,
                `${site} and ${siteByPort.get(config.port)} both use port ${config.port}`,
            );
            siteByPort.set(config.port, site);
        }
    });
});
