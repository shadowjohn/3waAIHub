<?php
declare(strict_types=1);

function hub_catalog_show_header(string $title, ?array $user): void
{
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= hub_h($title) ?> - 3waAIHub</title>
    <link rel="stylesheet" href="assets/catalog.css">
</head>
<body>
<header class="show-header">
    <a class="show-brand" href="./">3waAIHub</a>
    <nav>
        <a href="../public_api_docs.php"><?= hub_h(__('公開 API 文件')) ?></a>
        <a href="../api_manifest.json.php"><?= hub_h(__('Agent Manifest 文件')) ?></a>
        <?php if ($user): ?>
            <a href="../admin/playground.php"><?= hub_h(__('API 測試場')) ?></a>
            <a href="../admin/"><?= hub_h(__('後台')) ?></a>
        <?php else: ?>
            <a href="../admin/login.php"><?= hub_h(__('登入測試')) ?></a>
        <?php endif; ?>
        <?= hub_i18n_language_selector() ?>
    </nav>
</header>
<main class="show-main">
    <?php
}

function hub_catalog_show_footer(): void
{
    ?>
</main>
<script src="assets/catalog.js"></script>
</body>
</html>
    <?php
}
