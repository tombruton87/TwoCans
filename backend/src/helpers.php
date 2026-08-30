<?php
declare(strict_types=1);

/** Escape for HTML output. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an app URL from query params, dropping empty ones. */
function url(array $params = []): string
{
    $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
    $q = http_build_query($params);

    return '/' . ($q !== '' ? '?' . $q : '');
}

/**
 * Asset URL with the file's mtime as a version. nginx caches /assets/ for an
 * hour, which is fine for repeat visits but meant a changed stylesheet or
 * script kept running stale for up to an hour — this busts that on deploy.
 */
function asset(string $path): string
{
    $file = __DIR__ . '/../' . ltrim($path, '/');
    $version = @filemtime($file) ?: 0;

    return '/' . ltrim($path, '/') . '?v=' . $version;
}

/** First letter of a name, for avatar circles. */
function initial(?string $name): string
{
    $n = trim((string) $name);

    return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1));
}

/** Redirect after POST so a refresh never replays an action. */
function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** Where to send the user back to after an action. */
function back(): string
{
    $ref = $_POST['_back'] ?? $_SERVER['HTTP_REFERER'] ?? '/';
    $path = parse_url((string) $ref, PHP_URL_PATH) ?: '/';
    $query = parse_url((string) $ref, PHP_URL_QUERY);

    return $path . ($query ? '?' . $query : '');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf'];
}

/** Hidden CSRF + return-path inputs for every form. */
function form_fields(): string
{
    $back = $_SERVER['REQUEST_URI'] ?? '/';

    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'
         . '<input type="hidden" name="_back" value="' . e($back) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Bad request');
    }
}

/** Queue the transient confirmation pill shown bottom-centre. */
function flash(string $msg): void
{
    $_SESSION['toast'] = $msg;
}

function take_flash(): ?string
{
    $t = $_SESSION['toast'] ?? null;
    unset($_SESSION['toast']);

    return $t;
}

/** Error shown inline on the login / setup forms, rather than as a toast. */
function flash_error(string $msg): void
{
    $_SESSION['form_error'] = $msg;
}

function take_error(): ?string
{
    $e = $_SESSION['form_error'] ?? null;
    unset($_SESSION['form_error']);

    return $e;
}

/** Repopulate a rejected form so the user doesn't retype everything. */
function flash_old(array $values): void
{
    $_SESSION['form_old'] = $values;
}

function take_old(): array
{
    $o = $_SESSION['form_old'] ?? [];
    unset($_SESSION['form_old']);

    return is_array($o) ? $o : [];
}

/**
 * Escape for HTML, then wrap occurrences of a search term in <mark>.
 *
 * Escaping happens first and the needle is escaped to match, so the only markup
 * that can reach the page is the <mark> this function adds — a transcript
 * containing "<script>" stays inert whether or not it is searched for.
 */
function highlight(?string $text, string $term): string
{
    $safe = e($text);
    $term = trim($term);
    if ($term === '' || mb_strlen($term) < 2) {
        return $safe;
    }

    $needle = preg_quote(e($term), '/');

    return preg_replace('/(' . $needle . ')/iu', '<mark class="tc-mark">$1</mark>', $safe) ?? $safe;
}

/** Render a view file with $vars in scope. */
function view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/' . $name . '.php';
}

/** "8:13" from seconds. */
function fmt_duration(int $seconds): string
{
    $seconds = max(0, $seconds);

    return intdiv($seconds, 60) . ':' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
}
