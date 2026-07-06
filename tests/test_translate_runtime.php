<?php
declare(strict_types=1);

hub_test('TranslateGemma pack has runnable Ollama adapter files', function (): void {
    $base = HUB_ROOT . '/packs/translate-gemma12b/service';
    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py', 'model_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'Translate service missing ' . $file);
    }

    $dockerfile = (string)file_get_contents($base . '/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'python3 smoke.py') || str_contains($dockerfile, 'python smoke.py'), 'Translate Dockerfile must run import smoke');
    hub_test_assert(!str_contains($dockerfile, 'ollama pull'), 'Translate image build must not bake model pulls');

    $app = (string)file_get_contents($base . '/app.py');
    hub_test_assert(str_contains($app, '@app.post("/translate")'), 'Translate adapter must expose POST /translate');
    hub_test_assert(str_contains($app, 'return "L4a-model-present-smoke"'), 'Translate health must expose L4a runtime level');
    hub_test_assert(str_contains($app, '/api/tags'), 'Translate health must check Ollama tags API');
    hub_test_assert(str_contains($app, '"present"'), 'Translate health must report model present status');
    hub_test_assert(str_contains($app, 'model_not_present'), 'Translate health must warn when model is missing');
    hub_test_assert(!str_contains($app, '/api/pull'), 'Translate adapter must not pull models');
    hub_test_assert(!str_contains($app, '/api/generate'), 'Translate L3 adapter must not call real generate API yet');
    hub_test_assert(str_contains($app, 'mock translation'), 'Translate adapter must keep mock translation response');

    $smoke = (string)file_get_contents($base . '/smoke.py');
    foreach (['fastapi', 'requests'] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'Translate smoke.py must import ' . $needle);
    }
    hub_test_assert(str_contains($smoke, 'L4a-model-present-smoke'), 'Translate smoke.py runtime level must match L4a');
    foreach (['/api/pull', 'ollama pull', 'download', '/api/generate'] as $needle) {
        hub_test_assert(!str_contains($smoke, $needle), 'Translate smoke.py must not pull or translate: ' . $needle);
    }

    $storageSmoke = (string)file_get_contents($base . '/storage_smoke.py');
    foreach (['/cache/translate', '/data/service'] as $needle) {
        hub_test_assert(str_contains($storageSmoke, $needle), 'Translate storage_smoke.py missing ' . $needle);
    }
    foreach (['/api/pull', 'ollama pull', 'download', '/api/generate'] as $needle) {
        hub_test_assert(!str_contains($storageSmoke, $needle), 'Translate storage_smoke.py must not pull or translate: ' . $needle);
    }

    $modelSmoke = (string)file_get_contents($base . '/model_smoke.py');
    foreach (['/api/tags', 'OLLAMA_MODEL', 'OLLAMA_BASE_URL'] as $needle) {
        hub_test_assert(str_contains($modelSmoke, $needle), 'Translate model_smoke.py missing ' . $needle);
    }
    foreach (['/api/pull', 'ollama pull', 'download', '/api/generate'] as $needle) {
        hub_test_assert(!str_contains($modelSmoke, $needle), 'Translate model_smoke.py must only check model presence: ' . $needle);
    }
});

hub_test('TranslateGemma Ollama model pull CLI is explicit and allowlisted', function (): void {
    $script = HUB_ROOT . '/scripts/ollama_model_pull.php';
    hub_test_assert(is_file($script), 'ollama_model_pull.php must exist');
    $text = (string)file_get_contents($script);
    foreach (['hub_cli_only()', 'ollama pull', "HUB_LOG_DIR . '/models'", 'model_not_present_after_pull'] as $needle) {
        hub_test_assert(str_contains($text, $needle), 'ollama_model_pull.php missing ' . $needle);
    }
    hub_test_assert(!str_contains($text, '/api/generate'), 'ollama_model_pull.php must not run real translation');
    hub_test_assert(hub_is_valid_job_action('ollama_model_pull'), 'ollama_model_pull must be allowlisted');

    $admin = (string)file_get_contents(HUB_ROOT . '/admin/service_settings.php');
    hub_test_assert(str_contains($admin, "hub_enqueue_command_job(\n                \$db,\n                'ollama_model_pull'"), 'service settings must enqueue Ollama pull job');
    hub_test_assert(!str_contains($admin, 'ollama pull'), 'service settings must not execute ollama pull directly');
});

hub_test('TranslateGemma service instance generates Ollama sidecar compose and storage env', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'translate-gemma12b', ['idempotent' => true]);
    hub_test_assert(str_contains($installed['service']['compose_file'], 'data/test_services/'), 'test DB runtime files must not overwrite translate-main');
    $runtimeDir = dirname(hub_path($installed['service']['compose_file']));
    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents($runtimeDir . '/.env');

    hub_test_assert(str_contains($compose, '  ollama:'), 'Translate generated compose must include ollama service');
    hub_test_assert(str_contains($compose, '  translator-api:'), 'Translate generated compose must include translator-api service');
    hub_test_assert(str_contains($compose, 'ollama/ollama'), 'Translate generated compose must use Ollama image');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/ollama:/root/.ollama'), 'Translate generated compose must mount Ollama model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/translate:/cache/translate'), 'Translate generated compose must mount translator cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'Translate generated compose must mount translator service data');
    hub_test_assert(str_contains($compose, '127.0.0.1:${TRANSLATE_LOCAL_PORT:-18102}:8000'), 'Translate API port must bind localhost only');
    hub_test_assert(!str_contains($compose, '11434:11434'), 'Ollama API must not be exposed on host port');
    hub_test_assert(str_contains($env, 'OLLAMA_MODEL=translategemma:12b-it-q4_K_M'), 'Translate env must set default Ollama model');
    foreach ([
        'OLLAMA_BASE_URL=http://ollama:11434',
        'TRANSLATE_MODEL_DIR=/models/ollama',
        'TRANSLATE_CACHE_DIR=/cache/translate',
        'TRANSLATE_SERVICE_DATA_DIR=/data/service',
        'MAX_INPUT_CHARS=12000',
        'TEMPERATURE=0',
        'OLLAMA_NUM_CTX=4096',
        'KEEP_WARM=0',
        'TRANSLATE_REAL_INFERENCE=0',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'Translate env missing ' . $needle);
    }
});
