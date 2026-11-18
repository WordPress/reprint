/**
 * Test 59: files-pull mirror and catch-up modes
 *
 * Mirror restores local edits, type changes and paths that the remote no
 * longer has, and catch-up stays the default mode.
 * The shared tests are in lib/files-pull-mirror.js.
 */
import { describeFilesPullMirror } from '../lib/files-pull-mirror.js';

describeFilesPullMirror('files-pull-mirror-local-changes', 'local-changes');
