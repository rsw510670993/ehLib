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
// 主图区：高度适配 + 按可用宽度自适配单页/双页
var _readerIsSpread = false;
var _readerPageRatio = 1280 / 1743;   // 首张图 onload 后按真实比例更新
var _readerResizeBound = false;
var _readerResizeTimer = null;
// ─── Reader ───





function readerToggleDetails() {
    var el = document.getElementById('reader_header_details');
    var btn = document.getElementById('reader_toggle_details');
    if (!el) return;
    var collapsed = el.classList.toggle('is-collapsed');
    el.classList.toggle('show', !collapsed);
    if (btn) btn.innerHTML = collapsed ? '<i class="fas fa-chevron-down"></i>' : '<i class="fas fa-chevron-up"></i>';
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
    var btn = document.getElementById('reader_refresh_meta');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        var res = await api('refresh_metadata', { form: { action: 'refresh_metadata', source: source, source_id: sourceId } });
        if (!res.ok) throw new Error(res.error || res.output || '刷新失败');
        showToast('元数据已刷新', 'success');
        openReader(source, sourceId);
    } catch (err) {
        showToast('刷新元数据失败: ' + (err.message || ''), 'danger');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-rotate"></i> 刷新元数据'; }
    }
}

function readerRefreshMetaCurrent() {
    if (_readerSource && _readerSourceId) readerRefreshMetadata(_readerSource, _readerSourceId);
}

// 标签行默认最多两行，超出给「展开全部」按钮
function readerSetupTagToggle() {
    var wrap = document.getElementById('reader_detail_tags');
    var btn = document.getElementById('reader_tag_toggle');
    if (!wrap || !btn) return;
    wrap.classList.remove('is-expanded');
    btn.hidden = true;
    var total = wrap.querySelectorAll('.tag-badge').length;
    var measure = function() {
        var overflow = wrap.scrollHeight > wrap.clientHeight + 2;
        btn.hidden = !overflow;
        if (overflow) btn.textContent = '展开全部 ' + total + ' 个标签';
    };
    if (window.requestAnimationFrame) window.requestAnimationFrame(measure);
    else setTimeout(measure, 30);
    btn.onclick = function() {
        var open = wrap.classList.toggle('is-expanded');
        btn.textContent = open ? '收起标签' : '展开全部 ' + total + ' 个标签';
    };
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

// ─── 主图区：高度适配 + 单页/双页自适配 ───
function readerStageEl() { return document.getElementById('reader_page_stage'); }
function readerSecondImgEl() { return document.getElementById('reader_second_img'); }

function readerToggleDrawer(on) {
    var strip = document.getElementById('reader_thumbstrip');
    var scrim = document.getElementById('reader_drawer_scrim');
    if (!strip) return;
    var open = (typeof on === 'boolean') ? on : !strip.classList.contains('is-open');
    try { strip.classList.toggle('is-open', open); } catch (_) {}
    if (scrim) try { scrim.classList.toggle('is-open', open); } catch (_) {}
}

function readerMeasureRatio() {
    var img = document.getElementById('reader_main_img');
    if (img && img.naturalWidth && img.naturalHeight) {
        _readerPageRatio = img.naturalWidth / img.naturalHeight;
    }
}

// 高度适配时单页占宽 = 可用高 × 页宽高比；能并排两页 + 中缝(6) + 余量(16) 才算双页
function readerComputeSpread() {
    var stage = readerStageEl();
    if (!stage) return false;
    var w = stage.clientWidth, h = stage.clientHeight;
    if (!w || !h) return false;
    return w >= (h * _readerPageRatio) * 2 + 6 + 16;
}

// 成对规则：p1 单独（封面），此后偶数页起成对 [2,3] [4,5] …
function readerPairOf(page) {
    if (!_readerIsSpread || page <= 1) return [page];
    var left = (page % 2 === 0) ? page : page - 1;
    if (left + 1 > _readerTotalPages) return [page];
    return [left, left + 1];
}

function readerSetPageImg(el, page) {
    if (!el) return;
    if (!page) {
        el.hidden = true;
        try { el.removeAttribute('src'); } catch (_) {}
        el.alt = '';
        return;
    }
    var url = readerImageUrl(page);
    var fallback = (_readerSource && _readerSourceId) ? imageApiUrl(_readerSource, _readerSourceId, page) : '';
    el.hidden = false;
    try { el.classList.remove('is-broken'); } catch (_) {}
    el.alt = '第 ' + page + ' 页';
    if (fallback) { try { el.dataset.fallback = fallback; } catch (_) {} }
    el.onload = function () { readerMeasureRatio(); };
    el.onerror = function () {
        var fb = this.getAttribute && this.getAttribute('data-fallback');
        if (fb && this.getAttribute('src') !== fb) {
            try { this.removeAttribute('data-fallback'); } catch (_) {}
            this.src = fb;
            return;
        }
        try { this.classList.add('is-broken'); } catch (_) {}
    };
    if (el.getAttribute('src') !== url) el.src = url;
}

function readerRenderPages(page) {
    readerMeasureRatio();
    var pair = readerPairOf(page);
    readerSetPageImg(document.getElementById('reader_main_img'), pair[0]);
    readerSetPageImg(readerSecondImgEl(), pair.length > 1 ? pair[1] : null);
    var spread = pair.length > 1;
    var stage = readerStageEl();
    if (stage) try { stage.classList.toggle('is-spread', spread); } catch (_) {}
    var badge = document.getElementById('reader_mode_badge');
    if (badge) {
        badge.dataset.mode = spread ? 'spread' : 'single';
        badge.textContent = spread ? '双页' : '单页';
        badge.title = spread ? '当前按双页显示（可用宽度够并排两页）' : '当前按单页显示';
    }
}

function readerEnsureResizeHook() {
    if (_readerResizeBound) return;
    _readerResizeBound = true;
    window.addEventListener('resize', function () {
        if (!_readerTotalPages) return;
        if (_readerResizeTimer) clearTimeout(_readerResizeTimer);
        _readerResizeTimer = setTimeout(function () { readerGoToPage(_readerCurrentPage); }, 120);
    });
}

function readerResetWheelGesture() {    if (_readerWheelResetTimer) {
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
    readerToggleDrawer(false);
    readerEnsureResizeHook();
    _readerIsSpread = false;
    var mainImg = document.getElementById('reader_main_img');
    if (mainImg) {
        try { mainImg.removeAttribute && mainImg.removeAttribute('src'); } catch (_) {}
        try { mainImg.classList.remove('is-broken'); } catch (_) {}
    }
    if (mainImg) mainImg.alt = '加载中...';
    var secondImg = document.getElementById('reader_second_img');
    if (secondImg) {
        try { secondImg.removeAttribute && secondImg.removeAttribute('src'); } catch (_) {}
        try { secondImg.classList.remove('is-broken'); } catch (_) {}
        secondImg.hidden = true;
        secondImg.alt = '';
    }
    var modeBadge = document.getElementById('reader_mode_badge');
    if (modeBadge) { modeBadge.dataset.mode = 'single'; modeBadge.textContent = '单页'; }
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
            var aEl = document.getElementById('reader_detail_artist'); if (aEl) aEl.textContent = g.artist || '-';
            var lEl = document.getElementById('reader_detail_lang'); if (lEl) lEl.textContent = g.language || '-';
            var cEl = document.getElementById('reader_detail_category'); if (cEl) cEl.textContent = g.category || '-';
            var uEl = document.getElementById('reader_detail_uploaded'); if (uEl) uEl.textContent = g.uploaded_at || '-';
            var headerDetails = document.getElementById('reader_header_details');
            if (headerDetails) { headerDetails.classList.remove('show'); headerDetails.classList.remove('is-collapsed'); }
            var tagsHtml = '';
            var tagCount = (g.tags && g.tags.length > 0) ? g.tags.length : 0;
            var typeLabels = {
                artist: '画师', parody: '原作', character: '角色', group: '社团', circle: '社团',
                language: '语言', category: '分类', tag: '标签',
                female: '女性', male: '男性', mixed: '混合', other: '其他'
            };
            g.tags && g.tags.forEach(function(t) {
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
                var typeKey = String(t.type || 'tag');
                var typeLabel = typeLabels[typeKey] || typeKey;
                tagsHtml += '<button type="button" class="tag-badge" data-type="' + escapeAttr(typeKey) + '"' +
                    ' title="' + escapeAttr(typeLabel + ': ' + tooltip) + '"' +
                    ' onclick="event.stopPropagation();readerSearchTag(\'' + escapeAttr(typeKey) + '\',\'' + escapeAttr(rawName) + '\')">' +
                    '<span class="tag-type">' + escapeHtml(typeLabel) + '</span>' +
                    '<span class="tag-name">' + escapeHtml(display) + '</span>' +
                    '</button>';
            });
            var detailTags = document.getElementById('reader_detail_tags');
            if (detailTags) detailTags.innerHTML = tagsHtml || '<span class="tags-empty">无标签</span>';
            var countEl = document.getElementById('reader_tag_count');
            if (countEl) countEl.textContent = tagCount + ' 个标签';
            var warningBox = document.getElementById('reader_tag_warning');
            if (warningBox) {
                warningBox.innerHTML = (tagCount <= 1)
                    ? '<div class="tag-warning"><i class="fas fa-triangle-exclamation"></i><span>标签只有 ' + tagCount + ' 个，元数据可能不完整</span></div>'
                    : '';
            }
            readerSetupTagToggle();
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
                    thumbHtml += '<button type="button" class="thumb-item" data-page="' + pageNum + '" aria-label="第 ' + pageNum + ' 页" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<img data-src="' + thumbUrl + '" data-fallback="' + fallbackThumbUrl + '" alt="p' + pageNum + '" onload="readerThumbLoaded(this)" onerror="readerThumbError(this)">' +
                        '<span class="thumb-page-label">p' + pageNum + '</span>' +
                        '</button>';
                } else {
                    thumbHtml += '<button type="button" class="thumb-item" data-page="' + pageNum + '" aria-label="第 ' + pageNum + ' 页" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<span class="thumb-page-label">p' + pageNum + '</span>' +
                        '</button>';
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
    readerToggleDrawer(false);
    document.getElementById('reader_page').classList.add('section-hidden');
    var mainImg = document.getElementById('reader_main_img');
    if (mainImg) {
        try { mainImg.removeAttribute && mainImg.removeAttribute('src'); } catch (_) {}
        try { mainImg.classList.remove('is-broken'); } catch (_) {}
    }
    var secondImg = document.getElementById('reader_second_img');
    if (secondImg) {
        try { secondImg.removeAttribute && secondImg.removeAttribute('src'); } catch (_) {}
        try { secondImg.classList.remove('is-broken'); } catch (_) {}
        secondImg.hidden = true;
        secondImg.alt = '';
    }
    _readerIsSpread = false;
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
    _readerIsSpread = readerComputeSpread();   // 每次渲染前按当前可用宽度重判，避免初始尺寸未稳定时算错
    var pageInput = document.getElementById('reader_page_input'); if (pageInput) pageInput.value = page;
    readerUpdateThumbWindow(page);

    var thumbs = document.querySelectorAll('#reader_thumb_list .thumb-item');
    for (var i = 0; i < thumbs.length; i++) try { thumbs[i].classList.remove('active'); } catch (_) {}
    var activeThumb = document.querySelector('#reader_thumb_list .thumb-item[data-page="' + page + '"]');
    if (activeThumb) {
        try { activeThumb.classList.add('active'); } catch (_) {}
        try { activeThumb.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (_) {}
    }

    readerRenderPages(page);

    // 双页时两侧缩略图都要挂载，别让右页留在卸载态
    var pairPages = readerPairOf(page);
    for (var p = 0; p < pairPages.length; p++) {
        var btn = document.querySelector('#reader_thumb_list .thumb-item[data-page="' + pairPages[p] + '"] img[data-src]');
        if (btn && !btn.getAttribute('src')) readerLoadThumbImage(btn);
    }

    readerUpdatePreloadWindow(page);

    // 移动端：点缩略图后收起抽屉
    if (window.innerWidth <= 639) readerToggleDrawer(false);
}

function readerPrevPage() {
    if (_readerIsSpread) {
        if (_readerCurrentPage <= 2) return readerGoToPage(1);
        readerGoToPage(Math.max(1, readerPairOf(_readerCurrentPage)[0] - 2));
        return;
    }
    if (_readerCurrentPage > 1) readerGoToPage(_readerCurrentPage - 1);
}

function readerNextPage() {
    if (_readerIsSpread) {
        if (_readerCurrentPage === 1) return readerGoToPage(2);
        var nx = readerPairOf(_readerCurrentPage)[0] + 2;
        readerGoToPage(nx <= _readerTotalPages ? nx : Math.min(_readerCurrentPage + 1, _readerTotalPages));
        return;
    }
    if (_readerCurrentPage < _readerTotalPages) readerGoToPage(_readerCurrentPage + 1);
}
