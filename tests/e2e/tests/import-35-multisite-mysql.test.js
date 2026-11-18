/**
 * Test 35: Select one child site from a multisite source.
 *
 * A multisite source on MySQL.
 * The shared tests are in lib/multisite-child-paths.js.
 */
import { describeMultisiteChildPaths } from '../lib/multisite-child-paths.js';

describeMultisiteChildPaths('multisite-paths-mysql', 'mysql', 9699);
