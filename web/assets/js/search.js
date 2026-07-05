// ─── Online Search (exhentai) ───
let _searchResults = [];
let _searchQuery = '';
let _searchPage = 1;
let _nextCursor = '';
let _hasNext = false;
let _selectedIds = {};
let _cursorHistory = [];

async function doExSearch() {
    const q = document.getElementById('ex_search_query').value.trim();
    if (!q) { showToast('请输入搜索关键词', 'warning'); return; }
    _searchQuery = q;
    _searchPage = 1;
    _nextCursor = '';
    _hasNext = false;
    _selectedIds = {};
    _cursorHistory = [''];
    await performSearch();
}

async function doExSearchNext() {
    if (!_hasNext || !_nextCursor) { showToast('没有更多结果', 'info'); return; }
    _cursorHistory[_searchPage] = _nextCursor;
    _searchPage++;
    await performSearch();
}

async function doExSearchPrev() {
    if (_searchPage <= 1) return;
    _searchPage--;
    await performSearch();
}

async function performSearch() {
    const el = document.getElementById('ex_search_results');
    el.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>搜索中...</div>';
    document.getElementById('ex_search_meta').textContent = '';
    document.getElementById('ex_search_pagination').innerHTML = '';

    var cursor = _cursorHistory[_searchPage - 1] || '';
    var form = { action: 'search', source: 'exhentai', query: _searchQuery, page: _searchPage };
    if (cursor) form.next = cursor;

    const res = await api('search', { form: form });
    if (!res.ok) {
        el.innerHTML = '<div class="text-center text-danger py-4">' + escapeHtml(res.error || '搜索失败') + '</div>';
        return;
    }

    _searchResults = res.results || [];
    _nextCursor = res.next_cursor || '';
    _hasNext = !!res.has_next;
    renderSearchTable();
    updateBatchButton();
}

function renderSearchTable() {
    const el = document.getElementById('ex_search_results');
    const meta = document.getElementById('ex_search_meta');
    const pag = document.getElementById('ex_search_pagination');

    if (!_searchResults || _searchResults.length === 0) {
        el.innerHTML = '<div class="text-center text-muted py-4">未找到结果</div>';
        meta.textContent = '';
        pag.innerHTML = '';
        return;
    }

    meta.textContent = '第 ' + _searchPage + ' 页 · ' + _searchResults.length + ' 个结果';

    var rows = _searchResults.map(function(g, idx) {
        var displayTitle = escapeHtml(g.title_jp || g.title || '(无标题)');
        var sid = g.source_id || '';
        var pages = parseInt(g.total_pages, 10) || 0;
        var posted = escapeHtml(g.uploaded_at || '');
        var checked = _selectedIds[sid] ? ' checked' : '';
        return '<tr>' +
            '<td style="width:36px"><input type="checkbox" class="form-check-input search-select mt-0" data-idx="' + idx + '"' + checked + ' onchange="toggleSearchResult(this,\'' + escapeAttr(sid) + '\')"></td>' +
            '<td><span class="small" title="' + displayTitle + '">' + displayTitle + '</span></td>' +
            '<td class="text-nowrap text-muted small">' + pages + '</td>' +
            '<td class="text-nowrap text-muted small">' + posted + '</td>' +
            '<td class="text-nowrap" style="width:60px"><button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="doDownloadSingle(\'' + escapeAttr(sid) + '\')" title="下载"><i class="fas fa-download"></i></button></td>' +
            '</tr>';
    }).join('');

    el.innerHTML = '<table class="table table-sm table-hover align-middle mb-0">' +
        '<thead class="table-light"><tr>' +
        '<th style="width:36px"><input type="checkbox" class="form-check-input mt-0" onchange="toggleSelectAll(this)" title="全选/取消"></th>' +
        '<th>标题</th><th style="width:60px">页数</th><th style="width:150px">上传时间</th><th style="width:60px"></th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table>';

    var pagHtml = '<div class="d-flex justify-content-center align-items-center gap-2">';
    if (_searchPage > 1) {
        pagHtml += '<button class="btn btn-outline-secondary btn-sm" onclick="doExSearchPrev()"><i class="fas fa-chevron-left me-1"></i>上一页 (' + (_searchPage - 1) + ')</button>';
    }
    pagHtml += '<span class="small text-muted">' + _searchPage + '</span>';
    if (_hasNext) {
        pagHtml += '<button class="btn btn-outline-primary btn-sm" onclick="doExSearchNext()"><i class="fas fa-chevron-right me-1"></i>下一页 (' + (_searchPage + 1) + ')</button>';
    }
    pagHtml += '</div>';
    pag.innerHTML = pagHtml;
}

function toggleSelectAll(cb) {
    var checkboxes = document.querySelectorAll('#ex_search_results .search-select');
    checkboxes.forEach(function(c) { c.checked = cb.checked; });
    _selectedIds = {};
    if (cb.checked) {
        _searchResults.forEach(function(g) {
            if (g.source_id) _selectedIds[g.source_id] = true;
        });
    }
    updateBatchButton();
}

function toggleSearchResult(cb, sid) {
    if (cb.checked) {
        _selectedIds[sid] = true;
    } else {
        delete _selectedIds[sid];
    }
    updateBatchButton();
}

function updateBatchButton() {
    var count = Object.keys(_selectedIds).length;
    document.getElementById('ex_batch_count2').textContent = count;
    document.getElementById('ex_batch_dl_btn2').disabled = count === 0;
}

async function doDownloadSingle(sid) {
    if (!sid) return;
    var url = 'https://exhentai.org/g/' + sid + '/';
    if (!await confirmDialog({ title: '下载画廊', message: '确定下载这个画廊吗？', detail: url })) return;
    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中...';

    trackDownloadProgress('exhentai', sid);
    var res = await api('download', { form: { action: 'download', url: url } });
    clearDownloadProgress();
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function batchDownloadSelected() {
    var ids = Object.keys(_selectedIds);
    if (ids.length === 0) { showToast('请先选择要下载的画廊', 'warning'); return; }
    if (!await confirmDialog({ title: '批量下载', message: '确定批量下载选中的 ' + ids.length + ' 个画廊吗？', detail: '将逐个下载所有选中的画廊。', okText: '开始批量下载' })) return;

    var urls = ids.map(function(sid) { return 'https://exhentai.org/g/' + sid + '/'; }).join('\n');

    var batchTextarea = document.getElementById('batch_urls');
    var originalVal = batchTextarea.value;
    batchTextarea.value = urls;

    document.getElementById('batch_force').checked = false;
    switchPage('download');

    doBatchDownload();

    batchTextarea.value = originalVal;
}
