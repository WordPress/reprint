<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use function WordPress\Reprint\Server\build_pdo_dsn;

class BuildPdoDsnTest extends TestCase
{
    private string $tmpSocket;

    protected function setUp(): void
    {
        $this->tmpSocket = tempnam(sys_get_temp_dir(), 'sock_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpSocket)) {
            unlink($this->tmpSocket);
        }
    }

    public function testPlainHostname(): void
    {
        $dsn = build_pdo_dsn('localhost', 'wp_db');
        $this->assertSame('mysql:host=localhost;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testHostnameWithPort(): void
    {
        $dsn = build_pdo_dsn('db.host.com:3307', 'wp_db');
        $this->assertSame('mysql:host=db.host.com;port=3307;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testHostnameWithSocket(): void
    {
        $dsn = build_pdo_dsn("localhost:{$this->tmpSocket}", 'wp_db');
        $this->assertSame("mysql:unix_socket={$this->tmpSocket};dbname=wp_db;charset=utf8mb4", $dsn);
    }

    public function testHostnameWithNonexistentSocketTreatedAsPort(): void
    {
        $dsn = build_pdo_dsn('localhost:/no/such/file.sock', 'wp_db');
        $this->assertSame('mysql:host=localhost;port=/no/such/file.sock;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBareSocketPath(): void
    {
        $dsn = build_pdo_dsn($this->tmpSocket, 'wp_db');
        $this->assertSame("mysql:unix_socket={$this->tmpSocket};dbname=wp_db;charset=utf8mb4", $dsn);
    }

    public function testBareNonexistentPathTreatedAsHost(): void
    {
        $dsn = build_pdo_dsn('/no/such/mysql.sock', 'wp_db');
        $this->assertSame('mysql:host=/no/such/mysql.sock;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testIpAddressWithPort(): void
    {
        $dsn = build_pdo_dsn('127.0.0.1:3306', 'mydb');
        $this->assertSame('mysql:host=127.0.0.1;port=3306;dbname=mydb;charset=utf8mb4', $dsn);
    }

    public function testIpAddressOnly(): void
    {
        $dsn = build_pdo_dsn('127.0.0.1', 'mydb');
        $this->assertSame('mysql:host=127.0.0.1;dbname=mydb;charset=utf8mb4', $dsn);
    }

    public function testBareIpv6(): void
    {
        $dsn = build_pdo_dsn('::1', 'wp_db');
        $this->assertSame('mysql:host=::1;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBracketedIpv6(): void
    {
        $dsn = build_pdo_dsn('[::1]', 'wp_db');
        $this->assertSame('mysql:host=::1;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBracketedIpv6WithPort(): void
    {
        $dsn = build_pdo_dsn('[::1]:3306', 'wp_db');
        $this->assertSame('mysql:host=::1;port=3306;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testBracketedIpv6WithSocket(): void
    {
        $dsn = build_pdo_dsn("[::1]:{$this->tmpSocket}", 'wp_db');
        $this->assertSame("mysql:unix_socket={$this->tmpSocket};dbname=wp_db;charset=utf8mb4", $dsn);
    }

    public function testBracketedIpv6WithNonexistentSocketTreatedAsPort(): void
    {
        $dsn = build_pdo_dsn('[::1]:/no/such/file.sock', 'wp_db');
        $this->assertSame('mysql:host=::1;port=/no/such/file.sock;dbname=wp_db;charset=utf8mb4', $dsn);
    }

    public function testFullIpv6Address(): void
    {
        $dsn = build_pdo_dsn('2001:db8::1', 'wp_db');
        $this->assertSame('mysql:host=2001:db8::1;dbname=wp_db;charset=utf8mb4', $dsn);
    }
}
