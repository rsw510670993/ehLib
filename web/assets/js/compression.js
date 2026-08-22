// ─── Tools / Image Compression ───
let _compressionPage = 1;
let _compressionRows = [];
let _compressionActiveTasks = [];
let _compressionTotal = 0;
let _compressionPollTimer = null;

const COMPRESSION_STATUS_META = {
    '': { label: '尚未压缩', cls: 'bg-secondary' },
    queued: { label: '已排队', cls: 'bg-info text-dark' },
    compressing: { label: '压缩中', cls: 'bg-info text-dark' },
    user_review_required: { label: '等待审核', cls: 'bg-warning text-dark' },
    failed: { label: '失败', cls: 'bg-danger' },
    approved_pending_apply: { label: '已批准待应用', cls: 'bg-success' },
    skipped: { label: '已跳过', cls: 'bg-secondary' }
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
        _compressionTotal = parseInt(data.total || 0, 10) || 0;
        renderCompressionStats(data.counts || {});
        renderCompressionRows();
        renderCompressionActiveTasks();
        renderCompressionPagination(data.page || 1, data.per_page || 30, data.total || 0);
        var count = document.getElementById('compression_result_count');
        if (count) count.textContent = '共 ' + formatInt(_compressionTotal) + ' 本';
        scheduleCompressionPoll(_compressionActiveTasks.length > 0);
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
}

function renderCompressionRows() {
    var body = document.getElementById('compression_table_body');
    if (!body) return;
    if (_compressionRows.length === 0) {
        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">没有符合条件的已下载漫画。</td></tr>';
        return;
    }
    body.innerHTML = _compressionRows.map(function (row) {
        var meta = compressionStatusMeta(row.compression_status);
        var primary = row.title_jp || row.title || '未命名';
        var secondary = row.title_jp && row.title && row.title_jp !== row.title ? row.title : '';
        var used = parseInt(row.used_webp_count || 0, 10) || 0;
        var pages = parseInt(row.total_pages || 0, 10) || 0;
        var savings = Number(row.savings_pct_overall || 0);
        var candidate = used > 0
            ? used + '/' + pages + ' · <span class="' + (savings >= 0 ? 'text-success' : 'text-danger') + '">' + savings.toFixed(2) + '%</span>'
            : '<span class="text-muted">—</span>';
        var compareBtn = ['user_review_required', 'failed', 'approved_pending_apply'].includes(String(row.compression_status || ''))
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
    if (active.length === 0) {
        box.innerHTML = '<div class="text-muted small">当前没有运行中的压缩任务。</div>';
        return;
    }
    box.innerHTML = active.map(function (row) {
        var p = row.progress || {};
        var current = parseInt(p.current || 0, 10) || 0;
        var total = parseInt(p.total || row.total_pages || 0, 10) || 0;
        var pct = total > 0 ? Math.max(0, Math.min(100, Math.round(current / total * 100))) : 0;
        var title = row.title_jp || row.title || ('Gallery #' + row.id);
        return '<div class="compression-task mb-3" data-gallery-id="' + Number(row.id) + '">' +
            '<div class="d-flex justify-content-between gap-2 mb-1"><span class="fw-semibold">#' + Number(row.id) + ' ' + escapeHtml(title) + '</span><span class="small text-muted">' + current + ' / ' + total + '</span></div>' +
            '<div class="progress" style="height:12px"><div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:' + pct + '%">' + pct + '%</div></div>' +
            '<div class="d-flex justify-content-between mt-1 small text-muted"><span>' + escapeHtml(p.message || '等待任务状态…') + '</span><span>候选 ' + (parseInt(p.used_webp_count || 0, 10) || 0) + ' · 失败 ' + (parseInt(p.failed_pages_count || 0, 10) || 0) + '</span></div>' +
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
    var quality = parseInt(document.getElementById('compress_quality')?.value || '88', 10);
    var method = parseInt(document.getElementById('compress_method')?.value || '4', 10);
    var minSavings = parseFloat(document.getElementById('compress_min_savings')?.value || '5');
    var forceCandidates = !!document.getElementById('compress_force_candidates')?.checked;
    if (!id || quality < 1 || quality > 100 || method < 0 || method > 6 || !isFinite(minSavings) || minSavings < 0 || minSavings > 100) {
        showToast('请检查 Gallery ID 和压缩参数范围', 'warning');
        return;
    }
    var confirmed = await confirmDialog({
        title: '开始图片压缩？',
        message: '将为 Gallery #' + id + ' 生成新的 WebP 审核候选。',
        detail: '参数：quality=' + quality + ' / method=' + method + ' / min_savings=' + minSavings + '% / 忽略节省率阈值=' + (forceCandidates ? '是' : '否') + '。只有体积严格变小的候选会被保留，不会修改原图。',
        okText: '开始压缩',
        okClass: 'btn-primary'
    });
    if (!confirmed) return;
    setButtonBusy('compress_start_btn', true, '<i class="fas fa-spinner fa-spin me-1"></i>启动中');
    try {
        var res = await api('start_compression_task', { form: {
            gallery_id: id,
            quality: quality,
            method: method,
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
