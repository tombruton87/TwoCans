<?php
/**
 * Notification settings: Mailgun email and an Uptime Kuma heartbeat.
 *
 * @var Store $store
 */
$repo = new NotificationRepository();
$config = $repo->get();
$canEdit = Auth::can('notifications');
$me = (int) Auth::user()['id'];
$myDevices = (new Push())->all($me);
?>
<div class="tc-stack tc-narrow">

  <?php /* Notifications on a grown-up's own phone or computer — Web Push. This
           browser subscribes itself (twocans.js, data-tc-push); the ones already
           on are listed, to test or stop. See Push and WebPush. */ ?>
  <section class="tc-card tc-push" id="push-devices" data-tc-ajax-region
           data-tc-push="<?= e((new WebPush())->publicKey()) ?>">
    <h2 class="tc-card__title"><i class="fa-solid fa-bell" aria-hidden="true"></i> On your phone and computer</h2>
    <p class="tc-card__hint">
      A notification on this device, even with twocans closed: an emergency number dialled, a message from someone
      not on the list, a number a child tried, a phone gone offline or its handset left off the hook. Tap one to open
      the page it's about. No email needed.
    </p>
    <div class="tc-row tc-row--wrap">
      <button class="tc-btn tc-btn--teal" type="button" data-tc-push-on hidden>
        <i class="fa-solid fa-bell" aria-hidden="true"></i> Notify me on this device
      </button>
      <span class="tc-card__hint" data-tc-push-status role="status"></span>
    </div>
    <form method="post" action="/" data-tc-push-form hidden>
      <?= form_fields() ?>
      <input type="hidden" name="action" value="push_subscribe">
    </form>
    <?php if ($myDevices !== []): ?>
      <ul class="tc-push__devices">
        <?php foreach ($myDevices as $p): ?>
          <li data-tc-push-endpoint="<?= e((string) $p['endpoint']) ?>">
            <i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>
            <span class="tc-grow"><b><?= e((string) $p['label']) ?></b>
              <span class="tc-card__hint">· on since <?= e(date('j M', (int) strtotime((string) $p['created_at']))) ?></span></span>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="push_test">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Send a test</button>
            </form>
            <form method="post" action="/" data-tc-ajax>
              <?= form_fields() ?>
              <input type="hidden" name="action" value="push_remove">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button class="tc-btn--icon tc-btn--icon-danger" type="submit" aria-label="Stop notifying <?= e((string) $p['label']) ?>">×</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="tc-card__hint tc-push__iphone">
      On an iPhone or iPad: open twocans in Safari, tap Share → <b>Add to Home Screen</b>, then open it from there and
      turn notifications on.
    </p>
  </section>

  <section class="tc-card">
    <div class="tc-card__head">
      <div>
        <h2 class="tc-card__title">Notifications</h2>
        <div class="tc-card__hint">Email alerts (Mailgun) and an Uptime Kuma heartbeat.</div>
      </div>
      <?php if ($canEdit): ?>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="notifications_toggle">
          <button type="submit" class="tc-switch <?= $config['enabled'] ? 'is-on' : '' ?>" role="switch"
                  aria-checked="<?= $config['enabled'] ? 'true' : 'false' ?>" aria-label="Notifications"></button>
        </form>
      <?php else: ?>
        <span class="tc-switch <?= $config['enabled'] ? 'is-on' : '' ?>" role="img"
              title="Only the Owner can change this"></span>
      <?php endif; ?>
    </div>

    <?php if (!$config['enabled']): ?>
      <div class="tc-empty">Notifications are off.</div>
    <?php endif; ?>
  </section>

  <?php if ($config['enabled'] && $canEdit): ?>
    <form class="tc-card" method="post" action="/">
      <?= form_fields() ?>
      <input type="hidden" name="action" value="notifications_save">
      <input type="hidden" name="enabled" value="1">

      <div class="tc-card__head"><h2 class="tc-card__title">Email — Mailgun</h2></div>
      <p class="tc-card__hint">
        Email is optional. Leave it blank and only the Uptime Kuma heartbeat runs.
      </p>

      <label class="tc-label">Mailgun API key
        <input class="tc-input" type="password" name="mailgun_api_key" autocomplete="off"
               placeholder="<?= $config['hasKey'] ? '•••••• (leave blank to keep the saved key)' : 'key-…' ?>">
      </label>
      <label class="tc-label">Region
        <select class="tc-input" name="mailgun_region">
          <option value="us" <?= $config['region'] === 'us' ? 'selected' : '' ?>>US — api.mailgun.net</option>
          <option value="eu" <?= $config['region'] === 'eu' ? 'selected' : '' ?>>EU — api.eu.mailgun.net</option>
        </select>
      </label>
      <label class="tc-label">Sending domain
        <input class="tc-input" type="text" name="mailgun_domain" value="<?= e($config['domain']) ?>"
               placeholder="mg.example.com">
      </label>
      <label class="tc-label">From
        <input class="tc-input" type="text" name="mailgun_from" value="<?= e($config['from']) ?>"
               placeholder="twocans@mg.example.com">
      </label>
      <label class="tc-label">To (comma-separated)
        <input class="tc-input" type="text" name="mailgun_to" value="<?= e($config['to']) ?>"
               placeholder="you@example.com">
      </label>

      <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

      <div class="tc-card__head"><h2 class="tc-card__title">Uptime Kuma</h2></div>
      <p class="tc-card__hint">
        Create a <b>Push</b> monitor in Uptime Kuma and paste its URL here. twocans
        heartbeats it every minute; when the heartbeats stop, Kuma marks twocans down.
      </p>
      <label class="tc-label">Push URL
        <input class="tc-input" type="text" name="uptime_kuma_url" value="<?= e($config['kumaUrl']) ?>"
               placeholder="https://kuma.example.com/api/push/…">
      </label>

      <div class="tc-divider tc-divider--fine" style="margin:18px 0"></div>

      <div class="tc-card__head"><h2 class="tc-card__title">What to email about</h2></div>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_emergency" <?= $config['notifyEmergency'] ? 'checked' : '' ?>> A phone dialling an emergency number <span class="tc-micro">— sent straight away, on its own</span>
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_messages" <?= $config['notifyMessages'] ? 'checked' : '' ?>> Somebody not on the list leaving a message
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_asks" <?= $config['notifyAsks'] ? 'checked' : '' ?>> A child trying to call a number that isn't allowed
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_offline" <?= $config['notifyOffline'] ? 'checked' : '' ?>> A phone going offline
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_low_credit" <?= $config['notifyLowCredit'] ? 'checked' : '' ?>> Low call credit
      </label>
      <label style="display:flex;gap:8px;align-items:center;font:600 13px var(--tc-body);padding:5px 0">
        <input type="checkbox" name="notify_digest" <?= $config['notifyDigest'] ? 'checked' : '' ?>> A summary of the week <span class="tc-micro">— Sunday evening: calls and minutes per phone, who they talked to most</span>
      </label>

      <div style="margin-top:18px">
        <button class="tc-btn tc-btn--teal" type="submit">Save</button>
      </div>
    </form>

    <?php if ($config['mailgunConfigured'] || $config['kumaUrl'] !== ''): ?>
      <div class="tc-card">
        <div class="tc-card__head"><h2 class="tc-card__title">Test</h2></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($config['mailgunConfigured']): ?>
            <form method="post" action="/" class="tc-inline-form">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="notifications_test_email">
              <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Send test email</button>
            </form>
          <?php endif; ?>
          <?php if ($config['kumaUrl'] !== ''): ?>
            <form method="post" action="/" class="tc-inline-form">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="notifications_test_kuma">
              <button class="tc-btn tc-btn--ghost tc-btn--sm" type="submit">Send test heartbeat</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php elseif ($config['enabled']): ?>
    <div class="tc-card"><div class="tc-card__hint">Only the Owner can change these settings.</div></div>
  <?php endif; ?>

</div>
