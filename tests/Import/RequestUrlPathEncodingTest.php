<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match existing importer test class style.
// phpcs:disable Generic.WhiteSpace.ArbitraryParenthesesSpacing -- Match existing importer test call style.
// phpcs:disable WordPress.WhiteSpace.CastStructureSpacing -- Match existing importer test cast style.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

final class RequestUrlPathEncodingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/request-url-path-encoding-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/state', 0700, true);
        mkdir($this->root . '/files', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove_tree($this->root);
    }

    public function testPathParametersAreBase64Encoded(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api',
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_url = (new \ReflectionClass($client))->getMethod('build_url');
        $binary_path = "/srv/binary-\xff";

        $url = $build_url->invoke($client, 'file_index', null, [
            'directory' => ['/srv/site', $binary_path],
            'list_dir' => '/srv/site',
            'pulled_before' => ['/srv/site/removed'],
        ]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(
            [base64_encode('/srv/site'), base64_encode($binary_path)],
            $query['directory']
        );
        $this->assertSame(base64_encode('/srv/site'), $query['list_dir']);
        $this->assertSame(
            [base64_encode('/srv/site/removed')],
            $query['pulled_before']
        );
    }

    public function testPathParametersInApiUrlAreBase64Encoded(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api&directory%5B%5D=%2Fsrv%2Fsite'
                . '&directory%5B%5D=%2Fsrv%2Fshared&unrelated=value',
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_url = (new \ReflectionClass($client))->getMethod('build_url');

        $url = $build_url->invoke($client, 'file_index', null);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame(
            [base64_encode('/srv/site'), base64_encode('/srv/shared')],
            $query['directory']
        );
        $this->assertSame('value', $query['unrelated']);
    }

    public function testFallsBackToRawPathsWithoutServerCapability(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api&directory=%2Fsrv%2Fsite',
            $this->root . '/state',
            $this->root . '/files'
        );
        $build_url = (new \ReflectionClass($client))->getMethod('build_url');

        $url = $build_url->invoke($client, 'file_index', null, [
            'list_dir' => '/srv/site',
        ]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('/srv/site', $query['directory']);
        $this->assertSame('/srv/site', $query['list_dir']);
    }

    public function testPreflightKeepsRawPathsAfterCapabilityWasRecorded(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api&directory=%2Fsrv%2Fsite',
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_url = (new \ReflectionClass($client))->getMethod('build_url');

        $url = $build_url->invoke($client, 'preflight', null);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('/srv/site', $query['directory']);
        $this->assertArrayNotHasKey('directory_b64', $query);
    }

    private function remove_tree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove_tree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
