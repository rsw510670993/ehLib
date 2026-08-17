let _readerState = null;
let _readerKeyHandler = null;
let _readerWheelHandler = null;
let _readerWheelResetTimer = null;
let _readerWheelDelta = 0;
let _readerWheelLocked = false;
const _READER_WHEEL_THRESHOLD = 60;
const _READER_WHEEL_IDLE_MS = 160;
const _READER_THUMB_RADIUS = 4;
const _READER_PRELOAD_BEFORE = 2;
const _READER_PRELOAD_AFTER = 3;
let _readerPreloadImages = {};
let _readerThumbObserver = null;
let _readerOpenToken = 0;
// ─── Reader ───





function readerToggleDetails() {
    var el = document.getElementById('reader_header_details');
    var btn = document.getElementById('reader_toggle_details');
    el.classList.toggle('show');
    btn.innerHTML = el.classList.contains('show') ? '<i class="fas fa-chevron-up"></i>' : '<i class="fas fa-chevron-down"></i>';
}

function readerSearchTag(type, name) {
    closeReader();
    addTagToCrawlKeyword(type, name);
    showToast('已加入爬取关键词: ' + type + ':' + name, 'info');
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

function readerImageForPage(page) {
    for (var i = 0; i < _readerImageList.length; i++) {
        if ((_readerImageList[i].page || (i + 1)) === page) return _readerImageList[i];
    }
    return null;
}

function readerImageUrl(page) {
    var img = readerImageForPage(page);
    if (img && img.url) return img.url;
    if (_readerSource && _readerSourceId) return imageApiUrl(_readerSource, _readerSourceId, page);
    return '';
}

function readerClearPreloads() {
    Object.keys(_readerPreloadImages).forEach(function(key) {
        var preload = _readerPreloadImages[key];
        try { preload.onload = null; preload.onerror = null; } catch (_) {}
        try { preload.removeAttribute && preload.removeAttribute('src'); } catch (_) {}
    });
    _readerPreloadImages = {};
}

function readerLoadThumbImage(img) {
    if (!img || !img.dataset.src) return;
    var currentSrc = img.getAttribute && img.getAttribute('src');
    if (currentSrc && currentSrc !== '') return;
    try { img.style.visibility = ''; } catch (_) {}
    try { img.style.display = ''; } catch (_) {}
    try { delete img._fallbackTried; } catch (_) { try { img._fallbackTried = false; } catch (__) {} }
    try { img.classList.remove('is-loaded'); } catch (_) {}
    img.onerror = function() { readerThumbError(this); };
    img.onload  = function() { readerThumbLoaded(this); };
    img.src = img.dataset.src;
}

function readerUnloadThumbImage(img) {
    if (!img) return;
    try { delete img._fallbackTried; } catch (_) { try { img._fallbackTried = false; } catch (__) {} }
    try { img.classList.remove('is-loaded'); } catch (_) {}
    try { img.style.visibility = ''; } catch (_) {}
    try { img.style.display = ''; } catch (_) {}
    try { img.removeAttribute('src'); } catch (_) {
        try { img.src = ''; } catch (__) {}
    }
}

function readerThumbLoaded(img) {
    try { img && img.classList.add('is-loaded'); } catch (_) {}
}

function readerThumbError(img) {
    if (!img) return;
    if (img._fallbackTried) {
        try { img.style.visibility = 'hidden'; } catch (_) {}
        try { img.classList.remove('is-loaded'); } catch (_) {}
        return;
    }
    var fallback = img.getAttribute && img.getAttribute('data-fallback');
    if (fallback && fallback !== '') {
        var currentSrc = img.getAttribute && img.getAttribute('src');
        if (currentSrc && (fallback === currentSrc || fallback === img.dataset.src)) {
            img._fallbackTried = true;
            try { img.style.visibility = 'hidden'; } catch (_) {}
            return;
        }
        img._fallbackTried = true;
        try { img.style.visibility = ''; } catch (_) {}
        try { img.classList.remove('is-loaded'); } catch (_) {}
        img.onerror = function() { readerThumbError(this); };
        img.onload  = function() { readerThumbLoaded(this); };
        img.src = fallback;
    } else {
        img._fallbackTried = true;
        try { img.style.visibility = 'hidden'; } catch (_) {}
    }
}

function readerSetupThumbObserver() {
    if (_readerThumbObserver) {
        try { _readerThumbObserver.disconnect(); } catch (_) {}
    }
    _readerThumbObserver = null;
    if (typeof IntersectionObserver === 'undefined') return;
    var root = document.getElementById('reader_thumbstrip');
    if (!root) return;
    try {
        _readerThumbObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                var img = entry.target;
                var item = img && img.closest ? img.closest('.thumb-item') : null;
                var page = item ? parseInt(item.dataset.page, 10) : 0;
                if (entry.isIntersecting) {
                    readerLoadThumbImage(img);
                } else if (page && Math.abs(page - _readerCurrentPage) > _READER_THUMB_RADIUS) {
                    readerUnloadThumbImage(img);
                }
            });
        }, { root: root, rootMargin: '160px 0px' });
        var imgs = document.querySelectorAll('#reader_thumb_list img[data-src]');
        for (var i = 0; i < imgs.length; i++) {
            try { _readerThumbObserver.observe(imgs[i]); } catch (_) {}
        }
    } catch (_) {
        _readerThumbObserver = null;
    }
}

function readerUpdateThumbWindow(page) {
    var minPage = Math.max(1, page - _READER_THUMB_RADIUS);
    var maxPage = Math.min(_readerTotalPages, page + _READER_THUMB_RADIUS);
    var items = document.querySelectorAll('#reader_thumb_list .thumb-item');
    for (var i = 0; i < items.length; i++) {
        var item = items[i];
        var itemPage = parseInt(item.dataset.page, 10);
        var img = item.querySelector('img[data-src]');
        if (!img) continue;
        if (itemPage >= minPage && itemPage <= maxPage) {
            readerLoadThumbImage(img);
        } else {
            var style = window.getComputedStyle ? window.getComputedStyle(item) : null;
            if (style) {
                try {
                    var strip = document.getElementById('reader_thumbstrip');
                    var itemRect = item.getBoundingClientRect ? item.getBoundingClientRect() : null;
                    var stripRect = strip && strip.getBoundingClientRect ? strip.getBoundingClientRect() : null;
                    if (itemRect && stripRect) {
                        var margin = 80;
                        var visibleX = itemRect.right > stripRect.left - margin && itemRect.left < stripRect.right + margin;
                        var visibleY = itemRect.bottom > stripRect.top - margin && itemRect.top < stripRect.bottom + margin;
                        if (visibleX && visibleY) continue;
                    }
                } catch (_) {}
            }
            readerUnloadThumbImage(img);
        }
    }
}

function readerUpdatePreloadWindow(page) {
    var desired = {};
    var first = Math.max(1, page - _READER_PRELOAD_BEFORE);
    var last = Math.min(_readerTotalPages, page + _READER_PRELOAD_AFTER);
    for (var candidate = first; candidate <= last; candidate++) {
        if (candidate !== page) desired[candidate] = true;
    }
    Object.keys(_readerPreloadImages).forEach(function(key) {
        if (desired[key]) return;
        var preload = _readerPreloadImages[key];
        try { preload.onload = null; preload.onerror = null; } catch (_) {}
        try { preload.removeAttribute && preload.removeAttribute('src'); } catch (_) {}
        delete _readerPreloadImages[key];
    });
    Object.keys(desired).forEach(function(key) {
        if (_readerPreloadImages[key]) return;
        var preloadUrl = readerImageUrl(parseInt(key, 10));
        if (!preloadUrl) return;
        try {
            var preload = new Image();
            preload.src = preloadUrl;
            _readerPreloadImages[key] = preload;
        } catch (_) {}
    });
}

function readerResetLazyImages() {
    if (_readerThumbObserver) {
        try { _readerThumbObserver.disconnect(); } catch (_) {}
    }
    _readerThumbObserver = null;
    readerClearPreloads();
    var imgs = document.querySelectorAll('#reader_thumb_list img[data-src]');
    for (var i = 0; i < imgs.length; i++) readerUnloadThumbImage(imgs[i]);
}

function readerResetWheelGesture() {
    if (_readerWheelResetTimer) {
        clearTimeout(_readerWheelResetTimer);
        _readerWheelResetTimer = null;
    }
    _readerWheelDelta = 0;
    _readerWheelLocked = false;
}

function readerUnbindWheel() {
    var main = document.getElementById('reader_main');
    if (main && _readerWheelHandler) {
        try { main.removeEventListener('wheel', _readerWheelHandler, { passive: false }); } catch (_) {
            try { main.removeEventListener('wheel', _readerWheelHandler); } catch (_) {}
        }
    }
    _readerWheelHandler = null;
    readerResetWheelGesture();
}

function readerBindWheel() {
    readerUnbindWheel();
    var main = document.getElementById('reader_main');
    if (!main) return;
    _readerWheelHandler = function(event) {
        if (event.ctrlKey || _readerTotalPages < 1) return;
        if (!event.deltaY || Math.abs(event.deltaY) < Math.abs(event.deltaX || 0)) return;
        var readerMain = document.getElementById('reader_main');
        if (readerMain) {
            var canScrollDown = readerMain.scrollTop + readerMain.clientHeight < readerMain.scrollHeight - 1;
            var canScrollUp = readerMain.scrollTop > 1;
            if (event.deltaY > 0 && canScrollDown) return;
            if (event.deltaY < 0 && canScrollUp) return;
        }
        try { event.preventDefault && event.preventDefault(); } catch (_) {}
        if (_readerWheelResetTimer) clearTimeout(_readerWheelResetTimer);
        _readerWheelResetTimer = setTimeout(function() {
            readerResetWheelGesture();
        }, _READER_WHEEL_IDLE_MS);
        if (_readerWheelLocked) return;
        var delta = event.deltaY;
        if (event.deltaMode === 1) {
            delta *= 16;
        } else if (event.deltaMode === 2) {
            delta *= (main.clientHeight || window.innerHeight || 800);
        }
        _readerWheelDelta += delta;
        if (Math.abs(_readerWheelDelta) < _READER_WHEEL_THRESHOLD) return;
        if (_readerWheelDelta > 0) {
            readerNextPage();
        } else {
            readerPrevPage();
        }
        _readerWheelDelta = 0;
        _readerWheelLocked = true;
    };
    try {
        main.addEventListener('wheel', _readerWheelHandler, { passive: false });
    } catch (_) {
        main.addEventListener('wheel', _readerWheelHandler);
    }
}


function openReader(source, sourceId) {
    var requestToken = ++_readerOpenToken;
    readerResetLazyImages();
    _readerSource = source;
    _readerSourceId = sourceId;
    _readerCurrentPage = 1;
    _readerTotalPages = 0;
    _readerImageList = [];
    document.getElementById('reader_page').classList.remove('section-hidden');
    readerBindWheel();
    var mainImg = document.getElementById('reader_main_img');
    if (mainImg) {
        try { mainImg.removeAttribute && mainImg.removeAttribute('src'); } catch (_) {}
        mainImg.src = '';
    }
    if (mainImg) mainImg.alt = '加载中...';
    var thumbList = document.getElementById('reader_thumb_list');
    if (thumbList) thumbList.innerHTML = '<div class="text-center text-muted small py-3"><i class="fas fa-spinner fa-spin"></i></div>';

    fetch(API + '?action=get_gallery_detail&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (requestToken !== _readerOpenToken || !data.ok || !data.gallery) return;
            var g = data.gallery;
            var displayTitle = g.title_jp || g.title;
            var titleEl = document.getElementById('reader_title'); if (titleEl) titleEl.textContent = displayTitle;
            var metaEl = document.getElementById('reader_meta'); if (metaEl) metaEl.textContent = g.total_pages + 'p · ' + (g.language || '-') + ' · ' + (g.uploaded_at || '-');
            var aEl = document.getElementById('reader_detail_artist'); if (aEl) aEl.textContent = '作者: ' + (g.artist || '-');
            var lEl = document.getElementById('reader_detail_lang'); if (lEl) lEl.textContent = '语言: ' + (g.language || '-');
            var cEl = document.getElementById('reader_detail_category'); if (cEl) cEl.textContent = '分类: ' + (g.category || '-');
            var uEl = document.getElementById('reader_detail_uploaded'); if (uEl) uEl.textContent = '上传: ' + (g.uploaded_at || '-');
            var headerDetails = document.getElementById('reader_header_details');
            if (headerDetails) headerDetails.classList.remove('show');
            var tagsHtml = '';
            var tagCount = (g.tags && g.tags.length > 0) ? g.tags.length : 0;
            if (tagCount > 0) {
                var typeLabels = { artist: 'bg-danger', parody: 'bg-warning text-dark', character: 'bg-primary', group: 'bg-success', language: 'bg-secondary', category: 'bg-info text-dark', tag: 'bg-light text-dark' };
                g.tags.forEach(function(t) {
                    var cls = typeLabels[t.type] || 'bg-light text-dark';
                    var rawName = String(t.name || t.raw || '');
                    var cn = String(t.name_cn || t.cn || '');
                    var aliases = [];
                    if (rawName) {
                        rawName.split(/\s*\|\s*/).forEach(function(p) {
                            p = (p || '').trim();
                            if (p && aliases.indexOf(p) < 0) aliases.push(p);
                        });
                    }
                    var display = cn || (aliases.length > 0 ? aliases.slice().sort(function(a, b) { return a.length - b.length; })[0] : rawName) || '-';
                    var tooltip = (cn && aliases.length > 0) ? (cn + ' — ' + aliases.join(' | ')) : (aliases.length > 1 ? aliases.join(' | ') : display);
                    tagsHtml += '<span class="tag-badge ' + cls + '" title="' + escapeAttr(tooltip) + '" onclick="event.stopPropagation();readerSearchTag(\'' + escapeAttr(t.type) + '\',\'' + escapeAttr(rawName) + '\')">' + escapeHtml(t.type) + ': ' + escapeHtml(display) + '</span> ';
                });
            } else {
                tagsHtml = '<span style="color:#666">无标签</span>';
            }
            var tagWarning = '<div class="mt-1" style="font-size:.75rem;color:#6b7280">标签: ' + tagCount + '个' +
                ' <button class="btn btn-sm btn-outline-warning py-0 px-1 ms-2" onclick="readerRefreshMetadata(\'' + escapeAttr(source) + '\',\'' + escapeAttr(sourceId) + '\')" style="font-size:.7rem">刷新元数据</button></div>';
            if (tagCount <= 1) {
                tagWarning = '<div class="mt-1 p-1 rounded" style="background:rgba(255,193,7,.15);font-size:.75rem;color:#ffc107">' +
                    '标签数 (<strong>' + tagCount + '</strong>) 过少，可能元数据不完整' +
                    '<button class="btn btn-sm btn-warning py-0 px-1 ms-2" onclick="readerRefreshMetadata(\'' + escapeAttr(source) + '\',\'' + escapeAttr(sourceId) + '\')" style="font-size:.7rem">刷新元数据</button></div>';
            }
            var detailTags = document.getElementById('reader_detail_tags');
            if (detailTags) {
                detailTags.innerHTML = tagsHtml;
                var existingWarning = document.getElementById('reader_tag_warning');
                if (existingWarning) { try { existingWarning.remove(); } catch (_) {} }
                try { detailTags.insertAdjacentHTML('afterend', '<div id="reader_tag_warning">' + tagWarning + '</div>'); } catch (_) {}
            }
        });

    fetch(API + '?action=get_image_list&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (requestToken !== _readerOpenToken || !data.ok || !data.images) return;
            _readerTotalPages = data.total_pages || data.images.length;
            _readerImageList = data.images;
            var pageTotal = document.getElementById('reader_page_total'); if (pageTotal) pageTotal.textContent = '/ ' + _readerTotalPages;
            var pageInput = document.getElementById('reader_page_input'); if (pageInput) pageInput.max = _readerTotalPages;

            var thumbHtml = '';
            data.images.forEach(function(img, idx) {
                var pageNum = img.page || (idx + 1);
                var fallbackThumbUrl = imageApiUrl(source, sourceId, pageNum);
                var thumbUrl = img.url || fallbackThumbUrl;
                if (img.file && thumbUrl) {
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<img data-src="' + thumbUrl + '" data-fallback="' + fallbackThumbUrl + '" alt="p' + pageNum + '" onload="readerThumbLoaded(this)" onerror="readerThumbError(this)">' +
                        '<span class="thumb-page-label">p' + pageNum + '</span>' +
                        '</div>';
                } else {
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<span class="thumb-page-label">p' + pageNum + '</span>' +
                        '</div>';
                }
            });
            var thumbList = document.getElementById('reader_thumb_list');
            if (thumbList) thumbList.innerHTML = thumbHtml;
            readerSetupThumbObserver();
            readerGoToPage(1);
        });

    if (_readerKeyHandler) try { document.removeEventListener('keydown', _readerKeyHandler); } catch (_) {}
    _readerKeyHandler = function(e) {
        if (e.key === 'Escape') { closeReader(); }
        else if (e.key === 'ArrowLeft') { try { e.preventDefault && e.preventDefault(); } catch (_) {} readerPrevPage(); }
        else if (e.key === 'ArrowRight') { try { e.preventDefault && e.preventDefault(); } catch (_) {} readerNextPage(); }
    };
    document.addEventListener('keydown', _readerKeyHandler);
}

function closeReader() {
    _readerOpenToken++;
    readerUnbindWheel();
    readerResetLazyImages();
    document.getElementById('reader_page').classList.add('section-hidden');
    var mainImg = document.getElementById('reader_main_img');
    if (mainImg) {
        try { mainImg.removeAttribute && mainImg.removeAttribute('src'); } catch (_) {}
        mainImg.src = '';
    }
    var thumbList = document.getElementById('reader_thumb_list');
    if (thumbList) try { thumbList.innerHTML = ''; } catch (_) {}
    _readerImageList = [];
    _readerTotalPages = 0;
    _readerCurrentPage = 1;
    if (_readerKeyHandler) {
        try { document.removeEventListener('keydown', _readerKeyHandler); } catch (_) {}
        _readerKeyHandler = null;
    }
}

function readerGoToPage(page) {
    page = parseInt(page, 10);
    if (!Number.isFinite(page)) page = _readerCurrentPage || 1;
    if (_readerTotalPages < 1) return;
    if (page < 1) page = 1;
    if (page > _readerTotalPages) page = _readerTotalPages;
    _readerCurrentPage = page;
    var pageInput = document.getElementById('reader_page_input'); if (pageInput) pageInput.value = page;
    readerUpdateThumbWindow(page);

    var thumbs = document.querySelectorAll('#reader_thumb_list .thumb-item');
    for (var i = 0; i < thumbs.length; i++) try { thumbs[i].classList.remove('active'); } catch (_) {}
    var activeThumb = document.querySelector('#reader_thumb_list .thumb-item[data-page="' + page + '"]');
    if (activeThumb) {
        try { activeThumb.classList.add('active'); } catch (_) {}
        try { activeThumb.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (_) {}
    }

    var fallbackImgUrl = _readerSource && _readerSourceId ? imageApiUrl(_readerSource, _readerSourceId, page) : '';
    var imgUrl = readerImageUrl(page);
    var mainImg = document.getElementById('reader_main_img');
    if (mainImg) {
        mainImg.alt = 'p' + page;
        try { delete mainImg.dataset.fallback; } catch (_) { try { mainImg.removeAttribute && mainImg.removeAttribute('data-fallback'); } catch (_) {} }
        mainImg.onerror = function() {
            var fb = this.getAttribute && this.getAttribute('data-fallback');
            if (fb) {
                this.onerror = function() { fallbackImageOnError(this); };
                try { this.style.display = ''; } catch (_) {}
                this.dataset.fallback = fb;
                this.src = fb;
            } else {
                fallbackImageOnError(this);
            }
        };
        if (imgUrl) {
            try { mainImg.style.display = ''; } catch (_) {}
            if (fallbackImgUrl) mainImg.dataset.fallback = fallbackImgUrl;
            mainImg.src = imgUrl;
        } else {
            try { mainImg.removeAttribute && mainImg.removeAttribute('src'); } catch (_) {}
            try { mainImg.style.display = 'none'; } catch (_) {}
        }
    }

    readerUpdatePreloadWindow(page);
}

function readerPrevPage() {
    if (_readerCurrentPage > 1) readerGoToPage(_readerCurrentPage - 1);
}

function readerNextPage() {
    if (_readerCurrentPage < _readerTotalPages) readerGoToPage(_readerCurrentPage + 1);
}
