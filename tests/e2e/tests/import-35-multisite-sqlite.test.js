/**
 * Test 35: Select one child site from a multisite source.
 *
 * A multisite source on SQLite Database Integration 3.x.
 * The shared tests are in lib/multisite-child-paths.js.
 */
import { describeMultisiteChildPaths } from '../lib/multisite-child-paths.js';

describeMultisiteChildPaths('multisite-sqlite', '3.0.0', 9697);
