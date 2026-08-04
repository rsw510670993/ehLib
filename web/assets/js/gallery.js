// ─── Gallery (Card Grid) ───
// 共用工具（cache.js 先于 gallery.js 引入）：
//   normalizeTitle / _zwspWrap / getGalleryDisplayTitles / CAT_COLORS
let _galleryFilters = {};
let _galleryPage = 1;
let _galleryPerPage = 30;
let _galleryTotal = 0;

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
            var catColor = window.CAT_COLORS ? (window.CAT_COLORS[g.category] || '#6c757d') : '#6c757d';
            var catBadge = g.category ? '<span class="badge" style="background:' + catColor + ';font-size:.65rem">' + escapeHtml(g.category) + '</span>' : '';
            var badgeClass = g.source === 'nhentai' ? 'bg-danger' : 'bg-info';
            var sourceBadge = '<span class="badge ' + badgeClass + '" style="font-size:.65rem">' + escapeHtml(g.source) + '</span>';
            var langColor = { 'japanese': '#0dcaf0', 'chinese': '#dc3545' };
            var lang = g.language || '';
            var langBadge = lang ? '<span class="lang-badge" style="color:' + (langColor[lang.toLowerCase()] || '#6b7280') + '">' + escapeHtml(lang) + '</span>' : '';
            var fallbackImgUrl = imageApiUrl(g.source, g.source_id, 'cover');
            var imgUrl = g.cover_url || fallbackImgUrl;
            var totalPages = parseInt(g.total_pages || g.pages || 0, 10) || 0;
            var downloadedPages = parseInt(g.downloaded_pages || 0, 10) || 0;
            var isComplete = g.is_complete !== false && g.is_complete !== 0 && g.is_complete !== '0';
            var progressText = isComplete ? (totalPages + 'p') : (downloadedPages + '/' + totalPages + 'p');
            var progressClass = isComplete ? 'small text-muted' : 'small text-warning fw-semibold';
            var cardClass = isComplete ? '' : ' gallery-card-incomplete';
            var clickAction = isComplete
                ? 'openReader(\'' + g.source + '\',\'' + g.source_id + '\')'
                : 'openIncompleteGalleryRetry(\'' + g.source + '\',\'' + g.source_id + '\')';
            var statusBadge = isComplete ? '' : '<span class="badge bg-warning text-dark gallery-status-badge">未完成</span>';
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
                // 第4行（左对齐）：分类+语种+来源badge+页数/进度
                '<div class="d-flex align-items-center gap-1 card-info-row card-row-top">' +
                catBadge +
                langBadge +
                sourceBadge +
                '<span class="' + progressClass + '">' + progressText + '</span>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }).join('') +
        '</div>';

    renderGalleryPagination();
}

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
