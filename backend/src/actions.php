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
    // Straight into the Getting started guide — see Onboarding.
    redirect(url(['screen' => 'start']));
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

/*
 * Signing in with a passkey — Face ID, Touch ID, a fingerprint. Two steps,
 * both answered in JSON for the page's script: a challenge, then the phone's
 * signed reply. See WebAuthn for what is checked.
 */
$json = static function (array $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    exit(json_encode($body));
};

if ($action === 'passkey_login_options') {
    if (!WebAuthn::available()) {
        $json(['ok' => false, 'error' => 'Passkeys need the secure address of this app (https://…).'], 400);
    }
    $json(['ok' => true, 'options' => WebAuthn::loginOptions()]);
}

if ($action === 'passkey_login') {
    $credential = json_decode((string) ($_POST['credential'] ?? ''), true);
    $passkeys = new PasskeyRepository();
    $stored = is_array($credential) ? $passkeys->findByCredential((string) ($credential['id'] ?? '')) : null;
    if ($stored === null) {
        unset($_SESSION['webauthn']);
        $json(['ok' => false, 'error' => "That passkey isn't set up here. Sign in with your password, then add it on Family & guardians."], 401);
    }
    try {
        $count = WebAuthn::verifyLogin((array) ($credential['response'] ?? []), $stored);
    } catch (RuntimeException $e) {
        $json(['ok' => false, 'error' => $e->getMessage()], 401);
    }
    $passkeys->used((int) $stored['id'], $count);
    Auth::startSession((int) $stored['guardian_id']);
    $guardians->recordLogin((int) $stored['guardian_id']);
    $json(['ok' => true, 'redirect' => url(['screen' => 'dashboard'])]);
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

    case 'device_hours':
        if ($devices->find((int) $id) === null) {
            break;
        }
        [$rules, $problem] = Schedule::fromInput($_POST['schedule'] ?? []);
        if ($problem !== null) {
            flash($problem);
            redirect(url(['screen' => 'phones', 'device' => $id]));
        }
        $devices->setHours((int) $id, $rules);
        // A phone's hours decide whether it rings, in the generated dialplan.
        (new PjsipConfig($devices))->apply();
        flash('Hours saved — ' . Schedule::describe($rules));
        redirect(url(['screen' => 'phones', 'device' => $id]));

    case 'device_adult':
        $phone = $devices->find((int) $id);
        if ($phone === null) {
            break;
        }
        $on = ($_POST['on'] ?? '') === '1';
        // Switching it on only counts with the confirmation that spelled out
        // what it does; a stray or crafted post can't do it by itself.
        if ($on && ($_POST['confirm'] ?? '') !== 'remove-all-restrictions') {
            flash('Adult mode was not turned on.');
            redirect(url(['screen' => 'phones', 'device' => $id]));
        }
        $devices->setAdult((int) $id, $on);
        (new PjsipConfig($devices))->apply();
        flash($on
            ? (string) $phone['name'] . ' is in adult mode — no restrictions apply to it'
            : (string) $phone['name'] . ' is back to normal — its rules apply again');
        redirect(url(['screen' => 'phones', 'device' => $id]));

    case 'device_limits':
        if ($devices->find((int) $id) === null) {
            break;
        }
        // Minutes, from the page's fixed choices; anything else is "no limit".
        $minutes = static function (mixed $value): ?int {
            $n = (int) $value;

            return $n >= 1 && $n <= 600 ? $n : null;
        };
        $devices->setLimits((int) $id, $minutes($_POST['maxCall'] ?? ''), $minutes($_POST['daily'] ?? ''));
        // Limits are enforced by Asterisk, from values set on the phone's endpoint.
        (new PjsipConfig($devices))->apply();
        flash('Call limits saved');
        redirect(url(['screen' => 'phones', 'device' => $id]));

    // ----------------------------------------------------------- announcements
    case 'announce_send':
        $sent = (new AnnouncementRepository())->send((int) $id);
        flash($sent['ok']
            ? 'Sent to ' . $sent['phones'] . ' phone' . ($sent['phones'] === 1 ? '' : 's') . ' 📣'
            : (string) $sent['error']);
        break;

    case 'announce_new':
        $newId = (new AnnouncementRepository())->create();
        redirect(url(['screen' => 'announcements', 'edit' => $newId]) . '#announce-' . $newId);

    case 'announce_save':
        $announcements = new AnnouncementRepository();
        if ($announcements->find((int) $id) === null) {
            break;
        }
        $problem = $announcements->save((int) $id, $_POST);
        flash($problem ?? 'Saved ✓');
        redirect(url(['screen' => 'announcements', 'edit' => $problem === null ? '' : $id]) . '#announce-' . (int) $id);

    case 'announce_audio':
        $announcements = new AnnouncementRepository();
        $current = $announcements->find((int) $id);
        $store = new AnnouncementStore();
        if ($current === null) {
            break;
        }
        if (!$store->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            break;
        }
        $converted = $store->store($_FILES['message'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            redirect(url(['screen' => 'announcements']) . '#announce-' . (int) $id);
        }
        $store->delete($current['audio']);
        $announcements->setAudio((int) $id, (string) $converted['file'], $converted['seconds']);
        flash('Message saved ✓');
        redirect(url(['screen' => 'announcements']) . '#announce-' . (int) $id);

    case 'announce_delete':
        (new AnnouncementRepository())->delete((int) $id);
        flash('Announcement removed');
        redirect(url(['screen' => 'announcements']));

    case 'announce_token':
        (new AnnouncementRepository())->newToken((int) $id);
        flash('New trigger URL made — the old one no longer works');
        redirect(url(['screen' => 'announcements']) . '#announce-' . (int) $id);

    // ------------------------------------------------------------- passkeys
    case 'passkey_register_options':
        $me = Auth::user();
        if (!WebAuthn::available()) {
            $json(['ok' => false, 'error' => 'Passkeys need the secure address of this app (https://…).'], 400);
        }
        $existing = array_map(
            static fn(array $row): string => (string) $row['credential_id'],
            (new PasskeyRepository())->forGuardian((int) $me['id'])
        );
        $json(['ok' => true, 'options' => WebAuthn::registerOptions($me, $existing)]);

    case 'passkey_register':
        $me = Auth::user();
        $credential = json_decode((string) ($_POST['credential'] ?? ''), true);
        try {
            $key = WebAuthn::verifyRegistration((array) ($credential['response'] ?? []));
        } catch (RuntimeException $e) {
            $json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        $passkeys = new PasskeyRepository();
        if ($passkeys->findByCredential($key['id']) !== null) {
            $json(['ok' => false, 'error' => 'That passkey is already set up.'], 409);
        }
        $passkeys->add((int) $me['id'], $key, PasskeyRepository::labelFromAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        flash('Face ID sign-in is set up on this device ✓');
        $json(['ok' => true, 'redirect' => url(['screen' => 'guardians'])]);

    case 'passkey_delete':
        (new PasskeyRepository())->remove((int) $id, (int) Auth::user()['id']);
        flash('Passkey removed');
        redirect(url(['screen' => 'guardians']));

    // ------------------------------------------------------- Home Assistant
    case 'ha_save':
        $problem = (new HomeAssistant())->save($_POST);
        flash($problem ?? 'Saved — the bridge picks this up within a minute');
        redirect(url(['screen' => 'homeassistant']));

    case 'ha_test':
        $error = (new HomeAssistant())->test();
        flash($error === null ? 'Connected to the MQTT broker ✓' : 'Could not connect: ' . $error);
        redirect(url(['screen' => 'homeassistant']));

    case 'bedtime_save':
        [$rules, $problem] = Schedule::fromInput($_POST['schedule'] ?? []);
        if ($problem !== null) {
            flash($problem);
            redirect(url(['screen' => 'dashboard', 'bedtime' => '1']));
        }
        (new SettingsRepository())->setQuietRules($rules);
        // Bedtime is GotoIfTime in the generated dialplan.
        (new PjsipConfig($devices))->apply();
        flash('Bedtime saved — ' . Schedule::describe($rules));
        redirect(url(['screen' => 'dashboard']));

    case 'quiet_message':
        $quiet = new QuietMessageStore();
        $settings = new SettingsRepository();

        if (!$quiet->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            break;
        }

        $converted = $quiet->store($_FILES['message'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            break;
        }

        // One recording for the house, so the one this replaces is removed: the
        // dialplan is about to name the new file, and nothing would ever name
        // the old one again.
        $quiet->delete($settings->quietMessage());
        $settings->setQuietMessage((string) $converted['file'], $converted['seconds']);

        // Bedtime plays this from the generated dialplan, so it has to be
        // written in and reloaded before a caller can hear it.
        (new PjsipConfig($devices))->apply();
        flash('Message saved ✓ — callers hear it whenever the line is quiet');
        break;

    case 'quiet_message_remove':
        $quiet = new QuietMessageStore();
        $settings = new SettingsRepository();

        $quiet->delete($settings->quietMessage());
        $settings->setQuietMessage(null);
        (new PjsipConfig($devices))->apply();
        flash('Message removed — callers get the standard greeting again');
        break;

    case 'group_prompt':
        $prompts = new GroupPromptStore();
        $settings = new SettingsRepository();

        if (!$prompts->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            redirect(back());
        }

        $converted = $prompts->store($_FILES['greeting'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            redirect(back());
        }

        $prompts->delete($settings->groupPrompt());
        $settings->setGroupPrompt((string) $converted['file'], $converted['seconds']);
        (new PjsipConfig($devices))->apply();
        flash('Greeting saved ✓ — grown-ups hear it when a group call rings them');
        redirect(back());

    case 'group_prompt_remove':
        $settings = new SettingsRepository();
        (new GroupPromptStore())->delete($settings->groupPrompt());
        $settings->setGroupPrompt(null);
        (new PjsipConfig($devices))->apply();
        flash('Greeting removed — group calls use the standard prompt again');
        redirect(back());

    case 'greeting_save':
        // One of the line's stock prompts, re-recorded — see Greetings.
        $slot = (string) ($_POST['slot'] ?? '');
        $store = new GreetingStore();
        $greetings = new Greetings();

        if (!Greetings::exists($slot)) {
            break;
        }
        if (!$store->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            break;
        }

        $converted = $store->store($_FILES['greeting'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            break;
        }

        $store->delete($greetings->file($slot));
        $greetings->set($slot, (string) $converted['file'], $converted['seconds']);
        (new PjsipConfig($devices))->apply();
        flash(Greetings::SLOTS[$slot]['title'] . ' saved ✓');
        break;

    case 'greeting_remove':
        $slot = (string) ($_POST['slot'] ?? '');
        if (Greetings::exists($slot)) {
            $greetings = new Greetings();
            (new GreetingStore())->delete($greetings->file($slot));
            $greetings->set($slot, null);
            (new PjsipConfig($devices))->apply();
            flash(Greetings::SLOTS[$slot]['title'] . ' — back to the standard one');
        }
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

    case 'device_refusal_message':
        $store = new RefusalStore();

        if (!$store->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            break;
        }

        $converted = $store->store($_FILES['message'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            break;
        }

        $row = $devices->find((int) $id);
        if ($row === null) {
            // Nothing to attach it to. Don't leave the clip orphaned on disk.
            $store->delete((string) $converted['file']);
            flash('No such phone');
            break;
        }

        // One message per phone, so the one this replaces is removed: the
        // dialplan is about to name the new file, and nothing would ever name
        // the old one again.
        $store->delete((string) ($row['refusal_audio'] ?? ''));
        $devices->setRefusalAudio((int) $id, (string) $converted['file'], $converted['seconds']);

        // The dialplan plays the recording by name, so it has to be written in
        // before a caller can hear it.
        (new PjsipConfig($devices))->apply();
        flash('Message saved ✓ — the transcript will appear shortly');
        break;

    case 'device_refusal_remove':
        $row = $devices->find((int) $id);
        if ($row === null) {
            flash('No such phone');
            break;
        }

        (new RefusalStore())->delete((string) ($row['refusal_audio'] ?? ''));
        $devices->clearRefusalAudio((int) $id);
        (new PjsipConfig($devices))->apply();
        flash('Message removed — callers hear the standard message again');
        break;

    case 'device_refusal_transcript':
        // The wording is what the app shows back, and Whisper mishears a name
        // or a phrase here and there, so it stays editable and never touches
        // the dialplan.
        $devices->updateField((int) $id, 'refusalTranscript', (string) ($_POST['transcript'] ?? ''));
        flash('Saved ✓');
        break;

    case 'device_mac':
        $mac = GrandstreamProvisioning::normalizeMac((string) ($_POST['mac'] ?? ''));
        if ($mac === '') {
            flash('That MAC address does not look right.');
            break;
        }
        if (!$devices->setMac((int) $id, $mac)) {
            flash('Another phone already has that MAC address.');
            break;
        }
        flash('MAC saved ✓');
        break;

    case 'device_add_socket':
        // The other socket of an HT802: a new phone on the same box.
        $first = $devices->find((int) $id);
        if ($first === null || $first['type'] !== 'ht802' || (string) $first['mac'] === ''
            || count($devices->findByMac((string) $first['mac'])) > 1) {
            flash('That adapter has no free socket.');
            break;
        }
        $other = $devices->create(trim((string) ($_POST['name'] ?? '')), 'ht802', 'udp');
        $devices->setPort((int) $other['id'], (int) $first['port'] === 1 ? 2 : 1);
        $devices->setMac((int) $other['id'], (string) $first['mac']);
        (new PjsipConfig($devices))->apply();
        flash('Added — reboot the adapter so it picks up the new phone.');
        redirect(url(['screen' => 'phones', 'device' => $other['id']]));

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
        foreach (['name', 'timeFrom', 'timeTo'] as $field) {
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

            $rang = ($reply['response'] ?? '') === 'Success';
            if ($rang) {
                (new Onboarding())->markTested();
            }
            flash($rang
                ? $target['name'] . ' should be ringing now ☎'
                : 'Asterisk refused the call: ' . ($reply['message'] ?? 'no reply'));
        } catch (Throwable $e) {
            flash('Could not reach Asterisk: ' . $e->getMessage());
        }
        break;

    case 'onboarding':
        // The Getting started guide: put it off, finish it, open it again, or
        // skip (or un-skip) the phone line step.
        $onboarding = new Onboarding();
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'later') {
            $onboarding->setState('later');
            flash('No rush — Getting started is in the menu whenever you want it');
            redirect(url(['screen' => 'dashboard']));
        }
        if ($do === 'done') {
            $onboarding->setState('done');
            flash('All set 🎉');
            redirect(url(['screen' => 'dashboard']));
        }
        if ($do === 'skip-line' || $do === 'unskip-line') {
            $onboarding->skip('line', $do === 'skip-line');
        }
        redirect(url(['screen' => 'start']));

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
        // Grandstream hardware is provisioned over UDP; the picker is hidden for it.
        $provisioned = in_array($type, ['ghp621', 'ht801', 'ht802'], true);
        $transport = $provisioned ? 'udp' : (string) ($_POST['transport'] ?? 'udp');
        $mac = GrandstreamProvisioning::normalizeMac((string) ($_POST['mac'] ?? ''));

        // An adapter has no screen to type an account into: without its MAC
        // there is no way to hand it one, so ask before creating anything.
        if ($type === 'ht801' || $type === 'ht802') {
            if ($mac === '') {
                flash('That MAC address does not look right — it is on the label under the adapter.');
                redirect(url(['screen' => 'phones', 'wizard' => 2]));
            }
            if ($devices->findByMac($mac) !== []) {
                flash('Another phone already has that MAC address.');
                redirect(url(['screen' => 'phones', 'wizard' => 2]));
            }
        }

        if (!(DeviceRepository::TRANSPORTS[$transport]['available'] ?? false)) {
            flash('Pick a transport that is ready');
            redirect(url(['screen' => 'phones', 'wizard' => 2]));
        }

        $device = $devices->create(trim((string) ($_POST['name'] ?? '')), $type, $transport);

        if ($provisioned && $mac !== '' && !$devices->setMac((int) $device['id'], $mac)) {
            flash('Phone added, but another phone already has that MAC address.');
        }

        // The HT802's second socket is a phone of its own on the same box.
        $second = trim((string) ($_POST['name2'] ?? ''));
        if ($type === 'ht802' && $second !== '') {
            $other = $devices->create($second, $type, $transport);
            $devices->setPort((int) $other['id'], 2);
            $devices->setMac((int) $other['id'], $mac);
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
            'alwaysRing' => isset($_POST['alwaysRing']),
            // Custom hours by day — only read when the custom window is picked.
            'schedule' => $_POST['schedule'] ?? [],
            // Not from the post: the switch lives in its own form (see
            // contact_group_toggle), so the Save button never sends it, and a
            // group was being validated as a person with no number.
            'isGroup' => (int) ($contacts->find((int) $id)['is_group'] ?? 0) === 1,
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

    case 'contact_group_prompt':
        $prompts = new GroupPromptStore();
        $group = $contacts->find((int) $id);
        $back = url(['screen' => 'contacts', 'contact' => $id]);

        if ($group === null || (int) ($group['is_group'] ?? 0) !== 1) {
            flash('Only a group has a greeting of its own');
            redirect(url(['screen' => 'contacts']));
        }
        if (!$prompts->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            redirect($back);
        }

        $converted = $prompts->store($_FILES['greeting'] ?? []);
        if ($converted['error'] !== null) {
            flash($converted['error']);
            redirect($back);
        }

        $prompts->delete((string) ($group['group_prompt'] ?? ''));
        $contacts->setGroupPrompt((int) $id, (string) $converted['file'], $converted['seconds']);
        (new PjsipConfig($devices))->apply();
        flash('Greeting saved ✓');
        redirect($back);

    case 'contact_group_prompt_remove':
        $group = $contacts->find((int) $id);
        if ($group !== null) {
            (new GroupPromptStore())->delete((string) ($group['group_prompt'] ?? ''));
            $contacts->setGroupPrompt((int) $id, null);
            (new PjsipConfig($devices))->apply();
        }
        flash('Greeting removed — this group uses the house one again');
        redirect(url(['screen' => 'contacts', 'contact' => $id]));

    case 'contact_announce':
    case 'contact_announce_voice':
        // Their name, spoken when a phone that says who's calling is picked
        // up: a recording, or Home Assistant's voice saying it.
        $names = new CallerNameStore();
        $person = $contacts->find((int) $id);
        $back = url(['screen' => 'contacts', 'contact' => $id]);

        if ($person === null || (int) ($person['is_group'] ?? 0) === 1) {
            flash('Only a person has a name to say');
            redirect(url(['screen' => 'contacts']));
        }
        if (!$names->isAvailable()) {
            flash("Audio conversion isn't available — the web container needs rebuilding.");
            redirect($back);
        }

        if ($action === 'contact_announce_voice') {
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '') {
                $text = "It's " . (string) $person['name'] . '!';
            }
            $converted = (new HomeAssistant())->speak(mb_substr($text, 0, 80), $names);
            if ($converted['error'] !== null) {
                $converted['error'] = "Home Assistant couldn't say it — " . $converted['error'] . '.';
            }
        } else {
            $converted = $names->store($_FILES['clip'] ?? []);
        }
        if ($converted['error'] !== null) {
            flash($converted['error']);
            redirect($back);
        }

        $names->delete((string) ($person['announce_clip'] ?? ''));
        $contacts->setAnnounce((int) $id, (string) $converted['file'], $converted['seconds']);
        (new PjsipConfig($devices))->apply();
        flash('Saved ✓ — phones that say who\'s calling will use it');
        redirect($back);

    case 'ports_save':
    case 'ports_try':
        $opener = new PortOpener();
        if ($action === 'ports_save') {
            $router = trim((string) ($_POST['router'] ?? ''));
            if ($router !== '' && !filter_var($router, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                flash("That router address doesn't look right — it's four numbers, like 192.168.1.1.");
                redirect(url(['screen' => 'trunk']) . '#ports');
            }
            $opener->save(isset($_POST['auto']), array_map('strval', (array) ($_POST['groups'] ?? [])), $router);
        }
        if (!$opener->auto()) {
            $opener->closeAll();
            flash('Automatic port opening is off');
            redirect(url(['screen' => 'trunk']) . '#ports');
        }
        $state = $opener->run();
        flash($state['error'] === '' ? 'The router opened them ✓' : $state['error']);
        redirect(url(['screen' => 'trunk']) . '#ports');

    case 'contact_link_create':
        $person = $contacts->find((int) $id);
        if ($person === null || (int) ($person['is_group'] ?? 0) === 1) {
            flash('Only a person can have a link');
            redirect(url(['screen' => 'contacts']));
        }
        (new ContactLinkRepository())->create((int) $id);
        flash('Link made — copy it or send it');
        redirect(url(['screen' => 'contacts', 'contact' => $id]));

    case 'contact_link_stop':
        (new ContactLinkRepository())->revoke((int) $id);
        flash('Link stopped — it no longer works');
        redirect(url(['screen' => 'contacts', 'contact' => $id]));

    case 'contact_announce_remove':
        $person = $contacts->find((int) $id);
        if ($person !== null) {
            (new CallerNameStore())->delete((string) ($person['announce_clip'] ?? ''));
            $contacts->setAnnounce((int) $id, null);
            (new PjsipConfig($devices))->apply();
        }
        flash('Removed — their calls connect without their name');
        redirect(url(['screen' => 'contacts', 'contact' => $id]));

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

        // ------------------------------------------- callers nobody recognises
    case 'screening_set':
        $settings = new SettingsRepository();
        $on = ($_POST['on'] ?? '') === '1';
        $settings->setTakesUnknownMessages($on);

        // Whether an unrecognised caller is handed to the mailbox or hung up on
        // after the refusal is decided by the dialplan, so the switch only means
        // anything once the config has been rewritten and reloaded.
        (new PjsipConfig($devices))->apply();
        flash($on
            ? 'Unknown callers can leave a message again'
            : 'Unknown callers are hung up on again');
        break;

    case 'screening_allow':
        $voicemails = new VoicemailRepository();
        $message = $voicemails->find((int) $id);
        if ($message === null) {
            flash('That message has gone');
            break;
        }

        // A number that reached us over the trunk is already E.164.
        $number = (string) $message['peer_number'];
        $voicemails->resolve((int) $id, 'approved', Auth::user()['id'] ?? null);

        $existing = $contacts->findByNumber($number);
        if ($existing !== null) {
            flash($existing['name'] . ' is already on the call list');
            redirect(url(['screen' => 'contacts', 'contact' => (int) $existing['id']]));
        }

        /*
         * Adding them does not mean dropping a bare number on the list and
         * calling it done — it opens the contact editor with the number filled
         * in, so a grown-up still names the person and decides when they may be
         * called. prefill() leaves them switched off, so a half-built contact
         * cannot widen the allowlist on its own.
         */
        $contactId = $contacts->create();
        $contacts->prefill($contactId, $number);

        flash('Now finish setting them up');
        redirect(url(['screen' => 'contacts', 'contact' => $contactId]));

        // no break — redirect exits

    case 'screening_junk':
        // The row and the recording stay: the message is somebody's real words.
        // Recording the decision is what stops it asking again, and a new
        // message from the same number arrives as a row of its own.
        (new VoicemailRepository())->resolve((int) $id, 'junk', Auth::user()['id'] ?? null);
        flash('Dismissed — a new message from that number will show up here');
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
            'region' => (string) ($_POST['region'] ?? $store->trunkDraft()['region']),
            'sid' => (string) ($_POST['sid'] ?? $store->trunkDraft()['sid']),
            'token' => (string) ($_POST['token'] ?? $store->trunkDraft()['token']),
            'number' => (string) ($_POST['number'] ?? $store->trunkDraft()['number']),
            'termination' => (string) ($_POST['termination'] ?? $store->trunkDraft()['termination']),
            'terminationUsername' => (string) ($_POST['terminationUsername'] ?? $store->trunkDraft()['terminationUsername']),
            'terminationPassword' => (string) ($_POST['terminationPassword'] ?? $store->trunkDraft()['terminationPassword']),
            'apiKey' => (string) ($_POST['apiKey'] ?? $store->trunkDraft()['apiKey']),
            'proxy' => (string) ($_POST['proxy'] ?? $store->trunkDraft()['proxy']),
        ]);
        redirect(url(['screen' => 'trunk', 'trunkwizard' => $step]));

    case 'trunk_connect':
        $draft = $store->trunkDraft();
        $result = (new TrunkRepository())->connect($draft);

        if (!$result['ok']) {
            // Keep the non-secret fields, but never echo a secret back into the
            // form. setTrunkDraft merges, so every secret has to be named here
            // or it survives into the re-rendered inputs.
            $store->setTrunkDraft([
                'provider' => $draft['provider'],
                'region' => $draft['region'],
                'sid' => $draft['sid'],
                'number' => $draft['number'],
                'termination' => $draft['termination'],
                'terminationUsername' => $draft['terminationUsername'],
                'proxy' => $draft['proxy'],
                'token' => '',
                'apiKey' => '',
                'terminationPassword' => '',
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

    /*
     * Which phone the line's number rings. Empty means all of them, which is
     * how a line behaves until someone narrows it.
     */
    case 'trunk_ring_device':
        $wanted = trim((string) ($_POST['device'] ?? ''));
        $chosen = null;
        // Only one of the line's own numbers can be pointed anywhere.
        $number = (string) ($_POST['number'] ?? '');
        if (!in_array($number, (new TrunkRepository())->get()['numbers'], true)) {
            flash('That number is no longer on the line.');
            break;
        }

        if ($wanted !== '') {
            $chosen = $devices->find((int) $wanted);
            if ($chosen === null) {
                flash('That phone is no longer on the line.');
                break;
            }
        }

        (new TrunkRepository())->setRingDevice($number, $chosen === null ? null : (int) $chosen['id']);
        // Who rings is baked into the generated incoming dialplan.
        (new PjsipConfig($devices))->apply();

        flash($chosen === null
            ? 'Calls to ' . $number . ' will ring every phone'
            : 'Calls to ' . $number . ' will ring ' . (string) $chosen['name']);
        break;

    /*
     * Reopen the wizard against the line that is already connected.
     *
     * Everything but the secrets is seeded from the stored trunk, so changing
     * one field does not mean retyping the rest. The token and API key are
     * deliberately left blank: they are write-only, and asking for them again
     * is the price of not keeping them where they could be echoed back.
     */
    case 'trunk_edit':
        $current = (new TrunkRepository())->get();
        $store->setTrunkDraft([
            'provider' => $current['provider'],
            'region' => $current['region'],
            'sid' => $current['accountSid'],
            // Every number, main one first, the way the box takes them.
            'number' => implode(' ', $current['numbers']),
            'termination' => $current['terminationUri'],
            'terminationUsername' => $current['terminationUsername'],
            'terminationPassword' => '',
            'proxy' => $current['sipProxy'],
            'token' => '',
            'apiKey' => '',
        ]);
        redirect(url(['screen' => 'trunk', 'trunkwizard' => 2]));

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
