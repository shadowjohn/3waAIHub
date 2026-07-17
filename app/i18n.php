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

function hub_i18n_normalize_lang(?string $lang): string
{
    $lang = str_replace('-', '_', trim((string)$lang));

    return array_key_exists($lang, hub_i18n_languages()) ? $lang : 'zh_TW';
}

function hub_i18n_current_lang(): string
{
    return hub_i18n_normalize_lang((string)($_COOKIE['USER_LANG'] ?? 'zh_TW'));
}

function hub_i18n_apply_request_language(): void
{
    if (PHP_SAPI === 'cli' || !isset($_GET['set_lang'])) {
        return;
    }

    $lang = hub_i18n_normalize_lang((string)$_GET['set_lang']);
    $_COOKIE['USER_LANG'] = $lang;
    setcookie('USER_LANG', $lang, [
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

function hub_i18n_translate_google(string $text, string $targetLang, string $sourceLang = 'auto'): string
{
    if (!function_exists('curl_init')) {
        return '';
    }

    $map = ['zh_TW' => 'zh-TW', 'zh_CN' => 'zh-CN'];
    $tl = $map[hub_i18n_normalize_lang($targetLang)] ?? $targetLang;
    $sl = $sourceLang === 'auto' ? 'auto' : ($map[hub_i18n_normalize_lang($sourceLang)] ?? $sourceLang);
    $allowed = ['auto', 'zh-CN', 'zh-TW', 'th', 'ko', 'en', 'ja', 'vi', 'es', 'it'];
    if (!in_array($sl, $allowed, true) || !in_array($tl, $allowed, true) || trim($text) === '') {
        return '';
    }

    $query = http_build_query([
        'client' => 'gtx',
        'sl' => $sl,
        'tl' => $tl,
        'dt' => 't',
        'q' => $text,
    ], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init('https://translate.googleapis.com/translate_a/single?' . $query);
    if ($ch === false) {
        return '';
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 3waAIHub',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($raw) || $code >= 400) {
        return '';
    }

    $json = json_decode($raw, true);
    if (!is_array($json[0] ?? null)) {
        return '';
    }

    $out = '';
    foreach ($json[0] as $row) {
        $out .= (string)($row[0] ?? '');
    }

    return trim(str_replace('null', '', $out));
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
        $title = trim((string)($row['title'] ?? ''));
        $lang = hub_i18n_normalize_lang((string)($row['lang'] ?? ''));
        $trans = trim((string)($row['trans'] ?? ''));
        if ($title === '' || $trans === '' || $lang === 'zh_TW') {
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
             WHERE title != '' AND lang != '' AND lang != 'zh_TW' AND COALESCE(trans, '') != ''
             GROUP BY title, lang
         ) latest ON latest.id = i.id
         ORDER BY i.lang ASC, i.title ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static fn (array $row): array => [
        'title' => (string)$row['title'],
        'lang' => hub_i18n_normalize_lang((string)$row['lang']),
        'trans' => (string)$row['trans'],
    ], $rows);
}
