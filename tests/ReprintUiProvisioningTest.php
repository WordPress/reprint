<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../reprint-ui/lib/wpcom-reprint-api.php';

class ReprintUiProvisioningTest extends TestCase {
    public function testSelectsJetpackWhenEnableRouteExists(): void
    {
        $response = $this->bridgeResponse(200, ['enabled_at' => 1700000000]);

        $this->assertSame([
            'surface' => 'jetpack',
            'rotate_secret_path' => '/jetpack/v4/reprint/rotate-export-secret',
            'query_parameter' => 'reprint-api-jetpack',
        ], reprint_wpcom_export_api($response));
    }

    public function testFallsBackToWpcomshForEnvelope404(): void
    {
        $response = $this->bridgeResponse(404, ['code' => 'rest_no_route']);

        $this->assertSame([
            'surface' => 'wpcomsh',
            'rotate_secret_path' => '/wpcomsh/v1/reprint/rotate-export-secret',
            'query_parameter' => 'reprint-api',
        ], reprint_wpcom_export_api($response));
    }

    public function testFallsBackToWpcomshForEncodedRestNoRouteBody(): void
    {
        $response = $this->bridgeResponse(200, json_encode([
            'code' => 'rest_no_route',
            'message' => 'No route was found matching the URL and request method.',
        ]));

        $this->assertSame('wpcomsh', reprint_wpcom_export_api($response)['surface']);
    }

    public function testDoesNotHideOtherProbeFailuresBehindWpcomshFallback(): void
    {
        $response = $this->bridgeResponse(403, ['code' => 'rest_forbidden']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Jetpack enable-export probe reported status 403');

        reprint_wpcom_export_api($response);
    }

    public function testReportsOuterProbeFailureStatus(): void
    {
        $response = [
            'status' => 401,
            'body' => '{"error":"authorization_required"}',
            'json' => ['error' => 'authorization_required'],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Jetpack enable-export probe reported status 401');

        reprint_wpcom_export_api($response);
    }

    /**
     * @dataProvider secretResponseProvider
     *
     * @param array $response {
     *     Jetpack bridge response fixture.
     *
     *     @type int    $status Outer HTTP status.
     *     @type string $body   Raw response body.
     *     @type array  $json   Decoded response body.
     * }
     */
    public function testReadsBothJetpackAndWpcomshSecretShapes(array $response): void
    {
        $this->assertSame('rotated-secret', reprint_wpcom_export_secret($response));
    }

    /**
     * @return array<string,array{0:array{status:int,body:string,json:array<mixed>}}>
     */
    public static function secretResponseProvider(): array
    {
        return [
            'Jetpack flat body' => [
                self::bridgeResponseFixture(200, ['secret' => 'rotated-secret']),
            ],
            'wpcomsh encoded nested body' => [
                self::bridgeResponseFixture(200, json_encode([
                    'data' => ['secret' => 'rotated-secret'],
                ])),
            ],
        ];
    }

    public function testReportsRotateFailureStatus(): void
    {
        $response = $this->bridgeResponse(500, ['error' => 'Failed to persist the new secret.']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The rotate-export-secret request reported status 500');

        reprint_wpcom_export_secret($response);
    }

    public function testRejectsRotateResponseWithoutASecret(): void
    {
        $response = $this->bridgeResponse(200, ['enabled' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not contain a non-empty secret');

        reprint_wpcom_export_secret($response);
    }

    /**
     * @param mixed $body Inner response body.
     * @return array{status:int,body:string,json:array<mixed>}
     */
    private function bridgeResponse(int $code, $body): array
    {
        return self::bridgeResponseFixture($code, $body);
    }

    /**
     * @param mixed $body Inner response body.
     * @return array{status:int,body:string,json:array<mixed>}
     */
    private static function bridgeResponseFixture(int $code, $body): array
    {
        $raw_body = (string) json_encode([
            'code' => $code,
            'body' => $body,
        ]);

        return [
            'status' => 200,
            'body' => $raw_body,
            'json' => json_decode( $raw_body, true ),
        ];
    }
}
