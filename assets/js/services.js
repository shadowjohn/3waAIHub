(function ($) {
    'use strict';

    var dictionaryNode = document.getElementById('market-i18n');
    var dictionary = {};
    var requestTimeout = 10000;
    var summaryRefreshInFlight = false;
    var summaryRefreshQueued = false;
    var summaryErrorShown = false;
    var terminalFailureVisible = false;
    try {
        dictionary = dictionaryNode ? JSON.parse(dictionaryNode.textContent || '{}') : {};
    } catch (error) {
        dictionary = {};
    }

    function t(key, fallback) {
        return dictionary[key] || fallback;
    }

    function jobActionLabel(action) {
        var labels = {
            service_start: t('action_service_start', '啟動服務'),
            service_stop: t('action_service_stop', '停止服務'),
            service_restart: t('action_service_restart', '重啟服務'),
            service_build: t('action_service_build', '建置服務'),
            service_rebuild: t('action_service_rebuild', '重新建置'),
            service_remove: t('action_service_remove', '移除服務'),
            service_health_check: t('action_service_health_check', '健康檢查'),
            service_install: t('action_service_install', '安裝服務'),
            whisper_pascal_ckip_provision: t('action_whisper_pascal_ckip_provision', '準備 CKIP 字幕資產'),
            manual_vision_provision: t('action_manual_vision_provision', '準備 Manual Vision 模型'),
            manual_vision_acceptance: t('action_manual_vision_acceptance', '執行 Manual Vision 驗收')
        };
        return labels[action] || action || '';
    }

    function jobStatusLabel(status) {
        var labels = {
            queued: t('job_status_queued', '排隊中'),
            running: t('job_status_running', '執行中'),
            success: t('job_status_success', '成功'),
            failed: t('job_status_failed', '失敗'),
            cancelled: t('job_status_cancelled', '已取消'),
            timeout: t('job_status_timeout', '逾時')
        };
        return labels[status] || status || '';
    }

    function jobStatusClass(job) {
        return job.status_class || (job.status === 'success' || job.status === 'running' ? 'ok' : 'bad');
    }

    function serviceRuntimeStatus(job) {
        if (!job || !job.service) {
            return '';
        }
        return job.service.runtime_status || job.service.status || '';
    }

    function jobFailureMessage(status) {
        var messages = {
            failed: t('job_failed_feedback', '背景工作失敗，已保留工作輸出。'),
            cancelled: t('job_cancelled_feedback', '背景工作已取消，已保留工作輸出。'),
            timeout: t('job_timeout_feedback', '背景工作逾時，已保留工作輸出。')
        };
        return messages[status] || t('action_failed', '操作失敗，請重新整理後再試。');
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

    function showBackgroundError(message) {
        if (terminalFailureVisible) {
            var $message = $('#service-message');
            if ($message.text().indexOf(message) === -1) {
                $message.append(document.createTextNode(' ' + message));
            }
            return;
        }
        showMessage(message, true);
    }

    function prependJob(job) {
        if (!job || !$('#command-job-rows').length) {
            return;
        }

        var $row = $('<tr class="ajax-job-new">').attr('data-job-row-id', job.id);
        $('<td>').text('#' + job.id).appendTo($row);
        $('<td>').append($('<span>').text(jobActionLabel(job.action))).append(' ').append($('<code>').text(job.action)).appendTo($row);
        $('<td>').text(job.service_name || '').appendTo($row);
        $('<td>').attr('data-job-row-status', '').addClass(jobStatusClass(job)).text(jobStatusLabel(job.status)).appendTo($row);
        $('<td>').append($('<span class="job-row-progress">').text(job.progress || 0)).append('%').appendTo($row);
        $('<td>').append($('<code class="job-row-stage">').text(job.stage || '')).append('<br>').append($('<span class="muted job-row-message">').text(job.current_message || '')).appendTo($row);
        $('<td>').attr('data-job-row-exit', '').text('').appendTo($row);
        $('<td>').text(job.created_at || '').appendTo($row);
        $('<td>').attr('data-job-row-error', '').text('').appendTo($row);
        $('#command-job-rows').prepend($row);
    }

    function serviceStatusLabel(status) {
        var labels = {
            queued: t('queued_status', '排隊中'),
            starting: t('starting', '啟動中'),
            running: t('running', '執行中'),
            stopped: t('stopped', '已停止'),
            unhealthy: t('unhealthy', '異常'),
            error: t('unhealthy', '異常'),
            failed: t('failed', '失敗')
        };
        return labels[status] || t('unknown', '未知');
    }

    function serviceStatusClass(status) {
        if (status === 'running') {
            return 'ok hub-badge-ok';
        }
        if (status === 'queued' || status === 'starting') {
            return 'hub-badge-warn';
        }
        if (status === 'stopped') {
            return 'bad hub-badge-muted';
        }
        return 'bad hub-badge-bad';
    }

    function updateServiceSummary(summary) {
        var summaryKeys = ['total', 'running', 'stopped', 'disabled', 'active_jobs', 'failed_jobs'];
        if (!summary) {
            return;
        }

        $.each(summaryKeys, function (_, key) {
            if (Object.prototype.hasOwnProperty.call(summary, key)) {
                $('[data-service-summary="' + key + '"] [data-service-summary-value]').text(Number(summary[key]) || 0);
            }
        });
    }

    function requestServiceSummary() {
        if (!$('[data-service-summary]').length) {
            return;
        }
        if (summaryRefreshInFlight) {
            summaryRefreshQueued = true;
            return;
        }

        summaryRefreshInFlight = true;
        summaryRefreshQueued = false;
        $.ajax({
            method: 'GET',
            url: 'job_status.php',
            data: {summary: '1'},
            dataType: 'json',
            cache: false,
            timeout: requestTimeout
        }).done(function (response) {
            if (!response || !response.ok || !response.summary) {
                if (!summaryErrorShown) {
                    summaryErrorShown = true;
                    showBackgroundError(t('summary_failed', '讀取服務摘要失敗，請稍後重試。'));
                }
                return;
            }
            summaryErrorShown = false;
            updateServiceSummary(response.summary);
        }).fail(function () {
            if (!summaryErrorShown) {
                summaryErrorShown = true;
                showBackgroundError(t('summary_failed', '讀取服務摘要失敗，請稍後重試。'));
            }
        }).always(function () {
            summaryRefreshInFlight = false;
            if (summaryRefreshQueued) {
                summaryRefreshQueued = false;
                requestServiceSummary();
            }
        });
    }

    function syncServiceState(job) {
        if (!job || (!(job.service && job.service.id) && !job.service_id)) {
            return;
        }

        var serviceId = job.service && job.service.id ? job.service.id : job.service_id;
        var $row = $('[data-service-row-id="' + serviceId + '"]');
        var actualStatus = serviceRuntimeStatus(job);
        if (actualStatus) {
            $row.attr('data-service-actual-status', actualStatus);
        }

        if (job.service && Object.prototype.hasOwnProperty.call(job.service, 'enabled')) {
            var enabled = Number(job.service.enabled) === 1;
            $row.attr('data-service-enabled', enabled ? '1' : '0');
            $row.find('[data-service-enabled-badge]')
                .removeClass('hub-badge-ok hub-badge-muted')
                .addClass(enabled ? 'hub-badge-ok' : 'hub-badge-muted')
                .find('[data-service-enabled-label]')
                .text(enabled ? t('enabled', '已啟用') : t('disabled', '已停用'));
        }

        if (job.service && Object.prototype.hasOwnProperty.call(job.service, 'restart_required')) {
            var restartRequired = Number(job.service.restart_required) === 1;
            $row.attr('data-service-restart-required', restartRequired ? '1' : '0');
            $row.find('[data-service-restart-badge]')
                .removeClass('hub-badge-ok hub-badge-warn')
                .addClass(restartRequired ? 'hub-badge-warn' : 'hub-badge-ok')
                .find('[data-service-restart-label]')
                .text(restartRequired ? t('restart_required', '需重啟') : t('restart_applied', '設定已套用'));
        }

        var displayedStatus = actualStatus;
        if (job.status === 'queued') {
            displayedStatus = 'queued';
        } else if (job.status === 'running') {
            displayedStatus = job.action === 'service_start' || job.action === 'service_install' ? 'starting' : 'running';
        }
        if (displayedStatus) {
            $row.find('[data-service-status]').each(function () {
                $(this)
                    .removeClass('ok bad hub-badge-ok hub-badge-warn hub-badge-bad hub-badge-muted')
                    .addClass(serviceStatusClass(displayedStatus))
                    .find('[data-service-status-label]')
                    .text(serviceStatusLabel(displayedStatus));
            });
        }
    }

    function updateServiceRow(job) {
        syncServiceState(job);
    }

    function updateHealthBadge(job) {
        var serviceId = job && job.service && job.service.id ? job.service.id : (job ? job.service_id : null);
        if (!job || job.action !== 'service_health_check' || !serviceId) {
            return;
        }
        var $badge = $('[data-service-row-id="' + serviceId + '"]').find('[data-service-health]');
        var label = t('health_failed', '健康異常');
        var cls = 'hub-badge-bad';
        if (job.status === 'success' && serviceRuntimeStatus(job) === 'running') {
            label = t('health_ok', '健康正常');
            cls = 'hub-badge-ok';
        } else if (job.status === 'queued' || job.status === 'running') {
            label = t('health_checking', '健康檢查中');
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
            .append(document.createTextNode(jobActionLabel(job.action) + ' '))
            .append($('<code>').text(job.action || ''))
            .append(document.createTextNode(' '))
            .append($('<span data-service-last-job-status>').addClass(jobStatusClass(job)).text(jobStatusLabel(job.status)))
            .append(document.createTextNode(' '))
            .append($('<span class="muted">').text(job.updated_at || job.created_at || ''));
    }

    function triggerServiceRefresh(job) {
        var canRefresh = job
            && job.status === 'success'
            && job.error_code !== 'platform_target_unsupported'
            && job.service_id
            && ['service_start', 'service_restart', 'service_build', 'service_rebuild', 'service_install'].indexOf(job.action) !== -1;
        if (!canRefresh) {
            return false;
        }
        var $box = $('.service-job[data-service-id="' + job.service_id + '"]');
        if ($box.data('refresh-for') === job.id) {
            return false;
        }
        $box.data('refresh-for', job.id);
        return submitServiceAction($('[data-service-refresh-form="' + job.service_id + '"]'), 'refresh', true);
    }

    function scheduleReload($box) {
        if ($box.data('reload-scheduled')) {
            return;
        }
        $box.data('reload-scheduled', true);
        window.setTimeout(function () {
            window.location.reload();
        }, 700);
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
            if (['failed', 'cancelled', 'timeout'].indexOf(job.status) !== -1) {
                terminalFailureVisible = true;
                showMessage(jobFailureMessage(job.status), true);
                return;
            }
            if (job.action === 'service_remove') {
                scheduleReload($box);
                return;
            }
            if (!triggerServiceRefresh(job)) {
                scheduleReload($box);
            }
        }
    }

    function updateJobRow(job) {
        var $row = $('[data-job-row-id="' + job.id + '"]');
        if (!$row.length) {
            return;
        }
        $row.find('[data-job-row-status]')
            .removeClass('ok bad')
            .addClass(jobStatusClass(job))
            .text(jobStatusLabel(job.status));
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
            var inFlightJobId = $box.data('poll-in-flight');
            if (String(inFlightJobId || '') === String(jobId)) {
                return;
            }
            $box.data('poll-in-flight', jobId);
            $.ajax({
                method: 'GET',
                url: 'job_status.php',
                data: {job_id: jobId},
                dataType: 'json',
                cache: false,
                timeout: requestTimeout
            }).done(function (response) {
                if (!response || !response.ok || !response.job) {
                    return;
                }
                requestServiceSummary();
                if (String(response.job.id) !== String($box.attr('data-job-id'))) {
                    return;
                }
                $box.removeData('poll-error-shown');
                updateServiceJobBox($box, response.job);
                updateJobRow(response.job);
            }).fail(function () {
                if (String(jobId) !== String($box.attr('data-job-id'))) {
                    return;
                }
                if (!$box.data('poll-error-shown')) {
                    $box.data('poll-error-shown', true);
                    showBackgroundError(t('poll_failed', '讀取背景工作狀態失敗，請稍後重試或重新整理。'));
                }
            }).always(function () {
                if ($box.data('poll-in-flight') === jobId) {
                    $box.removeData('poll-in-flight');
                }
            });
        });
    }

    $(document).on('click', '.service-action-form button[name="action"]', function () {
        $(this.form).data('action', this.value);
    });

    function submitServiceAction($form, action, silent) {
        if (!$form.length) {
            return false;
        }
        var data = $form.serializeArray();
        data.push({name: 'action', value: action});
        if (!silent) {
            terminalFailureVisible = false;
        }

        $form.addClass('ajax-loading');
        $form.find('button').prop('disabled', true);

        $.ajax({
            method: 'POST',
            url: window.location.href,
            data: data,
            dataType: 'json'
        }).done(function (response) {
            if (!response || !response.ok) {
                showMessage((response && response.error) || t('action_failed', '操作失敗，請重新整理後再試。'), true);
                return;
            }
            if (!silent) {
                showMessage(response.message || t('queued', '已排入背景工作。'), false);
            }
            prependJob(response.job);
            requestServiceSummary();
            if (response.job && response.job.service_id) {
                var $box = $('.service-job[data-service-id="' + response.job.service_id + '"]');
                $box.attr('data-job-id', response.job.id);
                $box.removeData('poll-error-shown');
                updateServiceJobBox($box, response.job);
            }
        }).fail(function (xhr) {
            var response = xhr.responseJSON || {};
            showMessage(response.error || t('action_failed', '操作失敗，請重新整理後再試。'), true);
        }).always(function () {
            $form.removeClass('ajax-loading');
            $form.find('button').prop('disabled', false);
        });
        return true;
    }

    $(document).on('submit', '.service-action-form', function (event) {
        event.preventDefault();

        var $form = $(this);
        var action = $form.data('action') || $form.find('button[name="action"]').first().val();
        if (action === 'remove' && !window.confirm(t('remove_confirm', '確定移除此服務嗎？服務設定將刪除，模型與既有產物會保留。'))) {
            return;
        }
        submitServiceAction($form, action, false);
    });

    $(document).on('click', '[data-copy-target]', function () {
        var target = document.getElementById(this.getAttribute('data-copy-target') || '');
        if (!target || !navigator.clipboard) {
            showMessage(t('copy_failed', '無法自動複製，請手動複製。'), true);
            return;
        }
        navigator.clipboard.writeText(target.textContent || '').then(function () {
            showMessage(t('copied', 'API URL 已複製。'), false);
        }).catch(function () {
            showMessage(t('copy_failed', '無法自動複製，請手動複製。'), true);
        });
    });

    window.setInterval(pollJobs, 2000);
    pollJobs();
})(jQuery);
