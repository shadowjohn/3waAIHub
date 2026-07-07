(function ($) {
    'use strict';

    function refreshReadiness($button) {
        var packId = $button.data('pack-id');
        var $value = $('.pack-readiness-value[data-pack-id="' + packId + '"]');

        $button.prop('disabled', true).text('刷新中');
        $.ajax({
            method: 'GET',
            url: 'packs.php',
            data: {ajax: "readiness", pack_id: packId},
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.ok) {
                $value.text((response && response.error) || '讀取失敗');
                return;
            }
            $value.text(response.readiness || '');
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            $value.text(response.error || '讀取失敗');
        }).always(function () {
            $button.prop('disabled', false).text('刷新');
        });
    }

    $(document).on('click', '.pack-readiness-refresh', function () {
        refreshReadiness($(this));
    });
})(jQuery);
