let _readerState = null;
let _readerKeyHandler = null;
// ─── Reader ───




function readerToggleDetails() {
    var el = document.getElementById('reader_header_details');
    var btn = document.getElementById('reader_toggle_details');
    el.classList.toggle('show');
    btn.innerHTML = el.classList.contains('show') ? '<i class="fas fa-chevron-up"></i>' : '<i class="fas fa-chevron-down"></i>';
}

function readerSearchTag(type, name) {
    closeReader();
    document.getElementById('gallery_tag_filter').value = name;
    document.getElementById('gallery_tag_mode').value = 'any';
    loadGalleries({ tags: name, tag_mode: 'any' });
    switchPage('gallery');
}

async function readerRefreshMetadata(source, sourceId) {
    var warningDiv = document.getElementById('reader_tag_warning');
    var btn = warningDiv ? warningDiv.querySelector('button') : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        var res = await api('refresh_metadata', { form: { action: 'refresh_metadata', source: source, source_id: sourceId } });
        if (!res.ok) throw new Error(res.error || res.output || '刷新失败');
        showToast('元数据已刷新', 'success');
        openReader(source, sourceId);
    } catch (err) {
        showToast('刷新元数据失败: ' + (err.message || ''), 'danger');
        if (btn) { btn.disabled = false; btn.innerHTML = '重下载元数据'; }
    }
}

var _readerSource, _readerSourceId;
var _readerCurrentPage = 1;
var _readerTotalPages = 0;
var _readerImageList = [];

function openReader(source, sourceId) {
    _readerSource = source;
    _readerSourceId = sourceId;
    _readerCurrentPage = 1;
    _readerTotalPages = 0;
    _readerImageList = [];
    document.getElementById('reader_page').classList.remove('section-hidden');
    document.getElementById('reader_main_img').src = '';
    document.getElementById('reader_main_img').alt = '加载中...';
    document.getElementById('reader_thumb_list').innerHTML = '<div class="text-center text-muted small py-3"><i class="fas fa-spinner fa-spin"></i></div>';

    // Load metadata
    fetch(API + '?action=get_gallery_detail&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.gallery) return;
            var g = data.gallery;
            var displayTitle = g.title_jp || g.title;
            document.getElementById('reader_title').textContent = displayTitle;
            document.getElementById('reader_meta').textContent = g.total_pages + 'p · ' + (g.language || '-') + ' · ' + (g.uploaded_at || '-');
            document.getElementById('reader_detail_artist').textContent = '作者: ' + (g.artist || '-');
            document.getElementById('reader_detail_lang').textContent = '语言: ' + (g.language || '-');
            document.getElementById('reader_detail_category').textContent = '分类: ' + (g.category || '-');
            document.getElementById('reader_detail_uploaded').textContent = '上传: ' + (g.uploaded_at || '-');
            document.getElementById('reader_header_details').classList.remove('show');
            var tagsHtml = '';
            var tagCount = (g.tags && g.tags.length > 0) ? g.tags.length : 0;
            if (tagCount > 0) {
                var typeLabels = { artist: 'bg-danger', parody: 'bg-warning text-dark', character: 'bg-primary', group: 'bg-success', language: 'bg-secondary', category: 'bg-info text-dark', tag: 'bg-light text-dark' };
                g.tags.forEach(function(t) {
                    var cls = typeLabels[t.type] || 'bg-light text-dark';
                    tagsHtml += '<span class="tag-badge ' + cls + '" onclick="event.stopPropagation();readerSearchTag(\'' + t.type + '\',\'' + escapeAttr(t.name) + '\')">' + t.type + ': ' + t.name + '</span> ';
                });
            } else {
                tagsHtml = '<span style="color:#666">无标签</span>';
            }
            var tagWarning = '<div class="mt-1" style="font-size:.75rem;color:#6b7280">标签: ' + tagCount + '个' +
                ' <button class="btn btn-sm btn-outline-warning py-0 px-1 ms-2" onclick="readerRefreshMetadata(\'' + source + '\',\'' + sourceId + '\')" style="font-size:.7rem">刷新元数据</button></div>';
            if (tagCount <= 1) {
                tagWarning = '<div class="mt-1 p-1 rounded" style="background:rgba(255,193,7,.15);font-size:.75rem;color:#ffc107">' +
                    '标签数 (<strong>' + tagCount + '</strong>) 过少，可能元数据不完整' +
                    '<button class="btn btn-sm btn-warning py-0 px-1 ms-2" onclick="readerRefreshMetadata(\'' + source + '\',\'' + sourceId + '\')" style="font-size:.7rem">刷新元数据</button></div>';
            }
            document.getElementById('reader_detail_tags').innerHTML = tagsHtml;
            var existingWarning = document.getElementById('reader_tag_warning');
            if (existingWarning) existingWarning.remove();
            document.getElementById('reader_detail_tags').insertAdjacentHTML('afterend', '<div id="reader_tag_warning">' + tagWarning + '</div>');
        });

    // Load image list
    fetch(API + '?action=get_image_list&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.images) return;
            _readerTotalPages = data.total_pages || data.images.length;
            _readerImageList = data.images;
            document.getElementById('reader_page_total').textContent = '/ ' + _readerTotalPages;
            document.getElementById('reader_page_input').max = _readerTotalPages;

            var thumbHtml = '';
            data.images.forEach(function(img, idx) {
                var pageNum = img.page || (idx + 1);
                if (img.file) {
                    var thumbUrl = API + '?action=serve_image&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId) + '&page=' + pageNum;
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<img src="' + thumbUrl + '" alt="p' + pageNum + '" loading="lazy">' +
                        '</div>';
                } else {
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')" style="text-align:center;padding:.5rem;color:#666;font-size:.75rem">' +
                        'p' + pageNum + '</div>';
                }
            });
            document.getElementById('reader_thumb_list').innerHTML = thumbHtml;
            readerGoToPage(1);
        });

    // Keyboard
    if (_readerKeyHandler) document.removeEventListener('keydown', _readerKeyHandler);
    _readerKeyHandler = function(e) {
        if (e.key === 'Escape') { closeReader(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); readerPrevPage(); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); readerNextPage(); }
    };
    document.addEventListener('keydown', _readerKeyHandler);
}

function closeReader() {
    document.getElementById('reader_page').classList.add('section-hidden');
    document.getElementById('reader_main_img').src = '';
    if (_readerKeyHandler) {
        document.removeEventListener('keydown', _readerKeyHandler);
        _readerKeyHandler = null;
    }
}

function readerGoToPage(page) {
    if (page < 1) page = 1;
    if (page > _readerTotalPages) page = _readerTotalPages;
    _readerCurrentPage = page;
    document.getElementById('reader_page_input').value = page;

    // Update thumb active
    var thumbs = document.querySelectorAll('#reader_thumb_list .thumb-item');
    thumbs.forEach(function(t) { t.classList.remove('active'); });
    var activeThumb = document.querySelector('#reader_thumb_list .thumb-item[data-page="' + page + '"]');
    if (activeThumb) {
        activeThumb.classList.add('active');
        activeThumb.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // Load main image
    var imgUrl = API + '?action=serve_image&source=' + encodeURIComponent(_readerSource) + '&source_id=' + encodeURIComponent(_readerSourceId) + '&page=' + page;
    document.getElementById('reader_main_img').src = imgUrl;

    // Preload next 2 pages
    for (var i = 1; i <= 2; i++) {
        var nextPage = page + i;
        if (nextPage <= _readerTotalPages) {
            var preloadUrl = API + '?action=serve_image&source=' + encodeURIComponent(_readerSource) + '&source_id=' + encodeURIComponent(_readerSourceId) + '&page=' + nextPage;
            var link = document.createElement('link');
            link.rel = 'preload';
            link.as = 'image';
            link.href = preloadUrl;
            document.head.appendChild(link);
            setTimeout(function(el) { document.head.removeChild(el); }, 3000, link);
        }
    }
}

function readerPrevPage() {
    if (_readerCurrentPage > 1) readerGoToPage(_readerCurrentPage - 1);
}

function readerNextPage() {
    if (_readerCurrentPage < _readerTotalPages) readerGoToPage(_readerCurrentPage + 1);
}
