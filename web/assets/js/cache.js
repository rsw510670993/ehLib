// ─── Local Cache Index ───
let _cacheResults = [];
let _cachePage = 1;
let _cachePerPage = 25;
let _cacheTotal = 0;
let _selectedIds = new Set();
var CAT_COLORS = {
    'Doujinshi': '#e74c3c', 'Manga': '#3498db', 'Artist CG': '#9b59b6',
    'Game CG': '#e67e22', 'Western': '#27ae60', 'Non-H': '#95a5a6',
    'Image Set': '#1abc9c', 'Cosplay': '#e91e63', 'Asian Porn': '#795548',
    'Misc': '#607d8b'
};

// ─── Category toggles (local cache) ───────────────────────

function cacheToggleCategory(el) {
    var allBtn = document.querySelector('#cache_category_tags .cat-tag[data-cat="all"]');
    if (el.dataset.cat === 'all') {
        var allActive = allBtn.classList.contains('active');
        document.querySelectorAll('#cache_category_tags .cat-tag').forEach(function(t) {
            t.classList.toggle('active', !allActive);
        });
    } else {
        el.classList.toggle('active');
        var allTags = document.querySelectorAll('#cache_category_tags .cat-tag[data-cat]:not([data-cat="all"])');
        var activeTags = document.querySelectorAll('#cache_category_tags .cat-tag.active[data-cat]:not([data-cat="all"])');
        if (activeTags.length === allTags.length) {
            allBtn.classList.add('active');
        } else {
            allBtn.classList.remove('active');
        }
    }
    cacheSearch();
}

function getCacheSelectedCategories() {
    var allBtn = document.querySelector('#cache_category_tags .cat-tag[data-cat="all"]');
    if (allBtn && allBtn.classList.contains('active')) return null;
    var cats = [];
    document.querySelectorAll('#cache_category_tags .cat-tag.active[data-cat]').forEach(function(t) {
        if (t.dataset.cat !== 'all') cats.push(t.dataset.cat);
    });
    return cats.length > 0 ? cats : null;
}

function resetCacheCategories() {
    document.querySelectorAll('#cache_category_tags .cat-tag').forEach(function(t) { t.classList.add('active'); });
}

// ─── Local cache listing ─────────────────────────────────

async function loadCachePage(page) {
    _cachePage = page || 1;
    checkDownloadProgress();
    const body = document.getElementById('cache_grid_body');
    body.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';

    var keyword = document.getElementById('cache_keyword').value.trim();
    var cats = getCacheSelectedCategories();

    let params = '?action=cache_search&page=' + _cachePage + '&per_page=' + _cachePerPage;
    if (keyword) params += '&title=' + encodeURIComponent(keyword);
    if (cats) params += '&categories=' + encodeURIComponent(cats.join(','));

    const resp = await fetch(API + params);
    const data = await resp.json();

    if (!data.ok) {
        body.innerHTML = '<div class="text-center text-danger py-5">' + escapeHtml(data.error || '加载失败') + '</div>';
        return;
    }

    _cacheResults = data.results || [];
    _cacheTotal = data.total || 0;
    renderCacheGrid();
    renderCachePagination();
    loadSearchPresets();
}

function renderCacheGrid() {
    const body = document.getElementById('cache_grid_body');
    if (!_cacheResults || _cacheResults.length === 0) {
        body.innerHTML = '<div class="text-center text-muted py-5">暂无缓存数据。点「后台爬取」填充缓存。</div>';
        return;
    }

    var hasSel = _selectedIds.size > 0;
    var allSel = _cacheResults.length > 0 && _selectedIds.size === _cacheResults.length;
    var toolbar = '<div id="cache_batch_bar" class="d-flex align-items-center gap-2 mb-2 py-1 px-2 bg-light rounded">' +
        '<input class="form-check-input mt-0" type="checkbox" onchange="selectAllCache(this.checked)" ' + (allSel ? 'checked' : '') + ' title="全选/取消">' +
        '<span class="small text-muted me-1" id="batch_count">' + (hasSel ? '已选 ' + _selectedIds.size + '/' + _cacheResults.length + ' 项' : '未选中') + '</span>' +
        '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="batchDeleteCache()" title="批量删除"' + (hasSel ? '' : ' disabled') + '><i class="fas fa-trash-alt me-1"></i>删除选中</button>' +
        '<button class="btn btn-sm btn-outline-secondary py-0 px-2' + (hasSel ? '' : ' d-none') + '" onclick="clearCacheSelection()">取消选择</button>' +
        '</div>';

    body.innerHTML = toolbar + '<div class="gallery-flex-grid" id="cache_grid">' +
        _cacheResults.map(function(g) {
            var displayTitle = g.title || '(无标题)';
            var catColor = CAT_COLORS[g.category] || '#6c757d';
            var catBadge = g.category ? '<span class="badge" style="background:' + catColor + ';font-size:.65rem">' + escapeHtml(g.category) + '</span>' : '';
            var langColor = { 'japanese': '#6b7280', 'chinese': '#dc3545', 'english': '#0d6efd', 'thai': '#198754' };
            var lang = g.language || '';
            var langBadge = lang ? '<span class="lang-badge" style="color:' + (langColor[lang.toLowerCase()] || '#6b7280') + ';border-color:' + (langColor[lang.toLowerCase()] || '#6b7280') + '">' + escapeHtml(lang) + '</span>' : '';
            var statusText = g.is_local ? '已下载' : '未下载';
            var statusClass = g.is_local ? 'text-success' : 'text-muted';
            var statusIcon = g.is_local ? 'fa-check-circle' : 'fa-circle';
            var thumbHtml = g.thumb_url
                ? '<img src="' + g.thumb_url + '" class="card-img-top" alt="cover" loading="lazy" style="aspect-ratio:3/4;object-fit:cover" onerror="this.style.display=\'none\'">'
                : '<div class="placeholder-thumb" style="aspect-ratio:3/4;background:#f0f0f0;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:2rem"><i class="far fa-image"></i></div>';
            var source_id = g.source_id || '';
            var escapedSid = escapeAttr(source_id);
            var downloadBtn = g.is_local
                ? '<button class="btn btn-sm btn-outline-secondary py-0 px-1" disabled title="已下载"><i class="fas fa-check"></i></button>'
                : '<button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="cacheDownloadSingle(\'' + escapedSid + '\')" title="下载"><i class="fas fa-download"></i></button>';
            return '<div>' +
                '<div class="card gallery-card">' +
                '<div class="card-img-wrapper" style="aspect-ratio:3/4;overflow:hidden;background:#f0f0f0;cursor:pointer" onclick="toggleCacheSelect(\'' + escapedSid + '\',null,event)">' +
                '<div class="card-checkbox"><input type="checkbox" class="form-check-input" onchange="toggleCacheSelect(\'' + escapedSid + '\',this.checked,event)" ' + (_selectedIds.has(source_id) ? 'checked' : '') + '></div>' +
                thumbHtml +
                '<div class="delete-overlay"><button class="btn btn-sm btn-dark py-0 px-1" style="font-size:.7rem;line-height:1.4" onclick="event.stopPropagation();cacheDeleteItem(\'' + escapedSid + '\')" title="删除缓存"><i class="fas fa-trash-alt"></i></button></div>' +
                '</div>' +
                '<div class="card-body px-2 py-1">' +
                '<div class="small title-clamp" title="' + escapeAttr(displayTitle) + '">' + escapeHtml(displayTitle) + '</div>' +
                '<div class="d-flex justify-content-between align-items-center gap-1" style="margin-top:2px">' +
                '<span>' + catBadge + '</span>' +
                '<span>' + langBadge + '</span>' +
                '<span class="small ' + statusClass + '"><i class="fas ' + statusIcon + ' me-1"></i>' + statusText + '</span>' +
                '</div>' +
                '<div class="d-flex justify-content-between align-items-center" style="margin-top:2px">' +
                '<span class="small text-muted">' + (g.total_pages || 0) + 'p</span>' +
                downloadBtn +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }).join('') +
        '</div>';
}

function renderCachePagination() {
    const el = document.getElementById('cache_pagination');
    if (!el) return;
    var totalPages = Math.ceil(_cacheTotal / _cachePerPage);
    if (totalPages <= 1) { el.innerHTML = ''; return; }
    var html = '<div class="d-flex flex-wrap align-items-center justify-content-center gap-3">';
    html += '<nav aria-label="Cache pagination"><ul class="pagination pagination-sm mb-0">';
    html += '<li class="page-item' + (_cachePage <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" onclick="event.preventDefault();loadCachePage(' + (_cachePage - 1) + ')">&laquo;</a></li>';
    var start = Math.max(1, _cachePage - 2);
    var end = Math.min(totalPages, _cachePage + 2);
    if (start > 1) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault();loadCachePage(1)">1</a></li>';
        if (start > 2) html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
    }
    for (var i = start; i <= end; i++) {
        html += '<li class="page-item' + (i === _cachePage ? ' active' : '') + '"><a class="page-link" href="#" onclick="event.preventDefault();loadCachePage(' + i + ')">' + i + '</a></li>';
    }
    if (end < totalPages) {
        if (end < totalPages - 1) html += '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        html += '<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault();loadCachePage(' + totalPages + ')">' + totalPages + '</a></li>';
    }
    html += '<li class="page-item' + (_cachePage >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" onclick="event.preventDefault();loadCachePage(' + (_cachePage + 1) + ')">&raquo;</a></li>';
    html += '</ul></nav>';
    html += '<span class="text-muted small">共 ' + _cacheTotal + ' 条</span></div>';
    el.innerHTML = html;
}

function cacheSearch() {
    _cachePage = 1;
    loadCachePage(1);
}

function cacheClearFilter() {
    document.getElementById('cache_keyword').value = '';
    resetCacheCategories();
    _cachePage = 1;
    loadCachePage(1);
}

// ─── Crawl dialog category toggles ────────────────────────

// ─── Crawl action ─────────────────────────────────────────

async function startCrawl() {
    var query = document.getElementById('crawl_keyword').value.trim();
    if (!query) { showToast('请输入爬取关键词', 'warning'); return; }
    var force = document.getElementById('crawl_force').classList.contains('active');
    var allBtn = document.querySelector('#cache_category_tags .cat-tag[data-cat="all"]');
    var categories = (allBtn && allBtn.classList.contains('active')) ? 'all' : [];
    if (categories !== 'all') {
        document.querySelectorAll('#cache_category_tags .cat-tag.active[data-cat]').forEach(function(t) {
            if (t.dataset.cat !== 'all') categories.push(t.dataset.cat);
        });
        categories = categories.join(',');
    }
    var form = { action: 'crawl', source: 'exhentai', query: query, force: force ? '1' : '' };
    if (categories) form.categories = categories;
    showToast('正在启动爬取任务...', 'info');
    var res = await api('crawl', { form: form });
    if (res.ok) {
        showToast('爬取任务已启动，在底部进度栏查看进度', 'success');
        _crawlActive = true;
        _crawlEverSeen = false;
        startProgressPoller();
    } else if (res.error && res.error.indexOf('已有爬取任务') !== -1) {
        showToast(res.error, 'warning');
    } else {
        showToast(res.error || '启动失败', 'danger');
    }
}

// ─── Single download from cache ───────────────────────────

async function cacheDownloadSingle(sid) {
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
    if (res.ok) {
        showToast('下载成功!', 'success');
        await loadCachePage(_cachePage);
    }
}

async function cacheDeleteItem(sid) {
    if (!sid) return;
    if (!await confirmDialog({ title: '删除缓存记录', message: '确定删除这条缓存记录吗？', detail: '仅删除缓存索引，不影响已下载的文件。' })) return;
    var res = await api('delete_cache', { form: { action: 'delete_cache', source: 'exhentai', source_id: sid } });
    if (res.ok) {
        showToast('缓存已删除', 'success');
        await loadCachePage(_cachePage);
    } else {
        showToast(res.error || '删除失败', 'danger');
    }
}

// ─── Saved Search Presets ─────────────────────────────────

async function saveSearchPreset() {
    var keyword = document.getElementById('crawl_keyword').value.trim();
    var cats = getCacheSelectedCategories();
    var force = document.getElementById('crawl_force').classList.contains('active');
    var name = await promptDialog({ title: '保存检索条件', message: '为当前检索条件命名：', defaultValue: keyword || '未命名' });
    if (!name) return;
    var res = await api('save_search_preset', {
        form: {
            action: 'save_search_preset',
            name: name,
            keyword: keyword,
            categories: cats ? JSON.stringify(cats) : '',
            force: force ? '1' : '',
        }
    });
    if (res.ok) {
        showToast('检索条件已保存', 'success');
        await loadSearchPresets();
    } else {
        showToast(res.error || '保存失败', 'danger');
    }
}

async function loadSearchPresets() {
    var row = document.getElementById('saved_presets_row');
    if (!row) return;
    var res = await api('list_search_presets', { params: { action: 'list_search_presets' } });
    if (!res.ok || !res.presets || res.presets.length === 0) {
        row.innerHTML = '';
        return;
    }
    var html = '<div class="d-inline-flex flex-wrap gap-1 align-items-center">';
    res.presets.forEach(function(p) {
        var cats = p.categories || '';
        var force = p.force_crawl ? ' (强制)' : '';
        var detail = p.keyword;
        if (cats) detail += ' | ' + cats;
        html += '<span class="saved-search-tag" onclick="applySearchPreset(\'' + escapeAttr(p.name) + '\')" title="' + escapeAttr(detail) + '">' +
            '<i class="far fa-bookmark me-1" style="font-size:.65rem"></i>' + escapeHtml(p.name) + force +
            '<span class="saved-search-del" onclick="event.stopPropagation();deleteSearchPreset(' + p.id + ')" title="删除">&times;</span>' +
            '</span>';
    });
    html += '</div>';
    row.innerHTML = html;
}

async function applySearchPreset(name) {
    var res = await api('list_search_presets', { params: { action: 'list_search_presets' } });
    if (!res.ok || !res.presets) return;
    var preset = res.presets.find(function(p) { return p.name === name; });
    if (!preset) { showToast('未找到该预设', 'warning'); return; }
    document.getElementById('crawl_keyword').value = preset.keyword || '';
    document.getElementById('crawl_force').classList.toggle('active', !!preset.force_crawl);
    var cats = preset.categories ? preset.categories.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s; }) : [];
    document.querySelectorAll('#cache_category_tags .cat-tag').forEach(function(t) { t.classList.remove('active'); });
    if (cats.length === 0) {
        document.querySelectorAll('#cache_category_tags .cat-tag').forEach(function(t) { t.classList.add('active'); });
    } else {
        cats.forEach(function(c) {
            var btn = document.querySelector('#cache_category_tags .cat-tag[data-cat="' + c + '"]');
            if (btn) btn.classList.add('active');
        });
        var allBtn = document.querySelector('#cache_category_tags .cat-tag[data-cat="all"]');
        if (allBtn) {
            var allTags = document.querySelectorAll('#cache_category_tags .cat-tag[data-cat]:not([data-cat="all"])');
            var activeTags = document.querySelectorAll('#cache_category_tags .cat-tag.active[data-cat]:not([data-cat="all"])');
            if (activeTags.length === allTags.length) allBtn.classList.add('active');
        }
    }
}

async function deleteSearchPreset(id) {
    if (!await confirmDialog({ title: '删除预设', message: '确定删除这个检索条件预设吗？' })) return;
    var res = await api('delete_search_preset', { form: { action: 'delete_search_preset', id: id } });
    if (res.ok) {
        showToast('已删除', 'success');
        await loadSearchPresets();
    } else {
        showToast(res.error || '删除失败', 'danger');
    }
}

// ─── Batch Delete ────────────────────────────────────────────

function toggleCacheSelect(sid, checked, e) {
    if (e) e.stopPropagation();
    if (checked === null) {
        var cb = e.currentTarget.querySelector('.form-check-input');
        if (cb) { cb.checked = !cb.checked; checked = cb.checked; }
        else return;
    }
    if (checked) _selectedIds.add(sid);
    else _selectedIds.delete(sid);
    updateBatchBar();
}

function selectAllCache(checked) {
    _selectedIds.clear();
    if (checked) _cacheResults.forEach(function(g) { _selectedIds.add(g.source_id); });
    updateBatchBar();
    renderCacheGrid();
}

function clearCacheSelection() {
    _selectedIds.clear();
    updateBatchBar();
    renderCacheGrid();
}

function updateBatchBar() {
    var bar = document.getElementById('cache_batch_bar');
    if (!bar) return;
    var hasSel = _selectedIds.size > 0;
    var allSelected = _cacheResults.length > 0 && _selectedIds.size === _cacheResults.length;
    var countEl = document.getElementById('batch_count');
    var delBtn = bar.querySelector('.btn-outline-danger');
    var cancelBtn = bar.querySelector('.btn-outline-secondary');
    var selectAll = bar.querySelector('.form-check-input');
    if (countEl) countEl.textContent = hasSel ? '已选 ' + _selectedIds.size + '/' + _cacheResults.length + ' 项' : '未选中';
    if (delBtn) delBtn.disabled = !hasSel;
    if (cancelBtn) cancelBtn.classList.toggle('d-none', !hasSel);
    if (selectAll) selectAll.checked = allSelected;
}

async function batchDeleteCache() {
    if (_selectedIds.size === 0) { showToast('请先选择项目', 'warning'); return; }
    if (!await confirmDialog({ title: '批量删除', message: '确定删除选中的 ' + _selectedIds.size + ' 条缓存记录吗？', detail: '仅删除缓存索引，不影响已下载的文件。' })) return;
    var ids = Array.from(_selectedIds);
    var res = await api('batch_delete_cache', { form: { action: 'batch_delete_cache', ids: JSON.stringify(ids) } });
    if (res.ok) {
        showToast('已删除 ' + (res.deleted || ids.length) + ' 条记录', 'success');
        _selectedIds.clear();
        await loadCachePage(_cachePage);
    } else {
        showToast(res.error || '删除失败', 'danger');
    }
}