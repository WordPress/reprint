/**
 * Test 59: files-pull mirror and catch-up modes
 *
 * Both modes pull the same tree, recover from an interruption, and apply
 * remote additions, deletions and conflicts.
 * The shared tests are in lib/files-pull-mirror.js.
 */
import { describeFilesPullMirror } from '../lib/files-pull-mirror.js';

describeFilesPullMirror('files-pull-mirror', 'pull-modes');
