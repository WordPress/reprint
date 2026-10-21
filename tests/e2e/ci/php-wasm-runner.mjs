#!/usr/bin/env node
import { createNodeFsMountHandler, loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { existsSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';

const DEFAULT_PHP_VERSION = '8.3';
const MYSQL_PARSER_EXTENSION = createRequire(import.meta.url)('./wp-mysql-parser-extension.json');

function envRecord() {
    return Object.fromEntries(
        Object.entries(process.env).filter(([, value]) => typeof value === 'string'),
    );
}

function extractAbsolutePaths(arg) {
    const paths = new Set();
    if (arg.startsWith('/')) {
        paths.add(arg);
    }

    for (const match of arg.matchAll(/(?:^|[=,&])(?<path>\/[^,;&\s]+)/g)) {
        paths.add(match.groups.path);
    }
    return paths;
}

function existingDirectory(pathCandidate) {
    let current;
    try {
        current = resolve(pathCandidate);
    } catch {
        return null;
    }

    while (!existsSync(current)) {
        const parent = dirname(current);
        if (parent === current) {
            return null;
        }
        current = parent;
    }

    try {
        const stat = statSync(current);
        return stat.isDirectory() ? current : dirname(current);
    } catch {
        return null;
    }
}

function isSameOrChild(child, parent) {
    return child === parent || child.startsWith(`${parent}/`);
}

function mountRootFor(pathCandidate) {
    const existing = existingDirectory(pathCandidate);
    if (!existing) {
        return null;
    }

    for (const root of ['/tmp', '/srv']) {
        if (isSameOrChild(existing, root) && existsSync(root)) {
            return root;
        }
    }

    const parts = existing.split('/').filter(Boolean);
    if (parts.length >= 2) {
        const root = `/${parts[0]}/${parts[1]}`;
        if (existsSync(root)) {
            return root;
        }
    }

    return existing;
}

function collectMounts(args) {
    const candidates = new Set([process.cwd()]);
    for (const root of ['/tmp', '/srv']) {
        if (existsSync(root)) {
            candidates.add(root);
        }
    }

    for (const arg of args) {
        for (const pathCandidate of extractAbsolutePaths(arg)) {
            candidates.add(pathCandidate);
        }
    }

    const roots = [...candidates]
        .map(mountRootFor)
        .filter(Boolean)
        .sort((a, b) => a.length - b.length);

    const mounts = [];
    for (const root of roots) {
        if (!mounts.some((parent) => isSameOrChild(root, parent))) {
            mounts.push(root);
        }
    }
    return mounts;
}

async function main() {
    const argv = process.argv.slice(2);
    const phpVersion = process.env.PLAYGROUND_PHP_VERSION || DEFAULT_PHP_VERSION;
    const extensions = ['intl'];
    if (process.env.WP_MYSQL_PARSER_EXTENSION === '1') {
        const artifact = MYSQL_PARSER_EXTENSION.artifacts[phpVersion];
        if (!artifact) {
            throw new Error(`No recorded wp_mysql_parser extension for PHP ${phpVersion}.`);
        }
        const response = await fetch(artifact.url);
        if (!response.ok) {
            throw new Error(`wp_mysql_parser download returned HTTP ${response.status}: ${artifact.url}`);
        }
        const bytes = new Uint8Array(await response.arrayBuffer());
        const checksum = createHash('sha256').update(bytes).digest('hex');
        if (checksum !== artifact.sha256) {
            throw new Error(`SHA-256 mismatch for wp_mysql_parser PHP ${phpVersion}: expected ${artifact.sha256}, received ${checksum}.`);
        }
        extensions.push({ source: { format: 'so', name: 'wp_mysql_parser', bytes } });
    }

    const php = new PHP(await loadNodeRuntime(phpVersion, {
        extensions,
        emscriptenOptions: {
            processId: process.pid,
        },
    }));
    for (const mountPath of collectMounts(argv)) {
        await php.mount(mountPath, createNodeFsMountHandler(mountPath));
    }
    php.chdir(process.cwd());
    await php.setSpawnHandler(spawn);

    const response = await php.cli(['php', ...argv], {
        cwd: process.cwd(),
        env: envRecord(),
    });
    const [stdoutBytes, stderrText, exitCode] = await Promise.all([
        response.stdoutBytes,
        response.stderrText,
        response.exitCode,
    ]);
    if (stdoutBytes.length > 0) {
        process.stdout.write(Buffer.from(stdoutBytes));
    }
    if (stderrText.length > 0) {
        process.stderr.write(stderrText);
    }
    php[Symbol.dispose]?.();
    process.exitCode = exitCode;
}

main().catch((error) => {
    console.error(error?.stack || error);
    process.exitCode = 1;
});
