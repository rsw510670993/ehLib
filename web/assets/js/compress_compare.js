// ─── Compress Compare ───
// 方案 B（先可用再扩展）：单本 Modal 并排比较 + 整本批准 / 整本重做
// 入口：gallery.js 事件委托 → data-compare-gallery 按钮 → openCompressCompare({gallery_id, source, source_id})
let _cc_ctx = {
    modal: null,        // Bootstrap Modal instance
    gallery_id: null,
    source: '',
    source_id: '',
    gallery: null,      // api 返回的 gallery 行
    info: null,         // compression_info JSON 解析后
    pages: [],          // info.pages 的浅拷贝
    work_dir_exists: true,
    cur_idx: 0,
    zoom: { orig: 'fit', cmp: 'fit' },
    page_sizes: [],     // [{page_idx, name, w, h}] 原图尺寸数组 (由 get_compress_review_one 返回)
    pollingTimer: null
};

function openCompressCompare(args) {
    args = args || {};
    var gid = String(args.gallery_id || '');
    var src = String(args.source || '');
    var sid = String(args.source_id || '');
    if (!gid || !src || !sid) {
        showToast('压缩对比：缺少参数', 'warning');
        return;
    }
    _cc_ctx = {
        modal: null,
        gallery_id: gid,
        source: src,
        source_id: sid,
        gallery: null,
        info: null,
        pages: [],
        work_dir_exists: true,
        cur_idx: 0,
        zoom: { orig: 'fit', cmp: 'fit' },
        page_sizes: [],
        pollingTimer: null
    };
    var modalEl = document.getElementById('compress_compare_modal');
    if (!modalEl) { showToast('Modal 骨架不存在', 'danger'); return; }
    _cc_ctx.modal = new bootstrap.Modal(modalEl, { backdrop: true, keyboard: false });
    document.getElementById('cc_btn_approve').disabled = false;
    document.getElementById('cc_btn_rerun').disabled = false;
    _cc_ctx.modal.show();
    // 初始：双图隐藏，显示加载
    _ccSetEmpty('加载压缩审核信息…');
    _ccBindStatic();
    _ccFetchInfo();
}

// ═══ Static one-time bindings ═══
function _ccBindStatic() {
    var modalEl = document.getElementById('compress_compare_modal');
    if (modalEl.dataset.bound === '1') return;
    modalEl.dataset.bound = '1';

    document.getElementById('cc_prev').addEventListener('click', function () { _ccGo(-1); });
    document.getElementById('cc_next').addEventListener('click', function () { _ccGo(+1); });

    // 缩放按钮
    modalEl.querySelectorAll('.cc-zoom').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var side = btn.getAttribute('data-side');
            var zoom = btn.getAttribute('data-zoom');
            if (!side || !zoom) return;
            _ccSetZoom(side, zoom);
        });
    });

    // 复制路径按钮
    modalEl.querySelectorAll('.cc-copy-path').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var side = btn.getAttribute('data-side');
            _ccCopyPath(side);
        });
    });

    // 整本批准 / 重做
    document.getElementById('cc_btn_approve').addEventListener('click', _ccOnApprove);
    document.getElementById('cc_btn_rerun').addEventListener('click', _ccOnRerun);

    // Esc 关 modal（Bootstrap 默认 keyboard=false 关不掉，用自写捕获）
    document.addEventListener('keydown', _ccOnKeydown);
    modalEl.addEventListener('hidden.bs.modal', function () {
        if (_cc_ctx.pollingTimer) { clearInterval(_cc_ctx.pollingTimer); _cc_ctx.pollingTimer = null; }
    });
}

function _ccOnKeydown(e) {
    var m = document.getElementById('compress_compare_modal');
    if (!m || !m.classList || !m.classList.contains('show')) return;
    var tag = (document.activeElement && document.activeElement.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
    if (e.key === 'Escape') { _ccHideModal(); return; }
    if (e.key === 'ArrowLeft')  { _ccGo(-1); return; }
    if (e.key === 'ArrowRight') { _ccGo(+1); return; }
    if (e.key === '1') {
        _ccSetZoom('orig', 'fit');
        _ccSetZoom('cmp', 'fit');
        return;
    }
    if (e.key === '2') {
        _ccSetZoom('orig', '100');
        _ccSetZoom('cmp', '100');
        return;
    }
    if (e.key === '3') {
        _ccSetZoom('orig', '200');
        _ccSetZoom('cmp', '200');
        return;
    }
}

function _ccHideModal() {
    try { if (_cc_ctx.modal) _cc_ctx.modal.hide(); } catch (_) {}
}

// ═══ API ═══
async function _ccFetchInfo() {
    _ccSetEmpty('加载压缩审核信息…');
    try {
        const r = await api('get_compress_review_one', { form: { gallery_id: _cc_ctx.gallery_id } });
        if (!r || !r.ok) {
            var msg = (r && r.error) || '获取压缩信息失败';
            _ccSetEmpty(msg + '（请先跑 Phase 1 CLI）');
            return;
        }
        _cc_ctx.gallery = r.gallery || {};
        _cc_ctx.info = r.info || null;
        _cc_ctx.pages = ((r.info && r.info.pages) ? r.info.pages.slice() : []);
        _cc_ctx.work_dir_exists = !!r.work_dir_served;
        _cc_ctx.page_sizes = r.page_sizes || [];
        if (_cc_ctx.pages.length === 0) {
            _ccSetEmpty('compression_info 为空，尚未生成压缩候选。请先跑 CLI 然后再点「整本重做」。');
            return;
        }
        if (_cc_ctx.cur_idx >= _cc_ctx.pages.length) _cc_ctx.cur_idx = 0;
        _ccRenderHeader();
        _ccRenderThumbs();
        _ccRenderPage();
        // 隐藏 loading 提示、显示主区
        document.getElementById('cc_empty').classList.add('d-none');
        document.getElementById('cc_empty').style.display = 'none';
        document.getElementById('cc_toolbar').classList.remove('d-none');
        document.getElementById('cc_pane_row').classList.remove('d-none');
        document.getElementById('cc_pane_row').style.display = 'flex';
    } catch (e) {
        _ccSetEmpty('加载出错: ' + (e.message || e));
    }
}

// ═══ Header render ═══
function _ccRenderHeader() {
    var g = _cc_ctx.gallery || {};
    var info = _cc_ctx.info || {};
    var s = info;
    var status = g.compression_status || '';
    var badgeEl = document.getElementById('cc_badge');
    var badgeCls = 'bg-secondary', badgeText = status || '—';
    if (status === 'user_review_required') { badgeCls = 'bg-warning text-dark'; badgeText = '待审核'; }
    else if (status === 'approved_pending_apply') { badgeCls = 'bg-success'; badgeText = '已批准待应用'; }
    else if (status === 'failed') { badgeCls = 'bg-danger'; badgeText = '压缩失败'; }
    else if (status === 'compressing') { badgeCls = 'bg-info text-dark'; badgeText = '压缩中…'; }
    badgeEl.className = 'badge me-2 ' + badgeCls;
    badgeEl.textContent = badgeText;
    document.getElementById('cc_btn_approve').disabled = (status === 'approved_pending_apply');

    document.getElementById('cc_title').textContent = (g.title || g.title_jp || '未命名') + '  （id=' + (_cc_ctx.gallery_id) + '）';
    var meta = [];
    meta.push('source: ' + (g.source || '-') + '/' + (g.source_id || '-'));
    meta.push((g.total_pages || _cc_ctx.pages.length) + ' 页');
    if (g.file_size) meta.push(formatFileSize(parseInt(g.file_size || 0, 10) || 0));
    document.getElementById('cc_meta').textContent = meta.join(' · ');

    var params = [];
    params.push('quality=' + (s.quality ?? '?'));
    params.push('method=' + (s.method ?? '?'));
    params.push('min_savings=' + (s.min_savings_percent ?? '?') + '%');
    if (s.override_used) params.push('override_used=true');
    if (s.force_candidates) params.push('全页候选=true');
    params.push('orig ' + (s.orig_bytes_total ?? 0).toLocaleString() + 'B');
    params.push('webp ' + (s.webp_bytes_total ?? 0).toLocaleString() + 'B');
    var sp = parseFloat(s.savings_pct_overall ?? 0);
    params.push('节省 ' + sp.toFixed(2) + '%');
    if (s.used_webp_count != null) params.push('used=' + s.used_webp_count + '/' + (s.total_pages ?? _cc_ctx.pages.length));
    if (s.failed_pages_count) params.push('failed=' + s.failed_pages_count);
    document.getElementById('cc_params').textContent = params.join(' · ');
    // 升级提示（方案 B 提示）
    document.getElementById('cc_upgrade_hint').style.display = 'block';
}

// ═══ Thumbnails ═══
function _ccRenderThumbs() {
    var box = document.getElementById('cc_thumbs');
    box.innerHTML = '';
    var info = _cc_ctx.info || {};
    var workDirExists = _cc_ctx.work_dir_exists;
    for (var i = 0; i < _cc_ctx.pages.length; i++) {
        var p = _cc_ctx.pages[i] || {};
        var dotClass = 'dot-skipped', dotLetter = 'Skipped';
        if (p.exception) { dotClass = 'dot-failed'; dotLetter = 'Failed'; }
        else if (p.used_webp) { dotClass = 'dot-used'; dotLetter = 'Used'; }
        if ((_cc_ctx.gallery || {}).compression_status === 'approved_pending_apply' && p.used_webp) {
            dotClass = 'dot-approved'; dotLetter = 'Approved';
        }
        var thumb = document.createElement('div');
        thumb.className = 'cc-thumb' + (i === _cc_ctx.cur_idx ? ' active' : '');
        thumb.dataset.idx = String(i);
        thumb.addEventListener('click', function () {
            _cc_ctx.cur_idx = parseInt(this.dataset.idx, 10) || 0;
            _ccRenderThumbs();
            _ccRenderPage();
        });
        var pageNo = (parseInt(p.page_index, 10) >= 0 ? (parseInt(p.page_index, 10) + 1) : (i + 1));
        var imgSrc = 'api.php?action=serve_image&source=' + encodeURIComponent(_cc_ctx.source) +
            '&source_id=' + encodeURIComponent(_cc_ctx.source_id) +
            '&page=' + pageNo;
        thumb.innerHTML =
            '<div class="cc-thumb-dot ' + dotClass + '" title="' +
                escapeAttr(p.used_webp ? '已生成 WebP 候选' : (p.exception ? '压缩失败' : '未生成 (skipped)')) +
                '">' + dotLetter + '</div>' +
            '<img src="' + escapeAttr(imgSrc) + '" loading="lazy" alt="" onerror="this.style.background=\'#1e293b\'">' +
            '<div class="cc-thumb-label">' + String(pageNo).padStart(2, '0') + '</div>';
        box.appendChild(thumb);
    }
    if (!workDirExists) {
        var warn = document.createElement('div');
        warn.className = 'small text-warning mt-2 px-1';
        warn.innerHTML = '<i class="fas fa-triangle-exclamation me-1"></i>work_dir 不存在或不可读，<br>候选图无法显示。请点「整本重做」。';
        box.appendChild(warn);
    }
}

// ═══ Page render (main twin panes) ═══
function _ccRenderPage() {
    if (_cc_ctx.pages.length === 0) return;
    var i = _cc_ctx.cur_idx;
    if (i < 0) i = 0;
    if (i >= _cc_ctx.pages.length) i = _cc_ctx.pages.length - 1;
    _cc_ctx.cur_idx = i;
    var p = _cc_ctx.pages[i] || {};
    var pageNo = (parseInt(p.page_index, 10) >= 0 ? (parseInt(p.page_index, 10) + 1) : (i + 1));
    var origName = p.name || '';
    var origBytes = parseInt(p.orig_bytes || 0, 10) || 0;
    var webpBytes = parseInt(p.webp_bytes || 0, 10) || 0;
    var savingsPct = parseFloat(p.savings_pct || 0);
    var srcFormat = (p.src_format || '').toUpperCase() || (origName.split('.').pop() || '').toUpperCase();
    var wh = { w: p.width || '', h: p.height || '' };
    var expectedPageIdx = (parseInt(p.page_index, 10) >= 0 ? parseInt(p.page_index, 10) : i);
    var pageSize = null;
    // 若 compression_info.pages[] 没带宽高，从 page_sizes 补
    if (!wh.w && _cc_ctx.page_sizes) {
        pageSize = _cc_ctx.page_sizes.find(function (x) {
            return (x && parseInt(x.page_idx, 10) === expectedPageIdx);
        });
        if (pageSize) { wh.w = pageSize.w || ''; wh.h = pageSize.h || ''; }
    }
    // 设置页码 label
    document.getElementById('cc_page_label').textContent = pageNo + ' / ' + _cc_ctx.pages.length;
    // 缩略图 active 同步（cur_idx 没变也不担心重绘闪烁）
    document.querySelectorAll('#cc_thumbs .cc-thumb').forEach(function (t) {
        if (parseInt(t.dataset.idx, 10) === i) t.classList.add('active'); else t.classList.remove('active');
    });

    // 左：原图
    var origUrl = 'api.php?action=serve_image&source=' + encodeURIComponent(_cc_ctx.source) +
        '&source_id=' + encodeURIComponent(_cc_ctx.source_id) + '&page=' + pageNo;
    var imgOrig = document.getElementById('cc_img_orig');
    imgOrig.src = origUrl;
    imgOrig.style.display = 'block';
    imgOrig.dataset.path = p.orig_disk_path || _ccJoinDiskPath((_cc_ctx.gallery || {}).local_path || (_cc_ctx.info || {}).gallery_dir, origName);
    imgOrig.dataset.name = origName;

    // 右：候选
    var imgCmp = document.getElementById('cc_img_cmp');
    var cmpTag = document.getElementById('cc_cmp_tag');
    var cmpPlaceholder = document.getElementById('cc_cmp_placeholder');
    if (p.exception) {
        imgCmp.removeAttribute('src');
        imgCmp.removeAttribute('data-path');
        imgCmp.style.display = 'none';
        if (cmpPlaceholder) {
            cmpPlaceholder.style.display = 'block';
            cmpPlaceholder.textContent = 'Failed: ' + String(p.exception || '未知错误');
        }
        cmpTag.className = 'badge bg-danger';
        cmpTag.textContent = 'FAILED';
    } else if (!p.used_webp || !_cc_ctx.work_dir_exists) {
        imgCmp.removeAttribute('src');
        imgCmp.removeAttribute('data-path');
        imgCmp.style.display = 'none';
        if (cmpPlaceholder) cmpPlaceholder.style.display = 'block';
        cmpTag.className = 'badge bg-secondary';
        if (p.no_savings) cmpTag.textContent = 'SKIPPED (no_savings)';
        else if (p.poor_ratio) cmpTag.textContent = 'SKIPPED (poor_ratio)';
        else if (p.src_format && (p.src_format.toLowerCase() === 'gif' || p.src_format.toLowerCase() === 'webp')) {
            cmpTag.textContent = 'SKIPPED (src_format=' + escapeHtml(p.src_format.toUpperCase()) + ')';
        } else if (!_cc_ctx.work_dir_exists) {
            cmpTag.textContent = 'SKIPPED (work_dir 不存在)';
        } else {
            cmpTag.textContent = 'SKIPPED';
        }
        if (cmpPlaceholder) cmpPlaceholder.textContent = cmpTag.textContent;
    } else {
        var webpFile = p.webp_file_name || (origName.replace(/\.[^.]+$/, '') + '.webp');
        var cmpUrl = 'api.php?action=serve_compress_work_image&gallery_id=' + encodeURIComponent(_cc_ctx.gallery_id) +
            '&file=' + encodeURIComponent(webpFile) + '&v=' + encodeURIComponent((_cc_ctx.info || {}).finished_at || '');
        imgCmp.src = cmpUrl;
        imgCmp.style.display = 'block';
        if (cmpPlaceholder) {
            cmpPlaceholder.style.display = 'none';
            cmpPlaceholder.textContent = '';
        }
        imgCmp.dataset.path = p.webp_disk_path || _ccJoinDiskPath((_cc_ctx.info || {}).work_dir, webpFile);
        imgCmp.dataset.name = webpFile;
        cmpTag.className = 'badge bg-success';
        cmpTag.textContent = 'USED WEBP';
    }
    document.getElementById('cc_orig_tag').className = 'badge bg-info';
    document.getElementById('cc_orig_tag').textContent = srcFormat;

    // meta 行
    var whStr = (wh.w && wh.h) ? (wh.w + '×' + wh.h) : '—';
    var savingsCls = '';
    if (savingsPct >= 20) savingsCls = 'savings-ok';
    else if (savingsPct >= 5) savingsCls = 'savings-mid';
    else savingsCls = 'savings-bad';
    document.getElementById('cc_meta_orig').textContent =
        (origName || '') + '  ' + whStr + '  ' + (srcFormat || '?') + '  ' + origBytes.toLocaleString() + ' bytes';
    var cmpMetaParts = [];
    cmpMetaParts.push(escapeHtml(p.webp_file_name || (origName.replace(/\.[^.]+$/, '') + '.webp')));
    if (wh.w && wh.h) cmpMetaParts.push(whStr);
    cmpMetaParts.push('WebP');
    cmpMetaParts.push(webpBytes.toLocaleString() + ' bytes');
    if (p.exception) {
        cmpMetaParts = ['Failed: ' + escapeHtml((p.exception || '').toString().substring(0, 120))];
    } else if (!p.used_webp) {
        var skippedReason = p.no_savings ? 'no_savings' : (p.poor_ratio ? 'poor_ratio' : ('src_format=' + (p.src_format || 'unknown')));
        cmpMetaParts.push('Skipped: ' + escapeHtml(skippedReason));
    } else {
        cmpMetaParts.push('节省 <span class="' + savingsCls + '">' + savingsPct.toFixed(2) + '%</span>');
    }
    document.getElementById('cc_meta_cmp').innerHTML = cmpMetaParts.join('  ');

    // 缩放模式重新应用（保证切页后不丢）
    _ccSetZoom('orig', _cc_ctx.zoom.orig);
    _ccSetZoom('cmp', _cc_ctx.zoom.cmp);
}

function _ccJoinDiskPath(dir, name) {
    dir = String(dir || '');
    name = String(name || '');
    if (!dir) return name;
    var sep = dir.indexOf('\\') >= 0 ? '\\' : '/';
    return dir.replace(/[\\\/]+$/, '') + sep + name;
}

function _ccGo(delta) {
    var next = _cc_ctx.cur_idx + delta;
    if (next < 0 || next >= _cc_ctx.pages.length) return;
    _cc_ctx.cur_idx = next;
    _ccRenderThumbs();
    _ccRenderPage();
}

// ═══ Zoom ═══
function _ccSetZoom(side, zoom) {
    if (side !== 'orig' && side !== 'cmp') return;
    if (zoom !== 'fit' && zoom !== '100' && zoom !== '200') return;
    _cc_ctx.zoom[side] = zoom;
    var sc = document.getElementById('cc_scroll_' + side);
    sc.classList.remove('zoom-fit', 'zoom-100', 'zoom-200');
    sc.classList.add('zoom-' + zoom);
    // buttons active
    document.querySelectorAll('.cc-zoom[data-side="' + side + '"]').forEach(function (b) {
        if (b.getAttribute('data-zoom') === zoom) b.classList.add('active');
        else b.classList.remove('active');
    });
    // 200% 时滚回左上
    if (zoom === '200') { sc.scrollTop = 0; sc.scrollLeft = 0; }
}

// ═══ Copy path ═══
function _ccCopyPath(side) {
    var img = document.getElementById('cc_img_' + side);
    if (!img) return;
    var path = img.getAttribute('data-path') || '';
    var name = img.getAttribute('data-name') || '';
    var text = path || name || '(该侧没有磁盘路径)';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            showToast('路径已复制：' + text.substring(0, 40), 'success');
        }, function () {
            _ccFallbackCopy(text);
        });
    } else {
        _ccFallbackCopy(text);
    }
}

function _ccFallbackCopy(text) {
    try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
        showToast('路径已复制（兼容性）', 'success');
    } catch (_) {
        showToast('复制失败，请手动复制：' + text, 'warning');
    }
}

// ═══ Set empty state ═══
function _ccSetEmpty(msg) {
    var emptyEl = document.getElementById('cc_empty');
    var paneRow = document.getElementById('cc_pane_row');
    var tb = document.getElementById('cc_toolbar');
    tb.classList.add('d-none');
    paneRow.style.display = 'none';
    paneRow.classList.add('d-none');
    emptyEl.style.display = 'flex';
    emptyEl.classList.remove('d-none');
    document.getElementById('cc_empty_text').textContent = msg || '';
    // Header 也要尽量清理，避免老数据残留
    document.getElementById('cc_badge').className = 'badge me-2 bg-secondary';
    document.getElementById('cc_badge').textContent = '—';
}

// ═══ 整本批准 ═══
async function _ccOnApprove() {
    var btn = document.getElementById('cc_btn_approve');
    if (!_cc_ctx.info) { showToast('还未加载压缩信息', 'warning'); return; }
    var ok = await confirmDialog({
        title: '确认批准整本压缩？',
        message: '批准后状态将变为 approved_pending_apply，等待 Phase 2 真正替换磁盘文件。',
        detail: '当前仍可点「整本重做」撤销批准并重新跑压缩。',
        okText: '整本批准',
        okClass: 'btn-success'
    });
    if (!ok) return;
    btn.disabled = true;
    try {
        const r = await api('approve_compress_whole', { form: { gallery_id: _cc_ctx.gallery_id } });
        if (!r || !r.ok) { showToast('批准失败: ' + ((r && r.error) || '未知错误'), 'danger'); return; }
        showToast('已批准（approved_pending_apply）。等 Phase 2 apply 时才会真正替换磁盘。', 'success');
        // 刷新当前 modal + 若 Gallery 页在，触发 loadGalleries 同步刷新角标
        if (_cc_ctx.gallery) _cc_ctx.gallery.compression_status = 'approved_pending_apply';
        _ccRenderHeader();
        _ccRenderThumbs();
        if (typeof loadGalleries === 'function') loadGalleries();
    } finally {
        btn.disabled = !!(_cc_ctx.gallery && _cc_ctx.gallery.compression_status === 'approved_pending_apply');
    }
}

// ═══ 整本重做 ═══
async function _ccOnRerun() {
    var btn = document.getElementById('cc_btn_rerun');
    var ok = await confirmDialog({
        title: '确认整本重做压缩？',
        message: '会以默认参数 quality=88 / method=4 / min_savings=5% 重跑 Phase 1 CLI。',
        detail: '会为所有静态页生成审核候选（包括原本已是 WebP 的页），不修改原图；批准状态会被重置。',
        okText: '开始重做',
        okClass: 'btn-warning'
    });
    if (!ok) return;
    btn.disabled = true;
    try {
        const r = await api('rerun_compress_default', { form: { gallery_id: _cc_ctx.gallery_id } });
        if (!r || !r.ok) {
            showToast('启动失败: ' + ((r && r.error) || '未知错误'), 'danger');
            btn.disabled = false;
            return;
        }
        showToast('后台已启动重跑，开始轮询…', 'info');
        _ccSetEmpty('整本重做中…压缩在后台运行，本窗口会自动刷新。');
        // 轮询
        if (_cc_ctx.pollingTimer) { clearInterval(_cc_ctx.pollingTimer); _cc_ctx.pollingTimer = null; }
        var cnt = 0;
        _cc_ctx.pollingTimer = setInterval(async function () {
            cnt++;
            try {
                var rr = await api('get_compress_review_one', { form: { gallery_id: _cc_ctx.gallery_id } });
                if (rr && rr.ok && rr.info) {
                    var gst = (rr.gallery && rr.gallery.compression_status) || '';
                    if (gst === 'user_review_required' || gst === 'failed') {
                        clearInterval(_cc_ctx.pollingTimer); _cc_ctx.pollingTimer = null;
                        showToast('重跑完成，状态=' + gst, (gst === 'failed' ? 'danger' : 'success'));
                        _cc_ctx.gallery = rr.gallery || {};
                        _cc_ctx.info = rr.info || null;
                        _cc_ctx.pages = (rr.info && rr.info.pages) ? rr.info.pages.slice() : [];
                        _cc_ctx.work_dir_exists = !!rr.work_dir_served;
                        _cc_ctx.page_sizes = rr.page_sizes || [];
                        _cc_ctx.cur_idx = 0;
                        _ccRenderHeader();
                        _ccRenderThumbs();
                        _ccRenderPage();
                        document.getElementById('cc_empty').classList.add('d-none');
                        document.getElementById('cc_empty').style.display = 'none';
                        document.getElementById('cc_toolbar').classList.remove('d-none');
                        document.getElementById('cc_pane_row').classList.remove('d-none');
                        document.getElementById('cc_pane_row').style.display = 'flex';
                        if (typeof loadGalleries === 'function') loadGalleries();
                        btn.disabled = false;
                    }
                }
            } catch (_) { /* 轮询异常忽略，等下一轮 */ }
            if (cnt > 180) {
                clearInterval(_cc_ctx.pollingTimer); _cc_ctx.pollingTimer = null;
                showToast('轮询超时超过 6 分钟，请手动刷新。', 'warning');
                btn.disabled = false;
            }
        }, 2000);
    } catch (e) {
        btn.disabled = false;
        showToast('启动失败: ' + (e.message || e), 'danger');
    }
}
