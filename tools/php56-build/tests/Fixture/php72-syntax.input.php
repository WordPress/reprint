<?php

use Throwable as FixtureThrowable;

$fixture_calls = 0;
$fixture_map_calls = 0;

function fixture_nullable_value(): ?string
{
    ++$GLOBALS['fixture_calls'];
    return null;
}

function fixture_map(): array
{
    ++$GLOBALS['fixture_map_calls'];
    return ['user' => 'mapped'];
}

class Php56ParentFixture
{
}

final class Php72SyntaxFixture extends Php56ParentFixture
{
    /** Kept on the generated constant. */
    private const DEFAULT_HOST = 'localhost';

    private $optional;

    /** Kept on the generated method. */
    public function render(?string $value, array $options = []): string
    {
        $host = $options['host'] ?? self::DEFAULT_HOST;
        $optional = $this->optional ?? 'property-default';
        [$address, $port] = $options['address'];
        [
            // Kept with the generated assignment.
            'name' => $name,
            'enabled' => $enabled,
        ] = $options['metadata'];
        $secret = fixture_nullable_value() ?? 'secret-default';
        $user = fixture_map()['user'] ?? 'map-default';
        $nested = ($options['nested'] ?? null) ?? 'nested-default';

        return implode('|', [$value, $host, $optional, $address, $port, $name, $enabled, $secret, $user, $nested]);
    }

    public function preservedHints(
        array $items,
        callable $formatter,
        Php72SyntaxFixture $other,
        self $same,
        parent $parent,
        FixtureThrowable $throwable
    ): string {
        return $formatter($items[0]);
    }
}

echo json_encode([
    (new Php72SyntaxFixture())->render(null, [
        'address' => ['127.0.0.1', 8080],
        'metadata' => ['name' => 'fixture', 'enabled' => true],
    ]),
    $fixture_calls,
    $fixture_map_calls,
]);
