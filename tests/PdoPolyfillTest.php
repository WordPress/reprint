<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Keeps the PDO polyfill invisible to class scanners.
 *
 * src/class-pdo-polyfill.php backfills PDO, PDOStatement and PDOException on
 * hosts without ext-pdo. It declares them inside eval() heredocs, which reads
 * like an oddity worth tidying and is not: a tokeniser sees no class
 * declaration in a heredoc, so the names never reach a generated classmap.
 * That matters because a consumer's classmap may be shared by every plugin on
 * the site — Jetpack's is — and publishing PDO there makes every other
 * plugin's class_exists('PDO') resolve to this stub, so code that used to skip
 * the PDO path cleanly starts fatalling.
 *
 * Rewriting the eval() as plain conditional declarations is the mistake this
 * guards against. It would pass review and pass the rest of the suite.
 */
final class PdoPolyfillTest extends TestCase
{
    public function testThePolyfillDeclaresNoClassAToolCanSee(): void
    {
        $path = realpath(__DIR__ . '/../packages/reprint-server/src/class-pdo-polyfill.php');
        $this->assertNotFalse($path, 'The polyfill must exist.');

        $declared = [];
        $tokens = token_get_all((string) file_get_contents($path));
        foreach ($tokens as $offset => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }
            $declared[] = $this->classNameAfter($tokens, $offset) ?? 'anonymous';
        }

        $this->assertSame(
            [],
            $declared,
            'src/class-pdo-polyfill.php declares ' . implode(', ', $declared) . ' where a '
            . 'tokeniser can see it. Composer builds its classmap the same way this test '
            . 'reads the file, so those names would be published to every consumer — and on '
            . 'Jetpack the classmap is shared by every plugin on the site. Put the '
            . 'declarations back inside the eval() heredocs; the file\'s header explains why.'
        );
    }

    /**
     * Returns the name following a T_CLASS token, or null for an anonymous class.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function classNameAfter(array $tokens, int $offset): ?string
    {
        for ($index = $offset + 1; $index < count($tokens); ++$index) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }
}
