(function ($) {
    'use strict';

    var dictionaryNode = document.getElementById('market-i18n');
    var dictionary = {};
    try {
        dictionary = dictionaryNode ? JSON.parse(dictionaryNode.textContent || '{}') : {};
    } catch (error) {
        dictionary = {};
    }

    function t(key, fallback) {
        return dictionary[key] || fallback;
    }

    function refreshReadiness($button) {
        var packId = $button.data('pack-id');
        var $value = $('.pack-readiness-value[data-pack-id="' + packId + '"]');

        $button.prop('disabled', true).text(t('refreshing', '刷新中'));
        $.ajax({
            method: 'GET',
            // Legacy packs.php also loads this script; readiness is canonical.
            url: 'marketplace.php',
            data: {ajax: "readiness", pack_id: packId},
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.ok) {
                $value.text((response && response.error) || t('readiness_failed', '讀取失敗'));
                return;
            }
            $value.text(response.readiness || '');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $value.text(response.error || t('readiness_failed', '讀取失敗'));
        }).always(function () {
            $button.prop('disabled', false).text(t('refresh', '刷新'));
        });
    }

    $(document).on('click', '.pack-readiness-refresh', function () {
        refreshReadiness($(this));
    });
})(jQuery);
