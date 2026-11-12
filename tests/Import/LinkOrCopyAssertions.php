<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

/**
 * A Windows account that may not create symlinks gets a copy in place of a
 * file symlink, and a junction, which LocalLink reports as a link, in place
 * of a directory symlink.
 */
trait LinkOrCopyAssertions
{
    private static ?bool $php_can_create_symlinks = null;

    private function assertLinkedOrCopied(string $path, string $message = ''): void
    {
        $this->assertTrue(
            \LocalLink::is_link($path) || ( is_file($path) && !self::php_can_create_symlinks() ),
            $message !== '' ? $message : "{$path} should be a symlink"
        );
    }

    private static function php_can_create_symlinks(): bool
    {
        if (self::$php_can_create_symlinks === null) {
            $probe = sys_get_temp_dir() . '/symlink-probe-' . bin2hex(random_bytes(6));
            self::$php_can_create_symlinks = @symlink(__FILE__, $probe);
            if (self::$php_can_create_symlinks) {
                unlink($probe);
            }
        }
        return self::$php_can_create_symlinks;
    }
}
