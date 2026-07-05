// ─── Download ───
function parseDownloadUrl(url) {
    var m = url.match(/exhentai\.org\/g\/(\d+\/[a-f0-9]+)/i);
    if (m) return { source: 'exhentai', source_id: m[1] };
    m = url.match(/nhentai\.net\/g\/(\d+)/i);
    if (m) return { source: 'nhentai', source_id: m[1] };
    return null;
}

async function doDownload() {
    const url = document.getElementById('dl_url').value.trim();
    if (!url) { showToast('请输入 URL', 'warning'); return; }
    const force = document.getElementById('dl_force_url').checked;
    if (force && !await confirmDialog({ title: '确认强制重新下载', message: '强制重新下载将清空本地图片文件并覆盖数据库记录。', detail: '这个操作不可撤销，确定要继续吗？', okText: '强制下载' })) return;
    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中，请稍候...';

    var parsed = parseDownloadUrl(url);
    if (parsed) trackDownloadProgress(parsed.source, parsed.source_id);

    const form = { action: 'download', url: url };
    if (force) form.force = '1';
    const res = await api('download', { form: form });
    clearDownloadProgress();
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function doDownloadById() {
    const source = document.getElementById('dl_source').value;
    const id = document.getElementById('dl_id').value.trim();
    const gid = document.getElementById('dl_gid').value.trim();
    const token = document.getElementById('dl_token').value.trim();
    const force = document.getElementById('dl_force_id').checked;
    if (force && !await confirmDialog({ title: '确认强制重新下载', message: '强制重新下载将清空本地图片文件并覆盖数据库记录。', detail: '这个操作不可撤销，确定要继续吗？', okText: '强制下载' })) return;

    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中，请稍候...';

    let form;
    let parsedId;
    if (gid && token) {
        form = { action: 'download', gid: gid, token: token };
        parsedId = gid + '/' + token;
    } else if (id && source) {
        form = { action: 'download', source: source, id: id };
        parsedId = id;
    } else {
        showToast('请输入 ID 或 GID+Token', 'warning');
        return;
    }
    if (force) form.force = '1';
    if (parsedId && source) trackDownloadProgress(source, parsedId);
    const res = await api('download', { form: form });
    clearDownloadProgress();
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function doBatchDownload() {
    const urls = document.getElementById('batch_urls').value.trim();
    if (!urls) { showToast('请输入 URL', 'warning'); return; }
    const force = document.getElementById('batch_force').checked;
    if (force && !await confirmDialog({ title: '确认批量强制重新下载', message: '强制重新下载将清空所有匹配画廊的本地图片文件并覆盖数据库记录。', detail: '这个操作不可撤销，确定要继续吗？', okText: '强制批量下载' })) return;
    if (!setButtonBusy('batch_download_btn', true, '<i class="fas fa-spinner fa-spin me-1"></i>批量下载中')) return;
    clearOutput('batch_output');
    document.getElementById('batch_output').classList.add('show');

    _batchActive = true;
    renderBatchProgress([], '等待下载任务启动...');
    startProgressPoller();
    try {
        const res = await fetch(API + '?action=batch_download' + (force ? '&force=1' : ''), {
            method: 'POST',
            body: urls,
        });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            data = { ok: false, output: text || '批量下载返回了无效响应' };
        }
        showOutput('batch_output', data.output || '批量下载完成', !data.ok);
        if (data.ok) showToast('批量下载完成!', 'success');
    } catch (err) {
        showOutput('batch_output', '批量下载失败: ' + (err.message || err), true);
    } finally {
        _batchActive = false;
        setButtonBusy('batch_download_btn', false);
        if (!_activeProgressKey && !_retryActive) stopProgressPoller();
    }
}
async function doRetry() {
    if (!setButtonBusy('retry_btn', true, '<i class="fas fa-spinner fa-spin me-1"></i>重试中')) return;
    clearOutput('retry_output');
    document.getElementById('retry_output').classList.add('show');

    _retryActive = true;
    renderRetryProgress([], '正在启动后台重试任务...');
    startProgressPoller();
    try {
        const skip = document.getElementById('retry_skip_existing').checked;
        const res = await api('retry', { form: { skip_existing: skip ? '1' : '0' } });
        if (res.ok) {
            showOutput('retry_output', res.output || '重试任务已在后台启动', false);
            // Keep _retryActive = true so the poller tracks background progress
        } else {
            showOutput('retry_output', res.error || res.output || '重试失败', true);
            _retryActive = false;
        }
    } catch (err) {
        showOutput('retry_output', '重试失败: ' + (err.message || err), true);
        _retryActive = false;
    } finally {
        setButtonBusy('retry_btn', false);
        if (!_retryActive && !_activeProgressKey && !_batchActive) stopProgressPoller();
    }
}
// ─── Download Progress ───
let _progressPoller = null;
let _activeProgressKey = null;
let _batchActive = false;
let _retryActive = false;

function startProgressPoller() {
    if (_progressPoller) return;
    _progressPoller = setInterval(checkDownloadProgress, 2000);
    checkDownloadProgress();
}

function stopProgressPoller() {
    if (_progressPoller) {
        clearInterval(_progressPoller);
        _progressPoller = null;
    }
}

function progressTaskKey(t) {
    return String(t.source || '') + '__' + String(t.source_id || '').replace(/\//g, '_');
}

function renderProgressTaskRows(tasks) {
    return tasks.map(function(t) {
        var total = parseInt(t.total_pages, 10) || 0;
        var current = parseInt(t.current, 10) || 0;
        var pct = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
        var source = escapeHtml(t.source || '');
        var title = escapeHtml(t.title || t.source_id || '下载任务');
        var msg = escapeHtml(t.message || (current + '/' + total));
        var badgeClass = t.source === 'nhentai' ? 'bg-danger' : 'bg-info';
        return '<div class="mb-2" data-progress-key="' + escapeHtml(progressTaskKey(t)) + '">' +
            '<div class="d-flex align-items-center mb-1 small">' +
            '<span class="badge ' + badgeClass + ' me-2 flex-shrink-0">' + source + '</span>' +
            '<span class="text-truncate me-2 flex-grow-1" style="max-width:420px" title="' + title + '">' + title + '</span>' +
            '<span class="text-muted flex-shrink-0">' + msg + '</span>' +
            '</div>' +
            '<div class="progress" style="height:8px">' +
            '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width:' + pct + '%">' + pct + '%</div>' +
            '</div>' +
            '</div>';
    }).join('');
}

function renderProgressList(targetId, tasks, isActive, emptyText) {
    var el = document.getElementById(targetId);
    if (!el) return;
    if (!tasks || tasks.length === 0) {
        if (isActive && emptyText) {
            el.style.display = '';
            el.innerHTML = '<div class="small text-muted"><i class="fas fa-spinner fa-spin me-1"></i>' + escapeHtml(emptyText) + '</div>';
        } else {
            el.style.display = 'none';
            el.innerHTML = '';
        }
        return;
    }
    el.style.display = '';
    el.innerHTML = renderProgressTaskRows(tasks);
}

function renderBatchProgress(tasks, emptyText) {
    // Show progress inside the batch_output black box instead of a separate element
    var el = document.getElementById('batch_output');
    if (!el) return;
    el.classList.add('show');
    if (!tasks || tasks.length === 0) {
        if (_batchActive && emptyText) {
            el.innerHTML = '<span class="info"><i class="fas fa-spinner fa-spin me-1"></i>' + escapeHtml(emptyText) + '</span>';
        }
        return;
    }
    var lines = tasks.map(function(t) {
        var total = parseInt(t.total_pages, 10) || 0;
        var current = parseInt(t.current, 10) || 0;
        var pct = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
        var source = escapeHtml(t.source || '');
        var title = escapeHtml(t.title || t.source_id || '');
        var msg = escapeHtml(t.message || (current + '/' + total));
        return '[' + source + '] ' + title + ' - ' + msg + ' (' + pct + '%)';
    }).join('\n');
    el.innerHTML = '<span class="info">' + lines.replace(/\n/g, '<br>') + '</span>';
}

function renderRetryProgress(tasks, emptyText) {
    // Show progress inside the retry_output black box instead of a separate element
    var el = document.getElementById('retry_output');
    if (!el) return;
    el.classList.add('show');
    if (!tasks || tasks.length === 0) {
        if (_retryActive && emptyText) {
            el.innerHTML = '<span class="info"><i class="fas fa-spinner fa-spin me-1"></i>' + escapeHtml(emptyText) + '</span>';
        } else {
            el.innerHTML = '';
            el.classList.remove('show');
        }
        return;
    }
    // Format each task as a log line inside the black box
    var lines = tasks.map(function(t) {
        var total = parseInt(t.total_pages, 10) || 0;
        var current = parseInt(t.current, 10) || 0;
        var pct = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
        var source = escapeHtml(t.source || '');
        var title = escapeHtml(t.title || t.source_id || '');
        var msg = escapeHtml(t.message || (current + '/' + total));
        return '[' + source + '] ' + title + ' - ' + msg + ' (' + pct + '%)';
    }).join('\n');
    el.innerHTML = '<span class="info">' + lines.replace(/\n/g, '<br>') + '</span>';
}
async function checkDownloadProgress() {
    const data = await api('get_download_progress');
    const tasks = (data && data.tasks) || [];

    const card = document.getElementById('active_downloads_card');
    const body = document.getElementById('active_downloads_body');
    if (tasks.length === 0) {
        card.style.display = 'none';
        body.innerHTML = '';
        if (_batchActive) renderBatchProgress([], '等待下载任务启动...');
        if (_retryActive) {
            // Check if background retry is still running
            api('retry_status', { form: { action: 'retry_status' } }).then(function(status) {
                if (status && status.running) {
                    renderRetryProgress([], '后台重试进行中...');
                } else {
                    _retryActive = false;
                    setButtonBusy('retry_btn', false);
                    api('retry_log', { form: { action: 'retry_log' } }).then(function(log) {
                        if (log && log.ok && log.output) {
                            showOutput('retry_output', log.output, false);
                        }
                    });
                    if (!_retryActive && !_activeProgressKey && !_batchActive) stopProgressPoller();
                }
            });
            return;
        }
        if (!_activeProgressKey && !_batchActive && !_retryActive) stopProgressPoller();
        return;
    }

    card.style.display = '';
    body.innerHTML = renderProgressTaskRows(tasks);
    if (_batchActive) renderBatchProgress(tasks);
    if (_retryActive) renderRetryProgress(tasks);

    if (_activeProgressKey) {
        var active = tasks.find(function(t) { return progressTaskKey(t) === _activeProgressKey; });
        if (active) {
            var el = document.getElementById('dl_progress');
            var bar = document.getElementById('dl_progress_bar');
            if (el && bar) {
                el.style.display = '';
                var total = parseInt(active.total_pages, 10) || 0;
                var current = parseInt(active.current, 10) || 0;
                var pct = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
                bar.style.width = pct + '%';
                bar.textContent = current + '/' + total;
            }
        }
    }
}
function trackDownloadProgress(source, sourceId) {
    _activeProgressKey = source + '__' + sourceId.replace(/\//g, '_');
    var el = document.getElementById('dl_progress');
    var bar = document.getElementById('dl_progress_bar');
    if (el && bar) {
        el.style.display = '';
        bar.style.width = '0%';
        bar.textContent = '';
    }
    startProgressPoller();
}

function clearDownloadProgress() {
    _activeProgressKey = null;
    if (!_batchActive && !_retryActive) stopProgressPoller();
    var el = document.getElementById('dl_progress');
    var bar = document.getElementById('dl_progress_bar');
    if (el && bar) {
        el.style.display = 'none';
        bar.style.width = '0%';
        bar.textContent = '';
    }
}
