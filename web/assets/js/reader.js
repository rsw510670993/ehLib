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

var _readerCurrentPage = 1;
var _readerTotalPages = 0;
var _readerImageList = [];
var _readerThumbRadius = 4;
var _readerPreloadBefore = 2;
var _readerPreloadAfter = 3;
var _readerPreloadImages = {};
var _readerThumbObserver = null;
var _readerOpenToken = 0;
var _readerWheelHandler = null;
var _readerWheelResetTimer = null;
var _readerWheelDelta = 0;
var _readerWheelLocked = false;
var _readerWheelThreshold = 60;
var _readerWheelIdleMs = 160;

function readerImageForPage(page) {
    for (var i = 0; i < _readerImageList.length; i++) {
        if ((_readerImageList[i].page || (i + 1)) === page) return _readerImageList[i];
    }
    return null;
}

function readerImageUrl(page) {
    var img = readerImageForPage(page);
    return (img && img.url) ? img.url : '';
}
function readerClearPreloads() {
    Object.keys(_readerPreloadImages).forEach(function(key) {
        var preload = _readerPreloadImages[key];
        preload.onload = null;
        preload.onerror = null;
        preload.removeAttribute('src');
    });
    _readerPreloadImages = {};
}

function readerLoadThumbImage(img) {
    if (!img || img.getAttribute('src') || !img.dataset.src) return;
    img.style.display = '';
    img.src = img.dataset.src;
}

function readerUnloadThumbImage(img) {
    if (!img || !img.getAttribute('src')) return;
    img.removeAttribute('src');
    img.classList.remove('is-loaded');
    img.style.display = '';
}

function readerThumbLoaded(img) {
    img.classList.add('is-loaded');
}

function readerThumbError(img) {
    readerUnloadThumbImage(img);
}

function readerSetupThumbObserver() {
    if (_readerThumbObserver) _readerThumbObserver.disconnect();
    _readerThumbObserver = null;
    if (typeof IntersectionObserver === 'undefined') return;

    var root = document.getElementById('reader_thumbstrip');
    _readerThumbObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            var img = entry.target;
            var item = img.closest('.thumb-item');
            var page = item ? parseInt(item.dataset.page, 10) : 0;
            if (entry.isIntersecting) {
                readerLoadThumbImage(img);
            } else if (page && Math.abs(page - _readerCurrentPage) > _readerThumbRadius) {
                readerUnloadThumbImage(img);
            }
        });
    }, { root: root, rootMargin: '240px 0px' });

    document.querySelectorAll('#reader_thumb_list img[data-src]').forEach(function(img) {
        _readerThumbObserver.observe(img);
    });
}

function readerUpdateThumbWindow(page) {
    var minPage = Math.max(1, page - _readerThumbRadius);
    var maxPage = Math.min(_readerTotalPages, page + _readerThumbRadius);
    document.querySelectorAll('#reader_thumb_list .thumb-item').forEach(function(item) {
        var itemPage = parseInt(item.dataset.page, 10);
        var img = item.querySelector('img[data-src]');
        if (!img) return;
        if (itemPage >= minPage && itemPage <= maxPage) readerLoadThumbImage(img);
        else readerUnloadThumbImage(img);
    });
}

function readerUpdatePreloadWindow(page) {
    var desired = {};
    var first = Math.max(1, page - _readerPreloadBefore);
    var last = Math.min(_readerTotalPages, page + _readerPreloadAfter);
    for (var candidate = first; candidate <= last; candidate++) {
        if (candidate !== page) desired[candidate] = true;
    }

    Object.keys(_readerPreloadImages).forEach(function(key) {
        if (desired[key]) return;
        var preload = _readerPreloadImages[key];
        preload.onload = null;
        preload.onerror = null;
        preload.removeAttribute('src');
        delete _readerPreloadImages[key];
    });

    Object.keys(desired).forEach(function(key) {
        if (_readerPreloadImages[key]) return;
        var preloadUrl = readerImageUrl(parseInt(key, 10));
        if (!preloadUrl) return;
        var preload = new Image();
        preload.src = preloadUrl;
        _readerPreloadImages[key] = preload;
    });
}

function readerResetLazyImages() {
    if (_readerThumbObserver) _readerThumbObserver.disconnect();
    _readerThumbObserver = null;
    readerClearPreloads();
    document.querySelectorAll('#reader_thumb_list img[data-src]').forEach(readerUnloadThumbImage);
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
        main.removeEventListener('wheel', _readerWheelHandler);
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

        event.preventDefault();
        if (_readerWheelResetTimer) clearTimeout(_readerWheelResetTimer);
        _readerWheelResetTimer = setTimeout(function() {
            readerResetWheelGesture();
        }, _readerWheelIdleMs);

        if (_readerWheelLocked) return;

        var delta = event.deltaY;
        if (event.deltaMode === 1) {
            delta *= 16;
        } else if (event.deltaMode === 2) {
            delta *= main.clientHeight || window.innerHeight || 800;
        }
        _readerWheelDelta += delta;
        if (Math.abs(_readerWheelDelta) < _readerWheelThreshold) return;

        if (_readerWheelDelta > 0) {
            readerNextPage();
        } else {
            readerPrevPage();
        }
        _readerWheelDelta = 0;
        _readerWheelLocked = true;
    };
    main.addEventListener('wheel', _readerWheelHandler, { passive: false });
}


function openReader(source, sourceId) {
    var requestToken = ++_readerOpenToken;
    readerResetLazyImages();
    _readerCurrentPage = 1;
    _readerTotalPages = 0;
    _readerImageList = [];
    document.getElementById('reader_page').classList.remove('section-hidden');
    readerBindWheel();
    document.getElementById('reader_main_img').removeAttribute('src');
    document.getElementById('reader_main_img').alt = '加载中...';
    document.getElementById('reader_thumb_list').innerHTML = '<div class="text-center text-muted small py-3"><i class="fas fa-spinner fa-spin"></i></div>';

    // Load metadata
    fetch(API + '?action=get_gallery_detail&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (requestToken !== _readerOpenToken || !data.ok || !data.gallery) return;
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
                    // 多别名显示：优先 name_cn（PHP 已按 | 拆分 + EhTagTranslation 比对）；否则对 name 拆 | 取最短 alias，tooltip 写全名
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
                    tagsHtml += '<span class="tag-badge ' + cls + '" title="' + escapeAttr(tooltip) + '" onclick="event.stopPropagation();readerSearchTag(\'' + escapeAttr(t.type) + '\',\'' + escapeAttr(rawName) + '\')">' + escapeAttr(t.type) + ': ' + escapeHtml(display) + '</span> ';
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
            if (requestToken !== _readerOpenToken || !data.ok || !data.images) return;
            _readerTotalPages = data.total_pages || data.images.length;
            _readerImageList = data.images;
            document.getElementById('reader_page_total').textContent = '/ ' + _readerTotalPages;
            document.getElementById('reader_page_input').max = _readerTotalPages;

            var thumbHtml = '';
            data.images.forEach(function(img, idx) {
                var pageNum = img.page || (idx + 1);
                if (img.file && img.url) {
                    var thumbUrl = img.url;
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<img data-src="' + thumbUrl + '" alt="p' + pageNum + '" onload="readerThumbLoaded(this)" onerror="readerThumbError(this)">' +
                        '<span class="thumb-page-label">p' + pageNum + '</span>' +
                        '</div>';
                } else {
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<span class="thumb-page-label">p' + pageNum + '</span>' +
                        '</div>';
                }
            });
            document.getElementById('reader_thumb_list').innerHTML = thumbHtml;
            readerSetupThumbObserver();
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
    _readerOpenToken++;
    readerUnbindWheel();
    readerResetLazyImages();
    document.getElementById('reader_page').classList.add('section-hidden');
    document.getElementById('reader_main_img').removeAttribute('src');
    document.getElementById('reader_thumb_list').innerHTML = '';
    _readerImageList = [];
    _readerTotalPages = 0;
    _readerCurrentPage = 1;
    if (_readerKeyHandler) {
        document.removeEventListener('keydown', _readerKeyHandler);
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
    document.getElementById('reader_page_input').value = page;
    readerUpdateThumbWindow(page);

    // Update thumb active
    var thumbs = document.querySelectorAll('#reader_thumb_list .thumb-item');
    thumbs.forEach(function(t) { t.classList.remove('active'); });
    var activeThumb = document.querySelector('#reader_thumb_list .thumb-item[data-page="' + page + '"]');
    if (activeThumb) {
        activeThumb.classList.add('active');
        activeThumb.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // Load main image
    var imgUrl = readerImageUrl(page);
    var mainImg = document.getElementById('reader_main_img');
    mainImg.alt = 'p' + page;
    delete mainImg.dataset.fallback;
    mainImg.onerror = function() { this.style.display = 'none'; };
    if (imgUrl) {
        mainImg.style.display = '';
        mainImg.src = imgUrl;
    } else {
        mainImg.removeAttribute('src');
        mainImg.style.display = 'none';
    }

    // Keep only a small decoded-image window around the current page.
    readerUpdatePreloadWindow(page);
}

function readerPrevPage() {
    if (_readerCurrentPage > 1) readerGoToPage(_readerCurrentPage - 1);
}

function readerNextPage() {
    if (_readerCurrentPage < _readerTotalPages) readerGoToPage(_readerCurrentPage + 1);
}
