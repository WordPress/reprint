/**
 * Test 45: Migrate the complete source-host plugin inventory.
 *
 * A WP Engine source, downloading host plugins and keeping them at runtime.
 * The shared tests are in lib/source-host-plugin-migration.js.
 */
import { describeSourceHostPluginMigration } from '../lib/source-host-plugin-migration.js';

describeSourceHostPluginMigration('wpengine', 'wpengine-plugin-inventory', true, true);
