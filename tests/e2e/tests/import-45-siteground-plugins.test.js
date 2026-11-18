/**
 * Test 45: Migrate the complete source-host plugin inventory.
 *
 * A SiteGround source, cleaning up host plugins on pull and at runtime.
 * The shared tests are in lib/source-host-plugin-migration.js.
 */
import { describeSourceHostPluginMigration } from '../lib/source-host-plugin-migration.js';

describeSourceHostPluginMigration('other', 'siteground-plugins', false, false);
