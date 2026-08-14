<?php
declare(strict_types=1);

/**
 * Applies related state changes and saves them as one shutdown boundary.
 *
 * An async signal handler may save the current in-memory state before it
 * stops the process. Deferring that handler keeps it from saving only the
 * first half of a related state transition.
 *
 * @param callable():void $update Related in-memory state changes.
 * @param callable():void $save   Persist the updated state.
 */
function reprint_update_and_save_state_without_signal_interruption(
    callable $update,
    callable $save
): void {
    $async_signals_were_enabled =
        function_exists('pcntl_async_signals')
        && pcntl_async_signals();
    if ($async_signals_were_enabled) {
        pcntl_async_signals(false);
    }
    try {
        $update();
        $save();
    } finally {
        if ($async_signals_were_enabled) {
            pcntl_async_signals(true);
        }
    }
}
