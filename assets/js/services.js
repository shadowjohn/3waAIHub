(function ($) {
    'use strict';

    function showMessage(message, isError) {
        $('#service-message')
            .removeClass('notice error')
            .addClass(isError ? 'error' : 'notice')
            .text(message)
            .show();
    }

    function prependJob(job) {
        if (!job || !$('#command-job-rows').length) {
            return;
        }

        var $row = $('<tr class="ajax-job-new">');
        $('<td>').text('#' + job.id).appendTo($row);
        $('<td>').append($('<code>').text(job.action)).appendTo($row);
        $('<td>').text(job.service_name || '').appendTo($row);
        $('<td>').addClass(job.status_class || 'bad').text(job.status_label || job.status || '').appendTo($row);
        $('<td>').text('').appendTo($row);
        $('<td>').text(job.created_at || '').appendTo($row);
        $('<td>').text('').appendTo($row);
        $('#command-job-rows').prepend($row);
    }

    $(document).on('click', '.service-action-form button[name="action"]', function () {
        $(this.form).data('action', this.value);
    });

    $(document).on('submit', '.service-action-form', function (event) {
        event.preventDefault();

        var $form = $(this);
        var action = $form.data('action') || $form.find('button[name="action"]').first().val();
        var data = $form.serializeArray();
        data.push({name: 'action', value: action});

        $form.addClass('ajax-loading');
        $form.find('button').prop('disabled', true);

        $.ajax({
            method: 'POST',
            url: window.location.href,
            data: data,
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.ok) {
                showMessage((response && response.error) || '操作失敗。', true);
                return;
            }
            showMessage(response.message || '已排入背景工作。', false);
            prependJob(response.job);
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showMessage(response.error || '操作失敗，請重新整理後再試。', true);
        }).always(function () {
            $form.removeClass('ajax-loading');
            $form.find('button').prop('disabled', false);
        });
    });
})(jQuery);
