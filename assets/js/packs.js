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

    function showMessage(message, isError) {
        $('#service-message')
            .removeClass('notice error')
            .addClass(isError ? 'error' : 'notice')
            .attr('role', isError ? 'alert' : 'status')
            .attr('aria-live', isError ? 'assertive' : 'polite')
            .attr('aria-atomic', 'true')
            .text(message)
            .show();
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

    document.addEventListener('invalid', function (event) {
        var control = event.target;
        if (!control || typeof control.closest !== 'function') {
            return;
        }
        var details = control.closest('.pack-details');
        if (!details) {
            return;
        }

        details.open = true;
        showMessage(t('required_fields', '請完成標示的必填欄位。'), true);
        window.setTimeout(function () {
            control.focus();
        }, 0);
    }, true);
})(jQuery);
