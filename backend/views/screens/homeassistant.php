<?php
/**
 * Home Assistant: the MQTT broker twocans talks to, the optional HA login for
 * text-to-speech, the bridge's live status, and what HA gets.
 *
 * @var Store $store
 */
$ha = new HomeAssistant();
$c = $ha->config();
$status = $ha->status();
$seen = isset($status['at']) ? (int) $status['at'] : 0;
$fresh = $seen > 0 && time() - $seen < 150;
$engines = $c['url'] !== '' && $c['token'] !== '' ? $ha->ttsEngines() : [];
$phones = count(array_filter(
    array_map([DeviceRepository::class, 'toView'], (new DeviceRepository())->all()),
    static fn(array $d): bool => $d['sipUsername'] !== '' && $d['available']
));
?>
<div class="tc-split tc-split--main-first">

  <div class="tc-split__main tc-stack" style="gap:16px">

    <?php /* Where the bridge stands, straight away. */ ?>
    <section class="tc-card tc-ha-status tc-ha-status--<?= !$c['enabled'] || !$c['configured'] ? 'off' : ($fresh && !empty($status['connected']) ? 'ok' : 'bad') ?>">
      <i class="fa-solid fa-house-signal tc-ha-status__icon" aria-hidden="true"></i>
      <div class="tc-grow">
        <?php if (!$c['configured']): ?>
          <div class="tc-ha-status__title">Not set up yet</div>
          <div class="tc-card__hint">Add your MQTT broker below and switch the bridge on.</div>
        <?php elseif (!$c['enabled']): ?>
          <div class="tc-ha-status__title">Switched off</div>
          <div class="tc-card__hint">Nothing is sent to Home Assistant until you switch it on.</div>
        <?php elseif ($fresh && !empty($status['connected'])): ?>
          <div class="tc-ha-status__title">Connected to <?= e($c['host']) ?></div>
          <div class="tc-card__hint">Checked in <?= e(max(0, time() - $seen) < 60 ? 'just now' : intdiv(time() - $seen, 60) . ' min ago') ?> · your line and <?= (int) $phones ?> phone<?= $phones === 1 ? '' : 's' ?> are in Home Assistant.</div>
        <?php else: ?>
          <div class="tc-ha-status__title">Not connected</div>
          <div class="tc-card__hint">
            <?= !empty($status['error']) ? e((string) $status['error']) : "The bridge hasn't checked in — it may still be starting, or not running on this box." ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($c['configured']): ?>
        <form method="post" action="/">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="ha_test">
          <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Test connection</button>
        </form>
      <?php endif; ?>
    </section>

    <form class="tc-card tc-stack" style="gap:14px" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="ha_save">

      <div class="tc-card__intro">
        <h2 class="tc-card__title">MQTT broker</h2>
        <p class="tc-card__hint">
          The one Home Assistant uses — usually the <b>Mosquitto broker</b> add-on. twocans
          publishes to it with MQTT discovery, so everything appears in Home Assistant by
          itself under Settings → Devices.
        </p>
      </div>

      <label class="tc-ha-toggle">
        <input type="checkbox" name="enabled" <?= $c['enabled'] ? 'checked' : '' ?>>
        <span><b>Send twocans to Home Assistant</b></span>
      </label>

      <div class="tc-ha-grid">
        <label class="tc-label tc-label--sm tc-ha-grid__wide">Broker address
          <input class="tc-input" type="text" name="host" value="<?= e($c['host']) ?>" placeholder="192.168.1.10 or homeassistant.local" autocomplete="off">
        </label>
        <label class="tc-label tc-label--sm">Port
          <input class="tc-input" type="number" name="port" value="<?= (int) $c['port'] ?>" min="1" max="65535">
        </label>
        <label class="tc-label tc-label--sm">Username
          <input class="tc-input" type="text" name="username" value="<?= e($c['username']) ?>" autocomplete="off">
        </label>
        <label class="tc-label tc-label--sm">Password
          <input class="tc-input" type="password" name="password" value=""
                 placeholder="<?= $c['hasPassword'] ? 'saved — leave blank to keep' : '' ?>" autocomplete="new-password">
        </label>
      </div>
      <label class="tc-ha-toggle"><input type="checkbox" name="tls" <?= $c['tls'] ? 'checked' : '' ?>> <span>Use TLS (usually port 8883)</span></label>

      <details class="tc-manual-setup">
        <summary>Topics</summary>
        <div class="tc-ha-grid tc-mt-14">
          <label class="tc-label tc-label--sm">twocans topic
            <input class="tc-input" type="text" name="base" value="<?= e($c['base']) ?>">
          </label>
          <label class="tc-label tc-label--sm">Discovery prefix
            <input class="tc-input" type="text" name="prefix" value="<?= e($c['prefix']) ?>">
          </label>
        </div>
      </details>

      <div class="tc-card__intro tc-card__intro--sub">
        <h3 class="tc-card__subtitle">Speaking text on the phones (optional)</h3>
        <p class="tc-card__hint">
          For the <b>Announce</b> text box in Home Assistant: twocans asks Home Assistant's
          own text-to-speech to say it, then plays it on the phones. Needs Home Assistant's
          address and a long-lived access token (your profile → Security in Home Assistant).
        </p>
      </div>
      <div class="tc-ha-grid">
        <label class="tc-label tc-label--sm tc-ha-grid__wide">Home Assistant address
          <input class="tc-input" type="text" name="url" value="<?= e($c['url']) ?>" placeholder="http://homeassistant.local:8123" autocomplete="off">
        </label>
        <label class="tc-label tc-label--sm tc-ha-grid__wide">Long-lived access token
          <input class="tc-input" type="password" name="token" value=""
                 placeholder="<?= $c['hasToken'] ? 'saved — leave blank to keep' : '' ?>" autocomplete="off">
        </label>
        <label class="tc-label tc-label--sm tc-ha-grid__wide">Voice
          <?php if ($engines !== []): ?>
            <select class="tc-input" name="tts">
              <option value="">— choose —</option>
              <?php foreach ($engines as $id => $name): ?>
                <option value="<?= e($id) ?>"<?= $c['ttsEngine'] === $id ? ' selected' : '' ?>><?= e($name) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input class="tc-input" type="text" name="tts" value="<?= e($c['ttsEngine']) ?>" placeholder="tts.home_assistant_cloud">
            <span class="tc-micro tc-field-note">
              <?= $c['url'] !== '' && $c['token'] !== '' ? "Couldn't list Home Assistant's voices — type the entity id." : 'Save the address and token to pick from Home Assistant\'s voices.' ?>
            </span>
          <?php endif; ?>
        </label>
      </div>

      <div class="tc-row">
        <button class="tc-btn tc-btn--teal" type="submit">Save</button>
      </div>
    </form>
  </div>

  <aside class="tc-split__side tc-stack" style="gap:16px">
    <section class="tc-card">
      <div class="tc-card__intro">
        <h2 class="tc-card__title">What Home Assistant gets</h2>
      </div>
      <ul class="tc-ha-list">
        <li><b>A twocans device</b> — calls today, active calls, unheard voicemails, people waiting for a decision, the phone line and its credit, and whether bedtime is on and in force.</li>
        <li><b>Switches</b> for bedtime mode and whether unknown callers can leave a message.</li>
        <li><b>Buttons</b> for each announcement, and an <b>Announce</b> text box that speaks on the phones.</li>
        <li><b>A device per phone</b> — online, ringing or on a call and who with, adult mode, minutes today, switches for incoming and outgoing calls and for saying who's calling, and Ring and Hang up buttons.</li>
        <li><b>Events</b> for automations: calls ringing, answered and ended; unknown callers leaving a message and kids asking; a phone running out of time; bedtime starting and ending.</li>
      </ul>
      <p class="tc-card__hint">Adult mode is shown but can't be switched on from Home Assistant — only on the phone's page, behind its confirmation.</p>
    </section>

    <section class="tc-card">
      <div class="tc-card__intro">
        <h2 class="tc-card__title">An automation to start from</h2>
        <p class="tc-card__hint">Dim the living room when a call is answered:</p>
      </div>
      <pre class="tc-announce__code">trigger:
  - platform: state
    entity_id: event.twocans_event_calls
condition:
  - "{{ trigger.to_state.attributes.event_type == 'answered' }}"
action:
  - service: light.turn_on
    target: { entity_id: light.living_room }
    data: { brightness_pct: 30 }</pre>
    </section>
  </aside>
</div>
