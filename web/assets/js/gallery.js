// ─── Gallery (Card Grid) ───
// 共用工具（cache.js 先于 gallery.js 引入）：
//   normalizeTitle / _zwspWrap / getGalleryDisplayTitles / CAT_COLORS
let _galleryFilters = {};
let _galleryPage = 1;
let _galleryPerPage = 30;
let _galleryTotal = 0;

const GALLERY_CATEGORY_ZH = {
    'doujinshi': '同人志',
    'manga': '漫画',
    'artist cg': '艺术家CG',
    'game cg': '游戏CG',
    'western': '西方',
    'non-h': '非H',
    'image set': '图集',
    'cosplay': '角色扮演',
    'asian porn': '亚洲色情',
    'misc': '其他'
};

function compactGalleryCategory(value) {
    var raw = String(value || '').trim();
    return GALLERY_CATEGORY_ZH[raw.toLowerCase()] || raw;
}

function compactGalleryLanguage(value) {
    var raw = String(value || '').trim().toLowerCase();
    if (/chinese|中文|汉语|\bzh\b/.test(raw)) return '中';
    if (/japanese|日语|日本語|\bja\b/.test(raw)) return '日';
    if (/speechless|textless|no[ _-]?text|n\/a|无字|无言/.test(raw)) return '无字';
    if (/text[ _-]?cleaned|去字|清字/.test(raw)) return '去字';
    return '其他';
}

function compactGallerySource(value) {
    var raw = String(value || '').trim().toLowerCase();
    if (raw === 'exhentai') return 'Ex';
    if (raw === 'nhentai') return 'N';
    return raw ? raw.charAt(0).toUpperCase() : '?';
}

function openGalleryCompressionSetup(id) {
    switchPage('tools');
    setTimeout(function () {
        var tabEl = document.querySelector('[data-bs-target="#tab_tools_compression"]');
        if (tabEl && window.bootstrap && bootstrap.Tab) bootstrap.Tab.getOrCreateInstance(tabEl).show();
        var input = document.getElementById('compress_gallery_id');
        if (input) input.value = String(id);
        var hint = document.getElementById('compress_selected_hint');
        if (hint) hint.textContent = '已选择 Gallery #' + id;
        if (typeof loadCompressionPage === 'function') loadCompressionPage();
    }, 50);
}

function gotoGalleryPage(page) {
    _galleryPage = page;
    loadGalleries(_galleryFilters);
}

function setGalleryPerPage(n) {
    _galleryPerPage = n;
    _galleryPage = 1;
    loadGalleries(_galleryFilters);
}

function openIncompleteGalleryRetry(source, sourceId) {
    switchPage('download');
    showToast('这本画廊尚未下载完成，请使用“重试未完成下载”。', 'warning');
    setTimeout(function() {
        var retryBtn = document.getElementById('retry_btn');
        if (retryBtn) {
            retryBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            retryBtn.classList.add('btn-danger');
            setTimeout(function() { retryBtn.classList.remove('btn-danger'); }, 1800);
        }
    }, 80);
}
async function loadGalleries(filters) {
    filters = filters || _galleryFilters || {};
    _galleryFilters = { ...filters };
    const body = document.getElementById('gallery_grid_body');
    body.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';

    let params = '?action=get_galleries&page=' + _galleryPage + '&per_page=' + _galleryPerPage;
    if (filters.source) params += '&source=' + encodeURIComponent(filters.source);
    if (filters.tags) params += '&tags=' + encodeURIComponent(filters.tags);
    if (filters.tag_mode) params += '&tag_mode=' + encodeURIComponent(filters.tag_mode);
    if (filters.artist) params += '&artist=' + encodeURIComponent(filters.artist);
    if (filters.language) params += '&language=' + encodeURIComponent(filters.language);
    if (filters.tag) params += '&tag=' + encodeURIComponent(filters.tag);

    const resp = await fetch(API + params);
    const data = await resp.json();

    if (!data.ok || !data.galleries || data.galleries.length === 0) {
        body.innerHTML = '<div class="text-center text-muted py-5">暂无数据</div>';
        document.getElementById('gallery_pagination').innerHTML = '';
        return;
    }

    _galleryTotal = data.total || 0;

    body.innerHTML = '<div class="gallery-flex-grid" id="gallery_grid">' +
        data.galleries.map(function(g) {
            // 与 cache.js 共用的双标题优先级分配逻辑（日语 primary，中文/英文≠日语时放 secondary）
            var displayTitles = getGalleryDisplayTitles(g);
            var categoryColorKey = window.CAT_COLORS
                ? Object.keys(window.CAT_COLORS).find(function (key) { return key.toLowerCase() === String(g.category || '').toLowerCase(); })
                : null;
            var catColor = categoryColorKey ? window.CAT_COLORS[categoryColorKey] : '#6c757d';
            var categoryLabel = compactGalleryCategory(g.category);
            var catBadge = g.category ? '<span class="badge gallery-meta-chip" style="background:' + catColor + '" title="' + escapeAttr(g.category) + '">' + escapeHtml(categoryLabel) + '</span>' : '';
            var badgeClass = g.source === 'nhentai' ? 'bg-danger' : 'bg-info';
            var sourceBadge = '<span class="badge gallery-meta-chip ' + badgeClass + '" title="' + escapeAttr(g.source) + '">' + escapeHtml(compactGallerySource(g.source)) + '</span>';
            var langColor = { '日': '#0dcaf0', '中': '#dc3545', '无字': '#6f42c1', '去字': '#198754', '其他': '#6b7280' };
            var lang = g.language || '';
            var langLabel = compactGalleryLanguage(lang);
            var langBadge = '<span class="lang-badge gallery-meta-chip" style="color:' + langColor[langLabel] + '" title="' + escapeAttr(lang || '其他') + '">' + langLabel + '</span>';
            var fallbackImgUrl = imageApiUrl(g.source, g.source_id, 'cover');
            var imgUrl = g.cover_url || fallbackImgUrl;
            var totalPages = parseInt(g.total_pages || g.pages || 0, 10) || 0;
            var downloadedPages = parseInt(g.downloaded_pages || 0, 10) || 0;
            var isComplete = g.is_complete !== false && g.is_complete !== 0 && g.is_complete !== '0';
            var progressText = isComplete ? String(totalPages) : (downloadedPages + '/' + totalPages);
            var progressClass = isComplete ? 'gallery-page-count text-muted' : 'gallery-page-count text-warning fw-semibold';
            var cardClass = isComplete ? '' : ' gallery-card-incomplete';
            var clickAction = isComplete
                ? 'openReader(\'' + g.source + '\',\'' + g.source_id + '\')'
                : 'openIncompleteGalleryRetry(\'' + g.source + '\',\'' + g.source_id + '\')';
            var statusBadge = isComplete ? '' : '<span class="badge bg-warning text-dark gallery-status-badge">未完成</span>';
            // 按钮：本地阅览（已下载）/ 重试（未完成） + ExHentai 外链
            var readBtn = isComplete
                ? '<button class="btn btn-sm btn-outline-success py-0 px-1" onclick="event.stopPropagation();openReader(\'' + g.source + '\',\'' + g.source_id + '\')" title="本地阅览"><i class="fas fa-book-open"></i></button>'
                : '<button class="btn btn-sm btn-outline-warning py-0 px-1" onclick="event.stopPropagation();openIncompleteGalleryRetry(\'' + g.source + '\',\'' + g.source_id + '\')" title="继续下载"><i class="fas fa-redo-alt"></i></button>';
            var compressStatus = (g.compression_status || '').toString();
            var compressStateLabel = '未压';
            var compressStateClass = 'text-secondary';
            var savings = Number(g.compression_savings_pct);
            var hasSavings = g.compression_savings_pct !== null && g.compression_savings_pct !== '' && isFinite(savings);
            var hasCompressionResult = ['user_review_required', 'applied', 'compressed', 'skipped'].includes(compressStatus);
            if (compressStatus === 'user_review_required') {
                compressStateLabel = '待审';
                compressStateClass = 'text-warning';
            } else if (['applied', 'compressed', 'skipped'].includes(compressStatus)) {
                compressStateLabel = '已压';
                compressStateClass = 'text-success';
            } else if (compressStatus === 'queued' || compressStatus === 'compressing') {
                compressStateLabel = '压缩中';
                compressStateClass = 'text-info';
            } else if (compressStatus === 'failed') {
                compressStateLabel = '失败';
                compressStateClass = 'text-danger';
            }
            var compressState = '<span class="gallery-compress-state ' + compressStateClass + '">' + compressStateLabel + '</span>';
            var savingsBadge = hasCompressionResult && hasSavings
                ? '<span class="gallery-compress-saving ' + (savings > 0 ? 'text-success' : 'text-secondary') + '">' + savings.toFixed(2) + '%</span>'
                : '';
            var compressBtn = '';
            if (!isComplete) {
                compressBtn = '<button class="btn btn-sm btn-outline-secondary gallery-mini-btn" disabled title="下载完成后才能压缩"><i class="fas fa-compress"></i></button>';
            } else if (compressStatus === 'user_review_required') {
                compressBtn = '<button class="btn btn-sm btn-outline-warning gallery-mini-btn" data-compare-gallery="' + escapeAttr(String(g.id ?? '')) + '" data-source="' + escapeAttr(g.source) + '" data-source-id="' + escapeAttr(g.source_id || '') + '" title="审核压缩候选"><i class="fas fa-code-compare"></i></button>';
            } else if (compressStatus === 'queued' || compressStatus === 'compressing') {
                compressBtn = '<button class="btn btn-sm btn-outline-info gallery-mini-btn" disabled title="压缩任务进行中"><i class="fas fa-spinner fa-spin"></i></button>';
            } else {
                compressBtn = '<button class="btn btn-sm btn-outline-primary gallery-mini-btn" onclick="event.stopPropagation();openGalleryCompressionSetup(' + Number(g.id || 0) + ')" title="设置参数并压缩"><i class="fas fa-compress"></i></button>';
            }
            var escapedSid = escapeAttr(g.source_id || '');
            var exLink = (g.source && g.source.toLowerCase() === 'exhentai')
                ? '<a class="btn btn-sm btn-outline-secondary py-0 px-1" href="https://exhentai.org/g/' + escapedSid + '/" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()" title="在 ExHentai 打开"><i class="fas fa-arrow-up-right-from-square"></i></a>'
                : '';
            var titleTooltip = displayTitles.primary + (displayTitles.secondary ? '\n' + displayTitles.secondary : '');
            return '<div data-source="' + g.source + '" data-source-id="' + g.source_id + '">' +
                '<div class="card gallery-card' + cardClass + '" onclick="' + clickAction + '">' +
                '<div class="card-img-wrapper" style="aspect-ratio:3/4;overflow:hidden">' +
                '<img src="' + imgUrl + '" data-fallback="' + fallbackImgUrl + '" class="card-img-top" alt="cover" loading="lazy" onerror="fallbackImageOnError(this)" onload="onCoverLoad(this)">' +
                statusBadge +
                '<div class="delete-overlay"><button class="btn btn-sm btn-dark py-0 px-1" style="font-size:.7rem;line-height:1.4" onclick="event.stopPropagation();deleteGalleryFromCard(this,\'' + g.source + '\',\'' + g.source_id + '\',\'' + escapeAttr(displayTitles.primary) + '\')" title="删除"><i class="fas fa-trash-alt"></i></button></div>' +
                '</div>' +
                '<div class="card-body px-2 py-1 card-info-body">' +
                // 第1~3行：双标题严格 3 行高共享（Flex Column + 独立 line-clamp，与 cache.js 同构）
                // 注：_zwspWrap() 在 cache.js 里注入，给 CJK/假名/罗马字序列强制软换行点，防止 -webkit-box 里不换行
                '<div class="title-double-clamp" title="' + escapeAttr(titleTooltip) + '">' +
                '<div class="title-primary" style="color:var(--bs-link-color)">' + _zwspWrap(escapeHtml(displayTitles.primary)) + '</div>' +
                (displayTitles.secondary ? '<div class="title-secondary text-muted">' + _zwspWrap(escapeHtml(displayTitles.secondary)) + '</div>' : '') +
                '</div>' +
                // 第4行：精简分类、语种、来源、页数 + 本地阅读/来源跳转
                '<div class="d-flex align-items-center gap-1 card-info-row card-row-top">' +
                catBadge +
                langBadge +
                sourceBadge +
                '<span class="' + progressClass + '">' + progressText + '</span>' +
                '<span class="gallery-row-actions ms-auto">' + readBtn + exLink + '</span>' +
                '</div>' +
                // 第5行：压缩状态、整本节省率 + 压缩/审核按钮
                '<div class="d-flex align-items-center gap-1 card-info-row card-row-bottom gallery-compression-row">' +
                compressState + savingsBadge + '<span class="ms-auto">' + compressBtn + '</span>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }).join('') +
        '</div>';

    renderGalleryPagination();
}

// ═══ Compress compare entry: 事件委托 ═══
// 注意：画廊卡片主体会被完全替换（innerHTML），因此按钮委托绑在 #gallery_grid_body 上，
// 防止 onclick 字符串拼接用户可控数据带来的转义和语法风险。
document.addEventListener('DOMContentLoaded', function () {
    var body = document.getElementById('gallery_grid_body');
    if (!body) return;
    body.addEventListener('click', function (ev) {
        var t = ev.target && ev.target.closest ? ev.target.closest('[data-compare-gallery]') : null;
        if (t && body.contains(t)) {
            ev.preventDefault();
            ev.stopPropagation();
            var gid = t.getAttribute('data-compare-gallery') || '';
            var src = t.getAttribute('data-source') || '';
            var sid = t.getAttribute('data-source-id') || '';
            if (!gid || !src || !sid) {
                showToast('压缩对比：缺少 gallery_id/source/source_id', 'warning');
                return;
            }
            if (typeof openCompressCompare !== 'function') {
                showToast('压缩对比模块尚未加载，请刷新页面重试', 'danger');
                return;
            }
            openCompressCompare({
                gallery_id: gid,
                source: src,
                source_id: sid
            });
        }
    }, true);
});

function renderGalleryPagination() {
    const el = document.getElementById('gallery_pagination');
    if (!el) return;
    var totalPages = Math.ceil(_galleryTotal / _galleryPerPage);
    if (totalPages <= 1) { el.innerHTML = ''; return; }

    var html = '<div class="d-flex flex-wrap align-items-center justify-content-center gap-3">';

    html += '<nav aria-label="Gallery pagination"><ul class="pagination pagination-sm mb-0">';

    html += '<li class="page-item' + (_galleryPage <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" onclick="event.preventDefault();gotoGalleryPage(' + (_galleryPage - 1) + ')" aria-label="上一页">&laquo;</a></li>';

    var start = Math.max(1, _galleryPage - 2);
    var end = Math.min(totalPages, _galleryPage + 2);
    if (start > 1) {
        html += '<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault();gotoGalleryPage(1)">1</a></li>';
        if (start > 2) html += '<li class="page-item disabled"><span class="page-link" aria-hidden="true">&hellip;</span></li>';
    }
    for (var i = start; i <= end; i++) {
        html += '<li class="page-item' + (i === _galleryPage ? ' active' : '') + '"' + (i === _galleryPage ? ' aria-current="page"' : '') + '><a class="page-link" href="#" onclick="event.preventDefault();gotoGalleryPage(' + i + ')">' + i + '</a></li>';
    }
    if (end < totalPages) {
        if (end < totalPages - 1) html += '<li class="page-item disabled"><span class="page-link" aria-hidden="true">&hellip;</span></li>';
        html += '<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault();gotoGalleryPage(' + totalPages + ')">' + totalPages + '</a></li>';
    }

    html += '<li class="page-item' + (_galleryPage >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" onclick="event.preventDefault();gotoGalleryPage(' + (_galleryPage + 1) + ')" aria-label="下一页">&raquo;</a></li>';

    html += '</ul></nav>';

    html += '<div class="d-flex align-items-center gap-1 text-nowrap"><span class="text-muted small">每页</span>' +
        '<div class="btn-group btn-group-sm" role="group" aria-label="每页显示数量">' +
        '<button type="button" class="btn ' + (_galleryPerPage === 30 ? 'btn-primary' : 'btn-outline-secondary') + '" onclick="setGalleryPerPage(30)">30</button>' +
        '<button type="button" class="btn ' + (_galleryPerPage === 60 ? 'btn-primary' : 'btn-outline-secondary') + '" onclick="setGalleryPerPage(60)">60</button>' +
        '</div>' +
        '<span class="text-muted small">共 ' + _galleryTotal + ' 本</span></div>';

    html += '</div>';

    el.innerHTML = html;
}

function applyGalleryFilter() {
    _galleryPage = 1;
    const tags = document.getElementById('gallery_tag_filter').value.trim();
    const tagMode = document.getElementById('gallery_tag_mode').value;
    const artist = document.getElementById('gallery_artist_filter').value.trim();
    const lang = document.getElementById('gallery_lang_filter').value.trim();
    const filters = {};
    if (tags) filters.tags = tags;
    if (tagMode !== 'any') filters.tag_mode = tagMode;
    if (artist) filters.artist = artist;
    if (lang) filters.language = lang;
    loadGalleries(filters);
}

function clearGalleryFilter() {
    _galleryPage = 1;
    document.getElementById('gallery_tag_filter').value = '';
    document.getElementById('gallery_tag_mode').value = 'any';
    document.getElementById('gallery_artist_filter').value = '';
    document.getElementById('gallery_lang_filter').value = '';
    loadGalleries({});
}

async function recoverOrphans() {
    if (!await confirmDialog({ title: '恢复孤儿目录', message: '扫描下载目录，将已下载但无数据库记录的画廊恢复到列表中。', detail: '已有记录的画廊不受影响。', okText: '开始恢复', okClass: 'btn-warning' })) return;
    var btn = document.querySelector('[onclick*="recoverOrphans"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 恢复中...'; }
    try {
        var res = await api('recover_orphans', { form: { action: 'recover_orphans' } });
        showToast('恢复完成，切换到本地图库查看新记录', 'success');
    } catch (err) {
        showToast('恢复失败: ' + (err.message || ''), 'danger');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-ambulance"></i> 恢复孤儿'; }
    }
}

async function deleteGalleryFromCard(btn, source, sourceId, title) {
    if (!await confirmDialog({ title: '删除本地画廊', message: '确定删除这本本地画廊吗？', detail: title + '\n\n这会同时删除本地图片文件和数据库记录。', okText: '删除', okClass: 'btn-danger' })) return;
    btn.disabled = true;
    var oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        var res = await api('delete_gallery', { form: { action: 'delete_gallery', source: source, source_id: sourceId } });
        if (!res.ok) throw new Error(res.error || '删除失败');
        showToast('已删除：' + title, 'success');
        await loadGalleries();
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
        showToast(err.message, 'danger');
    }
}
