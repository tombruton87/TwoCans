<?php
/**
 * The self-service page: one person — a grandparent, usually — adds their own
 * photo and says their own name for the kids' phones. Reached by a link a
 * parent sends them (ContactLinkRepository); no account, no sign-in.
 *
 * Everything shown comes from their own contact row, and the current photo and
 * clip are inlined, so nothing here needs a session to fetch.
 *
 * @var ?array            $person the contact row, or null for a dead link
 * @var string            $token
 * @var array<int,string> $errors
 * @var array<int,string> $saved  'photo' and/or 'voice' after a save
 */
$inline = static function (?string $file, string $type): ?string {
    return $file === null ? null : 'data:' . $type . ';base64,' . base64_encode((string) file_get_contents($file));
};
if ($person !== null) {
    $name = (string) $person['name'];
    $first = $name !== '' ? $name : 'you';
    $photo = $inline((new PhotoStore())->file((string) ($person['photo_path'] ?? '')), 'image/jpeg');
    $voice = $inline((new CallerNameStore())->file((string) ($person['announce_clip'] ?? '')), 'audio/wav');
    $link = (new ContactLinkRepository())->forContact((int) $person['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php view('partials/head', ['title' => $person !== null ? 'Hello, ' . $first . '!' : 'twocans']); ?>
<meta name="robots" content="noindex">
</head>
<body>
<div class="tc-page">
  <div class="tc-login">
    <div class="tc-login__inner tc-hello">

      <div class="tc-login__head">
        <?php view('partials/logo', ['variant' => 'login']); ?>
        <div style="text-align:center">
          <div class="tc-wordmark"><span class="tc-two">two</span><span class="tc-cans">cans</span></div>
        </div>
      </div>

      <?php if ($person === null): ?>
        <div class="tc-login__card">
          <h1 class="tc-hello__title">This link has stopped working</h1>
          <p class="tc-hello__lead">
            Links like this only last a week. Ask whoever sent it for a new one.
          </p>
        </div>
      <?php else: ?>
        <form class="tc-login__card" method="post" action="/hello/<?= e($token) ?>" enctype="multipart/form-data" data-tc-hello>
          <h1 class="tc-hello__title">Hello, <?= e($first) ?>!</h1>
          <p class="tc-hello__lead">
            Add a photo and say your name, and the kids' phones will show your face and
            tell them it's you when you ring.
          </p>

          <?php if ($saved !== []): ?>
            <div class="tc-hello__done" role="status">
              <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
              Thank you — your <?= e(implode(' and ', array_map(static fn(string $s): string => $s === 'voice' ? 'voice' : 'photo', $saved))) ?>
              <?= count($saved) > 1 ? 'are' : 'is' ?> on the kids' phones now.
            </div>
          <?php endif; ?>
          <?php foreach ($errors as $error): ?>
            <div class="tc-form-error" role="alert"><?= e($error) ?></div>
          <?php endforeach; ?>

          <section class="tc-hello__part">
            <h2 class="tc-hello__step"><span>1</span> Your photo</h2>
            <label class="tc-photo-pick tc-hello__photo" id="hello-photo" for="hello-choose" title="Choose a photo">
              <div class="tc-avatar tc-avatar--96<?= $photo !== null ? ' tc-avatar--photo' : '' ?>" style="background:var(--tc-teal)">
                <?php if ($photo !== null): ?>
                  <img src="<?= e($photo) ?>" alt="">
                <?php else: ?>
                  <?= e(initial($name)) ?>
                <?php endif; ?>
              </div>
              <span class="tc-photo-pick__hint" aria-hidden="true">📷</span>
            </label>
            <p class="tc-hello__hint" data-tc-photo-note="New photo — press Send to keep it.">
              <?= $photo !== null ? 'Take a new one, or tap your photo to pick another.' : 'A smiley face, close up, works best.' ?>
            </p>

            <?php /* On a phone, capture opens the camera itself. A computer
                     ignores it, so there the script offers the webcam in the
                     page instead — see [data-tc-camera] in twocans.js. */ ?>
            <div class="tc-hello__photo-actions">
              <label class="tc-btn tc-btn--coral tc-hello__camera" data-tc-camera>
                <i class="fa-solid fa-camera" aria-hidden="true"></i> Take a photo
                <input type="file" name="camera" accept="image/*" capture="user"
                       data-tc-photo data-tc-photo-for="hello-photo">
              </label>
              <label class="tc-btn tc-btn--ghost tc-hello__camera">
                <i class="fa-solid fa-image" aria-hidden="true"></i> Choose one
                <input type="file" name="photo" id="hello-choose" accept="image/jpeg,image/png,image/webp"
                       data-tc-photo data-tc-photo-for="hello-photo">
              </label>
            </div>
            <div class="tc-hello__webcam" data-tc-webcam hidden>
              <video playsinline muted data-tc-webcam-video></video>
              <div class="tc-hello__photo-actions">
                <button class="tc-btn tc-btn--coral" type="button" data-tc-webcam-snap>
                  <i class="fa-solid fa-circle-dot" aria-hidden="true"></i> Take it
                </button>
                <button class="tc-btn tc-btn--ghost" type="button" data-tc-webcam-cancel>Cancel</button>
              </div>
            </div>
            <p class="tc-form-error" role="alert" data-tc-camera-error hidden></p>
          </section>

          <section class="tc-hello__part">
            <h2 class="tc-hello__step"><span>2</span> Say your name</h2>
            <p class="tc-hello__hint">
              Something short, like <b>“It's <?= e($first) ?>!”</b> — the kids hear it when they
              pick up, just before you're put through.
            </p>

            <?php if ($voice !== null): ?>
              <div class="tc-hello__now">
                <span>What they hear now</span>
                <audio controls preload="none" src="<?= e($voice) ?>"></audio>
              </div>
            <?php endif; ?>

            <div class="tc-hello__recorder" data-tc-rec-box>
              <button class="tc-btn tc-btn--coral tc-btn--lg tc-hello__rec" type="button" data-tc-rec data-tc-rec-max="7" hidden>
                <i class="fa-solid fa-microphone" aria-hidden="true"></i> <span data-tc-rec-label>Record</span>
              </button>
              <div class="tc-hello__take" data-tc-rec-take hidden>
                <span>Listen back — happy with it?</span>
                <audio controls data-tc-rec-audio></audio>
              </div>
              <p class="tc-form-error" role="alert" data-tc-rec-error hidden></p>
            </div>

            <label class="tc-btn tc-btn--ghost tc-audio-file tc-hello__upload">
              <i class="fa-solid fa-file-audio" aria-hidden="true"></i>
              <span data-tc-filename>Or choose a recording</span>
              <input type="file" name="clip" accept="audio/*,.mp3,.m4a,.wav,.ogg,.opus,.aac,.amr,.3gp"
                     data-tc-audiofile data-tc-rec-file>
            </label>
          </section>

          <button class="tc-btn tc-btn--teal tc-btn--lg" type="submit">Send to the kids' phones</button>

          <div class="tc-login__alt">
            Only you, with this link, and the grown-ups at home can change these.
            <?php if ($link !== null): ?>
              It stops working on <?= e(date('j F', strtotime($link['expiresAt']))) ?>.
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>

      <div class="tc-login__foot">twocans — a family's own little phone line</div>
    </div>
  </div>
</div>
<script src="<?= e(asset('assets/js/twocans.js')) ?>" defer></script>
</body>
</html>
