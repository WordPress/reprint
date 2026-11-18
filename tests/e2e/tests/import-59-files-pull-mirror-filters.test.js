/**
 * Test 59: files-pull mirror and catch-up modes
 *
 * Mirror into a remapped root, through include and exclude rules, in
 * separate filter passes, and around local caches.
 * The shared tests are in lib/files-pull-mirror.js.
 */
import { describeFilesPullMirror } from '../lib/files-pull-mirror.js';

describeFilesPullMirror('files-pull-mirror-filters', 'filters');
