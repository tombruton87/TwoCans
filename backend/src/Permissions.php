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
        // Bedtime's times, by day — see Schedule.
        'bedtime_save' => 'rules',
        // Home Assistant: the broker login and HA token are house-wide secrets.
        'ha_save' => 'system',
        'ha_test' => 'system',
        // Announcements: paging the house is a rule of the house, like bedtime.
        'announce_send' => 'rules',
        'announce_new' => 'rules',
        'announce_save' => 'rules',
        'announce_audio' => 'rules',
        'announce_delete' => 'rules',
        'announce_token' => 'rules',
        'retention_set' => 'rules',
        'joke_number' => 'rules',
        'voicemail_speed_dial' => 'rules',
        'device_rings' => 'devices',
        // The message bedtime plays belongs to the house rather than to one
        // phone, so it sits with the other rules too.
        'quiet_message' => 'rules',
        'hold_music_add' => 'rules',
        'hold_music_remove' => 'rules',
        'quiet_message_remove' => 'rules',
        // Whether an unrecognised caller may leave a message is part of what the
        // line does, like bedtime, so it sits with the other rules.
        'screening_set' => 'rules',
        // The house's "press 1 to join" for group calls, like the bedtime
        // message, belongs to the whole line.
        'group_prompt' => 'rules',
        'group_prompt_remove' => 'rules',
        // Re-recordings of the line's stock prompts — see Greetings.
        'greeting_save' => 'rules',
        'greeting_remove' => 'rules',
        'device_toggle' => 'devices',
        'device_edit' => 'devices',
        // A phone's hours by day, and how long it may talk.
        'device_hours' => 'devices',
        // Removing every restriction from a phone.
        'device_adult' => 'devices',
        'device_limits' => 'devices',
        'device_remove' => 'devices',
        'device_pick_family' => 'devices',
        'device_scan' => 'devices',
        'device_pick_found' => 'devices',
        'device_pick_model' => 'devices',
        // The Getting started guide walks through adding phones.
        'onboarding' => 'devices',
        'device_wizard_step' => 'devices',
        'device_finish' => 'devices',
        'device_test_call' => 'devices',
        'device_photo' => 'devices',
        'device_photo_remove' => 'devices',
        // The message a refused caller hears is a recording made by a parent,
        // so it is part of the phone's settings and sits with them.
        'device_refusal_message' => 'devices',
        'device_refusal_remove' => 'devices',
        'device_refusal_transcript' => 'devices',
        'device_mac' => 'devices',
        'device_add_socket' => 'devices',
        'hotkey_set' => 'devices',
        'hotkey_offer' => 'devices',
        'hotkey_offer_dismiss' => 'devices',
        'device_resync' => 'devices',
        'device_reboot' => 'devices',
        'contact_add' => 'contacts',
        'contact_save' => 'contacts',
        'contact_group_toggle' => 'contacts',
        'contact_delete' => 'contacts',
        'contact_photo_remove' => 'contacts',
        // A group's own greeting is part of editing that group.
        'contact_group_prompt' => 'contacts',
        'contact_group_prompt_remove' => 'contacts',
        // Their name spoken, for phones that say who's calling.
        'contact_announce' => 'contacts',
        'contact_announce_voice' => 'contacts',
        'contact_announce_remove' => 'contacts',
        // A link that lets that one person set their own photo and name.
        'contact_link_create' => 'contacts',
        'contact_link_stop' => 'contacts',
        'request_approve' => 'contacts',
        'request_deny' => 'contacts',
        // Dealing with a message left by a number nobody recognises ends in the
        // same place the ask queue does — a person added to the call list, or
        // not — so it needs the same permission.
        'screening_allow' => 'contacts',
        'screening_junk' => 'contacts',
        'vm_delete' => 'voicemail',
        'vm_move' => 'voicemail',
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
        'trunk_ring_device' => 'billing',
        'trunk_outgoing' => 'billing',
        'trunk_number_mailbox' => 'billing',
        'device_outgoing' => 'billing',
        'trunk_edit' => 'billing',
        // Dynamic DNS holds an API token that can rewrite every record in a domain
        // the household owns, so it sits with billing: Owner only.
        'ddns_connect' => 'billing',
        'ddns_address' => 'billing',
        'ddns_update' => 'billing',
        'ddns_enable' => 'billing',
        'ddns_disable' => 'billing',
        'cert_request' => 'billing',
        // Asking the router to open ports to this box: the Owner's call.
        'ports_save' => 'billing',
        'ports_try' => 'billing',
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
    public const SELF_AUTHORISED = [
        'guardian_password',
        // Your own passkeys: any signed-in grown-up, only ever their own.
        'passkey_register_options', 'passkey_register', 'passkey_delete',
    ];

    /** Actions that may run before a session exists. */
    public const PRE_AUTH = ['setup', 'login', 'logout', 'passkey_login_options', 'passkey_login'];
}
