// ─── Tools / Image Compression ───
let _compressionPage = 1;
let _compressionRows = [];
let _compressionActiveTasks = [];
let _compressionBatchTask = null;
let _compressionCounts = {};
let _compressionTotal = 0;
let _compressionPollTimer = null;
let _compressionBatchApproving = false;
let _compressionCleanupAttempted = false;

const COMPRESSION_STATUS_META = {
    queued: { label: '已排队', cls: 'bg-info text-dark' },
    compressing: { label: '压缩中', cls: 'bg-info text-dark' },
    user_review_required: { label: '等待审核', cls: 'bg-warning text-dark' },
    failed: { label: '失败', cls: 'bg-danger' }
};

function compressionStatusMeta(status) {
    return COMPRESSION_STATUS_META[String(status || '')] || { label: status || '未知', cls: 'bg-secondary' };
}

function compressionApplyFilter() {
    _compressionPage = 1;
    loadCompressionPage();
}

async function loadCompressionPage() {
    var body = document.getElementById('compression_table_body');
    if (!body) return;
    var q = (document.getElementById('compress_search')?.value || '').trim();
    var status = document.getElementById('compress_status_filter')?.value || 'all';
    var url = API + '?action=list_compression_galleries&page=' + _compressionPage + '&per_page=30' +
        '&status=' + encodeURIComponent(status) + '&q=' + encodeURIComponent(q);
    try {
        var resp = await fetch(url);
        var data = await resp.json();
        if (!data || !data.ok) throw new Error((data && data.error) || '加载失败');
        _compressionRows = data.items || [];
        _compressionActiveTasks = data.active_tasks || [];
        _compressionBatchTask = data.batch_task || null;
        _compressionCounts = data.counts || {};
        _compressionTotal = parseInt(data.total || 0, 10) || 0;
        renderCompressionStats(_compressionCounts);
        renderCompressionBatchTask();
        renderCompressionRows();
        renderCompressionActiveTasks();
        renderCompressionPagination(data.page || 1, data.per_page || 30, data.total || 0);
        renderCompressionBatchApproveButton();
        maybeCleanupCompressionArtifacts();
        var count = document.getElementById('compression_result_count');
        if (count) count.textContent = '共 ' + formatInt(_compressionTotal) + ' 个待处理任务';
        scheduleCompressionPoll(
            _compressionActiveTasks.length > 0 || !!(_compressionBatchTask && _compressionBatchTask.running)
        );
    } catch (e) {
        body.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">' + escapeHtml(e.message || String(e)) + '</td></tr>';
        scheduleCompressionPoll(false);
    }
}

function renderCompressionStats(counts) {
    var set = function (id, value) { var el = document.getElementById(id); if (el) el.textContent = formatInt(value || 0); };
    set('compress_stat_total', counts.total);
    set('compress_stat_not_started', counts.not_started);
    set('compress_stat_running', (counts.queued || 0) + (counts.compressing || 0));
    set('compress_stat_review', counts.user_review_required);
    set('compress_stat_failed', counts.failed);
    var batchBtn = document.getElementById('compress_batch_start_btn');
    var singleBtn = document.getElementById('compress_start_btn');
    var running = !!(_compressionBatchTask && _compressionBatchTask.running);
    var recoverable = !!(_compressionBatchTask && !running && ['queued', 'running'].includes(String(_compressionBatchTask.status || '')));
    if (batchBtn) {
        batchBtn.disabled = running || (!recoverable && Number(counts.not_started || 0) <= 0);
        batchBtn.title = running ? '批量任务正在运行' : (recoverable ? '恢复上次异常退出的批量队列' : ('当前未压漫画：' + Number(counts.not_started || 0) + ' 本'));
    }
    if (singleBtn && running) {
        singleBtn.disabled = true;
        singleBtn.title = '批量压缩运行期间不能启动单本压缩';
    } else if (singleBtn) {
        singleBtn.disabled = false;
        singleBtn.title = '';
    }
}

function renderCompressionBatchTask() {
    var box = document.getElementById('compression_batch_progress');
    if (!box) return;
    var task = _compressionBatchTask;
    if (_compressionActiveTasks.length > 0 && (!task || !task.running)) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    if (!task) {
        box.innerHTML = '<div class="d-flex justify-content-between small text-muted"><span>暂无运行任务</span><span>未压 ' +
            formatInt(_compressionCounts.not_started || 0) + ' 本</span></div>';
        return;
    }
    var fallbackTask = _compressionActiveTasks.length > 0 ? _compressionActiveTasks[0] : null;
    var total = parseInt(task.total_galleries || 0, 10) ||
        (Array.isArray(task.gallery_ids) ? task.gallery_ids.length : 0);
    var finished = parseInt(task.finished_count || 0, 10) || 0;
    var index = parseInt(task.current_gallery_index || 0, 10) || 0;
    var galleryProgress = task.gallery_progress || {};
    if ((!galleryProgress.total && !galleryProgress.current) && fallbackTask) {
        galleryProgress = fallbackTask.progress || {};
    }
    var pageCurrent = parseInt(galleryProgress.current || 0, 10) || 0;
    var pageTotal = parseInt(galleryProgress.total || task.current_gallery_total_pages ||
        (fallbackTask && fallbackTask.total_pages) || 0, 10) || 0;
    var pagePct = pageTotal > 0 ? Math.max(0, Math.min(100, Math.round(pageCurrent / pageTotal * 100))) : 0;
    var running = !!task.running;
    var statusText = running ? '运行中' : ({
        completed: '已完成',
        completed_with_errors: '完成但有失败',
        failed: '失败',
        queued: '等待启动'
    }[String(task.status || '')] || String(task.status || '已结束'));
    var statusClass = running ? 'text-info' : (String(task.status || '').includes('failed') || task.status === 'completed_with_errors' ? 'text-danger' : 'text-success');
    var currentId = Number(task.current_gallery_id || (fallbackTask && fallbackTask.id) || 0);
    var currentRawTitle = task.current_gallery_title ||
        (fallbackTask && (fallbackTask.title_jp || fallbackTask.title)) || '';
    var currentTitle = currentId ? ('#' + currentId + ' ' + escapeHtml(currentRawTitle)) : '';
    if (!index && currentId) index = Math.min(total || finished + 1, finished + 1);
    var overallText = total > 0 ? ('整本 ' + finished + ' / ' + total) : '正在准备队列';
    var message = escapeHtml((galleryProgress && galleryProgress.message) || task.message || '');
    box.innerHTML =
        '<div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold ' + statusClass + '">' + escapeHtml(statusText) +
        '</span><span class="small text-muted">' + overallText + '</span></div>' +
        (currentId ? '<div class="d-flex justify-content-between gap-2 small mb-1"><span class="text-truncate">' + currentTitle +
            (total ? '（' + index + ' / ' + total + '）' : '') + '</span><span class="text-muted text-nowrap">' + pageCurrent + ' / ' + pageTotal + '</span></div>' +
            '<div class="progress mb-1" style="height:12px"><div class="progress-bar bg-info' +
            (running ? ' progress-bar-striped progress-bar-animated' : '') + '" style="width:' + pagePct + '%">' + pagePct + '%</div></div>' : '') +
        '<div class="d-flex flex-wrap justify-content-between gap-2 small text-muted"><span>' +
        message + '</span><span>待审 ' +
        (parseInt(task.review_count || 0, 10) || 0) + ' · 跳过 ' + (parseInt(task.skipped_count || 0, 10) || 0) +
        ' · 失败 ' + (parseInt(task.failed_count || 0, 10) || 0) + '</span></div>';
}

async function startBatchCompression() {
    var count = parseInt(_compressionCounts.not_started || 0, 10) || 0;
    var recoverable = !!(_compressionBatchTask && !_compressionBatchTask.running && ['queued', 'running'].includes(String(_compressionBatchTask.status || '')));
    if (recoverable && count <= 0) count = Array.isArray(_compressionBatchTask.gallery_ids) ? _compressionBatchTask.gallery_ids.length : 0;
    if (count <= 0 && !recoverable) {
        showToast('当前没有未压缩漫画', 'info');
        return;
    }
    var confirmed = await confirmDialog({
        title: '批量压缩所有未压漫画？',
        message: (recoverable ? '将先释放上次异常中断的排队状态，再重新锁定并处理 ' : '将锁定并依次处理当前 ') + count + ' 本未压缩漫画。',
        detail: '固定参数：AVIF quality=65 / speed=5 / 最低节省率=5%。只保留体积变小的候选；每本完成后进入待审，不自动替换原图，也不创建备份。任务可能运行较长时间。',
        okText: '开始批量压缩',
        okClass: 'btn-warning'
    });
    if (!confirmed) return;
    setButtonBusy('compress_batch_start_btn', true, '<i class="fas fa-spinner fa-spin me-1"></i>启动中');
    try {
        var res = await api('start_compression_batch', { form: {} });
        if (!res || !res.ok) throw new Error((res && res.error) || '启动失败');
        showToast(res.message || '批量压缩已启动', 'success');
        _compressionBatchTask = res.batch_task || _compressionBatchTask;
        await loadCompressionPage();
        scheduleCompressionPoll(true);
    } catch (e) {
        showToast('启动批量压缩失败：' + (e.message || e), 'danger');
    } finally {
        setButtonBusy('compress_batch_start_btn', false);
        renderCompressionStats(_compressionCounts);
    }
}

function renderCompressionRows() {
    var body = document.getElementById('compression_table_body');
    if (!body) return;
    if (_compressionRows.length === 0) {
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">当前没有排队、压缩中、待审或失败任务。</td></tr>';
        return;
    }
    body.innerHTML = _compressionRows.map(function (row) {
        var meta = compressionStatusMeta(row.compression_status);
        var primary = row.title_jp || row.title || '未命名';
        var secondary = row.title_jp && row.title && row.title_jp !== row.title ? row.title : '';
        var used = parseInt(row.used_candidate_count || row.used_webp_count || 0, 10) || 0;
        var pages = parseInt(row.total_pages || 0, 10) || 0;
        var hasSavings = row.savings_pct_overall !== null && row.savings_pct_overall !== '';
        var savings = hasSavings ? Number(row.savings_pct_overall) : null;
        var candidate = used > 0
            ? used + '/' + pages + (hasSavings && isFinite(savings)
                ? ' · <span class="' + (savings >= 0 ? 'text-success' : 'text-danger') + '">' + savings.toFixed(2) + '%</span>'
                : ' · <span class="text-muted">—</span>')
            : '<span class="text-muted">—</span>';
        var compareBtn = ['user_review_required', 'failed'].includes(String(row.compression_status || ''))
            ? '<button class="btn btn-sm btn-outline-warning" onclick="compressionOpenCompare(' + Number(row.id) + ')" title="打开压缩对比"><i class="fas fa-code-compare"></i></button>'
            : '';
        var startDisabled = row.running ? ' disabled' : '';
        return '<tr>' +
            '<td><div class="fw-semibold">#' + Number(row.id) + '</div><div class="small text-muted">' + escapeHtml(row.source) + '/' + escapeHtml(row.source_id) + '</div></td>' +
            '<td style="min-width:260px"><div class="text-truncate" style="max-width:480px" title="' + escapeAttr(primary) + '">' + escapeHtml(primary) + '</div>' +
                (secondary ? '<div class="small text-muted text-truncate" style="max-width:480px">' + escapeHtml(secondary) + '</div>' : '') +
                (row.artist ? '<div class="small text-muted">' + escapeHtml(row.artist) + '</div>' : '') + '</td>' +
            '<td>' + pages + '</td>' +
            '<td><span class="badge ' + meta.cls + '">' + escapeHtml(meta.label) + '</span></td>' +
            '<td>' + candidate + '</td>' +
            '<td class="small text-muted text-nowrap">' + escapeHtml(formatCompressionTime(row.updated_at)) + '</td>' +
            '<td class="text-end text-nowrap"><div class="btn-group btn-group-sm">' +
                '<button class="btn btn-outline-primary" onclick="selectCompressionGallery(' + Number(row.id) + ')"' + startDisabled + ' title="选择参数并发起"><i class="fas fa-sliders"></i></button>' +
                compareBtn + '</div></td>' +
            '</tr>';
    }).join('');
}

function renderCompressionActiveTasks() {
    var box = document.getElementById('compression_active_tasks');
    if (!box) return;
    var active = _compressionActiveTasks;
    if ((_compressionBatchTask && _compressionBatchTask.running) || active.length === 0) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = active.map(function (row) {
        var p = row.progress || {};
        var current = parseInt(p.current || 0, 10) || 0;
        var total = parseInt(p.total || row.total_pages || 0, 10) || 0;
        var pct = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
        var title = row.title_jp || row.title || ('Gallery #' + row.id);
        return '<div class="compression-task mb-3" data-gallery-id="' + Number(row.id) + '">' +
            '<div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold">#' + Number(row.id) + ' ' + escapeHtml(title) + '</span><span class="small text-muted">' + current + ' / ' + total + '</span></div>' +
            '<div class="progress" style="height:12px"><div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:' + pct + '%">' + pct + '%</div></div>' +
            '<div class="d-flex justify-content-between mt-1 small text-muted"><span>' + escapeHtml(p.message || '等待任务状态…') + '</span><span>候选 ' + (parseInt(p.used_candidate_count || p.used_avif_count || p.used_webp_count || 0, 10) || 0) + ' · 失败 ' + (parseInt(p.failed_pages_count || 0, 10) || 0) + '</span></div>' +
            '</div>';
    }).join('');
}

function renderCompressionPagination(page, perPage, total) {
    var box = document.getElementById('compression_pagination');
    if (!box) return;
    var totalPages = Math.max(1, Math.ceil(Number(total || 0) / Number(perPage || 30)));
    if (totalPages <= 1) { box.innerHTML = ''; return; }
    box.innerHTML = '<div class="d-flex justify-content-between align-items-center"><span class="small text-muted">第 ' + page + ' / ' + totalPages + ' 页</span><div class="btn-group btn-group-sm"><button class="btn btn-outline-secondary" onclick="compressionGotoPage(' + (page - 1) + ')"' + (page <= 1 ? ' disabled' : '') + '>上一页</button><button class="btn btn-outline-secondary" onclick="compressionGotoPage(' + (page + 1) + ')"' + (page >= totalPages ? ' disabled' : '') + '>下一页</button></div></div>';
}

function compressionGotoPage(page) {
    if (page < 1) return;
    _compressionPage = page;
    loadCompressionPage();
}

function renderCompressionBatchApproveButton() {
    var btn = document.getElementById('compress_batch_approve_btn');
    if (!btn || _compressionBatchApproving) return;
    var status = document.getElementById('compress_status_filter')?.value || 'all';
    var allowed = status === 'all' || status === 'user_review_required';
    var reviewCount = parseInt(_compressionCounts.user_review_required || 0, 10) || 0;
    btn.disabled = !allowed || reviewCount <= 0;
    btn.title = !allowed
        ? '请选择“全部待处理”或“等待审核”后再批量通过'
        : (reviewCount > 0 ? '批准并立即应用当前搜索条件下的全部待审记录' : '当前没有待审记录');
}

async function maybeCleanupCompressionArtifacts() {
    if (_compressionCleanupAttempted || _compressionBatchApproving) return;
    var batchRunning = !!(_compressionBatchTask && _compressionBatchTask.running);
    var pending = (parseInt(_compressionCounts.queued || 0, 10) || 0) +
        (parseInt(_compressionCounts.compressing || 0, 10) || 0) +
        (parseInt(_compressionCounts.user_review_required || 0, 10) || 0);
    if (batchRunning || _compressionActiveTasks.length > 0 || pending > 0) return;
    _compressionCleanupAttempted = true;
    try {
        await api('cleanup_compression_artifacts', { form: {} });
    } catch (e) {
        console.warn('压缩工作文件自动清理失败', e);
    }
}

async function collectFilteredCompressionReviewIds() {
    var q = (document.getElementById('compress_search')?.value || '').trim();
    var ids = [];
    var page = 1;
    var total = 0;
    do {
        var url = API + '?action=list_compression_galleries&page=' + page + '&per_page=100' +
            '&status=user_review_required&q=' + encodeURIComponent(q);
        var resp = await fetch(url);
        var data = await resp.json();
        if (!data || !data.ok) throw new Error((data && data.error) || '读取待审列表失败');
        total = parseInt(data.total || 0, 10) || 0;
        (data.items || []).forEach(function (row) {
            var id = parseInt(row.id || 0, 10);
            if (id > 0) ids.push(id);
        });
        page += 1;
    } while (ids.length < total && page <= 1000);
    return Array.from(new Set(ids));
}

async function approveFilteredCompressionReviews() {
    if (_compressionBatchApproving) return;
    var btn = document.getElementById('compress_batch_approve_btn');
    if (!btn) return;
    var originalHtml = btn.innerHTML;
    var shouldReload = false;
    _compressionBatchApproving = true;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>统计中';
    try {
        var ids = await collectFilteredCompressionReviewIds();
        if (ids.length === 0) {
            showToast('当前搜索条件下没有待审记录', 'info');
            return;
        }
        var confirmed = await confirmDialog({
            title: '批量批准压缩候选？',
            message: '将批准并立即应用当前搜索条件下的 ' + ids.length + ' 本待审漫画。',
            detail: '候选图会逐本替换原图，不创建备份；失败项目不会影响其余项目继续处理。',
            okText: '批量批准并应用',
            okClass: 'btn-success'
        });
        if (!confirmed) return;

        shouldReload = true;
        var approved = 0;
        var failed = [];
        for (var i = 0; i < ids.length; i += 1) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + (i + 1) + ' / ' + ids.length;
            try {
                var result = await api('approve_compress_whole', { form: { gallery_id: ids[i], defer_cleanup: '1' } });
                if (!result || !result.ok) throw new Error((result && result.error) || '批准失败');
                approved += 1;
            } catch (e) {
                failed.push({ id: ids[i], error: e.message || String(e) });
            }
        }
        if (failed.length === 0) {
            showToast('已批准并应用 ' + approved + ' 本漫画', 'success');
        } else {
            var failedIds = failed.slice(0, 8).map(function (item) { return '#' + item.id; }).join('、');
            showToast('批量审核完成：成功 ' + approved + '，失败 ' + failed.length + '（' + failedIds + (failed.length > 8 ? '…' : '') + '）', 'warning');
        }
        try {
            await api('cleanup_compression_artifacts', { form: {} });
            _compressionCleanupAttempted = true;
        } catch (_cleanupError) {
            console.warn('压缩工作文件自动清理失败', _cleanupError);
        }
    } catch (e) {
        showToast('批量审核失败：' + (e.message || e), 'danger');
    } finally {
        _compressionBatchApproving = false;
        btn.innerHTML = originalHtml;
        if (shouldReload) {
            _compressionPage = 1;
            await loadCompressionPage();
            if (typeof loadGalleries === 'function') loadGalleries();
        } else {
            renderCompressionBatchApproveButton();
        }
    }
}

function selectCompressionGallery(id) {
    var row = _compressionRows.find(function (item) { return Number(item.id) === Number(id); });
    var input = document.getElementById('compress_gallery_id');
    if (input) input.value = String(id);
    var hint = document.getElementById('compress_selected_hint');
    if (hint) hint.textContent = row ? ('已选择 #' + id + '：' + (row.title_jp || row.title || '未命名')) : ('已选择 Gallery #' + id);
    document.getElementById('compress_quality')?.focus();
    document.getElementById('tab_tools_compression')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function startCompressionTask() {
    var id = parseInt(document.getElementById('compress_gallery_id')?.value || '0', 10);
    var quality = parseInt(document.getElementById('compress_quality')?.value || '65', 10);
    var speed = parseInt(document.getElementById('compress_speed')?.value || '5', 10);
    var minSavings = parseFloat(document.getElementById('compress_min_savings')?.value || '5');
    var forceCandidates = !!document.getElementById('compress_force_candidates')?.checked;
    if (!id || quality < 1 || quality > 100 || speed < 0 || speed > 10 || !isFinite(minSavings) || minSavings < 0 || minSavings > 100) {
        showToast('请检查 Gallery ID 和压缩参数范围', 'warning');
        return;
    }
    var confirmed = await confirmDialog({
        title: '开始图片压缩？',
        message: '将为 Gallery #' + id + ' 生成新的 AVIF 审核候选。',
        detail: '参数：quality=' + quality + ' / speed=' + speed + ' / min_savings=' + minSavings + '% / 忽略节省率阈值=' + (forceCandidates ? '是' : '否') + '。只有体积严格变小的候选会被保留，不会修改原图。',
        okText: '开始压缩',
        okClass: 'btn-primary'
    });
    if (!confirmed) return;
    setButtonBusy('compress_start_btn', true, '<i class="fas fa-spinner fa-spin me-1"></i>启动中');
    try {
        var res = await api('start_compression_task', { form: {
            gallery_id: id,
            quality: quality,
            speed: speed,
            min_savings: minSavings,
            force_candidates: forceCandidates ? '1' : '0'
        }});
        if (!res || !res.ok) throw new Error((res && res.error) || '启动失败');
        showToast(res.message || '压缩任务已启动', 'success');
        _compressionPage = 1;
        await loadCompressionPage();
        scheduleCompressionPoll(true);
    } catch (e) {
        showToast('启动压缩失败：' + (e.message || e), 'danger');
    } finally {
        setButtonBusy('compress_start_btn', false);
    }
}

function compressionOpenCompare(id) {
    var row = _compressionRows.find(function (item) { return Number(item.id) === Number(id); });
    if (!row || typeof openCompressCompare !== 'function') {
        showToast('无法打开压缩对比，请刷新页面重试', 'warning');
        return;
    }
    openCompressCompare({ gallery_id: row.id, source: row.source, source_id: row.source_id });
}

function scheduleCompressionPoll(shouldPoll) {
    if (_compressionPollTimer) {
        clearTimeout(_compressionPollTimer);
        _compressionPollTimer = null;
    }
    if (!shouldPoll) return;
    _compressionPollTimer = setTimeout(function () {
        _compressionPollTimer = null;
        var pane = document.getElementById('tab_tools_compression');
        if (pane && pane.classList.contains('active')) loadCompressionPage();
    }, 2000);
}

function formatCompressionTime(value) {
    if (!value) return '—';
    var date = new Date(value);
    if (isNaN(date.getTime())) return String(value);
    return date.toLocaleString();
}

document.addEventListener('DOMContentLoaded', function () {
    var tab = document.querySelector('[data-bs-target="#tab_tools_compression"]');
    if (tab) tab.addEventListener('shown.bs.tab', function () { loadCompressionPage(); });
});
