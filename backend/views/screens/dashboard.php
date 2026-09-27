<?php
/**
 * @var Store  $store
 * @var array $activeCalls
 * @var DeviceRepository $devices
 * @var CallRepository $calls
 */
$settings = $store->settings();
$deviceRows = array_map([DeviceRepository::class, 'toView'], $devices->all());
$deviceRows = array_map([Presenter::class, 'device'], $deviceRows);
$recent = array_map([CallRepository::class, 'toView'], $calls->all(4));
$recent = array_map([Presenter::class, 'call'], $recent);
// Who each group call reached — see call_participants.
$reached = $calls->participants(array_column($recent, 'uniqueid'));
// With more than one number on the line, say which one a call came in on.
$lineNumbers = $store->trunk()['numbers'];
// Callers nobody recognises who left a message in the house mailbox. Numbers a
// child tried to call are listed on the call log instead — see UnknownQueue.
$unknown = (new UnknownQueue())->pending();
$trunk = $store->trunk();

$callsToday = $calls->countToday('done');
$blockedToday = $calls->countToday('blocked');
$onlineCount = count(array_filter($deviceRows, static fn($d) => $d['online']));
?>
<div class="tc-stack">

  <?php foreach ($activeCalls as $call): ?>
    <div class="tc-livebar">
      <span class="tc-livebar__dot"></span>
      <div class="tc-livebar__body">
        <div class="tc-livebar__title">
          Live now · <?= e($call['deviceName']) ?> ↔ <?= e($call['peerName']) ?>
        </div>
        <div class="tc-livebar__meta">
          <?= $call['dir'] === 'in' ? 'Incoming' : 'Outgoing' ?> call ·
          <span data-tc-elapsed="<?= (int) $call['startTs'] ?>"><?= e(fmt_duration($call['seconds'])) ?></span>
          <?php if (!$call['connected']): ?> · ringing<?php endif; ?>
        </div>
      </div>
      <?php if (Auth::can('listen')): ?>
        <a class="tc-btn tc-btn--white"
           href="<?= e(url(['screen' => 'dashboard', 'listen' => $call['channel']])) ?>">Listen in</a>
        <form method="post" action="/" class="tc-inline-form">
          <?= form_fields() ?>
          <input type="hidden" name="action" value="call_end">
          <input type="hidden" name="channel" value="<?= e($call['channel']) ?>">
          <button class="tc-btn tc-btn--outline-white" type="submit">End</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($store->isLowCredit()): ?>
    <div class="tc-lowcredit">
      <div class="tc-lowcredit__icon">!</div>
      <div class="tc-grow">
        <div class="tc-lowcredit__title">Phone line is running low</div>
        <div class="tc-lowcredit__body">Only <?= e(Presenter::money($trunk)) ?> of call credit left — top up so calls don't get cut off.</div>
      </div>
      <a class="tc-btn tc-btn--sun" href="<?= e(url(['screen' => 'trunk'])) ?>">Top up</a>
    </div>
  <?php endif; ?>

  <div class="tc-grid tc-grid--stats4">
    <div class="tc-stat tc-stat--coral"><div class="tc-stat__num"><?= $callsToday ?></div><div class="tc-stat__label">calls today</div></div>
    <div class="tc-stat tc-stat--teal"><div class="tc-stat__num"><?= $onlineCount ?>/<?= count($deviceRows) ?></div><div class="tc-stat__label">phones online</div></div>
    <div class="tc-stat tc-stat--lav"><div class="tc-stat__num"><?= (new ContactRepository())->count() ?></div><div class="tc-stat__label">people allowed</div></div>
    <div class="tc-stat tc-stat--red"><div class="tc-stat__num"><?= $blockedToday ?></div><div class="tc-stat__label">blocked today</div></div>
  </div>

  <?php
  /*
   * Announcement buttons: press one and the phones play its message. Only for
   * the grown-ups who may change the house's rules; set up on Announcements.
   */
  ?>
  <?php if (Auth::can('rules')): ?>
    <?php $announcements = (new AnnouncementRepository())->all(); ?>
    <section class="tc-card tc-announce-bar">
      <div class="tc-card__head">
        <h2 class="tc-card__title">Announce</h2>
        <a class="tc-link" href="<?= e(url(['screen' => 'announcements'])) ?>"><?= $announcements === [] ? 'Set one up →' : 'Manage →' ?></a>
      </div>
      <?php if ($announcements === []): ?>
        <p class="tc-card__hint tc-card__lead">
          Record “dinner's ready” once and it becomes a button here that plays it on the phones.
        </p>
      <?php else: ?>
        <div class="tc-announce-bar__buttons">
          <?php foreach ($announcements as $a): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="announce_send">
              <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
              <button class="tc-announce-btn" type="submit"
                      <?= $a['audio'] === '' ? 'disabled title="Record its message first"' : '' ?>>
                <span class="tc-announce-btn__emoji"><?= icon_html($a['emoji']) ?></span>
                <span class="tc-announce-btn__label"><?= e($a['label'] !== '' ? $a['label'] : 'Untitled') ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="tc-dashboard">

    <div class="tc-stack">
      <!-- People we don't know -->
      <section class="tc-card">
        <div class="tc-card__head tc-card__head--wrap">
          <div class="tc-row">
            <h2 class="tc-card__title">People we don't know</h2>
            <?php if ($unknown): ?>
              <span class="tc-pill tc-pill--coral"><?= count($unknown) ?></span>
            <?php endif; ?>
          </div>
          <?php if (Auth::can('rules')): ?>
            <form method="post" action="/" class="tc-row tc-row--tight">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="screening_set">
              <span class="tc-card__hint tc-nowrap" aria-hidden="true">Take messages</span>
              <?php /* The switch posts the state it should move to, so the button
                       and the setting can never end up disagreeing. Plain switch,
                       not tc-switch--light: that variant is translucent white and
                       this card is white too — see the note in twocans.css. */ ?>
              <input type="hidden" name="on" value="<?= $settings['screenUnknown'] ? '0' : '1' ?>">
              <button type="submit"
                      class="tc-switch <?= $settings['screenUnknown'] ? 'is-on' : '' ?>"
                      role="switch"
                      aria-checked="<?= $settings['screenUnknown'] ? 'true' : 'false' ?>"
                      aria-label="Take messages from callers we don't know"
                      title="Take messages from callers we don't know"></button>
            </form>
          <?php else: ?>
            <span class="tc-row tc-row--tight">
              <span class="tc-card__hint tc-nowrap">Take messages</span>
              <span class="tc-switch <?= $settings['screenUnknown'] ? 'is-on' : '' ?>"
                    role="img" title="Only an Owner or Admin can change this"></span>
            </span>
          <?php endif; ?>
        </div>
        <p class="tc-card__hint tc-card__lead">
          Callers nobody recognises wait here for a grown-up. With <b>Take
          messages</b> on, they can leave one in the house mailbox; off, the line
          just hangs up. Numbers the kids tried to call are in the
          <a class="tc-link" href="<?= e(url(['screen' => 'calllog'])) ?>">call log</a>.
        </p>

        <?php if ($unknown): ?>
          <div class="tc-stack tc-stack--tight">
            <?php foreach ($unknown as $u): ?>
              <?php view('partials/unknown_card', ['u' => $u]); ?>
            <?php endforeach; ?>
          </div>
        <?php elseif ($settings['screenUnknown']): ?>
          <div class="tc-empty">All caught up 🎉</div>
        <?php else: ?>
          <div class="tc-empty">All caught up 🎉 Unknown callers are hung up on.</div>
        <?php endif; ?>
      </section>

      <!-- Recent calls -->
      <section class="tc-card">
        <div class="tc-card__head">
          <h2 class="tc-card__title">Recent calls</h2>
          <a class="tc-link" href="<?= e(url(['screen' => 'calllog'])) ?>">See all →</a>
        </div>
        <div class="tc-stack tc-stack--snug">
          <?php foreach ($recent as $c): ?>
            <a class="tc-recent-row" href="<?= e(url(['screen' => 'calllog', 'call' => $c['id']])) ?>#call-<?= (int) $c['id'] ?>">
              <div class="tc-avatar" style="background:<?= e($c['color']) ?>"><?= e($c['initial']) ?></div>
              <div class="tc-grow">
                <div class="tc-recent-row__name"><?= e($c['name']) ?></div>
                <div class="tc-recent-row__meta">
                  <?php $on = count($lineNumbers) > 1 && $c['dir'] === 'in' ? TrunkRepository::lineNumberFor($c['dialled'], $lineNumbers) : null; ?>
                  <?= $c['dir'] === 'in' ? '↙' : '↗' ?> <?= e($c['via']) ?><?= $on !== null ? ' on ' . e($on) : '' ?> · <?= e($c['date']) ?> <?= e($c['time']) ?>
                </div>
                <?php if (!empty($reached[$c['uniqueid']])): ?>
                  <div class="tc-recent-row__meta tc-recent-row__who"><?= e(CallRepository::describeParticipants($reached[$c['uniqueid']])) ?></div>
                <?php endif; ?>
              </div>
              <?php /* How long they talked is the answer to "did they get
                       through?", so an answered call shows that instead of a
                       tag; the rest say what happened. */ ?>
              <?php if ($c['status'] === 'done'): ?>
                <span class="tc-recent-row__dur"><?= e($c['dur'] !== '—' ? $c['dur'] : '0:00') ?></span>
              <?php else: ?>
                <span class="tc-pill tc-pill--<?= e($c['statusMod']) ?>"><?= e($c['tag']) ?></span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

    </div>

    <div class="tc-stack">
      <!-- The line -->
      <section class="tc-card">
        <div class="tc-card__head">
          <h2 class="tc-card__title">The line</h2>
          <a class="tc-link" href="<?= e(url(['screen' => 'phones'])) ?>">Manage →</a>
        </div>
        <div class="tc-stack tc-stack--tight">
          <?php foreach ($deviceRows as $d): ?>
            <a class="tc-line-row" href="<?= e(url(['screen' => 'phones', 'device' => $d['id']])) ?>">
              <span class="tc-can <?= $d['online'] ? '' : 'is-offline' ?>"></span>
              <span class="tc-grow">
                <span class="tc-line-row__name">
                  <?= e($d['name']) ?>
                  <?php if (!empty($d['adult'])): ?>
                    <span class="tc-adult-badge tc-adult-badge--sm"><i class="fa-solid fa-unlock" aria-hidden="true"></i> Adult mode</span>
                  <?php endif; ?>
                </span>
                <span class="tc-line-row__meta"><?= e($d['model']) ?> · <?= e($d['statusText']) ?></span>
              </span>
              <span class="tc-string <?= $d['online'] ? '' : 'is-offline' ?>"></span>
              <span class="tc-dot <?= $d['online'] ? '' : 'is-offline' ?>"></span>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Bedtime mode -->
      <section class="tc-bedtime">
        <div class="tc-bedtime__head">
          <h2 class="tc-card__title">Bedtime mode</h2>
          <?php if (Auth::can('rules')): ?>
            <form method="post" action="/">
              <?= form_fields() ?>
              <input type="hidden" name="action" value="toggle_quiet">
              <button type="submit"
                      class="tc-switch tc-switch--light <?= $settings['quietHours'] ? 'is-on' : '' ?>"
                      role="switch"
                      aria-checked="<?= $settings['quietHours'] ? 'true' : 'false' ?>"
                      aria-label="Bedtime mode"></button>
            </form>
          <?php else: ?>
            <span class="tc-switch tc-switch--light <?= $settings['quietHours'] ? 'is-on' : '' ?>"
                  role="img" title="Only an Owner or Admin can change this"></span>
          <?php endif; ?>
        </div>
        <div class="tc-bedtime__state"><?= e(Presenter::quietStateText($settings)) ?></div>
        <?php /* One slot per rule: school nights and weekends can differ. */ ?>
        <dl class="tc-bedtime__times">
          <?php foreach ($settings['quietRules'] as $rule): ?>
            <div class="tc-bedtime__slot">
              <dt><?= e(Schedule::describeDays($rule['days'])) ?></dt>
              <dd><?= e($rule['from']) ?>–<?= e($rule['to']) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
        <div class="tc-bedtime__foot">
          Only the SOS contact rings through while it's quiet.
          <?php if (Auth::can('rules')): ?>
            <a class="tc-bedtime__edit" href="<?= e(url(['screen' => 'dashboard', 'bedtime' => '1'])) ?>">Change times →</a>
          <?php endif; ?>
        </div>
      </section>

      <!-- What a caller hears while the line is quiet -->
      <section class="tc-card">
        <div class="tc-card__head">
          <h2 class="tc-card__title">Quiet-time message</h2>
        </div>
        <p class="tc-card__hint tc-card__lead">
          Plays instead of the voicemail greeting during bedtime, or when someone
          calls outside a person's hours. Callers can press <b>5</b> for a joke, or
          wait to leave a message.
        </p>

        <?php if ($settings['quietMessage'] !== ''): ?>
          <div class="tc-vm-row tc-vm-row--bare">
            <?php /* Same player as voicemail, the joke line and the phones'
                     refusal messages, so audio behaves the same everywhere. */ ?>
            <audio data-audio preload="none"
                   src="<?= e(url(['download' => 'quiet_message'])) ?>"></audio>
            <button class="tc-vm-play" type="button" data-play aria-label="Play the quiet-time message">▶</button>
            <div class="tc-grow">
              <div class="tc-vm-row__name">Your message</div>
              <div class="tc-call-row__meta">
                <?= e(fmt_duration($settings['quietMessageSeconds'])) ?> · the whole house, not one phone
              </div>
            </div>
            <?php if (Auth::can('rules')): ?>
              <form method="post" action="/" class="tc-inline-form">
                <?= form_fields() ?>
                <input type="hidden" name="action" value="quiet_message_remove">
                <button class="tc-link" type="submit">remove</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if (Auth::can('rules')): ?>
          <form method="post" action="/" enctype="multipart/form-data"
                class="tc-row tc-row--wrap">
            <?= form_fields() ?>
            <input type="hidden" name="action" value="quiet_message">
            <label class="tc-btn tc-btn--ghost tc-audio-file">
              <span data-tc-filename>Record or choose a file</span>
              <input type="file" name="message"
                     accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.flac,.amr,.aac,.3gp"
                     data-tc-audiofile required>
            </label>
            <button class="tc-btn tc-btn--teal" type="submit">
              <?= $settings['quietMessage'] === '' ? 'Save message' : 'Replace message' ?>
            </button>
          </form>
          <p class="tc-card__hint tc-card__after">
            Up to 30 seconds — any audio file or voice memo will do.
            <?php if ($settings['quietMessage'] === ''): ?>
              Until then, callers hear the standard greeting.
            <?php endif; ?>
          </p>
        <?php elseif ($settings['quietMessage'] === ''): ?>
          <div class="tc-empty">Nothing recorded yet, so callers hear the standard greeting.</div>
        <?php endif; ?>
      </section>
    </div>

  </div>
</div>
