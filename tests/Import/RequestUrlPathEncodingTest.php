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

    public function testPathParametersAreBase64EncodedInPostBody(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api',
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');
        $binary_path = "/srv/binary-\xff";

        $request = $build_request->invoke($client, 'file_index', null, [
            'directory' => ['/srv/site', $binary_path],
            'list_dir' => '/srv/site',
            'pulled_before' => ['/srv/site/removed'],
        ]);
        $post_params = $request['params'];
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $url_query);
        $this->assertArrayNotHasKey('directory', $url_query);
        $this->assertArrayNotHasKey('list_dir', $url_query);
        $this->assertArrayNotHasKey('pulled_before', $url_query);

        $this->assertSame(
            [base64_encode('/srv/site'), base64_encode($binary_path)],
            $post_params['directory']
        );
        $this->assertSame(base64_encode('/srv/site'), $post_params['list_dir']);
        $this->assertSame(
            [base64_encode('/srv/site/removed')],
            $post_params['pulled_before']
        );
    }

    /** @dataProvider remote_api_urls */
    public function testSuppliedUrlIsUnchangedAndDoesNotSupplyBodyParameters(string $remote_api_url): void
    {
        $client = new \ImportClient(
            $remote_api_url,
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'file_index', null, [
            'directory' => ['/srv/body-directory'],
        ]);

        $this->assertSame($remote_api_url, $request['url']);
        $this->assertSame([
            'endpoint' => 'file_index',
            'directory' => [base64_encode('/srv/body-directory')],
            'multisite_mode' => 'one-site-network-v1',
        ], $request['params']);
    }

    public static function remote_api_urls(): array
    {
        return [
            'plain endpoint' => ['https://example.com/export.php'],
            'empty query' => ['https://example.com/export.php?'],
            'routing marker' => ['https://example.com/?reprint-api'],
            'legacy marker' => ['https://example.com/?site-export-api=1'],
            'trailing separator' => ['https://example.com/?reprint-api&'],
            'encoded marker' => ['https://example.com/?%72eprint%2Dapi'],
            'array marker' => ['https://example.com/?reprint-api%5B%5D=1'],
            'leading encoded space' => ['https://example.com/?%20reprint-api=1'],
            'encoded null' => ['https://example.com/?reprint-api%00suffix=1'],
            'both markers' => ['https://example.com/?site-export-api&reprint-api'],
            'duplicate keys' => ['https://example.com/?reprint-api&route=one&route=two'],
            'empty value' => ['https://example.com/?reprint-api&route='],
            'semicolon' => ['https://example.com/?reprint-api=1;route=export'],
            'encoded delimiters' => ['https://example.com/a%2fb%3fc?route=a%26b%3Dc%23d&reprint-api'],
            'nested options' => ['https://example.com/?reprint-api&directory%5B%5D=%2Fsrv%2Furl-directory&endpoint=preflight'],
            'fragment' => ['https://example.com/?reprint-api#fragment?with=query&'],
            'IPv6 address' => ['http://[::1]:8080/export.php?route=export&reprint-api'],
            'Unicode path' => ['https://example.com/łódź/?reprint-api'],
        ];
    }

    public function testFallsBackToRawPathsWithoutServerCapability(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api',
            $this->root . '/state',
            $this->root . '/files'
        );
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'file_index', null, [
            'directory' => '/srv/site',
            'list_dir' => '/srv/site',
        ]);
        $post_params = $request['params'];
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $url_query);
        $this->assertArrayNotHasKey('directory', $url_query);
        $this->assertArrayNotHasKey('list_dir', $url_query);
        $this->assertArrayNotHasKey('pulled_before', $url_query);

        $this->assertSame('/srv/site', $post_params['directory']);
        $this->assertSame('/srv/site', $post_params['list_dir']);
    }

    public function testPreflightKeepsRawPathsAfterCapabilityWasRecorded(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api',
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'preflight', null, ['directory' => '/srv/site']);
        $post_params = $request['params'];
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $url_query);
        $this->assertArrayNotHasKey('directory', $url_query);
        $this->assertArrayNotHasKey('list_dir', $url_query);
        $this->assertArrayNotHasKey('pulled_before', $url_query);

        $this->assertSame('/srv/site', $post_params['directory']);
        $this->assertArrayNotHasKey('directory_b64', $post_params);
    }

    public function testCursorAndRowFiltersTravelInThePostBody(): void
    {
        $client = new \ImportClient(
            'https://example.com/?site-export-api&route=export',
            $this->root . '/state',
            $this->root . '/files'
        );
        $cursor = base64_encode('{"table":"wp_postmeta","offset":250}');
        $params = [
            'skip_rows' => [[
                'table_name_without_prefix' => 'postmeta',
                'column' => 'meta_key',
                'value_base64' => base64_encode('_edit_lock'),
            ]],
        ];
        $request = (new \ReflectionMethod($client, 'build_request'))->invoke(
            $client, 'sql_chunk', $cursor, $params
        );

        $this->assertSame('https://example.com/?site-export-api&route=export', $request['url']);
        $this->assertSame(['endpoint' => 'sql_chunk'] + $params + [
            'multisite_mode' => 'one-site-network-v1',
            'cursor' => $cursor,
        ], $request['params']);
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
