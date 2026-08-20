<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReprintServerNamespaceTest extends TestCase {
    /** @return iterable<string,array{string,string}> */
    public static function renamedServerClasses(): iterable
    {
        yield 'HMAC server' => [
            WordPress\Reprint\Server\HMACServer::class,
            'Site_Export_HMAC_Server',
        ];
        yield 'HTTP server' => [
            WordPress\Reprint\Server\HTTPServer::class,
            'Site_Export_HTTP_Server',
        ];
        yield 'multipart processor' => [
            WordPress\Reprint\Server\MultipartProcessor::class,
            'Site_Export_Multipart_Processor',
        ];
        yield 'push configuration exception' => [
            WordPress\Reprint\Server\PushConfigurationException::class,
            'Site_Export_Push_Configuration_Exception',
        ];
        yield 'push endpoints' => [
            WordPress\Reprint\Server\PushEndpoints::class,
            'Site_Export_Push_Endpoints',
        ];
        yield 'push exception' => [
            WordPress\Reprint\Server\PushException::class,
            'Site_Export_Push_Exception',
        ];
        yield 'push session' => [
            WordPress\Reprint\Server\PushSession::class,
            'Site_Export_Push_Session',
        ];
    }

    #[DataProvider('renamedServerClasses')]
    public function testCanonicalClassAndLegacyAliasBothAutoload(string $canonical_class, string $legacy_class): void
    {
        $this->assertTrue(class_exists($canonical_class));
        $this->assertTrue(class_exists($legacy_class));
        $this->assertSame($canonical_class, ( new ReflectionClass($legacy_class) )->getName());
    }

    public function testClientHmacClassKeepsItsReleasedNameAndFile(): void
    {
        $this->assertTrue(class_exists('Site_Export_HMAC_Client'));
        $this->assertFalse(class_exists('WordPress\\Reprint\\Server\\HMACClient'));
        $reflection = new ReflectionClass('Site_Export_HMAC_Client');
        $this->assertSame(
            realpath(__DIR__ . '/../packages/reprint-server/src/class-hmac-client.php'),
            $reflection->getFileName()
        );
    }
}
