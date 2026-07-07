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
        $('<td>').append($('<span>').text(job.action_label || job.action)).append(' ').append($('<code>').text(job.action)).appendTo($row);
        $('<td>').text(job.service_name || '').appendTo($row);
        $('<td>').attr('data-job-row-status', '').addClass(job.status_class || 'bad').text(job.status_label || job.status || '').appendTo($row);
        $('<td>').append($('<span class="job-row-progress">').text(job.progress || 0)).append('%').appendTo($row);
        $('<td>').append($('<code class="job-row-stage">').text(job.stage || '')).append('<br>').append($('<span class="muted job-row-message">').text(job.current_message || '')).appendTo($row);
        $('<td>').attr('data-job-row-exit', '').text('').appendTo($row);
        $('<td>').text(job.created_at || '').appendTo($row);
        $('<td>').attr('data-job-row-error', '').text('').appendTo($row);
        $('#command-job-rows').prepend($row);
    }

    function updateServiceRow(job) {
        if (!job || !job.service || !job.service.id) {
            return;
        }
        var $row = $('[data-service-row-id="' + job.service.id + '"]');
        var $status = $row.find('[data-service-status]');
        var containerOk = job.service.status === 'running';
        $status
            .removeClass('ok bad')
            .addClass(containerOk ? 'ok' : 'bad')
            .find('[data-service-status-label]')
            .text(containerOk ? '執行中' : (job.service.status === 'stopped' ? '已停止' : '未知'));
    }

    function updateHealthBadge(job) {
        if (!job || job.action !== 'service_health_check' || !job.service_id) {
            return;
        }
        var $badge = $('[data-service-row-id="' + job.service_id + '"]').find('[data-service-health]');
        var label = '健康異常';
        var cls = 'hub-badge-bad';
        if (job.status === 'success') {
            label = '健康正常';
            cls = 'hub-badge-ok';
        } else if (job.status === 'queued' || job.status === 'running') {
            label = '健康檢查中';
            cls = 'hub-badge-warn';
        }
        $badge
            .removeClass('hub-badge-ok hub-badge-warn hub-badge-bad hub-badge-muted')
            .addClass(cls)
            .find('[data-service-health-label]')
            .text(label);
    }

    function updateLastJob(job) {
        if (!job || !job.service_id) {
            return;
        }
        var $last = $('[data-service-row-id="' + job.service_id + '"]').find('[data-service-last-job]');
        if (!$last.length) {
            return;
        }
        $last.empty()
            .append(document.createTextNode((job.action_label || job.action || '') + ' '))
            .append($('<code>').text(job.action || ''))
            .append(document.createTextNode(' '))
            .append($('<span data-service-last-job-status>').addClass(job.status_class || 'bad').text(job.status_label || job.status || ''))
            .append(document.createTextNode(' '))
            .append($('<span class="muted">').text(job.updated_at || job.created_at || ''));
    }

    function triggerServiceRefresh(job) {
        if (!job || !job.service_id || ['service_start', 'service_restart', 'service_build', 'service_rebuild'].indexOf(job.action) === -1) {
            return;
        }
        var $box = $('.service-job[data-service-id="' + job.service_id + '"]');
        if ($box.data('refresh-for') === job.id) {
            return;
        }
        $box.data('refresh-for', job.id);
        submitServiceAction($('[data-service-refresh-form="' + job.service_id + '"]'), 'refresh', true);
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
        updateServiceRow(job);
        updateHealthBadge(job);
        updateLastJob(job);

        if (['success', 'failed', 'cancelled', 'timeout'].indexOf(job.status) !== -1) {
            $box.attr('data-job-id', '');
            triggerServiceRefresh(job);
        }
    }

    function updateJobRow(job) {
        var $row = $('[data-job-row-id="' + job.id + '"]');
        if (!$row.length) {
            return;
        }
        $row.find('[data-job-row-status]')
            .removeClass('ok bad')
            .addClass(job.status_class || 'bad')
            .text(job.status_label || job.status || '');
        $row.find('.job-row-progress').text(job.progress || 0);
        $row.find('.job-row-progress-bar span').css('width', (job.progress || 0) + '%');
        $row.find('.job-row-stage').text(job.stage || '');
        $row.find('.job-row-message').text(job.current_message || job.error_message || '');
        $row.find('[data-job-row-exit]').text(job.exit_code === null || job.exit_code === undefined ? '' : job.exit_code);
        $row.find('[data-job-row-error]').text(job.error_message || '');
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
            }).fail(function () {
                if (!$box.data('poll-error-shown')) {
                    $box.data('poll-error-shown', true);
                    showMessage('讀取背景工作狀態失敗，請稍後重試或重新整理。', true);
                }
            });
        });
    }

    $(document).on('click', '.service-action-form button[name="action"]', function () {
        $(this.form).data('action', this.value);
    });

    function submitServiceAction($form, action, silent) {
        if (!$form.length) {
            return;
        }
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
            if (!silent) {
                showMessage(response.message || '已排入背景工作。', false);
            }
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
    }

    $(document).on('submit', '.service-action-form', function (event) {
        event.preventDefault();

        var $form = $(this);
        var action = $form.data('action') || $form.find('button[name="action"]').first().val();
        submitServiceAction($form, action, false);
    });

    window.setInterval(pollJobs, 2000);
    pollJobs();
})(jQuery);
