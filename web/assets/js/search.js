// ─── Online Search (exhentai) ───
let _searchResults = [];
let _searchQuery = '';
let _searchPage = 1;
let _nextCursor = '';
let _hasNext = false;
let _selectedIds = {};
let _cursorHistory = [];
let _prevCursor = '';
let _totalPages = 0;

// ─── Category Tags (data-cat = e-hentai bitmask value) ───

function toggleCategory(el) {
    const cat = el.dataset.cat;
    if (cat === 'all') {
        document.querySelectorAll('#ex_category_tags .cat-tag').forEach(function(tag) {
            tag.classList.add('active');
        });
    } else {
        el.classList.toggle('active');
        const allTags = document.querySelectorAll('#ex_category_tags .cat-tag[data-cat]:not([data-cat="all"])');
        const activeTags = document.querySelectorAll('#ex_category_tags .cat-tag.active[data-cat]:not([data-cat="all"])');
        const allBtn = document.querySelector('#ex_category_tags .cat-tag[data-cat="all"]');
        if (activeTags.length === allTags.length) {
            allBtn.classList.add('active');
        } else {
            allBtn.classList.remove('active');
        }
    }
    saveCategorySelection();
}

function getSelectedCategories() {
    var allBtn = document.querySelector('#ex_category_tags .cat-tag[data-cat="all"]');
    if (allBtn && allBtn.classList.contains('active')) return ['1','2','4','8','16','32','64','128','256','512'];
    var cats = [];
    document.querySelectorAll('#ex_category_tags .cat-tag.active[data-cat]').forEach(function(tag) {
        if (tag.dataset.cat !== 'all') cats.push(tag.dataset.cat);
    });
    return cats.length > 0 ? cats : null;
}

function categoriesToParam(cats) {
    if (!cats) return '';
    return cats.join(',');
}

function saveCategorySelection() {
    var cats = getSelectedCategories();
    try {
        localStorage.setItem('ehlib_cat_selection', JSON.stringify(cats));
    } catch(e) {}
}

function loadCategorySelection() {
    try {
        var saved = localStorage.getItem('ehlib_cat_selection');
        if (saved === null) {
            document.querySelectorAll('#ex_category_tags .cat-tag').forEach(function(t) { t.classList.add('active'); });
            return;
        }
        var cats = JSON.parse(saved);
        document.querySelectorAll('#ex_category_tags .cat-tag').forEach(function(t) { t.classList.remove('active'); });
        if (cats) {
            cats.forEach(function(c) {
                var btn = document.querySelector('#ex_category_tags .cat-tag[data-cat="' + c + '"]');
                if (btn) btn.classList.add('active');
            });
        }
        var allTags = document.querySelectorAll('#ex_category_tags .cat-tag[data-cat]:not([data-cat="all"])');
        var activeTags = document.querySelectorAll('#ex_category_tags .cat-tag.active[data-cat]:not([data-cat="all"])');
        var allBtn = document.querySelector('#ex_category_tags .cat-tag[data-cat="all"]');
        if (allBtn && activeTags.length === allTags.length) allBtn.classList.add('active');
    } catch(e) {}
}

loadCategorySelection();

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
    await goToSearchPageDirect(_searchPage + 1);
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
    var cats = getSelectedCategories();
    if (cats) form.categories = categoriesToParam(cats);

    const res = await api('search', { form: form });
    if (!res.ok) {
        el.innerHTML = '<div class="text-center text-danger py-4">' + escapeHtml(res.error || '搜索失败') + '</div>';
        return;
    }

    _searchResults = res.results || [];
    _nextCursor = res.next_cursor || '';
    _prevCursor = res.prev_cursor || '';
    _hasNext = !!res.has_next;
    _totalPages = res.total_pages || 0;
    if (!_hasNext && _totalPages > _searchPage) _totalPages = _searchPage;
    renderSearchTable();
    updateBatchButton();
}

async function goToSearchFirst() {
    if (_searchPage === 1) return;
    _searchPage = 1;
    await performSearch();
}

async function goToSearchLast() {
    if (!_prevCursor) { showToast('没有上一页信息', 'warning'); return; }
    await _navSearch({ prev: '1' });
}

async function goToSearchRange(pct) {
    await _navSearch({ range: String(pct) });
}

async function _navSearch(params) {
    var spinner = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>搜索中...</div>';
    document.getElementById('ex_search_results').innerHTML = spinner;
    document.getElementById('ex_search_pagination').innerHTML = '';

    var form = { action: 'search', source: 'exhentai', query: _searchQuery, page: 1 };
    for (var k in params) form[k] = params[k];
    var cats = getSelectedCategories();
    if (cats) form.categories = categoriesToParam(cats);

    const res = await api('search', { form: form });
    if (!res.ok) {
        document.getElementById('ex_search_results').innerHTML = '<div class="text-center text-danger py-4">' + escapeHtml(res.error || '搜索失败') + '</div>';
        return;
    }
    _searchResults = res.results || [];
    _nextCursor = res.next_cursor || '';
    _prevCursor = res.prev_cursor || '';
    _hasNext = !!res.has_next;
    _totalPages = res.total_pages || 0;

    // Reset cursor history — prev/range jumps start a fresh navigation context
    _cursorHistory = [''];
    _searchPage = 1;
    renderSearchTable();
    updateBatchButton();
}

async function goToSearchPageDirect(p) {
    if (p < 1 || p === _searchPage) return;

    // Direct jump if cursor known (page already visited)
    if (p <= _cursorHistory.length) {
        _searchPage = p;
        await performSearch();
        return;
    }

    // Fast-forward: load intermediate pages sequentially until target reached
    var spinner = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>跳转到第 ' + p + ' 页...</div>';
    document.getElementById('ex_search_results').innerHTML = spinner;
    document.getElementById('ex_search_pagination').innerHTML = '';

    while (_searchPage < p) {
        if (!_hasNext || !_nextCursor) {
            _totalPages = _searchPage;
            showToast('只有 ' + _searchPage + ' 页', 'warning');
            break;
        }
        _cursorHistory[_searchPage] = _nextCursor;
        _searchPage++;

        var form = { action: 'search', source: 'exhentai', query: _searchQuery, page: _searchPage };
        form.next = _nextCursor;
        var cats = getSelectedCategories();
        if (cats) form.categories = categoriesToParam(cats);

        const res = await api('search', { form: form });
        if (!res.ok) return;
        _nextCursor = res.next_cursor || '';
        _hasNext = !!res.has_next;
        _totalPages = res.total_pages || 0;
        if (!_hasNext && _totalPages > _searchPage) _totalPages = _searchPage;
    }
    renderSearchTable();
    updateBatchButton();
}

var CAT_COLORS = {
    'Doujinshi': '#e74c3c', 'Manga': '#3498db', 'Artist CG': '#9b59b6',
    'Game CG': '#e67e22', 'Western': '#27ae60', 'Non-H': '#95a5a6',
    'Image Set': '#1abc9c', 'Cosplay': '#e91e63', 'Asian Porn': '#795548',
    'Misc': '#607d8b'
};

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

    var metaParts = ['第 ' + _searchPage + ' 页 · ' + _searchResults.length + ' 个结果'];
    if (_totalPages > 0) metaParts.push('共约 ' + _totalPages + ' 页');
    meta.textContent = metaParts.join(' · ');

    var rows = _searchResults.map(function(g, idx) {
        var displayTitle = escapeHtml(g.title_jp || g.title || '(无标题)');
        var sid = g.source_id || '';
        var pages = parseInt(g.total_pages, 10) || 0;
        var posted = escapeHtml(g.uploaded_at || '');
        var cat = g.category || '';
        var catColor = CAT_COLORS[cat] || '#6c757d';
        var catBadge = cat ? '<span class="cat-badge" style="color:#fff;background:' + catColor + '">' + escapeHtml(cat) + '</span>' : '<span class="text-muted small">-</span>';
        var checked = _selectedIds[sid] ? ' checked' : '';
        return '<tr>' +
            '<td style="width:36px"><input type="checkbox" class="form-check-input search-select mt-0" data-idx="' + idx + '"' + checked + ' onchange="toggleSearchResult(this,\'' + escapeAttr(sid) + '\')"></td>' +
            '<td class="text-nowrap text-muted small" style="width:80px">' + catBadge + '</td>' +
            '<td><span class="small" title="' + displayTitle + '">' + displayTitle + '</span></td>' +
            '<td class="text-nowrap text-muted small">' + pages + '</td>' +
            '<td class="text-nowrap text-muted small">' + posted + '</td>' +
            '<td class="text-nowrap" style="width:60px"><button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="doDownloadSingle(\'' + escapeAttr(sid) + '\')" title="下载"><i class="fas fa-download"></i></button></td>' +
            '</tr>';
    }).join('');

    el.innerHTML = '<table class="table table-sm table-hover align-middle mb-0">' +
        '<thead class="table-light"><tr>' +
        '<th style="width:36px"><input type="checkbox" class="form-check-input mt-0" onchange="toggleSelectAll(this)" title="全选/取消"></th>' +
        '<th style="width:80px">分类</th><th>标题</th><th style="width:60px">页数</th><th style="width:150px">上传时间</th><th style="width:60px"></th>' +
        '</tr></thead><tbody>' + rows + '</tbody></table>';

    // ─── Pagination row 1: navigation + page numbers ───
    var pagHtml = '<div class="d-flex justify-content-center align-items-center gap-1 flex-wrap mb-1">';

    pagHtml += '<button class="btn btn-outline-secondary btn-sm" onclick="goToSearchFirst()"' + (_searchPage === 1 ? ' disabled' : '') + ' title="首页"><i class="fas fa-angle-double-left"></i></button>';
    pagHtml += '<button class="btn btn-outline-secondary btn-sm" onclick="doExSearchPrev()"' + (_searchPage <= 1 ? ' disabled' : '') + ' title="上一页"><i class="fas fa-chevron-left"></i></button>';

    // Visited page numbers (compact, up to ~10)
    var maxLoaded = _cursorHistory.length;
    if (maxLoaded <= 10) {
        for (var p = 1; p <= maxLoaded; p++) {
            if (p === _searchPage) {
                pagHtml += '<button class="btn btn-primary btn-sm active px-2">' + p + '</button>';
            } else {
                pagHtml += '<button class="btn btn-outline-secondary btn-sm px-2" onclick="goToSearchPageDirect(' + p + ')">' + p + '</button>';
            }
        }
    } else {
        // Show window around current
        var s = Math.max(1, _searchPage - 2);
        var e = Math.min(maxLoaded, _searchPage + 2);
        if (s > 2) pagHtml += '<span class="text-muted small px-1">…</span>';
        if (s > 1) pagHtml += '<button class="btn btn-outline-secondary btn-sm px-2" onclick="goToSearchPageDirect(1)">1</button>';
        for (var p = s; p <= e; p++) {
            if (p === _searchPage) {
                pagHtml += '<button class="btn btn-primary btn-sm active px-2">' + p + '</button>';
            } else {
                pagHtml += '<button class="btn btn-outline-secondary btn-sm px-2" onclick="goToSearchPageDirect(' + p + ')">' + p + '</button>';
            }
        }
        if (e < maxLoaded - 1) pagHtml += '<span class="text-muted small px-1">…</span>';
        if (e < maxLoaded) pagHtml += '<button class="btn btn-outline-secondary btn-sm px-2" onclick="goToSearchPageDirect(' + maxLoaded + ')">' + maxLoaded + '</button>';
    }

    pagHtml += '<button class="btn btn-outline-primary btn-sm" onclick="doExSearchNext()"' + (!_hasNext ? ' disabled' : '') + ' title="下一页"><i class="fas fa-chevron-right"></i></button>';
    pagHtml += '<button class="btn btn-outline-secondary btn-sm" onclick="goToSearchLast()"' + (!_prevCursor ? ' disabled' : '') + ' title="末页"><i class="fas fa-angle-double-right"></i></button>';

    pagHtml += '</div>';

    // ─── Pagination row 2: range jumps ───
    var rangeSteps = [0, 20, 40, 60, 80];
    pagHtml += '<div class="d-flex justify-content-center align-items-center gap-1 flex-wrap">';
    pagHtml += '<span class="text-muted small me-1">跳转:</span>';
    for (var i = 0; i < rangeSteps.length; i++) {
        (function(r) {
            pagHtml += '<button class="btn btn-outline-info btn-sm px-2" onclick="goToSearchRange(' + r + ')">' + r + '%</button>';
        })(rangeSteps[i]);
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
