<?php
declare(strict_types=1);

/**
 * The permission required per mutating action, plus the two small sets of
 * actions that deliberately sit outside that map.
 *
 * Kept here (rather than inline in actions.php) so a test can prove the
 * invariant the product depends on: an action with no entry is refused by
 * default, so a new action cannot accidentally ship unguarded.
 */
final class Permissions
{
    /**
     * Action => permission. Anything not listed is refused by default.
     */
    public const ACTIONS = [
        'toggle_quiet' => 'rules',
        'retention_set' => 'rules',
        'joke_number' => 'rules',
        'device_toggle' => 'devices',
        'device_edit' => 'devices',
        'device_remove' => 'devices',
        'device_pick_model' => 'devices',
        'device_wizard_step' => 'devices',
        'device_finish' => 'devices',
        'device_test_call' => 'devices',
        'device_photo' => 'devices',
        'device_photo_remove' => 'devices',
        'device_mac' => 'devices',
        'hotkey_set' => 'devices',
        'contact_add' => 'contacts',
        'contact_save' => 'contacts',
        'contact_group_toggle' => 'contacts',
        'contact_delete' => 'contacts',
        'contact_photo_remove' => 'contacts',
        'request_approve' => 'contacts',
        'request_deny' => 'contacts',
        'vm_delete' => 'voicemail',
        // Jokes are part of what the line does, so they sit with the other rules:
        // an Admin may manage them, a Viewer may not.
        'joke_add' => 'rules',
        'joke_transcript' => 'rules',
        'joke_toggle' => 'rules',
        'joke_delete' => 'rules',
        'dialplan_rule_add' => 'rules',
        'dialplan_rule_label' => 'rules',
        'dialplan_rule_toggle' => 'rules',
        'dialplan_rule_delete' => 'rules',
        'guardian_invite' => 'guardians',
        'guardian_invite_role' => 'guardians',
        'guardian_role' => 'guardians',
        'guardian_remove' => 'guardians',
        'trunk_wizard_step' => 'billing',
        'trunk_connect' => 'billing',
        'trunk_topup' => 'billing',
        // Dynamic DNS holds an API token that can rewrite every record in a domain
        // the household owns, so it sits with billing: Owner only.
        'ddns_connect' => 'billing',
        'ddns_address' => 'billing',
        'ddns_update' => 'billing',
        'ddns_enable' => 'billing',
        'ddns_disable' => 'billing',
        'cert_request' => 'billing',
        'listen_mode' => 'listen',
        'listen_start' => 'listen',
        'call_end' => 'listen',
        // System health is read-only, so Admin may see it. Backups and restore
        // hold recordings of children, so they sit with billing: Owner only.
        'health_check' => 'system',
        'backup_create' => 'backups',
        'backup_delete' => 'backups',
        'backup_restore' => 'backups',
        // Notifications hold the Mailgun API key and recipients, so Owner only.
        'notifications_save' => 'notifications',
        'notifications_toggle' => 'notifications',
        'notifications_test_email' => 'notifications',
        'notifications_test_kuma' => 'notifications',
    ];

    /**
     * Actions that authorise themselves, because a flat role check is wrong
     * for them: `guardian_password` depends on whether you are changing your
     * own password or someone else's.
     */
    public const SELF_AUTHORISED = ['guardian_password'];

    /** Actions that may run before a session exists. */
    public const PRE_AUTH = ['setup', 'login', 'logout'];
}
