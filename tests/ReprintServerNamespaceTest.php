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

    #[DataProvider('renamedServerClasses')]
    public function testCompatibilityLoaderResolvesLegacyAliasBeforeCanonicalClass(
        string $canonical_class,
        string $legacy_class
    ): void
    {
        $autoload_path = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertNotFalse($autoload_path, 'Composer autoload.php must exist.');
        $compatibility_path = realpath(__DIR__ . '/../packages/reprint-server/src/compat.php');
        $this->assertNotFalse($compatibility_path, 'The compatibility loader must exist.');
        $script = <<<'PHP'
require $argv[1];
require $argv[2];
$legacy_class = $argv[3];
if (!class_exists($legacy_class)) {
    fwrite(STDERR, 'Could not autoload ' . $legacy_class . '.');
    exit(1);
}
echo (new ReflectionClass($legacy_class))->getName();
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, $autoload_path, $compatibility_path, $legacy_class],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'Failed to start the legacy class autoload subprocess.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr ?: 'The legacy class autoload subprocess failed.');
        $this->assertSame($canonical_class, $stdout);
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
