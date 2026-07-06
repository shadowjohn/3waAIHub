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

        var $row = $('<tr class="ajax-job-new">').attr('data-job-row-id', job.id);
        $('<td>').text('#' + job.id).appendTo($row);
        $('<td>').append($('<code>').text(job.action)).appendTo($row);
        $('<td>').text(job.service_name || '').appendTo($row);
        $('<td>').addClass(job.status_class || 'bad').text(job.status_label || job.status || '').appendTo($row);
        $('<td>').append($('<span class="job-row-progress">').text(job.progress || 0)).append('%').appendTo($row);
        $('<td>').append($('<code class="job-row-stage">').text(job.stage || '')).append('<br>').append($('<span class="muted job-row-message">').text(job.current_message || '')).appendTo($row);
        $('<td>').text('').appendTo($row);
        $('<td>').text(job.created_at || '').appendTo($row);
        $('<td>').text('').appendTo($row);
        $('#command-job-rows').prepend($row);
    }

    function updateServiceJobBox($box, job) {
        var tail = job.stdout_tail || '';
        if (job.stderr_tail) {
            tail += (tail ? "\n\n[stderr]\n" : "[stderr]\n") + job.stderr_tail;
        }

        $box.show();
        $box.find('.job-id').text(job.id || '');
        $box.find('.job-progress span').css('width', (job.progress || 0) + '%');
        $box.find('.job-progress-text').text(job.progress || 0);
        $box.find('.job-stage').text(job.stage || '');
        $box.find('.job-message').text(job.current_message || job.error_message || '');
        $box.find('.job-tail').text(tail);

        if (['success', 'failed', 'cancelled', 'timeout'].indexOf(job.status) !== -1) {
            $box.attr('data-job-id', '');
        }
    }

    function updateJobRow(job) {
        var $row = $('[data-job-row-id="' + job.id + '"]');
        if (!$row.length) {
            return;
        }
        $row.find('.job-row-progress').text(job.progress || 0);
        $row.find('.job-row-stage').text(job.stage || '');
        $row.find('.job-row-message').text(job.current_message || job.error_message || '');
    }

    function pollJobs() {
        $('.service-job').each(function () {
            var $box = $(this);
            var jobId = $box.attr('data-job-id');
            if (!jobId) {
                return;
            }
            $.ajax({
                method: 'GET',
                url: 'job_status.php',
                data: {job_id: jobId},
                dataType: 'json'
            }).done(function (response) {
                if (!response || !response.ok || !response.job) {
                    return;
                }
                updateServiceJobBox($box, response.job);
                updateJobRow(response.job);
            });
        });
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
            if (response.job && response.job.service_id) {
                var $box = $('.service-job[data-service-id="' + response.job.service_id + '"]');
                $box.attr('data-job-id', response.job.id);
                updateServiceJobBox($box, response.job);
            }
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showMessage(response.error || '操作失敗，請重新整理後再試。', true);
        }).always(function () {
            $form.removeClass('ajax-loading');
            $form.find('button').prop('disabled', false);
        });
    });

    window.setInterval(pollJobs, 2000);
    pollJobs();
})(jQuery);
