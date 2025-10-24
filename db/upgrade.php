<?php
/**
 * Upgrade steps for local_xlate.
 *
 * @package   local_xlate
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute local_xlate upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_xlate_upgrade(int $oldversion): bool {

    if ($oldversion < 2025102403) {
        // Placeholder for future upgrade logic.

        upgrade_plugin_savepoint(true, 2025102403, 'local', 'xlate');
    }

    return true;
}
