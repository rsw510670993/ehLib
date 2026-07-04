// ─── Gallery (Card Grid) ───
let _galleryFilters = {};



async function loadGalleries(filters) {
    filters = filters || _galleryFilters || {};
    _galleryFilters = { ...filters };
    const body = document.getElementById('gallery_grid_body');
    body.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';

    let params = '?action=get_galleries';
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
        return;
    }

    body.innerHTML = '<div class="gallery-flex-grid" id="gallery_grid">' +
        data.galleries.map(function(g) {
            var displayTitle = g.title_jp || g.title;
            var badgeClass = g.source === 'nhentai' ? 'bg-danger' : 'bg-info';
            var imgUrl = API + '?action=serve_image&source=' + encodeURIComponent(g.source) + '&source_id=' + encodeURIComponent(g.source_id) + '&page=cover';
            return '<div data-source="' + g.source + '" data-source-id="' + g.source_id + '">' +
                '<div class="card h-100 gallery-card" onclick="openReader(\'' + g.source + '\',\'' + g.source_id + '\')">' +
                '<div class="card-img-wrapper" style="aspect-ratio:3/4;overflow:hidden">' +
                '<img src="' + imgUrl + '" class="card-img-top" alt="cover" loading="lazy" onerror="this.style.display=\'none\'">' +
                '<div class="delete-overlay"><button class="btn btn-sm btn-dark py-0 px-1" style="font-size:.7rem;line-height:1.4" onclick="event.stopPropagation();deleteGalleryFromCard(this,\'' + g.source + '\',\'' + g.source_id + '\',\'' + escapeAttr(displayTitle) + '\')" title="删除"><i class="fas fa-trash-alt"></i></button></div>' +
                '</div>' +
                '<div class="card-body p-2">' +
                '<div class="small title-clamp" title="' + escapeAttr(displayTitle) + '">' + escapeHtml(displayTitle) + '</div>' +
                '<div class="d-flex justify-content-between align-items-center">' +
                '<span class="badge ' + badgeClass + '" style="font-size:.65rem">' + g.source + '</span>' +
                '<span class="small text-muted">' + g.pages + 'p</span>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }).join('') +
        '</div>';
}

function applyGalleryFilter() {
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
    document.getElementById('gallery_tag_filter').value = '';
    document.getElementById('gallery_tag_mode').value = 'any';
    document.getElementById('gallery_artist_filter').value = '';
    document.getElementById('gallery_lang_filter').value = '';
    loadGalleries({});
}

async function recoverOrphans() {
    if (!confirm('扫描下载目录，将已下载但无数据库记录的画廊恢复到列表中？\n已有记录的画廊不受影响。')) return;
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
    if (!confirm('⚠ 确定删除这本本地画廊吗？\n\n' + title + '\n\n这会同时删除本地图片文件和数据库记录。')) return;
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
