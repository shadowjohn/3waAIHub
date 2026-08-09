<?php
declare(strict_types=1);

function hub_i18n_languages(): array
{
    return [
        'zh_TW' => '正體中文',
        'zh_CN' => '简体中文',
        'en' => 'English',
        'ja' => '日本語',
        'ko' => '한국어',
        'es' => 'Spanish',
        'vi' => 'Việt',
        'th' => 'ภาษาไทย',
        'it' => 'Italiano',
    ];
}

function hub_i18n_is_seed_key(string $key): bool
{
    if (
        !str_contains($key, '.')
        || preg_match('/\A[A-Za-z0-9_.-]+\z/', $key) !== 1
        || preg_match('/[A-Za-z]/', $key) !== 1
    ) {
        return false;
    }

    foreach (explode('.', $key) as $segment) {
        if (preg_match('/[A-Za-z0-9]/', $segment) !== 1) {
            return false;
        }
    }

    return true;
}

function hub_i18n_normalize_lang(?string $lang): string
{
    $lang = str_replace('-', '_', trim((string)$lang));

    return array_key_exists($lang, hub_i18n_languages()) ? $lang : 'zh_TW';
}

function hub_i18n_current_lang(): string
{
    return hub_i18n_normalize_lang((string)($_COOKIE['USER_LANG'] ?? 'zh_TW'));
}

/**
 * Cookie 值只使用內建語系鍵，避免請求字串進入 HTTP response header。
 */
function hub_i18n_cookie_language(mixed $value): string
{
    $lang = hub_i18n_normalize_lang(is_string($value) ? $value : '');
    if (!array_key_exists($lang, hub_i18n_languages()) || preg_match('/\A[a-z]{2}(?:_[A-Z]{2})?\z/D', $lang) !== 1) {
        throw new RuntimeException('Language cookie value is invalid.');
    }

    return $lang;
}

function hub_i18n_cookie_value(string $lang): string
{
    return match ($lang) {
        'zh_CN' => 'zh_CN',
        'en' => 'en',
        'ja' => 'ja',
        'ko' => 'ko',
        'es' => 'es',
        'vi' => 'vi',
        'th' => 'th',
        'it' => 'it',
        default => 'zh_TW',
    };
}

function hub_i18n_apply_request_language(): void
{
    if (PHP_SAPI === 'cli' || !isset($_GET['set_lang'])) {
        return;
    }

    $lang = hub_i18n_cookie_language($_GET['set_lang']);
    $_COOKIE['USER_LANG'] = $lang;
    setcookie('USER_LANG', hub_i18n_cookie_value($lang), [
        'expires' => time() + 86400 * 365,
        'path' => '/',
        'samesite' => 'Lax',
    ]);
}

function hub_i18n_language_url(string $lang): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $parts = parse_url($uri);
    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $query);
    $query['set_lang'] = hub_i18n_normalize_lang($lang);
    $qs = http_build_query($query);

    return $path . ($qs !== '' ? '?' . $qs : '');
}

function hub_i18n_language_selector(string $class = 'i18n-selector'): string
{
    $current = hub_i18n_current_lang();
    $html = '<span class="' . hub_h($class) . '"><select aria-label="Language" onchange="if(this.value) location.href=this.value">';
    foreach (hub_i18n_languages() as $lang => $label) {
        $selected = $lang === $current ? ' selected' : '';
        $html .= '<option value="' . hub_h(hub_i18n_language_url($lang)) . '"' . $selected . '>' . hub_h($label) . '</option>';
    }
    return $html . '</select></span>';
}

function __(string $title, ?string $lang = null): string
{
    $title = trim(stripslashes($title));
    if ($title === '') {
        return '';
    }

    $lang = hub_i18n_normalize_lang($lang ?? hub_i18n_current_lang());
    if ($lang === 'zh_TW') {
        return $title;
    }

    $db = hub_db();
    $stmt = $db->prepare('SELECT trans FROM i18n WHERE title = :title AND lang = :lang ORDER BY id DESC LIMIT 1');
    $stmt->execute([':title' => $title, ':lang' => $lang]);
    $trans = trim((string)($stmt->fetchColumn() ?: ''));
    if ($trans !== '') {
        return str_replace('null', '', $trans);
    }

    $trans = hub_i18n_translate_google($title, $lang, 'zh_TW');
    if ($trans === '') {
        return $title;
    }

    $insert = $db->prepare('INSERT INTO i18n (title, lang, trans) VALUES (:title, :lang, :trans)');
    $insert->execute([':title' => $title, ':lang' => $lang, ':trans' => $trans]);

    return $trans;
}

function hub_i18n_seeded(string $key, string $fallback, ?string $lang = null, ?PDO $db = null): string
{
    $key = trim($key);
    $fallback = trim($fallback);
    if ($key === '') {
        return __($fallback, $lang);
    }
    $lang = hub_i18n_normalize_lang($lang ?? hub_i18n_current_lang());
    $db ??= hub_db();
    $stmt = $db->prepare('SELECT trans FROM i18n WHERE title = :title AND lang = :lang ORDER BY id DESC LIMIT 1');
    $stmt->execute([':title' => $key, ':lang' => $lang]);
    $translation = trim((string)($stmt->fetchColumn() ?: ''));
    if ($translation !== '') {
        return $translation;
    }

    return $fallback;
}

function hub_i18n_google_circuit_state(?bool $open = null): bool
{
    static $circuitOpen = false;
    if ($open !== null) {
        $circuitOpen = $open;
    }

    return $circuitOpen;
}

/**
 * Google 翻譯是唯一允許的外部 i18n transport；主機、協定與 endpoint 必須固定。
 */
function hub_i18n_google_translation_url(string $sourceLang, string $targetLang, string $text): string
{
    $query = http_build_query([
        'client' => 'gtx',
        'sl' => $sourceLang,
        'tl' => $targetLang,
        'dt' => 't',
        'q' => $text,
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://translate.googleapis.com/translate_a/single?' . $query;
    $parts = parse_url($url);

    if (
        $parts === false
        || ($parts['scheme'] ?? null) !== 'https'
        || ($parts['host'] ?? null) !== 'translate.googleapis.com'
        || isset($parts['port'], $parts['user'], $parts['pass'], $parts['fragment'])
        || ($parts['path'] ?? null) !== '/translate_a/single'
    ) {
        throw new RuntimeException('Google translation endpoint is invalid.');
    }

    return $url;
}

function hub_i18n_translate_google(
    string $text,
    string $targetLang,
    string $sourceLang = 'auto',
    ?callable $fetcher = null
): string
{
    if (hub_i18n_google_circuit_state() || ($fetcher === null && !function_exists('curl_init'))) {
        return '';
    }

    $map = ['zh_TW' => 'zh-TW', 'zh_CN' => 'zh-CN'];
    $tl = $map[hub_i18n_normalize_lang($targetLang)] ?? $targetLang;
    $sl = $sourceLang === 'auto' ? 'auto' : ($map[hub_i18n_normalize_lang($sourceLang)] ?? $sourceLang);
    $allowed = ['auto', 'zh-CN', 'zh-TW', 'th', 'ko', 'en', 'ja', 'vi', 'es', 'it'];
    if (!in_array($sl, $allowed, true) || !in_array($tl, $allowed, true) || trim($text) === '') {
        return '';
    }

    $url = hub_i18n_google_translation_url($sl, $tl, $text);
    if ($fetcher !== null) {
        try {
            $response = $fetcher($url);
        } catch (Throwable) {
            hub_i18n_google_circuit_state(true);
            return '';
        }
        $raw = is_array($response) ? ($response['body'] ?? null) : null;
        $code = is_array($response) ? (int)($response['status'] ?? 0) : 0;
    } else {
        $ch = curl_init($url);
        if ($ch === false) {
            hub_i18n_google_circuit_state(true);
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 3waAIHub',
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if (!is_string($raw) || $code < 200 || $code >= 400) {
        hub_i18n_google_circuit_state(true);
        return '';
    }

    $json = json_decode($raw, true);
    if (!is_array($json[0] ?? null)) {
        hub_i18n_google_circuit_state(true);
        return '';
    }

    $out = '';
    foreach ($json[0] as $row) {
        $out .= (string)($row[0] ?? '');
    }

    $out = trim(str_replace('null', '', $out));
    if ($out === '') {
        hub_i18n_google_circuit_state(true);
    }

    return $out;
}

function hub_i18n_seed_path(): string
{
    return HUB_ROOT . '/i18n/seed.json';
}

function hub_i18n_import_seed(PDO $db, ?string $path = null): int
{
    $path ??= hub_i18n_seed_path();
    if (!is_file($path)) {
        return 0;
    }

    $rows = json_decode((string)file_get_contents($path), true);
    if (!is_array($rows)) {
        throw new RuntimeException('Invalid i18n seed JSON.');
    }

    $insert = $db->prepare(
        'INSERT INTO i18n (title, lang, trans)
         SELECT :title, :lang, :trans
         WHERE NOT EXISTS (
             SELECT 1 FROM i18n WHERE title = :title AND lang = :lang
         )'
    );
    $count = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
        $lang = is_string($row['lang'] ?? null) ? str_replace('-', '_', trim($row['lang'])) : '';
        $trans = is_string($row['trans'] ?? null) ? trim($row['trans']) : '';
        if (
            $title === ''
            || $trans === ''
            || !array_key_exists($lang, hub_i18n_languages())
            || ($lang === 'zh_TW' && !hub_i18n_is_seed_key($title))
        ) {
            continue;
        }
        $insert->execute([':title' => $title, ':lang' => $lang, ':trans' => $trans]);
        $count += $insert->rowCount();
    }

    return $count;
}

function hub_i18n_export_seed(PDO $db): array
{
    $rows = $db->query(
        "SELECT i.title, i.lang, i.trans
         FROM i18n i
         INNER JOIN (
             SELECT title, lang, MAX(id) AS id
             FROM i18n
             WHERE title != '' AND lang != '' AND COALESCE(trans, '') != ''
             GROUP BY title, lang
         ) latest ON latest.id = i.id
         ORDER BY i.lang ASC, i.title ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $export = [];
    foreach ($rows as $row) {
        $title = (string)$row['title'];
        $lang = str_replace('-', '_', trim((string)$row['lang']));
        if (
            !array_key_exists($lang, hub_i18n_languages())
            || ($lang === 'zh_TW' && !hub_i18n_is_seed_key($title))
        ) {
            continue;
        }
        $export[] = ['title' => $title, 'lang' => $lang, 'trans' => (string)$row['trans']];
    }

    return $export;
}
