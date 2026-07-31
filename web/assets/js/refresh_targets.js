let _crawlHistoryPage = 1;
const CRAWL_HISTORY_PER_PAGE = 50;

function loadCrawlHistoryPage() {
    loadCrawlHistory(1);
    loadRefreshTargets();
}

function formatCrawlHistoryTime(value) {
    return String(value || '').trim().replace('T', ' ').replace(/(\d{2}:\d{2}:\d{2})\.\d+/, '$1');
}

async function loadCrawlHistory(page) {
    const el = document.getElementById('crawl_history_list');
    const pagination = document.getElementById('crawl_history_pagination');
    if (!el) return;
    _crawlHistoryPage = page || _crawlHistoryPage || 1;
    el.innerHTML = '<div class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';
    if (pagination) pagination.innerHTML = '';
    const data = await api('crawl_history&page=' + _crawlHistoryPage + '&per_page=' + CRAWL_HISTORY_PER_PAGE);
    if (!data.ok) {
        el.innerHTML = '<div class="text-danger">' + escapeHtml(data.error || '加载失败') + '</div>';
        return;
    }
    const jobs = data.jobs || [];
    if (!jobs.length) {
        el.innerHTML = '<div class="text-muted">暂无爬取历史</div>';
        return;
    }
    const labels = { completed: '完成', failed: '失败', cancelled: '已取消' };
    const colors = { completed: 'success', failed: 'danger', cancelled: 'secondary' };
    el.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
        '<thead><tr><th>#</th><th>状态</th><th>类型</th><th>检索条件</th><th>语言</th><th>时间</th></tr></thead><tbody>' +
        jobs.map(job => {
            const isRefresh = job.refresh_target_id !== null && job.refresh_target_id !== '';
            const type = isRefresh
                ? '<span class="badge bg-info text-dark">定期刷新</span>' + (job.refresh_target_name ? '<div class="small text-muted mt-1">' + escapeHtml(job.refresh_target_name) + '</div>' : '')
                : '<span class="badge bg-light text-dark border">普通爬取</span>';
            const error = job.error ? '<div class="small text-danger mt-1 text-break">' + escapeHtml(job.error) + '</div>' : '';
            return '<tr><td>' + job.id + '</td>' +
                '<td><span class="badge bg-' + (colors[job.status] || 'secondary') + '">' + escapeHtml(labels[job.status] || job.status) + '</span></td>' +
                '<td>' + type + '</td>' +
                '<td class="text-break"><code>' + escapeHtml(job.query || '') + '</code>' + error + '</td>' +
                '<td class="text-break">' + escapeHtml(job.languages || '全部') + '</td>' +
                '<td class="text-nowrap small"><div>开始：' + escapeHtml(formatCrawlHistoryTime(job.started_at || job.created_at)) + '</div>' +
                '<div class="text-muted">结束：' + escapeHtml(formatCrawlHistoryTime(job.finished_at)) + '</div></td></tr>';
        }).join('') + '</tbody></table></div>';
    renderCrawlHistoryPagination(parseInt(data.page, 10) || 1, parseInt(data.total, 10) || 0, parseInt(data.per_page, 10) || CRAWL_HISTORY_PER_PAGE);
}

function renderCrawlHistoryPagination(page, total, perPage) {
    const el = document.getElementById('crawl_history_pagination');
    if (!el) return;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    el.innerHTML = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' +
        '<span class="small text-muted">共 ' + total + ' 条，第 ' + page + ' / ' + totalPages + ' 页</span>' +
        '<div class="btn-group btn-group-sm">' +
        '<button class="btn btn-outline-secondary" ' + (page <= 1 ? 'disabled ' : '') + 'onclick="loadCrawlHistory(' + (page - 1) + ')"><i class="fas fa-chevron-left"></i></button>' +
        '<button class="btn btn-outline-secondary" ' + (page >= totalPages ? 'disabled ' : '') + 'onclick="loadCrawlHistory(' + (page + 1) + ')"><i class="fas fa-chevron-right"></i></button>' +
        '</div></div>';
}
async function clearCrawlHistory(scope) {
    const failedOnly = scope === 'failed';
    const confirmed = await confirmDialog({
        title: failedOnly ? '清空失败记录' : '清空全部爬取历史',
        message: failedOnly
            ? '将删除所有失败的爬取历史。活动任务和定期刷新对象不会受到影响。'
            : '将删除所有完成、失败和已取消的爬取历史。活动任务和定期刷新对象不会受到影响。',
        okText: failedOnly ? '清空失败记录' : '清空全部历史',
        okClass: 'btn-danger'
    });
    if (!confirmed) return;
    const data = await api('clear_crawl_history', {
        form: { action: 'clear_crawl_history', scope: scope }
    });
    if (!data.ok) {
        showToast(data.error || '清理失败', 'danger');
        return;
    }
    showToast(data.message || '爬取历史已清理', 'success');
    loadCrawlHistory(1);
}

async function loadRefreshTargets() {
    const el = document.getElementById('refresh_targets_list');
    if (!el) return;
    el.innerHTML = '<div class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';
    const data = await api('refresh_targets');
    if (!data.ok) {
        el.innerHTML = '<div class="text-danger">' + escapeHtml(data.error || '加载失败') + '</div>';
        return;
    }
    const targets = data.targets || [];
    if (!targets.length) {
        el.innerHTML = '<div class="text-muted">暂无已完成刷新任务</div>';
        return;
    }
    el.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover align-middle">' +
        '<thead><tr><th>刷新</th><th>任务</th><th>检索条件</th><th>语言</th><th>完成时间</th></tr></thead><tbody>' +
        targets.map(target => '<tr>' +
            '<td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" ' + (parseInt(target.enabled, 10) ? 'checked ' : '') + 'onchange="toggleRefreshTarget(' + target.id + ',this.checked,this)"></div></td>' +
            '<td>' + escapeHtml(target.name || '') + '</td>' +
            '<td class="text-break"><code>' + escapeHtml(target.query || '') + '</code></td>' +
            '<td class="text-break">' + escapeHtml(target.languages || '全部') + '</td>' +
            '<td class="text-nowrap">' + escapeHtml(formatCrawlHistoryTime(target.completed_at || '')) + '</td>' +
            '</tr>').join('') + '</tbody></table></div>';
}

async function toggleRefreshTarget(id, enabled, input) {
    input.disabled = true;
    const data = await api('toggle_refresh_target', { form: { action: 'toggle_refresh_target', id: id, enabled: enabled ? '1' : '' } });
    input.disabled = false;
    if (!data.ok) {
        input.checked = !enabled;
        showToast(data.error || '更新失败', 'danger');
        return;
    }
    showToast(data.message || '刷新设置已更新', 'success');
}
