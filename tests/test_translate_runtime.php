<?php
declare(strict_types=1);

hub_test('TranslateGemma pack has runnable Ollama adapter files', function (): void {
    $base = HUB_ROOT . '/packs/translate-gemma12b/service';
    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'start.sh'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'Translate service missing ' . $file);
    }

    $dockerfile = (string)file_get_contents($base . '/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'ollama/ollama'), 'Translate Dockerfile must use Ollama runtime');
    hub_test_assert(str_contains($dockerfile, 'COPY --from=ollama'), 'Translate Dockerfile must copy Ollama binary from runtime image');
    hub_test_assert(str_contains($dockerfile, '/usr/lib/ollama'), 'Translate Dockerfile must copy Ollama inference runtime libraries');
    hub_test_assert(!str_contains($dockerfile, 'apt-get'), 'Translate Dockerfile must not depend on apt inside Ollama base');
    hub_test_assert(!str_contains($dockerfile, 'ollama pull'), 'Translate image build must not bake model pulls');

    $app = (string)file_get_contents($base . '/app.py');
    hub_test_assert(str_contains($app, '@app.post("/translate")'), 'Translate adapter must expose POST /translate');
    hub_test_assert(str_contains($app, '/api/generate'), 'Translate adapter must call Ollama generate API');
    hub_test_assert(str_contains($app, 'Traditional Chinese'), 'Translate adapter must map zh-TW to Traditional Chinese');

    $start = (string)file_get_contents($base . '/start.sh');
    hub_test_assert(str_contains($start, 'OLLAMA_AUTO_PULL'), 'Translate startup must support automatic model pull');
    hub_test_assert(str_contains($start, 'ollama pull'), 'Translate startup must pull the configured model outside image build');

    $env = hub_get_pack('translate-gemma12b')['manifest']['env'];
    $defaults = array_column($env, 'default', 'name');
    hub_test_assert(($defaults['OLLAMA_AUTO_PULL'] ?? '') === '1', 'Translate auto pull should be enabled by default');
});

hub_test('TranslateGemma service instance generates GPU Ollama compose', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'translate-gemma12b', ['idempotent' => true]);
    hub_test_assert(str_contains($installed['service']['compose_file'], 'data/test_services/'), 'test DB runtime files must not overwrite translate-main');
    $runtimeDir = dirname(hub_path($installed['service']['compose_file']));
    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents($runtimeDir . '/.env');

    hub_test_assert(str_contains($compose, 'gpus: all'), 'Translate generated compose must request GPU access');
    hub_test_assert(str_contains($compose, '/root/.ollama'), 'Translate generated compose must mount Ollama model storage');
    hub_test_assert(str_contains($env, 'OLLAMA_MODEL=translategemma:12b-it-q4_K_M'), 'Translate env must set default Ollama model');
});
