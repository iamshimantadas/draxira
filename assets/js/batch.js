/**
 * Draxira background generation with a live progress bar.
 *
 * Any form with a data-dx-batch="<module>" attribute is intercepted: its
 * fields are sent once to start a job, then the browser asks the server to
 * process small slices until the job is done. Without JavaScript the form
 * still posts normally and the server runs everything in one request.
 */
(function ($) {
    'use strict';

    var cfg = window.draxira_batch || null;
    if (!cfg) {
        return;
    }
    var t = cfg.i18n;
    var running = false;

    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1);
        var i = 0;
        return str.replace(/%(\d+\$)?[ds%]/g, function (m) {
            if (m === '%%') {
                return '%';
            }
            var idx = m.indexOf('$') > -1 ? parseInt(m, 10) - 1 : i++;
            return args[idx];
        });
    }

    function post(action, data) {
        return $.ajax({
            url: cfg.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: $.extend({ action: action, nonce: cfg.nonce }, data)
        });
    }

    function errorMessage(xhr) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        return t.error;
    }

    function buildPanel($form) {
        var $panel = $form.find('.dx-progress');
        if ($panel.length) {
            return $panel.empty();
        }
        $panel = $('<div class="dx-progress" role="status" aria-live="polite"></div>');
        var $anchor = $form.find('.submit').last();
        if ($anchor.length) {
            $anchor.after($panel);
        } else {
            $form.append($panel);
        }
        return $panel;
    }

    function showNotice($panel, type, message, withReload) {
        var $n = $('<div class="notice is-dismissible"></div>')
            .addClass(type === 'error' ? 'notice-error' : 'notice-success')
            .append($('<p></p>').text(message));
        if (withReload) {
            $n.append(
                $('<p></p>').append(
                    $('<a href="#" class="button button-small"></a>').text(t.reload).on('click', function (e) {
                        e.preventDefault();
                        window.location.reload();
                    })
                )
            );
        }
        $panel.append($n);
    }

    function startJob($form, $btn) {
        var module = $form.data('dx-batch');
        var $panel = buildPanel($form);
        var originalLabel = $btn.is('input') ? $btn.val() : $btn.text();
        var jobId = null;
        var cancelled = false;

        $btn.prop('disabled', true);
        running = true;

        var $label = $('<p class="dx-progress-label"></p>').text(t.preparing);
        var $bar = $('<div class="dx-progress-bar"><span></span></div>');
        var $stats = $('<p class="dx-progress-stats"></p>');
        var $cancel = $('<button type="button" class="button button-small"></button>').text(t.cancel);
        $panel.append($label, $bar, $stats, $cancel);
        $panel[0].scrollIntoView({ behavior: 'smooth', block: 'center' });

        function finish(spinnerOff) {
            running = false;
            $btn.prop('disabled', false);
            if ($btn.is('input')) {
                $btn.val(originalLabel);
            }
            $cancel.remove();
            if (spinnerOff) {
                $bar.removeClass('is-active');
            }
        }

        function fail(message) {
            finish(true);
            $label.text('');
            showNotice($panel, 'error', message, false);
        }

        $cancel.on('click', function () {
            cancelled = true;
            if (jobId) {
                post('draxira_batch_cancel', { job: jobId });
            }
            finish(true);
            $label.text('');
            showNotice($panel, 'error', t.cancelled, true);
        });

        post('draxira_batch_start', {
            module: module,
            form: JSON.stringify($form.serializeArray())
        })
            .done(function (res) {
                if (!res || !res.success) {
                    fail(res && res.data && res.data.message ? res.data.message : t.error);
                    return;
                }
                jobId = res.data.job;
                $bar.addClass('is-active');
                $label.text(res.data.label || t.working);
                step(res.data.total);
            })
            .fail(function (xhr) {
                fail(errorMessage(xhr));
            });

        function step(total) {
            if (cancelled) {
                return;
            }
            post('draxira_batch_step', { job: jobId })
                .done(function (res) {
                    if (cancelled) {
                        return;
                    }
                    if (!res || !res.success) {
                        fail(res && res.data && res.data.message ? res.data.message : t.error);
                        return;
                    }
                    var d = res.data;
                    var pct = d.total ? Math.floor((d.done / d.total) * 100) : 100;
                    $bar.find('span').css('width', pct + '%');
                    $label.text(sprintf(t.progress, d.done, d.total, pct));
                    $stats.text(sprintf(t.ok, d.success) + '  •  ' + sprintf(t.failed, d.failed));

                    if (d.finished) {
                        finish(true);
                        $label.text(t.done + ' — ' + sprintf(t.progress, d.done, d.total, 100));
                        showNotice($panel, d.type, d.message, true);
                        $(document).trigger('draxira:batch-finished', [module, d]);
                    } else {
                        step(total);
                    }
                })
                .fail(function (xhr) {
                    if (!cancelled) {
                        fail(errorMessage(xhr));
                    }
                });
        }
    }

    $(document).on('submit', 'form[data-dx-batch]', function (e) {
        var $form = $(this);
        if (running) {
            e.preventDefault();
            return;
        }
        // Let the browser validate required fields first.
        if (this.checkValidity && !this.checkValidity()) {
            return;
        }
        e.preventDefault();
        var $btn = $form.find('input[type="submit"], button[type="submit"]').first();
        startJob($form, $btn);
    });

    $(window).on('beforeunload', function () {
        if (running) {
            return t.leave;
        }
        return undefined;
    });
})(jQuery);
