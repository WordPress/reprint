<?php

use Throwable as FixtureThrowable;

$fixture_calls = 0;
$fixture_map_calls = 0;

function fixture_nullable_value()
{
    ++$GLOBALS['fixture_calls'];
    return null;
}

function fixture_map()
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
    const DEFAULT_HOST = 'localhost';

    private $optional;

    /** Kept on the generated method. */
    public function render($value, array $options = [])
    {
        $host = isset($options['host']) ? $options['host'] : self::DEFAULT_HOST;
        $optional = isset($this->optional) ? $this->optional : 'property-default';
        list($address, $port) = $options['address'];
        $__reprint_php56_destructure_37_786 = $options['metadata'];
        // Kept with the generated assignment.
        $name = $__reprint_php56_destructure_37_786['name'];
        $enabled = $__reprint_php56_destructure_37_786['enabled'];
        $secret = ($__reprint_php56_coalesce_42_955 = fixture_nullable_value()) !== null ? $__reprint_php56_coalesce_42_955 : 'secret-default';
        $user = ($__reprint_php56_coalesce_43_1017 = fixture_map()) !== null && isset($__reprint_php56_coalesce_43_1017['user']) ? $__reprint_php56_coalesce_43_1017['user'] : 'map-default';
        $nested = ($__reprint_php56_coalesce_44_1075 = isset($options['nested']) ? $options['nested'] : null) !== null ? $__reprint_php56_coalesce_44_1075 : 'nested-default';

        return implode('|', [$value, $host, $optional, $address, $port, $name, $enabled, $secret, $user, $nested]);
    }

    public function preservedHints(
        array $items,
        callable $formatter,
        Php72SyntaxFixture $other,
        self $same,
        parent $parent,
        $throwable
    ) {
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
