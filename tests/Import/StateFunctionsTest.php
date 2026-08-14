<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/state/state-functions.php';

final class StateFunctionsTest extends TestCase {
    public function testRelatedStateChangesReachAsyncSignalHandlerTogether(): void
    {
        if (
            !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_signal_get_handler')
            || !function_exists('posix_getpid')
            || !function_exists('posix_kill')
            || !defined('SIGUSR1')
        ) {
            $this->markTestSkipped('This test requires async signal support.');
        }

        $state = ['cursor' => 'old-cursor', 'byte_offset' => 10];
        $saved_state = null;
        $signal_observed_state = null;
        $previous_async_signals = pcntl_async_signals();
        $previous_signal_handler = pcntl_signal_get_handler(SIGUSR1);
        pcntl_signal(
            SIGUSR1,
            static function () use (&$signal_observed_state, &$saved_state): void {
                $signal_observed_state = $saved_state;
            }
        );
        pcntl_async_signals(true);

        try {
            \reprint_update_and_save_state_without_signal_interruption(
                function () use (&$state, &$signal_observed_state): void {
                    $state['cursor'] = null;
                    posix_kill(posix_getpid(), SIGUSR1);
                    $this->assertNull($signal_observed_state);
                    $state['byte_offset'] = 20;
                },
                static function () use (&$state, &$saved_state): void {
                    $saved_state = $state;
                }
            );
            pcntl_signal_dispatch();
            $this->assertSame(
                ['cursor' => null, 'byte_offset' => 20],
                $signal_observed_state
            );
        } finally {
            pcntl_signal(SIGUSR1, $previous_signal_handler);
            pcntl_async_signals($previous_async_signals);
        }
    }
}
