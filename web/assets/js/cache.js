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
    // 显式触发：移除自动 cacheSearch()，等用户按"筛选"按钮或 Enter（符合 project_memory 偏好显式触发而非自动触发）
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

// ─── Category toggles (crawl) ─────────────────────────────

function crawlToggleCategory(el) {
    var allBtn = document.querySelector('#crawl_category_tags .cat-tag[data-cat="all"]');
    if (el.dataset.cat === 'all') {
        var allActive = allBtn.classList.contains('active');
        document.querySelectorAll('#crawl_category_tags .cat-tag').forEach(function(t) {
            t.classList.toggle('active', !allActive);
        });
    } else {
        el.classList.toggle('active');
        var allTags = document.querySelectorAll('#crawl_category_tags .cat-tag[data-cat]:not([data-cat="all"])');
        var activeTags = document.querySelectorAll('#crawl_category_tags .cat-tag.active[data-cat]:not([data-cat="all"])');
        if (activeTags.length === allTags.length) {
            allBtn.classList.add('active');
        } else {
            allBtn.classList.remove('active');
        }
    }
}

function getCrawlSelectedCategories() {
    var allBtn = document.querySelector('#crawl_category_tags .cat-tag[data-cat="all"]');
    if (allBtn && allBtn.classList.contains('active')) return null;
    var cats = [];
    document.querySelectorAll('#crawl_category_tags .cat-tag.active[data-cat]').forEach(function(t) {
        if (t.dataset.cat !== 'all') cats.push(t.dataset.cat);
    });
    return cats.length > 0 ? cats : null;
}

// 将分类列表应用到爬取标签组（书签恢复用）
function _applyCrawlCategoryTags(cats) {
    var sel = '#crawl_category_tags';
    document.querySelectorAll(sel + ' .cat-tag').forEach(function(t) { t.classList.remove('active'); });
    if (!cats || cats.length === 0) {
        document.querySelectorAll(sel + ' .cat-tag').forEach(function(t) { t.classList.add('active'); });
        return;
    }
    cats.forEach(function(c) {
        var btn = document.querySelector(sel + ' .cat-tag[data-cat="' + c + '"]');
        if (btn) btn.classList.add('active');
    });
    var allBtn = document.querySelector(sel + ' .cat-tag[data-cat="all"]');
    var allTags = document.querySelectorAll(sel + ' .cat-tag[data-cat]:not([data-cat="all"])');
    var activeTags = document.querySelectorAll(sel + ' .cat-tag.active[data-cat]:not([data-cat="all"])');
    if (allBtn && activeTags.length === allTags.length) allBtn.classList.add('active');
}

// ─── Language tags (shared builder) ───────────────────────
// 常用语种固定显示在折叠栏外（speechless 在外，english 收进折叠栏）
var LANG_PRIORITY = ['japanese', 'chinese', 'speechless', 'text cleaned'];
// 默认选中：中日+speechless+text cleaned
var LANG_DEFAULTS = ['japanese', 'chinese', 'speechless', 'text cleaned'];

async function _fetchSiteLanguages() {
    try {
        var resp = await fetch(API + '?action=cache_languages&source=exhentai');
        var data = await resp.json();
        if (!data.ok || !data.languages) return null;
        return data.languages.filter(function(l) { return l; });
    } catch (e) {
        return null;
    }
}

function toggleLanguageCollapse(btn) {
    var content = btn.parentElement.querySelector('.language-collapse-content');
    var collapsed = content.classList.toggle('collapsed');
    btn.textContent = btn.dataset.label + (collapsed ? ' ▸' : ' ▾');
}

// 渲染一组语言标签：常用语种在折叠栏外，其余进折叠栏；点亮状态以 stateSet 为准
function _buildLanguageTags(container, langs, stateSet, onToggle) {
    var work = (langs || []).slice();
    LANG_PRIORITY.forEach(function(p) {
        var idx = work.indexOf(p);
        if (idx !== -1) work.splice(idx, 1);
    });
    work.sort();
    container.innerHTML = '';

    var allBtn = document.createElement('button');
    allBtn.className = 'cat-tag';
    allBtn.dataset.lang = 'all';
    allBtn.style.setProperty('--cat-color', '#0d6efd');
    allBtn.textContent = '全部';
    allBtn.onclick = function() { onToggle(this); };
    container.appendChild(allBtn);

    LANG_PRIORITY.forEach(function(l) {
        var btn = document.createElement('button');
        btn.className = 'cat-tag' + (stateSet.has(l) ? ' active' : '');
        btn.dataset.lang = l;
        btn.style.setProperty('--cat-color', '#6b7280');
        btn.textContent = l;
        btn.onclick = function() { onToggle(this); };
        container.appendChild(btn);
    });

    if (work.length > 0) {
        var wrapper = document.createElement('div');
        wrapper.className = 'language-collapse-wrapper';

        var toggleBtn = document.createElement('button');
        toggleBtn.className = 'cat-tag language-collapse-toggle';
        toggleBtn.dataset.label = '其他语言 (' + work.length + ')';
        toggleBtn.textContent = toggleBtn.dataset.label + ' ▸';
        toggleBtn.onclick = function() { toggleLanguageCollapse(this); };
        wrapper.appendChild(toggleBtn);

        var content = document.createElement('div');
        content.className = 'language-collapse-content collapsed';
        work.forEach(function(l) {
            var btn = document.createElement('button');
            btn.className = 'cat-tag' + (stateSet.has(l) ? ' active' : '');
            btn.dataset.lang = l;
            btn.style.setProperty('--cat-color', '#6b7280');
            btn.textContent = l;
            btn.onclick = function() { onToggle(this); };
            content.appendChild(btn);
        });
        wrapper.appendChild(content);
        container.appendChild(wrapper);
    }
}

// 同步「全部」按钮点亮状态
function _syncLanguageAllState(container) {
    var allBtn = container.querySelector('.cat-tag[data-lang="all"]');
    if (!allBtn) return;
    var allTags = container.querySelectorAll('.cat-tag[data-lang]:not([data-lang="all"])');
    var activeTags = container.querySelectorAll('.cat-tag.active[data-lang]:not([data-lang="all"])');
    allBtn.classList.toggle('active', allTags.length > 0 && activeTags.length === allTags.length);
}

function _toggleLanguageTag(el, container, stateSet) {
    if (el.dataset.lang === 'all') {
        var allActive = el.classList.contains('active');
        stateSet.clear();
        container.querySelectorAll('.cat-tag[data-lang]').forEach(function(t) {
            t.classList.toggle('active', !allActive);
            if (!allActive && t.dataset.lang !== 'all') stateSet.add(t.dataset.lang);
        });
    } else {
        el.classList.toggle('active');
        if (el.classList.contains('active')) stateSet.add(el.dataset.lang);
        else stateSet.delete(el.dataset.lang);
        _syncLanguageAllState(container);
    }
}

// 重置为默认语种（中日+speechless+text cleaned）并同步 DOM
function _resetLanguageTags(container, stateSet) {
    stateSet.clear();
    LANG_DEFAULTS.forEach(function(l) { stateSet.add(l); });
    container.querySelectorAll('.cat-tag[data-lang]').forEach(function(t) {
        t.classList.toggle('active', t.dataset.lang !== 'all' && stateSet.has(t.dataset.lang));
    });
    _syncLanguageAllState(container);
}

// 将给定语种列表应用到标签组（书签恢复用）
function _applyLanguageTags(container, stateSet, langs) {
    stateSet.clear();
    (langs || []).forEach(function(l) { stateSet.add(l); });
    container.querySelectorAll('.cat-tag[data-lang]').forEach(function(t) {
        t.classList.toggle('active', t.dataset.lang !== 'all' && stateSet.has(t.dataset.lang));
    });
    _syncLanguageAllState(container);
}

// ─── Language toggle (local cache) ────────────────────────

let _cacheLanguages = new Set(LANG_DEFAULTS);

function cacheToggleLanguage(el) {
    _toggleLanguageTag(el, document.getElementById('cache_language_tags'), _cacheLanguages);
    // 显式触发：移除自动 cacheSearch()，等用户按"筛选"按钮或 Enter
}

function getCacheSelectedLanguages() {
    if (_cacheLanguages.size === 0) return null;
    return Array.from(_cacheLanguages);
}

function resetCacheLanguage() {
    _resetLanguageTags(document.getElementById('cache_language_tags'), _cacheLanguages);
}

async function loadCacheLanguages() {
    // 默认中日+speechless+text cleaned 在 fetch 前就设置好，loadCachePage 能立刻生效
    _cacheLanguages = new Set(LANG_DEFAULTS);
    var langs = await _fetchSiteLanguages();
    _buildLanguageTags(document.getElementById('cache_language_tags'), langs, _cacheLanguages, cacheToggleLanguage);
}

// ─── Language toggle (crawl) ──────────────────────────────

let _crawlLanguages = new Set(LANG_DEFAULTS);

function crawlToggleLanguage(el) {
    _toggleLanguageTag(el, document.getElementById('crawl_language_tags'), _crawlLanguages);
}

function getCrawlSelectedLanguages() {
    if (_crawlLanguages.size === 0) return null;
    return Array.from(_crawlLanguages);
}

async function loadCrawlLanguages() {
    _crawlLanguages = new Set(LANG_DEFAULTS);
    var langs = await _fetchSiteLanguages();
    _buildLanguageTags(document.getElementById('crawl_language_tags'), langs, _crawlLanguages, crawlToggleLanguage);
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
    var scope = getCacheSearchScope();
    if (scope !== 'all') params += '&search_fields=' + encodeURIComponent(scope);
    if (cats) params += '&categories=' + encodeURIComponent(cats.join(','));
    if (_cacheLanguages.size > 0) params += '&language=' + encodeURIComponent(getCacheSelectedLanguages().join(','));

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

function renderCacheBatchBar() {
    var hasSel = _selectedIds.size > 0;
    var allSel = _cacheResults.length > 0 && _selectedIds.size === _cacheResults.length;
    var bar = document.getElementById('cache_batch_bar');
    if (!bar) return;
    var dlDisabled = hasSel ? '' : ' disabled';
    bar.innerHTML =
        '<div class="d-flex align-items-center gap-2 py-1 px-2 bg-light rounded">' +
        '<input class="form-check-input mt-0" type="checkbox" onchange="selectAllCache(this.checked)" ' + (allSel ? 'checked' : '') + ' title="全选/取消">' +
        '<span class="small text-muted me-1" id="batch_count">' + (hasSel ? '已选 ' + _selectedIds.size + '/' + _cacheResults.length + ' 项' : '未选中') + '</span>' +
        '<button class="btn btn-sm btn-outline-primary py-0 px-2" onclick="cacheBatchDownload()" title="批量下载"' + dlDisabled + '><i class="fas fa-download me-1"></i>批量下载</button>' +
        '<button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="batchDeleteCache()" title="批量删除"' + dlDisabled + '><i class="fas fa-trash-alt me-1"></i>删除选中</button>' +
        '<button class="btn btn-sm btn-outline-secondary py-0 px-2' + (hasSel ? '' : ' d-none') + '" onclick="clearCacheSelection()">取消选择</button>' +
        '</div>';
}

function normalizeTitle(title) {
    return String(title || '').replace(/\s+/g, ' ').trim();
}
var normalizeCacheTitle = normalizeTitle;

// ─── Tooling: ZWSP 强制软换行（Chromium -webkit-box + CJK 不换行老坑）───
// 在 ① CJK 字符之间 / ② ASCII 与 CJK 相变点 / ③ 连续 8 个以上 ASCII 字母数字之间
// 插入 U+200B ZERO WIDTH SPACE，保证 -webkit-box 下 word-break:break-all 能真正断行
function _zwspWrap(s) {
    if (!s) return '';
    var zwsp = '\u200b';
    var out = '';
    var run = 0;
    var prevC = '';
    var prevType = 0; // 0=none, 1=CJK(汉/日/韩/符号), 2=ASCII 字母数字
    function _type(c) {
        var code = c.charCodeAt(0);
        if (!c) return 0;
        if (code <= 0x20) return -1;
        if ((code >= 0x30 && code <= 0x39) || (code >= 0x41 && code <= 0x5a) || (code >= 0x61 && code <= 0x7a)) return 2;
        if (code < 0x80) return -1;
        return 1;
    }
    for (var i = 0; i < s.length; i++) {
        var ch = s.charAt(i);
        var t = _type(ch);
        if (t !== -1) {
            if (prevType !== 0 && prevC && ((t === 1 && prevType === 1) || (t !== prevType))) {
                out += zwsp;
                run = 0;
            } else if (t === 2 && prevType === 2) {
                run++;
                if (run >= 8) { out += zwsp; run = 0; }
            }
            prevType = t;
            prevC = ch;
        } else {
            run = 0; prevType = 0; prevC = '';
        }
        out += ch;
    }
    return out;
}

function getGalleryDisplayTitles(gallery) {
    var original = normalizeTitle(gallery.title);
    var japanese = normalizeTitle(gallery.title_jp);
    return {
        primary: japanese || original || '(无标题)',
        secondary: ''
    };
}
var getCacheDisplayTitles = getGalleryDisplayTitles;

function renderCacheGrid() {
    const body = document.getElementById('cache_grid_body');
    if (!_cacheResults || _cacheResults.length === 0) {
        body.innerHTML = '<div class="text-center text-muted py-5">暂无缓存数据。点「后台爬取」填充缓存。</div>';
        renderCacheBatchBar();
        return;
    }

    renderCacheBatchBar();
    body.innerHTML = '<div class="gallery-flex-grid" id="cache_grid">' +
        _cacheResults.map(function(g, idx) {
            var displayTitles = getGalleryDisplayTitles(g);
            var catColor = CAT_COLORS[g.category] || '#6c757d';
            var catBadge = g.category ? '<span class="badge" style="background:' + catColor + ';font-size:.65rem">' + escapeHtml(g.category) + '</span>' : '';
            var langColor = { 'japanese': '#0dcaf0', 'chinese': '#dc3545' };
            var lang = g.language || '';
            var langBadge = lang ? '<span class="lang-badge" style="color:' + (langColor[lang.toLowerCase()] || '#6b7280') + '">' + escapeHtml(lang) + '</span>' : '';
            var thumbHtml = g.thumb_url
                ? '<img src="' + g.thumb_url + '" class="card-img-top" alt="cover" loading="lazy" style="aspect-ratio:3/4;object-fit:cover" onerror="this.style.display=\'none\'" onload="onCoverLoad(this)">'
                : '<div class="placeholder-thumb" style="aspect-ratio:3/4;background:#f0f0f0;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:2rem"><i class="far fa-image"></i></div>';
            var source_id = g.source_id || '';
            var escapedSid = escapeAttr(source_id);
            var downloadBtn = g.is_local
                ? '<button class="btn btn-sm btn-outline-success py-0 px-1" onclick="openReader(\'' + escapeAttr(g.source) + '\',\'' + escapedSid + '\')" title="本地阅览"><i class="fas fa-book-open"></i></button>'
                : '<button class="btn btn-sm btn-outline-primary py-0 px-1" onclick="cacheDownloadSingle(\'' + escapedSid + '\')" title="下载"><i class="fas fa-download"></i></button>';
            var sourceBtn = '<a class="btn btn-sm btn-outline-secondary py-0 px-1" href="https://exhentai.org/g/' + escapedSid + '/" target="_blank" rel="noopener noreferrer" title="在 ExHentai 打开"><i class="fas fa-arrow-up-right-from-square"></i></a>';
            return '<div>' +
                '<div class="card gallery-card">' +
                '<div class="card-img-wrapper" style="aspect-ratio:3/4;overflow:hidden;background:#f0f0f0;cursor:pointer" onclick="toggleCacheSelect(\'' + escapedSid + '\',null,event)">' +
                '<div class="card-checkbox"><input type="checkbox" class="form-check-input" onchange="toggleCacheSelect(\'' + escapedSid + '\',this.checked,event)" ' + (_selectedIds.has(source_id) ? 'checked' : '') + '></div>' +
                thumbHtml +
                '<div class="delete-overlay"><button class="btn btn-sm btn-dark py-0 px-1" style="font-size:.7rem;line-height:1.4" onclick="event.stopPropagation();cacheDeleteItem(\'' + escapedSid + '\')" title="删除缓存"><i class="fas fa-trash-alt"></i></button></div>' +
                '</div>' +
                '<div class="card-body px-2 py-1 card-info-body">' +
                // 第 1~3 行：中文标题 + 日语标题，合占严格 3 行（主最多 2 行 / 副 1 行；无副时主占满 3 行）
                // ZWSP 注入保证 -webkit-box 容器下 CJK/假名/罗马字序列能真正换行
                '<div class="title-double-clamp" style="cursor:pointer" title="' + escapeAttr(displayTitles.primary + (displayTitles.secondary ? '\n' + displayTitles.secondary : '')) + '" onclick="showCacheDetail(' + idx + ')">' +
                '<div class="title-primary" style="color:var(--bs-link-color)">' + _zwspWrap(escapeHtml(displayTitles.primary)) + '</div>' +
                (displayTitles.secondary ? '<div class="title-secondary text-muted">' + _zwspWrap(escapeHtml(displayTitles.secondary)) + '</div>' : '') +
                '</div>' +
                // 第 4 行（左对齐）：分类 badge + 语种 badge + 页数
                '<div class="d-flex align-items-center gap-1 card-info-row card-row-top">' +
                catBadge +
                langBadge +
                '<span class="small text-muted">' + (g.total_pages || 0) + 'p</span>' +
                '</div>' +
                // 第 5 行（右对齐）：本地阅览 / 下载 / ExHentai 外链
                '<div class="d-flex align-items-center gap-1 card-info-row card-row-bottom">' +
                '<span class="d-inline-flex gap-1">' + downloadBtn + sourceBtn + '</span>' +
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
    html += '<span class="text-muted small me-2">共 ' + _cacheTotal + ' 条</span>';
    html += '<div class="input-group input-group-sm" style="width:150px"><span class="input-group-text">跳转</span>' +
        '<input type="number" class="form-control" id="cache_page_jump" value="' + _cachePage + '" min="1" max="' + totalPages + '" onkeydown="if(event.key===\'Enter\')cacheJumpPage(' + totalPages + ')">' +
        '<button type="button" class="btn btn-outline-primary" onclick="cacheJumpPage(' + totalPages + ')">确定</button>' +
        '</div>';
    html += '</div>';
    el.innerHTML = html;
}

function cacheJumpPage(totalPages) {
    var input = document.getElementById('cache_page_jump');
    var page = input ? parseInt(input.value, 10) : 0;
    if (page >= 1 && page <= totalPages) loadCachePage(page);
}


function cacheSearch() {
    _cachePage = 1;
    loadCachePage(1);
}

// ─── Search scope toggles ─────────────────────────────

function getCacheSearchScope() {
    var el = document.getElementById('cache_search_scope');
    if (!el) return 'all';
    return (el.value || 'all').trim() || 'all';
}

function resetSearchScope() {
    var el = document.getElementById('cache_search_scope');
    if (el) el.value = 'all';
}

function cacheClearFilter() {
    document.getElementById('cache_keyword').value = '';
    resetCacheCategories();
    resetCacheLanguage();
    resetSearchScope();
    _cachePage = 1;
    loadCachePage(1);
}

// ─── Crawl dialog category toggles ────────────────────────

async function loadCrawlQueue() {
    var el = document.getElementById('crawl_queue_list');
    if (!el) return;
    var res = await api('crawl_queue');
    if (!res.ok) {
        el.innerHTML = '<span class="text-danger">' + escapeHtml(res.error || '队列读取失败') + '</span>';
        return;
    }
    var jobs = res.jobs || [];
    if (!jobs.length) {
        el.innerHTML = '<span class="text-muted">暂无任务</span>';
        return;
    }
    var labels = { pending:'排队中', running:'执行中', cancel_requested:'停止中' };
    var colors = { pending:'warning', running:'primary', cancel_requested:'danger' };
    el.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
        '<thead><tr><th>顺序</th><th>状态</th><th>关键词</th><th>语言</th><th>创建时间</th><th></th></tr></thead><tbody>' +
        jobs.map(function(job, index) {
            var statusText = labels[job.status] || job.status;
            var canStop = job.status === 'pending' || job.status === 'running';
            return '<tr><td title="任务 #' + job.id + '">' + (index + 1) + '</td>' +
                '<td><span class="badge bg-' + (colors[job.status] || 'secondary') + '">' + escapeHtml(statusText) + '</span></td>' +
                '<td class="text-break">' + escapeHtml(job.query || '') + '</td>' +
                '<td class="text-break">' + escapeHtml(job.languages || '全部') + '</td>' +
                '<td class="text-nowrap">' + escapeHtml(job.created_at || '') + '</td>' +
                '<td>' + (canStop ? '<button class="btn btn-sm btn-outline-danger" onclick="stopCrawlJob(' + job.id + ')"><i class="fas fa-stop"></i></button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table></div>';
}

async function stopCrawlJob(jobId) {
    if (!await confirmDialog({ title: '停止爬取任务', message: '只停止任务 #' + jobId + '，队列中的其他任务会继续执行。', okText: '停止', okClass: 'btn-danger' })) return;
    var res = await api('stop_crawl', { form: { action: 'stop_crawl', job_id: jobId } });
    showToast(res.ok ? '停止请求已提交' : (res.error || '停止失败'), res.ok ? 'success' : 'danger');
    loadCrawlQueue();
}
// ─── Crawl action ─────────────────────────────────────────

async function startCrawl() {
    var query = document.getElementById('crawl_keyword').value.trim();
    if (!query) { showToast('请输入爬取关键词', 'warning'); return; }
    var force = document.getElementById('crawl_force').classList.contains('active');
    var allBtn = document.querySelector('#crawl_category_tags .cat-tag[data-cat="all"]');
    var categories = (allBtn && allBtn.classList.contains('active')) ? 'all' : [];
    if (categories !== 'all') {
        document.querySelectorAll('#crawl_category_tags .cat-tag.active[data-cat]').forEach(function(t) {
            if (t.dataset.cat !== 'all') categories.push(t.dataset.cat);
        });
        categories = categories.join(',');
    }
    var form = { action: 'crawl', source: 'exhentai', query: query, force: force ? '1' : '' };
    if (categories) form.categories = categories;
    var langs = getCrawlSelectedLanguages();
    if (langs && langs.length) form.languages = langs.join(',');
    showToast('正在启动爬取任务...', 'info');
    var res = await api('crawl', { form: form });
    if (res.ok) {
        showToast('爬取任务已加入队列，当前排队位置：' + (res.position || 1), 'success');
        loadCrawlQueue();
        _crawlActive = true;
        _crawlEverSeen = false;
        startProgressPoller();
    } else if (res.error && res.error.indexOf('已有爬取任务') !== -1) {
        showToast(res.error, 'warning');
    } else {
        showToast(res.error || '启动失败', 'danger');
    }
}

async function clearCrawlLog() {
    if (!await confirmDialog({ title: '清理工作文件', message: '确定清理所有工作文件吗？' })) return;
    var res = await api('clear_log', { form: { action: 'clear_log' } });
    showToast(res.ok ? '已清理' : (res.error || '清理失败'), res.ok ? 'success' : 'danger');
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
    var cats = getCrawlSelectedCategories();
    var langs = getCrawlSelectedLanguages();
    var force = document.getElementById('crawl_force').classList.contains('active');
    var defaultName = keyword || '未命名';
    if (keyword) {
        var translated = await api('translate_search_preset_name', {
            form: { action: 'translate_search_preset_name', query: keyword }
        });
        if (translated.ok && translated.name) defaultName = translated.name;
    }
    var name = await promptDialog({ title: '保存检索条件', message: '为当前检索条件命名：', defaultValue: defaultName });
    if (!name) return;
    var res = await api('save_search_preset', {
        form: {
            action: 'save_search_preset',
            name: name,
            keyword: keyword,
            categories: cats ? JSON.stringify(cats) : '',
            languages: langs ? JSON.stringify(langs) : '',
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

var _bookmarkCollapsed = false;

function toggleBookmarkCollapse() {
    _bookmarkCollapsed = !_bookmarkCollapsed;
    var row = document.getElementById('saved_presets_row');
    var icon = document.getElementById('bookmark_collapse_icon');
    if (_bookmarkCollapsed) {
        row.classList.add('collapsed');
        icon.className = 'fas fa-chevron-down';
    } else {
        row.classList.remove('collapsed');
        icon.className = 'fas fa-chevron-up';
    }
}

async function loadSearchPresets() {
    var row = document.getElementById('saved_presets_row');
    var count = document.getElementById('bookmark_count');
    if (!row) return;
    var res = await api('list_search_presets', { params: { action: 'list_search_presets' } });
    if (!res.ok || !res.presets || res.presets.length === 0) {
        row.innerHTML = '';
        if (count) count.textContent = '0';
        return;
    }
    if (count) count.textContent = res.presets.length;
    var html = '<div class="d-inline-flex flex-wrap gap-1 align-items-center">';
    res.presets.forEach(function(p) {
        var cats = p.categories || '';
        var force = p.force_crawl ? ' (强制)' : '';
        var detail = p.keyword;
        if (cats) detail += ' | ' + cats;
        if (p.languages) detail += ' | ' + p.languages;
        html += '<span class="saved-search-tag" onclick="applySearchPreset(\'' + escapeAttr(p.name) + '\')" title="' + escapeAttr(detail) + '">' +
            '<i class="far fa-bookmark me-1" style="font-size:.65rem"></i>' + escapeHtml(p.name) + force +
            '<span class="saved-search-del" onclick="event.stopPropagation();deleteSearchPreset(' + p.id + ')" title="删除">&times;</span>' +
            '</span>';
    });
    html += '</div>';
    row.innerHTML = html;
    if (_bookmarkCollapsed) row.classList.add('collapsed');
}

async function applySearchPreset(name) {
    var res = await api('list_search_presets', { params: { action: 'list_search_presets' } });
    if (!res.ok || !res.presets) return;
    var preset = res.presets.find(function(p) { return p.name === name; });
    if (!preset) { showToast('未找到该预设', 'warning'); return; }
    // 书签只恢复爬取侧控件，不影响本地浏览筛选
    document.getElementById('crawl_keyword').value = preset.keyword || '';
    document.getElementById('crawl_force').classList.toggle('active', !!preset.force_crawl);
    var cats = preset.categories ? preset.categories.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s; }) : [];
    _applyCrawlCategoryTags(cats);
    // 旧预设无 languages 字段时用默认（中日+speechless+text cleaned），空串表示不限制
    var presetLangs;
    if (preset.languages === null || preset.languages === undefined) {
        presetLangs = LANG_DEFAULTS.slice();
    } else {
        presetLangs = preset.languages.split(',').map(function(s) { return s.trim(); }).filter(function(s) { return s; });
    }
    _applyLanguageTags(document.getElementById('crawl_language_tags'), _crawlLanguages, presetLangs);
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
    var dlBtn = bar.querySelector('.btn-outline-primary');
    var cancelBtn = bar.querySelector('.btn-outline-secondary');
    var selectAll = bar.querySelector('.form-check-input');
    if (countEl) countEl.textContent = hasSel ? '已选 ' + _selectedIds.size + '/' + _cacheResults.length + ' 项' : '未选中';
    if (delBtn) delBtn.disabled = !hasSel;
    if (dlBtn) dlBtn.disabled = !hasSel;
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

async function cacheBatchDownload() {
    if (_selectedIds.size === 0) { showToast('请先选择项目', 'warning'); return; }
    var urls = [];
    _cacheResults.forEach(function(g) {
        if (_selectedIds.has(g.source_id)) {
            if (g.source === 'exhentai') urls.push('https://exhentai.org/g/' + g.source_id + '/');
            else if (g.source === 'nhentai') urls.push('https://nhentai.net/g/' + g.source_id + '/');
        }
    });
    if (urls.length === 0) { showToast('无法构建下载链接', 'warning'); return; }
    if (!await confirmDialog({ title: '批量下载', message: '确定下载选中的 ' + urls.length + ' 个画廊吗？' })) return;
    document.getElementById('batch_urls').value = urls.join('\n');
    var tabEl = document.querySelector('[data-bs-target="#download"]');
    if (tabEl) { var tab = new bootstrap.Tab(tabEl); tab.show(); }
    doBatchDownload();
}

// ─── 校对 ───

function renderVerifyStatus(data) {
    var el = document.getElementById('verify_progress');
    if (!el) return;
    if (!data || !data.running || !data.progress) {
        el.classList.remove('show');
        el.innerHTML = '';
        document.getElementById('verify_start_btn')?.classList.remove('d-none');
        document.getElementById('verify_stop_btn')?.classList.add('d-none');
        return;
    }
    document.getElementById('verify_start_btn')?.classList.add('d-none');
    document.getElementById('verify_stop_btn')?.classList.remove('d-none');
    el.classList.add('show');

    var p = data.progress;
    var total = parseInt(p.total_pages, 10) || 0;
    var cur = parseInt(p.current, 10) || 0;
    var pct = total > 0 ? Math.min(100, Math.round(cur / total * 100)) : 0;
    var msg = escapeHtml(p.message || '');
    var isWaiting = p.status === 'waiting';
    var barColor = isWaiting ? '#6c757d' : '#0d6efd';
    el.innerHTML = '<div class="mb-1 small">' + msg + '</div>' +
        '<div class="progress" style="height:8px"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:' + pct + '%;background:' + barColor + '">' + pct + '%</div></div>';
}

async function startVerify() {
    var source = 'exhentai';
    var res = await api('start_verify', { form: { action: 'start_verify', source: source } });
    if (res.ok) {
        showToast(res.output || '校对任务已启动', 'success');
        renderVerifyStatus({ running: true, progress: { total_pages: 0, current: 0, message: '启动中...', status: 'running' } });
    } else {
        showToast(res.error || res.output || '启动失败', 'danger');
    }
}

async function stopVerify() {
    if (!await confirmDialog({ title: '终止校对', message: '确定终止正在运行的校对任务吗？', okText: '终止', okClass: 'btn-danger' })) return;
    await api('stop_verify', { form: { action: 'stop_verify', source: 'exhentai' } });
    renderVerifyStatus(null);
}

function addTagToCacheSearch(type, rawName, nameCn) {
    // 把 popup 点的标签累加到缓存检索框（AND 关系用逗号分隔），scope 根据标签 type 设定下拉框
    // —— 显式触发搜索：仅修改输入框/下拉，不自动调用 cacheSearch()，等用户点"筛选"按钮或按 Enter（避免 NAS/大库点 tag 时强制 4s+ 延迟）
    var input = document.getElementById('cache_keyword');
    var scopeSel = document.getElementById('cache_search_scope');
    var value = (nameCn && nameCn.trim()) ? nameCn.trim() : (rawName || '').trim();
    if (!value) return;
    // 1) scope 设定（只在当前 scope 仍是默认 all 时覆盖；用户手动改过就保留）
    if (scopeSel && scopeSel.value === 'all') {
        var CONTENT_TYPES = new Set(['male','female','parody','character','mixed','other','language','category','cosplayer']);
        if (type === 'artist' || type === 'group') scopeSel.value = 'author';
        else if (CONTENT_TYPES.has(type)) scopeSel.value = (nameCn && nameCn.trim()) ? 'tags_cn' : 'tags';
    }
    // 2) 去重 + 累加（用逗号做 AND 分隔符）
    var parts = input.value.split(/[\s]*[,;，&][\s]*|[\s]{2,}/).map(function (s) { return s.trim(); }).filter(Boolean);
    var exists = false;
    for (var i = 0; i < parts.length; i++) {
        if (parts[i].toLowerCase() === value.toLowerCase()) { exists = true; break; }
    }
    if (!exists) parts.push(value);
    input.value = parts.join(', ');
    // —— 不再自动触发 cacheSearch() + 不关弹窗，允许用户连续点多个 tag 后手动按"筛选"
    //    用户如果想立刻搜，直接按 Enter 或点"筛选"按钮即可（符合 project_memory 显式触发原则）
}

// ─── Detail Popup ─────────────────────────────────────────

function showCacheDetail(idx) {
    var gallery = _cacheResults[idx];
    if (!gallery) return;

    var catColor = CAT_COLORS[gallery.category] || '#6c757d';
    var langColor = { 'japanese': '#0dcaf0', 'chinese': '#dc3545' };
    var lang = gallery.language || '';
    var langColorVal = langColor[lang.toLowerCase()] || '#6b7280';

    var titleEl = document.getElementById('cache_detail_title');
    var bodyEl = document.getElementById('cache_detail_body');
    if (!bodyEl) return;
    var detailTitles = getCacheDisplayTitles(gallery);
    if (titleEl) titleEl.textContent = detailTitles.primary;

    var tagsHtml = '';
    var tagSource = gallery.tags_cn || gallery.tags;
    if (tagSource) {
        try {
            var parsed = JSON.parse(tagSource);
            if (Array.isArray(parsed)) {
                var typeLabels = { 'artist':'作者', 'parody':'原作', 'character':'角色', 'group':'社团', 'male':'男性', 'female':'女性', 'language':'语言', 'category':'分类', 'mixed':'混合', 'cosplayer':'Coser', 'other':'其他' };
                var typeColors = { 'artist':'#e74c3c', 'male':'#3498db', 'female':'#e91e63', 'parody':'#9b59b6', 'character':'#27ae60', 'group':'#f39c12', 'language':'#0dcaf0', 'category':'#95a5a6', 'mixed':'#607d8b', 'cosplayer':'#00bcd4', 'other':'#6b7280' };
                var grouped = {};
                parsed.forEach(function(t) {
                    var type = t.type || 'other';
                    if (type === 'category' && gallery.category) return;
                    if (!grouped[type]) grouped[type] = [];
                    grouped[type].push({ type: type, raw: t.name || '', cn: t.name_cn || '' });
                });
                var order = ['parody', 'character', 'artist', 'group', 'male', 'female', 'cosplayer', 'mixed', 'language', 'category', 'other'];
                tagsHtml = order.map(function(type) {
                    if (!grouped[type] || grouped[type].length === 0) return '';
                    var color = typeColors[type] || '#6b7280';
                    var label = typeLabels[type] || type;
                    var badges = grouped[type].map(function(tag) {
                        var nameOrCn = tag.cn || tag.raw;
                        return '<span class="badge me-1 mb-1" style="background:' + color + ';font-size:.75rem;cursor:pointer" title="点击：累加到缓存检索（AND，不自动触发，按筛选按钮或 Enter 执行）" onclick="addTagToCacheSearch(\'' + escapeAttr(tag.type) + '\',\'' + escapeAttr(tag.raw) + '\',\'' + escapeAttr(tag.cn) + '\')">' + escapeHtml(nameOrCn) + '</span>';
                    }).join('');
                    return '<div class="mb-1"><span class="small fw-semibold me-2" style="color:' + color + ';min-width:40px;display:inline-block">' + label + ':</span>' + badges + '</div>';
                }).filter(function(s) { return s; }).join('');
            }
        } catch(e) {}
    }

    bodyEl.innerHTML =
        '<div class="row g-3">' +
        '<div class="col-md-4">' +
        (gallery.thumb_url
            ? '<div style="background:#1a1a1a;border-radius:0.375rem;display:flex;align-items:center;justify-content:center;min-height:200px"><img src="' + gallery.thumb_url + '" class="img-fluid rounded" alt="cover" style="max-width:100%;max-height:400px;object-fit:contain"></div>'
            : '<div style="aspect-ratio:3/4;background:#f0f0f0;display:flex;align-items:center;justify-content:center;color:#ccc;font-size:3rem"><i class="far fa-image"></i></div>') +
        '</div>' +
        '<div class="col-md-8">' +
        '<table class="table table-sm table-borderless mb-0">' +
        '<tr><td class="text-muted" style="width:80px">标题</td><td>' + escapeHtml(detailTitles.primary) + '</td></tr>' +
        (detailTitles.secondary ? '<tr><td class="text-muted">原标题</td><td><small class="text-muted">' + escapeHtml(detailTitles.secondary) + '</small></td></tr>' : '') +
        (gallery.artist ? '<tr><td class="text-muted">作者</td><td>' + escapeHtml(gallery.artist) + '</td></tr>' : '') +
        (gallery.group_name ? '<tr><td class="text-muted">社团</td><td>' + escapeHtml(gallery.group_name) + '</td></tr>' : '') +
                '<tr><td class="text-muted">分类</td><td><span class="badge" style="background:' + catColor + '">' + escapeHtml(gallery.category || '') + '</span></td></tr>' +
        (lang ? '<tr><td class="text-muted">语言</td><td><span style="color:' + langColorVal + '">' + escapeHtml(lang) + '</span></td></tr>' : '') +
        '<tr><td class="text-muted">页数</td><td>' + (gallery.total_pages || 0) + 'p</td></tr>' +
        (gallery.uploaded_at ? '<tr><td class="text-muted">上传日期</td><td>' + escapeHtml(gallery.uploaded_at) + '</td></tr>' : '') +
        '<tr><td class="text-muted">下载状态</td><td>' + (gallery.is_local ? '<span class="text-success"><i class="fas fa-check me-1"></i>已下载</span>' : '<span class="text-muted">未下载</span>') + '</td></tr>' +
        '</table>' +
        (tagsHtml ? '<div class="mt-2 pt-2 border-top">' + tagsHtml + '</div>' : '') +
        '</div>' +
        '</div>';

    var modalEl = document.getElementById('cache_detail_modal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    }
}

async function pollVerifyStatus() {
    var data = await api('verify_status&source=exhentai');
    pollVerifyStatus._running = data && data.running;
    renderVerifyStatus(data);
}
