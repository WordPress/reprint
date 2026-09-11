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

    public function testPathParametersInApiUrlMoveToPostBody(): void
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
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'file_index', null);
        $post_params = $request['params'];
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $url_query);
        $this->assertArrayNotHasKey('directory', $url_query);
        $this->assertArrayNotHasKey('list_dir', $url_query);
        $this->assertArrayNotHasKey('pulled_before', $url_query);

        $this->assertSame(
            [base64_encode('/srv/site'), base64_encode('/srv/shared')],
            $post_params['directory']
        );
        $this->assertSame(['reprint-api' => ''], $url_query);
        $this->assertSame('file_index', $post_params['endpoint']);
        $this->assertSame('value', $post_params['unrelated']);
    }

    public function testFallsBackToRawPathsWithoutServerCapability(): void
    {
        $client = new \ImportClient(
            'https://example.com/?reprint-api&directory=%2Fsrv%2Fsite',
            $this->root . '/state',
            $this->root . '/files'
        );
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'file_index', null, [
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
            'https://example.com/?reprint-api&directory=%2Fsrv%2Fsite',
            $this->root . '/state',
            $this->root . '/files'
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'preflight', null);
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

        $this->assertSame('https://example.com/?site-export-api', $request['url']);
        $this->assertSame(['endpoint' => 'sql_chunk', 'route' => 'export'] + $params + ['cursor' => $cursor], $request['params']);
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
