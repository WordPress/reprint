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

    public function testConstructorPreservesTheSuppliedScheme(): void
    {
        foreach ([
            ['https://example.com/?reprint-api', false],
            ['https://example.com/?reprint-api', true],
            ['http://example.com/?reprint-api', true],
            ['HTTPS://Example.com/?reprint-api', false],
            ['HTTP://Example.com/?reprint-api', true],
        ] as [$remote_reprint_api_url, $allow_http]) {
            $client = new \ImportClient(
                $remote_reprint_api_url,
                $this->root . '/state',
                $this->root . '/files',
                ['allow_http' => $allow_http, 'unrelated_option' => 'ignored']
            );
            $this->assertSame($remote_reprint_api_url, $client->remote_reprint_api_url);
        }
    }

    public function testConstructorRejectsHttpBeforeCreatingRemoteState(): void
    {
        foreach ([
            'http://example.com/',
            'http://localhost:8080/',
            'http://127.0.0.1:8080/',
            'http://192.168.1.2/',
            'HTTP://Example.com:8080/path?next=http://other.example/#fragment',
        ] as $remote_reprint_api_url) {
            try {
                new \ImportClient($remote_reprint_api_url, $this->root . '/state', $this->root . '/files');
                $this->fail('An HTTP remote Reprint API URL requires explicit permission.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('The remote Reprint API URL you provided uses HTTP.', $error->getMessage());
                $this->assertStringContainsString('--insecure', $error->getMessage());
            }
            $this->assertDirectoryDoesNotExist($this->root . '/state/remotes');
        }
    }

    public function testConstructorRequiresAnExplicitHttpOrHttpsScheme(): void
    {
        foreach ([
            'example.com/?reprint-api',
            'example.com:8080/?reprint-api',
            '//example.com/?reprint-api',
            'ftp://example.com/site',
            'file:///tmp/export.json',
            'https:example.com',
            'https:/example.com',
            'user:some-password@example.com',
        ] as $remote_reprint_api_url) {
            foreach ([false, true] as $allow_http) {
                try {
                    new \ImportClient($remote_reprint_api_url, $this->root . '/state', $this->root . '/files', ['allow_http' => $allow_http]);
                    $this->fail('A remote Reprint API URL needs an explicit HTTP or HTTPS scheme.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertStringContainsString('must start with https:// or http://', $error->getMessage());
                    $this->assertStringNotContainsString('some-password', $error->getMessage());
                }
                $this->assertDirectoryDoesNotExist($this->root . '/state/remotes');
            }
        }
    }

    public function testConstructorRejectsInvalidOptionsBeforeCreatingRemoteState(): void
    {
        foreach ([
            [['insecure' => 'false'], 'insecure option must be a boolean'],
            [['insecure' => 1], 'insecure option must be a boolean'],
            [['insecure' => null], 'insecure option must be a boolean'],
            [['allow_http' => 'false'], 'allow_http option must be a boolean'],
            [['allow_http' => 1], 'allow_http option must be a boolean'],
            [['allow_http' => null], 'allow_http option must be a boolean'],
            [['signal_handling_command' => false], 'signal_handling_command option must be a string or null'],
            [['selected_remote_state_directory' => false], 'selected_remote_state_directory option must be a string or null'],
        ] as [$options, $message]) {
            try {
                new \ImportClient('https://example.com/', $this->root . '/state', $this->root . '/files', $options);
                $this->fail('Invalid constructor options must be rejected.');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
            $this->assertDirectoryDoesNotExist($this->root . '/state/remotes');
        }
    }

    public function testConstructorRejectsAFragmentBeforeCreatingRemoteState(): void
    {
        // Every request appends its endpoint to the API URL, which would put
        // it inside the fragment. cURL never sends a fragment.
        foreach ([
            'https://example.com/?reprint-api#fragment?with=query&',
            'https://example.com/?reprint-api#',
            'https://example.com/#section',
        ] as $remote_reprint_api_url) {
            try {
                new \ImportClient($remote_reprint_api_url, $this->root . '/state', $this->root . '/files');
                $this->fail('A remote Reprint API URL with a fragment must be rejected: ' . $remote_reprint_api_url);
            } catch (\InvalidArgumentException $error) {
                $this->assertSame(
                    'The remote Reprint API URL must not contain a fragment: ' . $remote_reprint_api_url . '. ' .
                    'Remove # and everything after it. URL fragments are not sent to the server; ' .
                    'the endpoint Reprint appends there would not reach the server either.',
                    $error->getMessage()
                );
            }
            $this->assertDirectoryDoesNotExist($this->root . '/state/remotes');
        }
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

    /** @dataProvider branded_path_requests */
    public function testPathParametersUseTheAdvertisedRot13Base64Format(?string $cursor, bool $legacy_capability): void
    {
        $client = new \ImportClient('https://example.com/?reprint-api', $this->root . '/state', $this->root . '/files');
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => [
                'base64_path_parameters' => $legacy_capability,
                'rot13_base64_request_values' => true,
            ]],
        ]);
        $binary_path = "/srv/binary-\xff";
        $request = (new \ReflectionMethod($client, 'build_request'))->invoke($client, 'file_index', $cursor, [
            'directory' => [2 => '/tmp', 5 => $binary_path],
            'list_dir' => '/tmp',
            'pulled_before' => ['D:\\Sites\\removed'],
        ]);

        $this->assertSame('https://example.com/?reprint-api&endpoint=file_index', $request['url']);
        $this->assertSame([2 => 'rp.Y3EgpN==', 5 => 'rp.' . str_rot13(base64_encode($binary_path))], $request['params']['directory']);
        $this->assertSame('rp.Y3EgpN==', $request['params']['list_dir']);
        $this->assertSame(['rp.' . str_rot13(base64_encode('D:\\Sites\\removed'))], $request['params']['pulled_before']);
        $this->assertSame($cursor === null ? null : 'rp.' . str_rot13($cursor), $request['params']['cursor'] ?? null);
        $this->assertFalse(base64_decode($request['params']['list_dir'], true));
        $this->assertSame('rp.', urlencode('rp.'));
        parse_str(http_build_query($request['params']), $received);
        $this->assertSame($request['params'], $received);
    }

    public static function branded_path_requests(): array
    {
        return [
            'both capabilities' => [null, true],
            'new capability alone' => [null, false],
            'resumed request' => [base64_encode('{"stack":[]}'), true],
        ];
    }

    /** @dataProvider remote_api_urls */
    public function testSuppliedUrlOnlyGainsTheEndpointAndDoesNotSupplyBodyParameters(string $remote_api_url, string $expected_url): void
    {
        $client = new \ImportClient(
            $remote_api_url,
            $this->root . '/state',
            $this->root . '/files',
            ['allow_http' => true]
        );
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $build_request = (new \ReflectionClass($client))->getMethod('build_request');

        $request = $build_request->invoke($client, 'file_index', null, [
            'directory' => ['/srv/body-directory'],
        ]);

        $this->assertSame($expected_url, $request['url']);
        $this->assertSame([
            'directory' => [base64_encode('/srv/body-directory')],
            'multisite_mode' => 'one-site-network-v1',
        ], $request['params']);
    }

    public static function remote_api_urls(): array
    {
        return [
            'plain endpoint' => ['https://example.com/export.php', 'https://example.com/export.php?endpoint=file_index'],
            'empty query' => ['https://example.com/export.php?', 'https://example.com/export.php?endpoint=file_index'],
            'routing marker' => ['https://example.com/?reprint-api', 'https://example.com/?reprint-api&endpoint=file_index'],
            'legacy marker' => ['https://example.com/?site-export-api=1', 'https://example.com/?site-export-api=1&endpoint=file_index'],
            'trailing separator' => ['https://example.com/?reprint-api&', 'https://example.com/?reprint-api&endpoint=file_index'],
            'encoded marker' => ['https://example.com/?%72eprint%2Dapi', 'https://example.com/?%72eprint%2Dapi&endpoint=file_index'],
            'array marker' => ['https://example.com/?reprint-api%5B%5D=1', 'https://example.com/?reprint-api%5B%5D=1&endpoint=file_index'],
            'leading encoded space' => ['https://example.com/?%20reprint-api=1', 'https://example.com/?%20reprint-api=1&endpoint=file_index'],
            'encoded null' => ['https://example.com/?reprint-api%00suffix=1', 'https://example.com/?reprint-api%00suffix=1&endpoint=file_index'],
            'both markers' => ['https://example.com/?site-export-api&reprint-api', 'https://example.com/?site-export-api&reprint-api&endpoint=file_index'],
            'duplicate keys' => ['https://example.com/?reprint-api&route=one&route=two', 'https://example.com/?reprint-api&route=one&route=two&endpoint=file_index'],
            'empty value' => ['https://example.com/?reprint-api&route=', 'https://example.com/?reprint-api&route=&endpoint=file_index'],
            'semicolon' => ['https://example.com/?reprint-api=1;route=export', 'https://example.com/?reprint-api=1;route=export&endpoint=file_index'],
            'encoded delimiters' => [
                'https://example.com/a%2fb%3fc?route=a%26b%3Dc%23d&reprint-api',
                'https://example.com/a%2fb%3fc?route=a%26b%3Dc%23d&reprint-api&endpoint=file_index',
            ],
            'nested options' => [
                'https://example.com/?reprint-api&directory%5B%5D=%2Fsrv%2Furl-directory&endpoint=preflight',
                'https://example.com/?reprint-api&directory%5B%5D=%2Fsrv%2Furl-directory&endpoint=preflight&endpoint=file_index',
            ],
            'IPv6 address' => ['http://[::1]:8080/export.php?route=export&reprint-api', 'http://[::1]:8080/export.php?route=export&reprint-api&endpoint=file_index'],
            'Unicode path' => ['https://example.com/łódź/?reprint-api', 'https://example.com/łódź/?reprint-api&endpoint=file_index'],
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
            'data' => ['capabilities' => [
                'base64_path_parameters' => true,
                'rot13_base64_request_values' => true,
            ]],
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
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['rot13_base64_request_values' => true]],
        ]);
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

        $this->assertSame('https://example.com/?site-export-api&route=export&endpoint=sql_chunk', $request['url']);
        $this->assertSame($params + [
            'multisite_mode' => 'one-site-network-v1',
            'cursor' => 'rp.' . str_rot13($cursor),
        ], $request['params']);
    }

    public function testCursorKeepsItsLegacyEncodingWithoutTheNewCapability(): void
    {
        $client = new \ImportClient('https://example.com/', $this->root . '/state', $this->root . '/files');
        $client->get_state()->set_preflight_record([
            'data' => ['capabilities' => ['base64_path_parameters' => true]],
        ]);
        $cursor = base64_encode('{"path":"L3RtcA==","bytes":16384}');
        $request = (new \ReflectionMethod($client, 'build_request'))->invoke($client, 'file_fetch', $cursor);
        $this->assertSame($cursor, $request['params']['cursor']);
    }

    /** @dataProvider sql_output_modes */
    public function testSetFormatMatchesSqlOutputMode(string $mode, ?string $cursor): void
    {
        $client = new \ImportClient('https://example.com/', $this->root . '/state', $this->root . '/files');
        (new \ReflectionProperty($client, 'sql_output_mode'))->setValue($client, $mode);
        $build_request = new \ReflectionMethod($client, 'build_request');
        $request = $build_request->invoke($client, 'sql_chunk', $cursor);
        // Files and stdout can later be imported into SQLite. Only direct
        // MySQL output can request masks without a SQLite label converter.
        $this->assertSame($mode === 'mysql' ? 'unsigned' : null, $request['params']['set_value_format'] ?? null);
        $request = $build_request->invoke($client, 'preflight', null);
        $this->assertArrayNotHasKey('set_value_format', $request['params']);
    }

    /** Initial and resumed requests must choose the same SET representation. */
    public static function sql_output_modes(): array
    {
        return [
            'file' => ['file', null],
            'stdout' => ['stdout', null],
            'mysql' => ['mysql', null],
            'resumed file' => ['file', 'saved-cursor'],
            'resumed stdout' => ['stdout', 'saved-cursor'],
            'resumed mysql' => ['mysql', 'saved-cursor'],
        ];
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
