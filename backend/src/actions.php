<?php
declare(strict_types=1);

/**
 * POST handlers. Every action mutates state and redirects (POST/redirect/GET)
 * so a refresh never replays it.
 *
 * Authorisation is enforced here, server-side, on every mutating action. The
 * views also hide controls a role can't use, but that is only cosmetic — this
 * is the boundary that actually matters.
 *
 * TODO(wire): the non-auth blocks below are where the real work goes — AMI/ARI
 * calls for provisioning and call control, MariaDB writes for contacts.
 */

/** @var Store $store */
/** @var bool $needsSetup */
csrf_check();

$action = (string) ($_POST['action'] ?? '');
$id = (string) ($_POST['id'] ?? '');
$guardians = new GuardianRepository();
$devices = new DeviceRepository();
$contacts = new ContactRepository();

// ---------------------------------------------------------------------------
// Unauthenticated actions. Only these two may run without a session, and
// `setup` only while the household has no Owner at all.
// ---------------------------------------------------------------------------
if ($action === 'setup') {
    if (!$needsSetup) {
        redirect(url());                      // Owner exists: setup is closed.
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    flash_old(['name' => $name, 'email' => $email]);

    if ($name === '') {
        flash_error('Tell us your name.');
        redirect(url());
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_error("That email address doesn't look right.");
        redirect(url());
    }
    if (($problem = Auth::passwordProblem($password, (string) ($_POST['password_confirm'] ?? ''))) !== null) {
        flash_error($problem);
        redirect(url());
    }

    $ownerId = $guardians->createOwner($name, $email, $password);
    Auth::startSession($ownerId);
    $guardians->recordLogin($ownerId);   // setup signs you in, so count it as one
    take_old();
    flash('Welcome to twocans 🎉');
    redirect(url(['screen' => 'dashboard']));
}

if ($action === 'login') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if (($error = Auth::attempt($email, (string) ($_POST['password'] ?? ''))) !== null) {
        flash_error($error);
        flash_old(['email' => $email]);
        redirect(url());
    }

    redirect(url(['screen' => 'dashboard']));
}

// ---------------------------------------------------------------------------
// Everything past this point requires a session.
// ---------------------------------------------------------------------------
if (!Auth::check()) {
    redirect(url());
}

if ($action === 'logout') {
    Auth::logout();
    redirect(url());
}

/**
 * Permission required per action. Anything not listed is refused by default,
 * so a new action cannot accidentally ship unguarded.
 *
 * Playback needs no action at all now — the browser streams the audio straight
 * from the spool, so there is no server-side play state to flip.
 */
$permissions = Permissions::ACTIONS;

/**
 * Actions that authorise themselves, because a flat role check is wrong for
 * them: `vm_play` is a read every role may do, and `guardian_password` depends
 * on whether you are changing your own password or someone else's.
 */
$selfAuthorised = Permissions::SELF_AUTHORISED;

if (isset($permissions[$action])) {
    Auth::requirePermission($permissions[$action]);
} elseif (!in_array($action, $selfAuthorised, true)) {
    redirect(back());                          // unknown action: do nothing
}

switch ($action) {
    // -------------------------------------------------------------- settings
    case 'toggle_quiet':
        $on = $store->toggleQuietHours();
        // Bedtime is enforced by GotoIfTime in the generated dialplan, so the
        // switch has to rewrite and reload it to mean anything.
        (new PjsipConfig($devices))->apply();
        flash($on ? 'Bedtime mode on' : 'Bedtime mode off');
        break;

    case 'joke_number':
        $settings = new SettingsRepository();
        $wanted = trim((string) ($_POST['number'] ?? ''));

        if (($problem = $settings->jokeNumberProblem($wanted)) !== null) {
            flash($problem);
            break;
        }

        $settings->setJokeNumber($wanted);
        // The number is an extension in the generated dialplan, so moving it
        // means rewriting and reloading.
        (new PjsipConfig($devices))->apply();
        flash('The joke line is now on ' . $wanted);
        break;

    case 'retention_set':
        $settings = new SettingsRepository();
        $settings->setRetentionDays((int) ($_POST['days'] ?? 90));

        /*
         * Sweep straight away rather than waiting for the hourly guard.
         * Shortening the window and seeing nothing happen would look broken,
         * and the parent is right here, having just made the decision.
         */
        $swept = (new Retention($settings))->sweep(true);

        $removed = $swept['calls'] + $swept['voicemails'];
        flash($settings->retentionDays() === 0
            ? 'Recordings will now be kept forever'
            : 'Keeping recordings for ' . $settings->retentionLabel()
              . ($removed > 0 ? " — {$removed} older one(s) deleted" : ''));
        break;

        // --------------------------------------------------------------- devices
    case 'device_toggle':
        $devices->toggle((int) $id, (string) ($_POST['field'] ?? ''));
        (new PjsipConfig($devices))->apply();
        break;

    case 'device_photo':
        $stored = (new PhotoStore())->store($_FILES['photo'] ?? []);
        if ($stored['error'] !== null) {
            flash($stored['error']);
            break;
        }
        if ($stored['file'] !== null) {
            $devices->setPhoto((int) $id, $stored['file']);
            flash('Photo updated ✓');
        }
        break;

    case 'device_photo_remove':
        $devices->setPhoto((int) $id, null);
        flash('Photo removed');
        break;

    case 'device_mac':
        $mac = GrandstreamProvisioning::normalizeMac((string) ($_POST['mac'] ?? ''));
        if ($mac === '') {
            flash('That MAC address does not look right.');
            break;
        }
        $devices->setMac((int) $id, $mac);
        flash('MAC saved ✓');
        break;

    case 'hotkey_set':
        $hotkeys = [];
        foreach ((array) ($_POST['hotkey'] ?? []) as $index => $number) {
            $number = trim((string) $number);
            if ($number !== '') {
                $hotkeys[(int) $index] = $number;
            }
        }
        // save() only keeps numbers a child may actually dial (the allowlist or
        // a service number), so a tampered form cannot provision a blocked key.
        (new DeviceHotkeyRepository())->save((int) $id, $hotkeys);
        flash('Hotkeys saved');
        break;

    // ------------------------------------------------------------ joke line
    case 'joke_add':
        $jokeStore = new JokeStore();

        if (!$jokeStore->isAvailable()) {
            flash("Audio conversion isn't available — the php container needs rebuilding.");
            break;
        }

        $converted = $jokeStore->store($_FILES['audio'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            break;
        }

        $jokeRepo = new JokeRepository();

        // Same clip already on the line: drop the converted copy rather than
        // leaving it orphaned on disk, and say so.
        $existing = $jokeRepo->findByHash($converted['sha256']);
        if ($existing !== null) {
            $jokeStore->delete((string) $converted['file']);
            flash("That joke is already on the line.");
            break;
        }

        $jokeRepo->create(
            (string) $converted['file'],
            $converted['seconds'],
            (string) ($_FILES['audio']['name'] ?? ''),
            Auth::user()['id'] ?? null,
            $converted['sha256']
        );

        // The dialplan names each joke file, so a new one has to be written in
        // before it can be dialled.
        (new PjsipConfig($devices))->apply();
        flash('Joke added ✓ — the transcript will appear shortly');

        // Newest first, so the new joke is on page one. Adding from page three
        // and being left on page three looks like nothing happened.
        redirect(url(['screen' => 'jokes']));

        // no break — redirect exits

    case 'joke_transcript':
        (new JokeRepository())->setTranscript((int) $id, (string) ($_POST['transcript'] ?? ''));
        flash('Saved ✓');
        break;

    case 'joke_toggle':
        $jokeRepo = new JokeRepository();
        $joke = $jokeRepo->find((int) $id);
        if ($joke !== null) {
            $jokeRepo->setEnabled((int) $id, !(bool) $joke['enabled']);
            (new PjsipConfig($devices))->apply();
            flash((bool) $joke['enabled'] ? 'Joke turned off' : 'Joke turned back on');
        }
        break;

    case 'joke_delete':
        (new JokeRepository())->delete((int) $id);
        (new PjsipConfig($devices))->apply();
        flash('Joke deleted');
        break;

    case 'dialplan_rule_add':
        $rules = new DialplanRuleRepository();
        $result = $rules->create(
            (string) ($_POST['rule_action'] ?? 'allow'),
            (string) ($_POST['prefix'] ?? ''),
            (string) ($_POST['label'] ?? '')
        );
        if (!$result['ok']) {
            flash($result['error']);
            break;
        }
        (new PjsipConfig($devices))->apply();
        flash('Dial-plan rule added');
        break;

    case 'dialplan_rule_label':
        // The label is cosmetic — it never appears in the generated dialplan,
        // so renaming one does not need an Asterisk reload.
        (new DialplanRuleRepository())->rename((int) $id, (string) ($_POST['label'] ?? ''));
        flash('Saved ✓');
        break;

    case 'dialplan_rule_toggle':
        (new DialplanRuleRepository())->toggleAction((int) $id);
        (new PjsipConfig($devices))->apply();
        flash('Rule flipped');
        break;

    case 'dialplan_rule_delete':
        (new DialplanRuleRepository())->delete((int) $id);
        (new PjsipConfig($devices))->apply();
        flash('Rule deleted');
        break;

    case 'device_edit':
        foreach (['name', 'timeFrom', 'timeTo', 'blockedMsg'] as $field) {
            if (isset($_POST[$field])) {
                $devices->updateField((int) $id, $field, (string) $_POST[$field]);
            }
        }
        // Name is the caller ID and the hours gate inbound ringing, so both
        // change the dialplan.
        (new PjsipConfig($devices))->apply();
        flash('Saved ✓');
        break;

    case 'device_remove':
        $devices->remove((int) $id);
        // Regenerating from the database drops the endpoint with it.
        (new PjsipConfig($devices))->apply();
        flash('Phone removed');
        redirect(url(['screen' => 'phones']));

    case 'device_test_call':
        $row = $devices->find((int) $id);
        if ($row === null) {
            flash('No such phone');
            break;
        }

        // Check live state first — originating to a phone that is not
        // registered fails silently a few seconds later, which looks like a
        // bug rather than a phone that is asleep.
        (new PjsipConfig($devices))->syncRegistrations();
        $target = DeviceRepository::toView($devices->find((int) $id));

        if (!$target['online']) {
            flash($target['name'] . " isn't online, so it can't ring");
            break;
        }

        try {
            $ami = new Ami();
            $ami->connect();
            $reply = $ami->originate(
                'PJSIP/' . $target['sipUsername'],
                'twocans-devices',
                '601',                       // answer -> play the greeting
                sprintf('"%s" <%s>', PjsipConfig::TEST_CALLER_NAME, PjsipConfig::TEST_CALLER_NUMBER)
            );
            $ami->disconnect();

            flash(($reply['response'] ?? '') === 'Success'
                ? $target['name'] . ' should be ringing now ☎'
                : 'Asterisk refused the call: ' . ($reply['message'] ?? 'no reply'));
        } catch (Throwable $e) {
            flash('Could not reach Asterisk: ' . $e->getMessage());
        }
        break;

    case 'device_pick_model':
        $type = (string) ($_POST['type'] ?? '');
        if (!(DeviceRepository::TYPES[$type]['available'] ?? false)) {
            flash('That one is not ready yet');
            redirect(url(['screen' => 'phones', 'wizard' => 1]));
        }
        $store->setDeviceDraft(['type' => $type]);
        redirect(url(['screen' => 'phones', 'wizard' => 2]));

    case 'device_wizard_step':
        $step = max(1, min(3, (int) ($_POST['step'] ?? 1)));
        redirect(url(['screen' => 'phones', 'wizard' => $step]));

    case 'device_finish':
        $draft = $store->deviceDraft();
        $type = (string) ($draft['type'] ?? 'linphone');
        // The GHP621 is UDP-only; the transport picker is hidden for it.
        $transport = $type === 'ghp621' ? 'udp' : (string) ($_POST['transport'] ?? 'udp');

        if (!(DeviceRepository::TRANSPORTS[$transport]['available'] ?? false)) {
            flash('Pick a transport that is ready');
            redirect(url(['screen' => 'phones', 'wizard' => 2]));
        }

        $device = $devices->create(trim((string) ($_POST['name'] ?? '')), $type, $transport);

        if ($type === 'ghp621') {
            $devices->setMac((int) $device['id'], (string) ($_POST['mac'] ?? ''));
        }

        $store->resetDeviceDraft();

        // Write the endpoint and reload Asterisk so it can register right away.
        $result = (new PjsipConfig($devices))->apply();
        if ($result['error'] !== null) {
            flash('Phone added, but Asterisk did not reload: ' . $result['error']);
        }

        redirect(url(['screen' => 'phones', 'wizard' => 3, 'device' => $device['id']]));

        // -------------------------------------------------------------- contacts
    case 'contact_add':
        redirect(url(['screen' => 'contacts', 'contact' => $contacts->create()]));

    case 'contact_photo_remove':
        $contacts->setPhoto((int) $id, null);
        (new PjsipConfig($devices))->apply();
        flash('Photo removed');
        redirect(url(['screen' => 'contacts', 'contact' => $id]));

    case 'contact_save':
        // A bad photo shouldn't throw away the rest of the edit, so it is
        // handled first and reported on its own.
        if (isset($_FILES['photo'])) {
            $stored = (new PhotoStore())->store($_FILES['photo']);
            if ($stored['error'] !== null) {
                flash($stored['error']);
                redirect(url(['screen' => 'contacts', 'contact' => $id]));
            }
            if ($stored['file'] !== null) {
                $contacts->setPhoto((int) $id, $stored['file']);
            }
        }

        $problem = $contacts->save((int) $id, [
            'name' => $_POST['name'] ?? '',
            'rel' => $_POST['rel'] ?? '',
            'number' => $_POST['number'] ?? '',
            'code' => $_POST['code'] ?? '',
            'window' => $_POST['window'] ?? '',
            'allowIn' => isset($_POST['allowIn']),
            'allowOut' => isset($_POST['allowOut']),
            'ringboth' => isset($_POST['ringboth']),
            'sos' => isset($_POST['sos']),
            'isGroup' => isset($_POST['isGroup']),
            'members' => (array) ($_POST['members'] ?? []),
        ]);

        if ($problem !== null) {
            // Keep the sheet open with the message rather than losing the edit.
            flash($problem);
            redirect(url(['screen' => 'contacts', 'contact' => $id]));
        }

        // The allowlist IS the dialplan, so saving a person rewrites it.
        (new PjsipConfig($devices))->apply();
        flash('Saved ✓');
        redirect(url(['screen' => 'contacts']));

    case 'contact_group_toggle':
        /*
         * Only ever flips person/group. It has to stay this small: a group
         * shows a member list where a person shows a phone number, so putting
         * this through the full save would ask for a number the form is not
         * displaying — which is what made a group impossible to switch back.
         *
         * Members are kept when switching off, so changing your mind twice
         * doesn't lose the list.
         */
        $contacts->setIsGroup((int) $id, isset($_POST['isGroup']));
        (new PjsipConfig($devices))->apply();
        redirect(url(['screen' => 'contacts', 'contact' => $id]));

        // no break — redirect exits

    case 'contact_delete':
        $contacts->remove((int) $id);
        (new PjsipConfig($devices))->apply();
        flash('Removed from the list');
        redirect(url(['screen' => 'contacts']));

        // ------------------------------------------------------ ask-to-call queue
    case 'request_approve':
        $asks = new CallRequestRepository();
        $ask = $asks->find((int) $id);
        if ($ask === null) {
            flash('That ask has already been dealt with');
            break;
        }

        $number = (string) $ask['number_e164'];

        // Already saved — nothing to add, just clear the ask.
        $existing = $contacts->findByNumber($number);
        if ($existing !== null) {
            $asks->decide((int) $id, 'approved', Auth::user()['id'] ?? null);
            flash($existing['name'] . ' is already on the call list');
            redirect(url(['screen' => 'contacts', 'contact' => (int) $existing['id']]));
        }

        /*
         * Approving does not add a bare number and call it done — it opens the
         * contact editor with the number and the child's own words filled in,
         * so a grown-up still names the person and decides when they may be
         * called. Half a contact on the allowlist is worse than none, which is
         * why prefill() leaves them switched off until the editor is saved.
         */
        $contactId = $contacts->create();
        // Same tidying the card does — one line, sensible length.
        $suggested = CallRequestRepository::toView($ask)['saidName'];
        $contacts->prefill($contactId, $number, $suggested);
        $asks->decide((int) $id, 'approved', Auth::user()['id'] ?? null);

        flash('Now finish setting them up');
        redirect(url(['screen' => 'contacts', 'contact' => $contactId]));

        // no break — redirect exits

    case 'request_deny':
        $asks = new CallRequestRepository();
        $ask = $asks->find((int) $id);
        if ($ask !== null) {
            // Keep the row so the same number does not pop straight back up,
            // but the voice note has served its purpose.
            $asks->deleteRecording($ask);
            Database::pdo()->prepare('UPDATE call_requests SET recording_path = NULL WHERE id = ?')
                ->execute([(int) $id]);
            $asks->decide((int) $id, 'denied', Auth::user()['id'] ?? null);
        }
        flash('Dismissed — it will come back if they keep trying');
        break;

        // ------------------------------------------------------------- voicemail
    case 'vm_delete':
        // Removes the spool files as well as the row, so the message really is
        // gone and the phone's message light clears.
        (new VoicemailRepository())->remove((int) $id);
        flash('Voicemail deleted');
        break;

        // ------------------------------------------------------------- guardians
    case 'guardian_invite':
        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $store->setInviteRole((string) ($_POST['role'] ?? 'Admin'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid email first');
            break;
        }
        if ($guardians->findByEmail($email) !== null) {
            flash('That person is already on the list');
            break;
        }

        // A password is optional. With one they can sign in immediately; without
        // one the row is a pending invite that cannot be used to sign in.
        // TODO(wire): when a password is omitted, email a single-use, expiring
        // token so they can set their own.
        if ($password !== '') {
            if (($problem = Auth::passwordProblem($password)) !== null) {
                flash($problem);
                break;
            }
            $guardians->add($name, $email, $store->inviteRole(), $password);
            flash('Added — they can sign in now ✓');
            break;
        }

        $guardians->add($name, $email, $store->inviteRole(), null);
        flash('Invite created — set a password for them to sign in');
        break;

    case 'guardian_password':
        $target = $guardians->find((int) $id);
        if ($target === null) {
            flash('No such guardian');
            break;
        }

        $password = (string) ($_POST['password'] ?? '');
        $isSelf = (int) $target['id'] === (int) Auth::user()['id'];

        // Anyone may change their own password; changing someone else's is an
        // Owner-only act.
        if (!$isSelf) {
            Auth::requirePermission('guardians');
        }

        // Changing your own password requires proving you know the current one,
        // so a hijacked session can't lock the real Owner out.
        if ($isSelf) {
            $current = (string) ($_POST['current_password'] ?? '');
            if (!password_verify($current, (string) $target['password_hash'])) {
                flash('That is not your current password');
                redirect(url(['screen' => 'guardians', 'password' => $id]));
            }
        }

        if (($problem = Auth::passwordProblem($password, (string) ($_POST['password_confirm'] ?? ''))) !== null) {
            flash($problem);
            redirect(url(['screen' => 'guardians', 'password' => $id]));
        }

        $guardians->setPassword((int) $target['id'], $password);
        flash($isSelf ? 'Your password is updated ✓' : 'Password set for ' . $target['name'] . ' ✓');
        redirect(url(['screen' => 'guardians']));

    case 'guardian_invite_role':
        $store->setInviteRole((string) ($_POST['role'] ?? 'Admin'));
        break;

    case 'guardian_role':
        $guardians->cycleRole((int) $id);
        break;

    case 'guardian_remove':
        if ((int) $id === (int) Auth::user()['id']) {
            flash("You can't remove yourself");
            break;
        }
        $guardians->remove((int) $id);
        flash('Guardian removed');
        break;

        // ------------------------------------------------------------- SIP trunk
    case 'trunk_wizard_step':
        $step = max(1, min(3, (int) ($_POST['step'] ?? 1)));
        $store->setTrunkDraft([
            'provider' => (string) ($_POST['provider'] ?? $store->trunkDraft()['provider']),
            'sid' => (string) ($_POST['sid'] ?? $store->trunkDraft()['sid']),
            'token' => (string) ($_POST['token'] ?? $store->trunkDraft()['token']),
            'number' => (string) ($_POST['number'] ?? $store->trunkDraft()['number']),
            'termination' => (string) ($_POST['termination'] ?? $store->trunkDraft()['termination']),
            'apiKey' => (string) ($_POST['apiKey'] ?? $store->trunkDraft()['apiKey']),
            'proxy' => (string) ($_POST['proxy'] ?? $store->trunkDraft()['proxy']),
        ]);
        redirect(url(['screen' => 'trunk', 'trunkwizard' => $step]));

    case 'trunk_connect':
        $draft = $store->trunkDraft();
        $result = (new TrunkRepository())->connect($draft);

        if (!$result['ok']) {
            // Keep the non-secret fields, but never echo a token/API key back.
            $store->setTrunkDraft([
                'provider' => $draft['provider'],
                'sid' => $draft['sid'],
                'number' => $draft['number'],
                'termination' => $draft['termination'],
                'proxy' => $draft['proxy'],
                'token' => '',
                'apiKey' => '',
            ]);
            flash($result['error']);
            redirect(url(['screen' => 'trunk', 'trunkwizard' => 2]));
        }

        // Write the trunk endpoint + outbound route and reload Asterisk.
        $apply = (new PjsipConfig($devices))->apply();
        $store->resetTrunkDraft();

        flash($apply['error'] === null
            ? 'Phone line connected to ' . (string) $draft['provider'] . ' ✓'
            : 'Phone line connected, but Asterisk did not reload: ' . $apply['error']);
        redirect(url(['screen' => 'trunk']));

    case 'trunk_topup':
        // TODO(wire): charge the payment method on file via Twilio, then update
        // trunk.balance from the account's new balance.
        flash("Topping up from the app isn't wired yet — add credit in the Twilio console.");
        break;

        // ----------------------------------------------------------- dynamic DNS
    case 'ddns_address':
        $dns = new DynamicDnsRepository();
        $saved = $dns->setExternalHostname((string) ($_POST['hostname'] ?? ''));

        if (!$saved['ok']) {
            $dns->noteError((string) $saved['error']);
            flash((string) $saved['error']);
            break;
        }

        flash('External address saved: ' . $saved['hostname']);
        break;

    case 'ddns_connect':
        $zone = (string) ($_POST['zone'] ?? '');

        $dns = new DynamicDnsRepository();
        // The name is whatever the external address (above) is set to — Cloudflare
        // keeps that name's record updated, it does not define the name.
        $hostname = (string) ($dns->get()['hostname'] ?? '');
        $saved = $dns->connect([
            'token' => (string) ($_POST['token'] ?? ''),
            'zone' => $zone,
            'hostname' => $hostname,
        ]);

        if (!$saved['ok']) {
            // Keep what they typed, but never echo the token back into the page.
            $store->setDdnsDraft(['zone' => trim($zone)]);
            // Shown inline on the card as well as in the toast, so a failure that
            // flashes past is not missed.
            $dns->noteError((string) $saved['error']);
            flash((string) $saved['error']);
            redirect(url(['screen' => 'trunk']));
        }

        /*
         * Point it at the house now rather than within the minute. Somebody has
         * just pressed a button and is owed an answer — and if the token cannot
         * write the record, this is when they want to hear about it, not later.
         */
        $sync = (new DynamicDns($dns))->sync(true);
        $store->resetDdnsDraft();

        // Two separate things are being asked about on one button: is the key
        // good, and is the record now right? Say both, so a failure to write the
        // record is not mistaken for a failure to verify the key.
        flash($sync['error'] === null
            ? 'Cloudflare token verified ✓ — ' . $sync['message']
            : 'Cloudflare token accepted, but the record could not be set: ' . $sync['error']);
        redirect(url(['screen' => 'trunk']));

    case 'ddns_update':
        $sync = (new DynamicDns())->sync(true);
        flash($sync['error'] ?? $sync['message']);
        break;

    case 'ddns_enable':
        $dns = new DynamicDnsRepository();
        $dns->enable();
        $sync = (new DynamicDns($dns))->sync(true);
        flash($sync['error'] ?? $sync['message']);
        break;

    case 'ddns_disable':
        (new DynamicDnsRepository())->disable();
        // The record itself is left alone — and so is the saved setup, so turning
        // it back on doesn't ask for the token again.
        flash('Dynamic DNS is paused — the record is left as it is');
        break;

    case 'cert_request':
        $cert = new Certificates();
        $requested = $cert->request((string) ($_POST['email'] ?? ''));

        if (!$requested['ok']) {
            flash((string) $requested['error']);
            break;
        }

        flash('Certificate request sent — nginx will obtain a certificate for '
            . $cert->domain() . '. This can take a minute.');
        break;

        // ------------------------------------------------------------ live call
    case 'listen_mode':
        $store->setListenMode((string) ($_POST['mode'] ?? 'listen'));
        break;

    case 'listen_start':
        $channel = (string) ($_POST['channel'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'listen');
        $store->setListenMode($mode);

        $live = new LiveCalls($devices);
        $target = $live->find($channel);
        $listenOn = $devices->find((int) ($_POST['listen_on'] ?? 0));

        if ($target === null) {
            flash('That call has already ended');
            redirect(url(['screen' => 'dashboard']));
        }
        if ($listenOn === null) {
            flash('Pick a phone to listen on');
            redirect(url(['screen' => 'dashboard', 'listen' => $channel]));
        }

        $result = $live->listen($channel, $listenOn, $mode);

        if ($result['ok']) {
            // Note it before anyone hears anything: the UI promises the family
            // that listening is recorded, so it must not depend on the call
            // completing normally.
            $live->recordListen(
                (string) ($_POST['uniqueid'] ?? $target['uniqueid']),
                (int) Auth::user()['id'],
                $mode
            );
            flash(DeviceRepository::toView($listenOn)['name'] . ' is ringing — answer it to listen ☎');
        } else {
            flash($result['error'] ?? "Couldn't start listening");
        }

        redirect(url(['screen' => 'dashboard']));

    case 'call_end':
        $channel = (string) ($_POST['channel'] ?? '');
        $ended = $channel !== '' && (new LiveCalls($devices))->hangup($channel);
        flash($ended ? 'Call ended' : 'That call had already finished');
        redirect(url(['screen' => 'dashboard']));

        // -------------------------------------------------------------- system
    case 'health_check':
        // Read-only: the screen re-renders with fresh checks after this POST.
        flash('Health checks refreshed');
        redirect(url(['screen' => 'system']));

    case 'backup_create':
        $result = (new Backup())->create();
        flash($result['ok']
            ? 'Backup created — ' . $result['name']
            : ($result['error'] ?? 'Could not create backup'));
        redirect(url(['screen' => 'system']));

    case 'backup_delete':
        $removed = (new Backup())->remove((string) ($_POST['name'] ?? ''));
        flash($removed ? 'Backup removed' : 'Could not remove that backup');
        redirect(url(['screen' => 'system']));

    case 'backup_restore':
        // Owner-only (Permissions::ACTIONS) plus a typed confirmation — the two
        // guards in front of a destructive, whole-household restore.
        if ((string) ($_POST['confirm'] ?? '') !== 'RESTORE') {
            flash('Type RESTORE to confirm the restore.');
            redirect(url(['screen' => 'system']));
        }

        $file = $_FILES['backup'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Pick a backup file to restore.');
            redirect(url(['screen' => 'system']));
        }

        $result = (new Backup())->restoreFile((string) $file['tmp_name']);

        if (!$result['ok']) {
            flash('Restore failed: ' . ($result['error'] ?? 'unknown reason'));
            redirect(url(['screen' => 'system']));
        }

        // Regenerate Asterisk config from the restored database and reload it,
        // so the restored phones, contacts and rules take effect.
        $applied = (new PjsipConfig($devices))->apply();

        flash('Restored the database and ' . count($result['files'] ?? []) . ' folder(s).'
            . ($applied['reloaded'] ? ' Asterisk reloaded.' : ' Asterisk will pick up the config on restart.'));
        redirect(url(['screen' => 'system']));

        // -------------------------------------------------------- notifications
    case 'notifications_save':
        $result = (new NotificationRepository())->save($_POST);
        flash($result['ok'] ? 'Notifications saved' : ($result['error'] ?? 'Could not save notifications'));
        redirect(url(['screen' => 'notifications']));

    case 'notifications_toggle':
        $repo = new NotificationRepository();
        $on = !$repo->get()['enabled'];
        $repo->setEnabled($on);
        flash($on ? 'Notifications on' : 'Notifications off');
        redirect(url(['screen' => 'notifications']));

    case 'notifications_test_email':
        try {
            $repo = new NotificationRepository();
            $config = $repo->get();
            if (!$config['mailgunConfigured']) {
                flash('Mailgun is not configured — set the key, domain, from and to first.');
            } else {
                $mail = new Mailgun($repo->apiKey() ?? '', $config['region'], $config['domain']);
                $res = $mail->send($config['from'], $config['to'], 'twocans test',
                    "This is a test email from your twocans line.\n\nIf you can read this, email notifications work.");
                flash($res['ok'] ? 'Test email sent ✓' : ($res['error'] ?? 'Could not send the test email'));
            }
        } catch (Throwable $e) {
            flash('Could not send the test email: ' . $e->getMessage());
        }
        redirect(url(['screen' => 'notifications']));

    case 'notifications_test_kuma':
        $config = (new NotificationRepository())->get();
        if ($config['kumaUrl'] === '') {
            flash('No Uptime Kuma push URL set.');
        } else {
            $res = UptimeKuma::heartbeat($config['kumaUrl'], 'twocans test heartbeat');
            flash($res['ok'] ? 'Test heartbeat sent ✓' : ($res['error'] ?? 'Could not send the test heartbeat'));
        }
        redirect(url(['screen' => 'notifications']));
}

redirect(back());
