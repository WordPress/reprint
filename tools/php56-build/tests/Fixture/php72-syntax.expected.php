<?php

use Throwable as FixtureThrowable;

$fixture_calls = 0;
$fixture_map_calls = 0;
$fixture_static_calls = 0;
$fixture_method_calls = 0;

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

    public static function nullableStatic()
    {
        ++$GLOBALS['fixture_static_calls'];
        return null;
    }

    public function nullableMethod()
    {
        ++$GLOBALS['fixture_method_calls'];
        return null;
    }

    /** Kept on the generated method. */
    public function render($value, array $options = [])
    {
        $host = isset($options['host']) ? $options['host'] : self::DEFAULT_HOST;
        $optional = isset($this->optional) ? $this->optional : 'property-default';
        list($address, $port) = $options['address'];
        $__reprint_php56_destructure_51_1095 = $options['metadata'];
        // Kept with the generated assignment.
        $name = $__reprint_php56_destructure_51_1095['name'];
        $enabled = $__reprint_php56_destructure_51_1095['enabled'];
        $secret = ($__reprint_php56_coalesce_56_1264 = fixture_nullable_value()) !== null ? $__reprint_php56_coalesce_56_1264 : 'secret-default';
        $user = ($__reprint_php56_coalesce_57_1326 = fixture_map()) !== null && isset($__reprint_php56_coalesce_57_1326['user']) ? $__reprint_php56_coalesce_57_1326['user'] : 'map-default';
        $nested = ($__reprint_php56_coalesce_58_1384 = isset($options['nested']) ? $options['nested'] : null) !== null ? $__reprint_php56_coalesce_58_1384 : 'nested-default';
        $static = ($__reprint_php56_coalesce_59_1452 = self::nullableStatic()) !== null ? $__reprint_php56_coalesce_59_1452 : 'static-default';
        $method = ($__reprint_php56_coalesce_60_1514 = $this->nullableMethod()) !== null ? $__reprint_php56_coalesce_60_1514 : 'method-default';

        return implode('|', [$value, $host, $optional, $address, $port, $name, $enabled, $secret, $user, $nested, $static, $method]);
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
    $fixture_static_calls,
    $fixture_method_calls,
]);
