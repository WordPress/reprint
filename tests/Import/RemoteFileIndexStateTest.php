<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the next line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\State\RemoteFileIndexState;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class RemoteFileIndexStateTest extends TestCase
{
    public function testTraversalRequestRoundTripsArbitraryPathBytes(): void
    {
        $listDirectory = "/srv/site-\xff";
        $requestedDirectories = [$listDirectory, '/srv/shared'];
        $state = new RemoteFileIndexState();
        $state->start_traversal(
            $listDirectory,
            $requestedDirectories,
            true,
            false
        );
        $state->cursor = 'cursor-1';
        $state->next_remote_index_byte_offset = 123;

        $loaded = RemoteFileIndexState::from_array($state->to_array());

        $this->assertSame('cursor-1', $loaded->cursor);
        $this->assertSame(123, $loaded->next_remote_index_byte_offset);
        $this->assertSame(
            [
                'list_directory' => $listDirectory,
                'requested_directories' => $requestedDirectories,
                'follow_symlinks' => true,
                'include_caches' => false,
            ],
            $loaded->active_traversal_request()
        );
        $this->assertSame(
            base64_encode($listDirectory),
            $loaded->active_traversal['list_directory_b64']
        );
    }

    public function testCursorRequiresAnActiveTraversal(): void
    {
        $data = ( new RemoteFileIndexState() )->to_array();
        $data['cursor'] = 'cursor-1';

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'remote file-index cursor requires an active traversal'
        );

        RemoteFileIndexState::from_array($data);
    }

    public function testCursorOnlySchemaIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'missing active_traversal, next_remote_index_byte_offset'
        );

        RemoteFileIndexState::from_array(['cursor' => null]);
    }

    /** @dataProvider invalidStateProvider */
    public function testInvalidStateIsRejected(
        array $data,
        string $expectedMessage
    ): void {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        RemoteFileIndexState::from_array($data);
    }

    public static function invalidStateProvider(): array
    {
        $valid = self::activeStateArray();

        $invalidCursor = $valid;
        $invalidCursor['cursor'] = '';

        $invalidOffset = $valid;
        $invalidOffset['next_remote_index_byte_offset'] = '1';

        $relativeListDirectory = $valid;
        $relativeListDirectory['active_traversal']['list_directory_b64'] =
            base64_encode('srv/site');

        $associativeRequestedDirectories = $valid;
        $associativeRequestedDirectories['active_traversal']
            ['requested_directories_b64'] = [
                'root' => base64_encode('/srv/site'),
            ];

        $invalidRequestedDirectory = $valid;
        $invalidRequestedDirectory['active_traversal']
            ['requested_directories_b64'] = ['not base64'];

        $invalidBoolean = $valid;
        $invalidBoolean['active_traversal']['follow_symlinks'] = 1;

        $unexpectedActiveField = $valid;
        $unexpectedActiveField['active_traversal']['status'] = 'partial';

        return [
            'empty cursor' => [
                $invalidCursor,
                'cursor must be a non-empty string or null',
            ],
            'numeric-string offset' => [
                $invalidOffset,
                'byte offset must be a non-negative integer',
            ],
            'relative list directory' => [
                $relativeListDirectory,
                'active traversal has invalid fields',
            ],
            'associative requested directories' => [
                $associativeRequestedDirectories,
                'active traversal has invalid fields',
            ],
            'invalid requested directory' => [
                $invalidRequestedDirectory,
                'active traversal contains an invalid requested path',
            ],
            'non-boolean follow setting' => [
                $invalidBoolean,
                'active traversal has invalid fields',
            ],
            'unexpected active field' => [
                $unexpectedActiveField,
                'active traversal has invalid fields',
            ],
        ];
    }

    private static function activeStateArray(): array
    {
        $state = new RemoteFileIndexState();
        $state->start_traversal(
            '/srv/site',
            ['/srv/site'],
            false,
            false
        );
        return $state->to_array();
    }
}
