let _crawlHistoryPage = 1;
const CRAWL_HISTORY_PER_PAGE = 50;
let _refreshTargetsPage = 1;
const REFRESH_TARGETS_PER_PAGE = 50;
let _ehTagTranslationMap = null;

async function loadEhTagTranslationMap() {
    if (_ehTagTranslationMap !== null) return _ehTagTranslationMap;
    try {
        const resp = await fetch('data/eh_tag_translation.json', { cache: 'no-cache' });
        if (!resp.ok) { _ehTagTranslationMap = false; return null; }
        const raw = await resp.json();
        const entries = (raw && typeof raw === 'object' && Array.isArray(raw.data)) ? raw.data : (Array.isArray(raw) ? raw : null);
        if (!entries) { _ehTagTranslationMap = false; return null; }
        const map = {};
        const NS_ALIAS = { category: 'reclass' };
        for (const entry of entries) {
            let ns = (entry && typeof entry === 'object') ? String(entry.namespace || '') : '';
            const data = (entry && typeof entry === 'object') ? entry.data : null;
            if (!ns || !data || typeof data !== 'object') continue;
            ns = NS_ALIAS[ns] || ns;
            const nsMap = {};
            for (const k of Object.keys(data)) {
                const v = data[k];
                const cn = (v && typeof v === 'object') ? String(v.name || '') : '';
                if (cn) nsMap[String(k).toLowerCase()] = cn;
            }
            if (Object.keys(nsMap).length) map[ns] = nsMap;
        }
        _ehTagTranslationMap = map;
        return map;
    } catch (_) { _ehTagTranslationMap = false; return null; }
}
function translateArtistKey(keyRaw) {
    const raw = String(keyRaw || '').trim();
    if (!raw) return '';
    const name = raw.endsWith('$') ? raw.substring(0, raw.length - 1) : raw;
    const mp = _ehTagTranslationMap && typeof _ehTagTranslationMap === 'object' ? _ehTagTranslationMap : null;
    const nsMap = (mp && mp.artist) ? mp.artist : null;
    if (!nsMap) return '';
    const cn = nsMap[name.toLowerCase()] || null;
    return cn || '';
}
function formatArtistDisplay(keyRaw) {
    const key = String(keyRaw || '').trim();
    if (!key) return '';
    const cn = translateArtistKey(key);
    if (!cn) return escapeHtml(key);
    if (cn === key) return escapeHtml(cn);
    return escapeHtml(cn) + ' <span class="text-muted small ms-1">(' + escapeHtml(key) + ')</span>';
}

function loadCrawlHistoryPage() {
    loadCrawlHistory(1);
    loadRefreshTargets(1);
}

function formatCrawlHistoryTime(value) {
    return String(value || '').trim().replace('T', ' ').replace(/(\d{2}:\d{2}:\d{2})\.\d+/, '$1');
}

async function loadCrawlHistory(page) {
    const el = document.getElementById('crawl_history_list');
    const pagination = document.getElementById('crawl_history_pagination');
    if (!el) return;
    _crawlHistoryPage = page || _crawlHistoryPage || 1;
    el.innerHTML = '<div class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';
    if (pagination) pagination.innerHTML = '';
    const data = await api('crawl_history&page=' + _crawlHistoryPage + '&per_page=' + CRAWL_HISTORY_PER_PAGE);
    if (!data.ok) {
        el.innerHTML = '<div class="text-danger">' + escapeHtml(data.error || '加载失败') + '</div>';
        return;
    }
    const jobs = data.jobs || [];
    if (!jobs.length) {
        el.innerHTML = '<div class="text-muted">暂无爬取历史</div>';
        return;
    }
    const labels = { completed: '完成', failed: '失败', cancelled: '已取消' };
    const colors = { completed: 'success', failed: 'danger', cancelled: 'secondary' };
    el.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">' +
        '<thead><tr><th>#</th><th>状态</th><th>类型</th><th>检索条件</th><th>语言</th><th>时间</th></tr></thead><tbody>' +
        jobs.map(job => {
            const isRefresh = job.refresh_target_id !== null && job.refresh_target_id !== '';
            const type = isRefresh
                ? '<span class="badge bg-info text-dark">定期刷新</span>' + (job.refresh_target_name ? '<div class="small text-muted mt-1">' + escapeHtml(job.refresh_target_name) + '</div>' : '')
                : '<span class="badge bg-light text-dark border">普通爬取</span>';
            const error = job.error ? '<div class="small text-danger mt-1 text-break">' + escapeHtml(job.error) + '</div>' : '';
            return '<tr><td>' + job.id + '</td>' +
                '<td><span class="badge bg-' + (colors[job.status] || 'secondary') + '">' + escapeHtml(labels[job.status] || job.status) + '</span></td>' +
                '<td>' + type + '</td>' +
                '<td class="text-break"><code>' + escapeHtml(job.query || '') + '</code>' + error + '</td>' +
                '<td class="text-break">' + escapeHtml(job.languages || '全部') + '</td>' +
                '<td class="text-nowrap small"><div>开始：' + escapeHtml(formatCrawlHistoryTime(job.started_at || job.created_at)) + '</div>' +
                '<div class="text-muted">结束：' + escapeHtml(formatCrawlHistoryTime(job.finished_at)) + '</div></td></tr>';
        }).join('') + '</tbody></table></div>';
    renderCrawlHistoryPagination(parseInt(data.page, 10) || 1, parseInt(data.total, 10) || 0, parseInt(data.per_page, 10) || CRAWL_HISTORY_PER_PAGE);
}

function renderCrawlHistoryPagination(page, total, perPage) {
    const el = document.getElementById('crawl_history_pagination');
    if (!el) return;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    el.innerHTML = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' +
        '<span class="small text-muted">共 ' + total + ' 条，第 ' + page + ' / ' + totalPages + ' 页</span>' +
        '<div class="btn-group btn-group-sm">' +
        '<button class="btn btn-outline-secondary" ' + (page <= 1 ? 'disabled ' : '') + 'onclick="loadCrawlHistory(' + (page - 1) + ')"><i class="fas fa-chevron-left"></i></button>' +
        '<button class="btn btn-outline-secondary" ' + (page >= totalPages ? 'disabled ' : '') + 'onclick="loadCrawlHistory(' + (page + 1) + ')"><i class="fas fa-chevron-right"></i></button>' +
        '</div></div>';
}
async function clearCrawlHistory(scope) {
    const failedOnly = scope === 'failed';
    const confirmed = await confirmDialog({
        title: failedOnly ? '清空失败记录' : '清空全部爬取历史',
        message: failedOnly
            ? '将删除所有失败的爬取历史。活动任务和定期刷新对象不会受到影响。'
            : '将删除所有完成、失败和已取消的爬取历史。活动任务和定期刷新对象不会受到影响。',
        okText: failedOnly ? '清空失败记录' : '清空全部历史',
        okClass: 'btn-danger'
    });
    if (!confirmed) return;
    const data = await api('clear_crawl_history', {
        form: { action: 'clear_crawl_history', scope: scope }
    });
    if (!data.ok) {
        showToast(data.error || '清理失败', 'danger');
        return;
    }
    showToast(data.message || '爬取历史已清理', 'success');
    loadCrawlHistory(1);
}

async function loadRefreshTargets(page) {
    const el = document.getElementById('refresh_targets_list');
    const pagination = document.getElementById('refresh_targets_pagination');
    if (!el) return;
    _refreshTargetsPage = page || _refreshTargetsPage || 1;
    el.innerHTML = '<div class="text-muted"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';
    if (pagination) pagination.innerHTML = '';
    const data = await api('refresh_targets&page=' + _refreshTargetsPage + '&per_page=' + REFRESH_TARGETS_PER_PAGE);
    if (!data.ok) {
        el.innerHTML = '<div class="text-danger">' + escapeHtml(data.error || '加载失败') + '</div>';
        return;
    }
    const targets = data.targets || [];
    if (!targets.length) {
        el.innerHTML = '<div class="text-muted">暂无刷新任务对象</div>';
        return;
    }
    el.innerHTML = '<div class="table-responsive"><table class="table table-sm table-hover align-middle">' +
        '<thead><tr><th>刷新</th><th>任务</th><th>检索条件</th><th>完成时间</th><th>来源</th></tr></thead><tbody>' +
        targets.map(target => {
            const displayName = String(target.query_label || target.name || '').trim();
            const favcats = String(target.origin_favcats || '').trim();
            let originInfo;
            if (favcats) {
                originInfo = '<span class="badge bg-light text-dark border"><i class="fas fa-sync me-1"></i>同步</span>' +
                    '<span class="small text-muted ms-2 text-nowrap">favcat: ' + escapeHtml(favcats) + '</span>';
            } else {
                originInfo = '<span class="badge bg-light text-dark border"><i class="fas fa-pen me-1"></i>手动</span>';
            }
            const queryCell = '<code class="text-break">' + escapeHtml(target.query || '') + '</code>';
            return '<tr>' +
                '<td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" ' + (parseInt(target.enabled, 10) ? 'checked ' : '') + 'onchange="toggleRefreshTarget(' + target.id + ',this.checked,this)"></div></td>' +
                '<td class="text-break">' + (displayName ? escapeHtml(displayName) : '<span class="text-muted small">（未命名）</span>') + '</td>' +
                '<td class="text-break">' + queryCell + '</td>' +
                '<td class="text-nowrap">' + escapeHtml(formatCrawlHistoryTime(target.completed_at || '')) + '</td>' +
                '<td class="text-nowrap small">' + originInfo + '</td>' +
                '</tr>';
        }).join('') + '</tbody></table></div>';
    renderRefreshTargetsPagination(parseInt(data.page, 10) || 1, parseInt(data.total, 10) || 0, parseInt(data.per_page, 10) || REFRESH_TARGETS_PER_PAGE);
}

function renderRefreshTargetsPagination(page, total, perPage) {
    const el = document.getElementById('refresh_targets_pagination');
    if (!el) return;
    const totalPages = Math.max(1, Math.ceil(total / perPage));
    el.innerHTML = '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' +
        '<span class="small text-muted">共 ' + total + ' 条，第 ' + page + ' / ' + totalPages + ' 页</span>' +
        '<div class="btn-group btn-group-sm">' +
        '<button class="btn btn-outline-secondary" ' + (page <= 1 ? 'disabled ' : '') + 'onclick="loadRefreshTargets(' + (page - 1) + ')"><i class="fas fa-chevron-left"></i></button>' +
        '<button class="btn btn-outline-secondary" ' + (page >= totalPages ? 'disabled ' : '') + 'onclick="loadRefreshTargets(' + (page + 1) + ')"><i class="fas fa-chevron-right"></i></button>' +
        '</div></div>';
}

async function toggleRefreshTarget(id, enabled, input) {
    input.disabled = true;
    const data = await api('toggle_refresh_target', { form: { action: 'toggle_refresh_target', id: id, enabled: enabled ? '1' : '' } });
    input.disabled = false;
    if (!data.ok) {
        input.checked = !enabled;
        showToast(data.error || '更新失败', 'danger');
        return;
    }
    showToast(data.message || '刷新设置已更新', 'success');
}

async function syncFavoriteAuthors() {
    const defaults = {
        favcats: '0,1,9',
        pages_per_cat: 0,
        max_detail: 0,
        dry_run: false,
    };
    const cfg = await showSyncFavoriteDialog(defaults);
    if (!cfg) return;

    const startBtn = document.getElementById('sync_fav_btn');
    const cancelBtn = document.getElementById('sync_fav_cancel_btn');
    const progWrap = document.getElementById('sync_fav_progress');
    const stageEl = document.getElementById('sync_fav_stage');
    const pctEl = document.getElementById('sync_fav_pct');
    const barEl = document.getElementById('sync_fav_bar');
    const statsEl = document.getElementById('sync_fav_stats');
    const outEl = document.getElementById('sync_fav_output');

    function setRunning(running) {
        if (startBtn) startBtn.disabled = !!running;
        if (startBtn) startBtn.classList.toggle('opacity-75', !!running);
        if (cancelBtn) cancelBtn.classList.toggle('d-none', !running);
        if (progWrap) progWrap.classList.toggle('d-none', !running);
    }

    function renderSnapshot(snap, isFinal, elapsed) {
        snap = snap || {};

        function _decodeUnicode(text) {
            const s = String(text == null ? '' : text);
            if (s.indexOf('\\u') < 0 && s.indexOf('\\U') < 0) return s;
            try {
                return s.replace(/\\u([0-9a-fA-F]{4})/g, function (_m, _g) {
                    return String.fromCharCode(parseInt(_g, 16));
                }).replace(/\\U([0-9a-fA-F]{8})/g, function (_m, _g) {
                    const cp = parseInt(_g, 16);
                    if (cp > 0xFFFF) {
                        const u = cp - 0x10000;
                        return String.fromCharCode(0xD800 + (u >> 10)) + String.fromCharCode(0xDC00 + (u & 0x3FF));
                    }
                    return String.fromCharCode(cp);
                });
            } catch (_) {
                return s;
            }
        }

        function _formatEventLine(evt) {
            if (!evt || typeof evt !== 'object') return String(evt);
            const kind = String(evt.__kind || 'event');
            if (kind === 'stage') {
                const stageMap = {
                    'initializing': '初始化',
                    'list': '抓取列表',
                    'detail': '抓取详情抽作者',
                    'aggregate': '聚合作者',
                    'upsert': '写入刷新对象',
                    'finished': '完成',
                    'error': '出错'
                };
                const stg = stageMap[String(evt.stage || '')] || String(evt.stage || '');
                let parts = ['[STAGE] ' + stg];
                if (evt.message) parts.push(_decodeUnicode(String(evt.message)));
                return parts.join('  ');
            }
            if (kind === 'progress') {
                const cur = parseInt(evt.current, 10) || 0;
                const tot = parseInt(evt.total, 10) || 0;
                const stg = String(evt.stage || '');
                if (stg === 'detail' && String(evt.sub || '') === 'ok') {
                    const artists = Array.isArray(evt.artists) ? evt.artists.map(function (a) { return _decodeUnicode(String(a)); }).join(', ') : '';
                    const title = _decodeUnicode(String(evt.title || ''));
                    return '[DETAIL OK] ' + cur + '/' + tot +
                        (evt.source_id ? ' ' + String(evt.source_id) : '') +
                        (title ? '  《' + title + '》' : '') +
                        (artists ? '  作者=[' + artists + ']' : '');
                }
                if (stg === 'list') {
                    return '[LIST] page ' + (parseInt(evt.page,10) || 0) + '/' + (parseInt(evt.max_page,10) || 0) +
                        '  favcat=' + (evt.favcat != null ? String(evt.favcat) : '') +
                        '  本页=' + (parseInt(evt.items_in_this_page,10) || 0) +
                        '  累计看到=' + (parseInt(evt.items_seen,10) || 0);
                }
                if (stg === 'aggregate') {
                    return '[AGGREGATE] 去重作者=' + (parseInt(evt.unique_artists,10) || 0);
                }
                if (stg === 'upsert') {
                    return '[UPSERT] 新建=' + (parseInt(evt.created,10) || 0) +
                        '  更新=' + (parseInt(evt.updated,10) || 0) +
                        '  跳过用户命名=' + (parseInt(evt.name_skipped_user_custom,10) || 0) +
                        '  跳过无变化=' + (parseInt(evt.skipped_unchanged,10) || 0) +
                        '  跳过完全重复=' + (parseInt(evt.skipped_duplicate_existing,10) || 0) +
                        '  回填旧对象=' + (parseInt(evt.origin_backfilled,10) || 0);
                }
                return '[PROGRESS] ' + stg + '  ' + cur + '/' + tot;
            }
            if (kind === 'wait') {
                const ws = typeof evt.wait_s == null ? '' : Number(evt.wait_s).toFixed(1) + 's';
                return '[WAIT] 节流等待 ' + ws + '  ' + _decodeUnicode(String(evt.message || ''));
            }
            if (kind === 'error') {
                return '[ERROR] ' + _decodeUnicode(String(evt.error || '')) +
                    (evt.stage ? '  @' + String(evt.stage) : '');
            }
            return '[' + kind.toUpperCase() + '] ' + _decodeUnicode(JSON.stringify(evt));
        }

        const events = Array.isArray(snap.events) ? snap.events : [];
        const stageEvts = events.filter(function (e) { return e && e.__kind === 'stage'; });
        const currentStage = stageEvts.length ? stageEvts[stageEvts.length - 1].stage : (snap.stage || 'preparing');
        const stageLabelMap = {
            'initializing': '初始化（校验 cookies）',
            'list': '抓取收藏列表页',
            'detail': '抓取详情页抽作者',
            'aggregate': '按作者维度聚合',
            'upsert': '写入 refresh_targets',
            'finished': '完成',
            'error': '出错',
        };
        const label = stageLabelMap[String(currentStage)] || String(currentStage);
        if (stageEl) stageEl.textContent = label + (isFinal ? '（完成）' : '…');

        const progressEvts = events.filter(function (e) { return e && e.__kind === 'progress'; });
        const waitEvts = events.filter(function (e) { return e && e.__kind === 'wait'; });
        const lastWait = waitEvts.length ? waitEvts[waitEvts.length - 1] : null;

        function _lastProgressByStage(stage) {
            const matches = progressEvts.filter(function (e) { return String(e.stage || '') === String(stage); });
            if (!matches.length) return null;
            const subPref = matches.filter(function (e) {
                const s = String(e.sub || '');
                return s === 'ok' || s === 'skip' || s === 'fail' || s === 'error';
            });
            return (subPref.length ? subPref : matches)[(subPref.length ? subPref : matches).length - 1];
        }
        const lastEvtByStage = _lastProgressByStage(currentStage);
        const prevDetailEvt = String(currentStage) !== 'detail' ? _lastProgressByStage('detail') : null;
        const lastEvt = lastEvtByStage || (progressEvts.length ? progressEvts[progressEvts.length - 1] : null);

        let cur = 0, total = 0, pct = 0;
        if (String(currentStage) === 'aggregate' || String(currentStage) === 'upsert') {
            const det = prevDetailEvt || _lastProgressByStage('detail');
            if (det) {
                const dc = parseInt(det.current, 10) || 0;
                const dt = parseInt(det.total, 10) || 0;
                cur = dc; total = dt;
                pct = (dt > 0) ? Math.max(0, Math.min(99, Math.round(dc / dt * 100))) : 99;
            } else {
                pct = 99;
            }
        } else if (lastEvt) {
            cur = parseInt(lastEvt.current, 10) || 0;
            total = parseInt(lastEvt.total, 10) || 0;
            pct = (total > 0) ? Math.max(0, Math.min(99, Math.round(cur / total * 100))) : 0;
        }
        if (pctEl) pctEl.textContent = pct + '%';
        if (barEl) barEl.style.width = pct + '%';
        if (barEl) {
            barEl.classList.remove('bg-info', 'bg-success', 'bg-warning', 'bg-danger');
            if (isFinal && snap.errors && (Array.isArray(snap.errors) ? snap.errors.length > 0 : false)) barEl.classList.add('bg-warning');
            else if (isFinal) barEl.classList.add('bg-success');
            else barEl.classList.add('bg-info');
        }

        const statPills = [];
        statPills.push('<span class="badge bg-light text-dark border"><i class="fas fa-folder-open me-1"></i>收藏夹 ' + escapeHtml(String(cfg.favcats || '0,1,9')) + '</span>');
        if (cfg.dry_run) statPills.push('<span class="badge bg-warning text-dark"><i class="fas fa-eye me-1"></i>DRY-RUN 预览</span>');
        statPills.push('<span class="badge bg-light text-dark border"><i class="fas fa-clock me-1"></i>' + (elapsed != null ? Number(elapsed).toFixed(1) + 's' : '-') + '</span>');
        if (lastEvt) {
            if (String(lastEvt.stage || '') === 'list') {
                statPills.push('<span class="badge bg-info text-white"><i class="fas fa-list me-1"></i>列表页 ' + cur + '/' + total + '</span>');
                if (typeof lastEvt.items_seen === 'number') statPills.push('<span class="badge bg-light text-dark border"><i class="fas fa-book me-1"></i>看到 ' + lastEvt.items_seen + ' 本</span>');
            } else if (String(lastEvt.stage || '') === 'detail') {
                statPills.push('<span class="badge bg-primary text-white"><i class="fas fa-image me-1"></i>详情 ' + cur + '/' + total + '</span>');
            } else if (String(lastEvt.stage || '') === 'aggregate') {
                if (typeof lastEvt.unique_artists === 'number') statPills.push('<span class="badge bg-success text-white"><i class="fas fa-user-group me-1"></i>去重作者 ' + lastEvt.unique_artists + '</span>');
            } else if (String(lastEvt.stage || '') === 'upsert') {
                if (typeof lastEvt.created === 'number') statPills.push('<span class="badge bg-primary text-white"><i class="fas fa-plus me-1"></i>新建 ' + lastEvt.created + '</span>');
                if (typeof lastEvt.updated === 'number') statPills.push('<span class="badge bg-info text-white"><i class="fas fa-pen me-1"></i>更新 ' + lastEvt.updated + '</span>');
                if (typeof lastEvt.skipped_duplicate_existing === 'number' && lastEvt.skipped_duplicate_existing > 0) statPills.push('<span class="badge bg-light text-dark border"><i class="fas fa-copy me-1"></i>跳过重复 ' + lastEvt.skipped_duplicate_existing + '</span>');
                if (typeof lastEvt.origin_backfilled === 'number' && lastEvt.origin_backfilled > 0) statPills.push('<span class="badge bg-secondary text-white"><i class="fas fa-rotate me-1"></i>回填 ' + lastEvt.origin_backfilled + '</span>');
            }
        }
        if (lastWait && !isFinal) {
            const ws = typeof lastWait.wait_s === 'number' ? lastWait.wait_s.toFixed(0) : '?';
            statPills.push('<span class="badge bg-secondary text-white"><i class="fas fa-hourglass-half me-1"></i>节流中 ' + ws + 's：' + escapeHtml(_decodeUnicode(String(lastWait.message || ''))) + '</span>');
        }
        if (statsEl) statsEl.innerHTML = statPills.join('');

        const logLines = Array.isArray(snap.log_lines) ? snap.log_lines.slice().slice(-200) : [];
        if (outEl) {
            outEl.classList.add('show');
            const tailArr = [];
            let _evtIdx = 0;
            for (let i = 0; i < logLines.length; i++) {
                const ln = String(logLines[i] || '');
                if (ln.indexOf('__EVT__') === 0) {
                    let raw = ln.substring('__EVT__'.length);
                    let evt = null;
                    try { evt = JSON.parse(raw); } catch (_) { evt = null; }
                    if (evt && typeof evt === 'object') {
                        const kind = String(evt.__kind || 'event');
                        if (kind === 'error') {
                            tailArr.push('<span class="text-danger">' + escapeHtml(_formatEventLine(evt)) + '</span>');
                        } else if (kind === 'wait') {
                            tailArr.push('<span class="text-muted">' + escapeHtml(_formatEventLine(evt)) + '</span>');
                        } else {
                            tailArr.push('<span class="text-body">' + escapeHtml(_formatEventLine(evt)) + '</span>');
                        }
                        _evtIdx++;
                        continue;
                    }
                    raw = _decodeUnicode(raw);
                    tailArr.push('<span class="text-body">' + escapeHtml(raw) + '</span>');
                    continue;
                }
                tailArr.push('<span class="text-body">' + escapeHtml(_decodeUnicode(ln)) + '</span>');
            }
            outEl.innerHTML = '<div class="p-3 bg-light border rounded-2 small text-body" style="white-space:pre-wrap;max-height:320px;overflow:auto">' + tailArr.join('<br>') + '</div>';
            try { outEl.scrollTop = outEl.scrollHeight; } catch (_) {}
        }
    }

    function renderFinal(data, elapsed) {
        const s = (data && data.summary) || {};
        const summaryHtml =
            '<div class="row g-2 mb-3 text-center small">' +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5">' + (s.total_favorite_items ?? 0) + '</div><div class="text-muted small mt-1">收藏条目</div></div></div>' +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5">' + (s.detail_fetched ?? 0) + '</div><div class="text-muted small mt-1">详情页抓取</div></div></div>' +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-success">' + (s.unique_artists ?? 0) + '</div><div class="text-muted small mt-1">去重作者</div></div></div>' +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-primary">' + (s.created ?? 0) + '</div><div class="text-muted small mt-1">新建刷新</div></div></div>' +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-info">' + (s.updated ?? 0) + '</div><div class="text-muted small mt-1">更新刷新</div></div></div>' +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-warning">' + (s.skipped ?? 0) + '</div><div class="text-muted small mt-1">跳过</div></div></div>' +
            ((typeof s.skipped_duplicate_existing === 'number' && s.skipped_duplicate_existing > 0) ? '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-secondary">' + s.skipped_duplicate_existing + '</div><div class="text-muted small mt-1">跳过完全重复</div></div></div>' : '') +
            ((typeof s.origin_backfilled === 'number' && s.origin_backfilled > 0) ? '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-success">' + s.origin_backfilled + '</div><div class="text-muted small mt-1">回填旧对象</div></div></div>' : '') +
            '<div class="col"><div class="p-2 border rounded-2 bg-white shadow-sm"><div class="fw-bold fs-5 text-danger">' + (s.detail_errors ?? 0) + '</div><div class="text-muted small mt-1">详情失败</div></div></div>' +
            '</div>' +
            '<div class="small mb-3 text-center">' +
            '<span class="me-3"><i class="fas fa-clock text-muted me-1"></i>耗时：' + elapsed + 's</span>' +
            (s.scanned_favcats ? '<span class="me-3"><i class="fas fa-folder-open text-muted me-1"></i>收藏夹：' + escapeHtml(String(s.scanned_favcats)) + '</span>' : '') +
            (s.dry_run ? '<span class="text-warning fw-bold"><i class="fas fa-eye me-1"></i>DRY-RUN 预览</span>' : '') +
            '</div>';
        let perArtist = '';
        if (s.artist_favcats_map && typeof s.artist_favcats_map === 'object') {
            const keys = Object.keys(s.artist_favcats_map).sort();
            if (keys.length) {
                const placeId = 'sync_fav_per_artist_' + (Date.now() + '_' + Math.floor(Math.random() * 1e6));
                perArtist = '<div class="mb-3"><div class="small fw-bold mb-2"><i class="fas fa-user-group text-muted me-1"></i>作者（前50）→ 所在收藏夹：</div>' +
                    '<div class="p-2 bg-white border rounded-2 shadow-sm small" style="max-height:240px;overflow:auto" id="' + placeId + '"><table class="table table-sm mb-0"><tbody>' +
                    keys.slice(0, 50).map(k => '<tr><td class="text-break"><code>artist:&quot;' + escapeHtml(k) + '$&quot;</code></td><td class="text-nowrap">' +
                        (Array.isArray(s.artist_favcats_map[k]) ? s.artist_favcats_map[k].map(n => '<span class="badge bg-secondary me-1">' + escapeHtml(String(n)) + '</span>').join('') : '') +
                        '</td></tr>').join('') +
                    '</tbody></table></div></div>';
                Promise.resolve().then(async function () {
                    try {
                        if (typeof loadEhTagTranslationMap === 'function') {
                            try { await loadEhTagTranslationMap(); } catch (_e1) {}
                        }
                        const holder = document.getElementById(placeId);
                        if (!holder) return;
                        const rows = keys.slice(0, 50).map(function (k) {
                            const favs = Array.isArray(s.artist_favcats_map[k]) ? s.artist_favcats_map[k] : [];
                            const labelHtml = (typeof formatArtistDisplay === 'function') ? formatArtistDisplay(k) : '<code>artist:"' + escapeHtml(k) + '$"</code>';
                            return '<tr><td class="text-break">' + labelHtml + '</td><td class="text-nowrap">' +
                                favs.map(function (n) { return '<span class="badge bg-secondary me-1">' + escapeHtml(String(n)) + '</span>'; }).join('') +
                                '</td></tr>';
                        }).join('');
                        const tb = holder.querySelector('tbody');
                        if (tb) tb.innerHTML = rows;
                    } catch (_e) {}
                }).catch(function () {});
            }
        }
        let errors = '';
        if (Array.isArray(s.failed_items) && s.failed_items.length) {
            errors = '<div class="mb-3"><div class="small fw-bold mb-2 text-danger"><i class="fas fa-triangle-exclamation me-1"></i>失败条目：</div>' +
                '<div class="p-2 bg-white border rounded-2 shadow-sm small" style="max-height:160px;overflow:auto">' +
                s.failed_items.slice(0, 50).map(f =>
                    '<div class="text-break"><b>' + escapeHtml((f && (f.title || f.source_id)) ? (f.title || f.source_id) : String(f)) + '</b>' +
                    (f && f.error ? '<div class="text-danger ms-2 small">' + escapeHtml(String(f.error)) + '</div>' : '') + '</div>'
                ).join('') +
                '</div></div>';
        } else if (Array.isArray(s.errors) && s.errors.length) {
            errors = '<div class="mb-3"><div class="small fw-bold mb-2 text-danger"><i class="fas fa-triangle-exclamation me-1"></i>运行中错误：</div>' +
                '<div class="p-2 bg-white border rounded-2 shadow-sm small" style="max-height:160px;overflow:auto">' +
                s.errors.slice(0, 50).map(m => '<div class="text-danger text-break">' + escapeHtml(String(m)) + '</div>').join('') +
                '</div></div>';
        }
        const outHtml = (data && data.output ? '<details class="mb-2"><summary class="small cursor-pointer fw-bold"><i class="fas fa-terminal text-muted me-1"></i>原始 stdout/stderr 完整输出</summary>' +
            '<pre class="mt-2 p-3 bg-light border rounded-2 small mb-0 text-dark" style="color:#111;white-space:pre-wrap;max-height:260px;overflow:auto">' + escapeHtml(String(data.output)) + '</pre></details>' : '');
        if (outEl) {
            outEl.classList.add('show');
            outEl.innerHTML = summaryHtml + perArtist + errors + outHtml;
            try { outEl.scrollTop = 0; } catch (_) {}
        }
    }

    if (outEl) { clearOutput('sync_fav_output'); outEl.classList.add('show'); outEl.innerHTML = '<span class="info"><i class="fas fa-spinner fa-spin me-1"></i>提交同步任务…</span>'; }
    setRunning(true);

    const t0 = Date.now();
    try {
        api('clear_sync_fav_progress').catch(function () {});
        const form = {
            action: 'sync_favorite_authors',
            stream: '1',
        };
        if (cfg.favcats) form.favcats = cfg.favcats;
        if (cfg.pages_per_cat > 0) form.pages_per_cat = String(cfg.pages_per_cat);
        if (cfg.max_detail > 0) form.max_detail = String(cfg.max_detail);
        if (cfg.dry_run) form.dry_run = '1';

        let pollingStopped = false;
        const mainPromise = (async function () {
            try {
                return await api('sync_favorite_authors', { form: form });
            } finally {
                pollingStopped = true;
            }
        })();

        (async function poll() {
            let consecutiveEmpty = 0;
            while (!pollingStopped) {
                try {
                    const r = await api('get_sync_fav_progress');
                    if (r && r.snapshot) {
                        renderSnapshot(r.snapshot, false, (Date.now() - t0) / 1000);
                        consecutiveEmpty = 0;
                    } else {
                        consecutiveEmpty++;
                    }
                } catch (_) {
                    consecutiveEmpty++;
                }
                if (pollingStopped) break;
                await new Promise(function (res) { setTimeout(res, 800); });
                if (consecutiveEmpty > 15 && !pollingStopped) break;
            }
        })();

        const data = await mainPromise;
        const elapsed = ((Date.now() - t0) / 1000).toFixed(1);

        if (pctEl) pctEl.textContent = '100%';
        if (barEl) barEl.style.width = '100%';
        if (stageEl) stageEl.textContent = data.ok ? '完成 ✓' : ('失败（exit=' + (data.exit_code ?? '?') + '）');
        if (barEl) {
            barEl.classList.remove('bg-info', 'bg-warning', 'bg-danger');
            barEl.classList.add(data.ok ? 'bg-success' : 'bg-warning');
        }
        if (statsEl && data && data.summary) {
            const s = data.summary || {};
            const pills = [];
            pills.push('<span class="badge bg-light text-dark border"><i class="fas fa-folder-open me-1"></i>收藏夹 ' + escapeHtml(String(cfg.favcats || '0,1,9')) + '</span>');
            if (cfg.dry_run) pills.push('<span class="badge bg-warning text-dark"><i class="fas fa-eye me-1"></i>DRY-RUN 预览</span>');
            pills.push('<span class="badge bg-light text-dark border"><i class="fas fa-clock me-1"></i>' + elapsed + 's</span>');
            pills.push('<span class="badge bg-success text-white"><i class="fas fa-user-group me-1"></i>作者 ' + (s.unique_artists ?? 0) + '</span>');
            pills.push('<span class="badge bg-primary text-white"><i class="fas fa-plus me-1"></i>新建 ' + (s.created ?? 0) + '</span>');
            pills.push('<span class="badge bg-info text-white"><i class="fas fa-pen me-1"></i>更新 ' + (s.updated ?? 0) + '</span>');
            if (typeof s.skipped_duplicate_existing === 'number' && s.skipped_duplicate_existing > 0) pills.push('<span class="badge bg-light text-dark border"><i class="fas fa-copy me-1"></i>跳过重复 ' + s.skipped_duplicate_existing + '</span>');
            if (typeof s.origin_backfilled === 'number' && s.origin_backfilled > 0) pills.push('<span class="badge bg-secondary text-white"><i class="fas fa-rotate me-1"></i>回填 ' + s.origin_backfilled + '</span>');
            pills.push('<span class="badge bg-danger text-white"><i class="fas fa-triangle-exclamation me-1"></i>失败 ' + ((s.detail_errors ?? 0) + (Array.isArray(s.errors) ? s.errors.length : 0)) + '</span>');
            statsEl.innerHTML = pills.join('');
        }

        renderFinal(data, elapsed);

        if (!data.ok) {
            showToast('远程收藏同步失败（exit=' + (data.exit_code ?? '?') + '）', 'danger');
        } else {
            const s = data.summary || {};
            showToast((cfg.dry_run ? '【预览】' : '') + '远程收藏同步完成：作者 ' + (s.unique_artists ?? 0) + ' / 新建 ' + (s.created ?? 0) + ' / 更新 ' + (s.updated ?? 0), cfg.dry_run ? 'warning' : 'success');
            if (!cfg.dry_run) setTimeout(function () { loadRefreshTargets(1); }, 400);
        }
    } catch (e) {
        const elapsed = ((Date.now() - t0) / 1000).toFixed(1);
        if (stageEl) stageEl.textContent = '调用失败';
        if (barEl) { barEl.classList.remove('bg-info', 'bg-success'); barEl.classList.add('bg-danger'); }
        if (outEl) {
            outEl.classList.add('show');
            outEl.innerHTML = '<div class="text-danger">调用失败：' + escapeHtml(String(e && e.message ? e.message : e)) + '</div>';
        }
        showToast('调用失败', 'danger');
    } finally {
        setRunning(false);
    }
}

function cancelSyncFavoriteAuthors() {
    const cancelBtn = document.getElementById('sync_fav_cancel_btn');
    if (cancelBtn) { cancelBtn.disabled = true; }
    const outEl = document.getElementById('sync_fav_output');
    if (outEl) {
        outEl.classList.add('show');
        outEl.innerHTML = '<div class="text-warning mb-2"><i class="fas fa-hourglass-half me-1"></i>已请求终止，PHP 下一次轮询（~1s）会杀掉 Python 子进程…</div>' +
            (outEl.innerHTML ? '<hr class="my-2"><div>' + outEl.innerHTML + '</div>' : '');
    }
    api('stop_download', { form: { action: 'stop_download' } }).then(function () {
        showToast('已请求终止同步任务', 'warning');
    }).catch(function () {});
}

function showSyncFavoriteDialog(defaults) {
    return new Promise(function (resolve) {
        const id = '_sfav_dialog_' + Math.random().toString(36).slice(2, 8);
        const mask = document.createElement('div');
        mask.className = 'modal-backdrop fade show';
        mask.style.zIndex = '1050';
        mask.style.background = 'rgba(0,0,0,0.45)';
        const wrap = document.createElement('div');
        wrap.className = 'modal d-block fade show';
        wrap.style.zIndex = '1055';
        wrap.setAttribute('tabindex', '-1');
        wrap.innerHTML =
            '<div class="modal-dialog modal-dialog-centered">' +
            '<div class="modal-content" data-sfav-clickable="1">' +
            '<div class="modal-header" data-sfav-clickable="1"><h5 class="modal-title" data-sfav-clickable="1">从远程收藏同步作者刷新</h5>' +
            '<button type="button" class="btn-close" data-sfav-dismiss="' + id + '" aria-label="Close" data-sfav-clickable="1"></button></div>' +
            '<div class="modal-body" data-sfav-clickable="1">' +
            '<div class="mb-2" data-sfav-clickable="1"><label class="form-label small mb-1" data-sfav-clickable="1">收藏夹（favcat，逗号分隔，0-9）</label>' +
            '<input type="text" class="form-control form-control-sm" id="' + id + '_favcats" value="' + escapeHtml(defaults.favcats) + '" data-sfav-clickable="1"></div>' +
            '<div class="row g-2 mb-2" data-sfav-clickable="1">' +
            '<div class="col" data-sfav-clickable="1"><label class="form-label small mb-1" data-sfav-clickable="1">每夹最多页数（0=全部）</label>' +
            '<input type="number" min="0" class="form-control form-control-sm" id="' + id + '_pages" value="' + defaults.pages_per_cat + '" data-sfav-clickable="1"></div>' +
            '<div class="col" data-sfav-clickable="1"><label class="form-label small mb-1" data-sfav-clickable="1">详情最大条数（0=不限制）</label>' +
            '<input type="number" min="0" class="form-control form-control-sm" id="' + id + '_maxd" value="' + defaults.max_detail + '" data-sfav-clickable="1"></div></div>' +
            '<div class="form-check small mb-1" data-sfav-clickable="1"><input class="form-check-input" type="checkbox" id="' + id + '_dry" ' + (defaults.dry_run ? 'checked' : '') + ' data-sfav-clickable="1">' +
            '<label class="form-check-label" for="' + id + '_dry" data-sfav-clickable="1">仅预览（dry-run，不写入 DB / 不进详情页）</label></div>' +
            '<div class="small text-muted mt-2" data-sfav-clickable="1"><i class="fas fa-shield-halved me-1"></i>节流：列表页 2×delay+抖动，详情页 3×delay+抖动，每20本详情再批次间隔30~60s。</div>' +
            '</div>' +
            '<div class="modal-footer" data-sfav-clickable="1">' +
            '<button type="button" class="btn btn-secondary btn-sm" data-sfav-dismiss="' + id + '" data-sfav-clickable="1">取消</button>' +
            '<button type="button" class="btn btn-info btn-sm" id="' + id + '_ok" data-sfav-clickable="1">开始同步</button>' +
            '</div></div></div>';
        let settled = false;
        let pressStartInDialog = false;
        let pressStartInMask = false;
        let pressStartedAt = 0;
        const CLOSE_CLICK_MS = 400;
        const close = function (result) {
            if (settled) return;
            settled = true;
            window.removeEventListener('mousedown', onDocMouseDown, true);
            window.removeEventListener('mouseup', onDocMouseUp, true);
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('selectstart', onSelectStartOrEnd, true);
            window.removeEventListener('selectend', onSelectStartOrEnd, true);
            if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
            if (mask.parentNode) mask.parentNode.removeChild(mask);
            resolve(result);
        };
        const submit = function () {
            const favcatsEl = document.getElementById(id + '_favcats');
            const pagesEl = document.getElementById(id + '_pages');
            const maxdEl = document.getElementById(id + '_maxd');
            const dryEl = document.getElementById(id + '_dry');
            const favcats = favcatsEl ? favcatsEl.value.trim() : defaults.favcats;
            const pages_per_cat = pagesEl ? parseInt(pagesEl.value, 10) || 0 : 0;
            const max_detail = maxdEl ? parseInt(maxdEl.value, 10) || 0 : 0;
            const dry_run = dryEl ? !!dryEl.checked : defaults.dry_run;
            close({ favcats: favcats, pages_per_cat: pages_per_cat, max_detail: max_detail, dry_run: dry_run });
        };
        function inDialogContent(elem) {
            let el = elem;
            while (el && el.nodeType === 1) {
                if (el.getAttribute && el.getAttribute('data-sfav-clickable') === '1') return true;
                if (el.tagName && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'BUTTON' || el.tagName === 'A')) return true;
                el = el.parentNode;
            }
            return false;
        }
        function inMask(elem) {
            let el = elem;
            while (el && el.nodeType === 1) {
                if (el === mask) return true;
                el = el.parentNode;
            }
            return false;
        }
        function onKey(e) {
            if (settled) return;
            if (e.key === 'Escape') { e.preventDefault(); close(null); return; }
            if (e.key === 'Enter' && !(e.target && (e.target.tagName === 'TEXTAREA' || (e.target.tagName === 'INPUT' && e.target.type && String(e.target.type).toLowerCase() === 'button')))) {
                const okb = document.getElementById(id + '_ok');
                if (okb && !okb.disabled) { e.preventDefault(); submit(); }
            }
        }
        function onSelectStartOrEnd(e) {
            if (settled) return;
            const sel = window.getSelection ? window.getSelection() : null;
            if (!sel || sel.rangeCount === 0) return;
            const range = sel.getRangeAt(0);
            const sc = range.startContainer, ec = range.endContainer;
            function _inside(el) {
                if (!el) return false;
                let n = el.nodeType === 1 ? el : el.parentNode;
                while (n && n.nodeType === 1) {
                    if (inDialogContent(n)) return true;
                    if (n === wrap) return false;
                    n = n.parentNode;
                }
                return false;
            }
            if (_inside(sc) && !_inside(ec)) {
                pressStartInDialog = true;
                pressStartedAt = Date.now();
            }
        }
        function onDocMouseDown(e) {
            if (settled) return;
            if (e.button != null && e.button !== 0) return;
            const target = e.target;
            pressStartInDialog = inDialogContent(target);
            pressStartInMask = !pressStartInDialog && inMask(target);
            pressStartedAt = Date.now();
        }
        function onDocMouseUp(e) {
            if (settled) return;
            if (e.button != null && e.button !== 0) return;
            const target = e.target;
            const endInDialog = inDialogContent(target);
            const endInMask = !endInDialog && inMask(target);
            const dur = Date.now() - pressStartedAt;
            const isQuick = dur <= CLOSE_CLICK_MS;
            if (pressStartInDialog && !endInDialog && !isQuick) return;
            if (pressStartInDialog && endInMask && isQuick) return;
            if (pressStartInMask && endInMask && isQuick) {
                close(null);
                return;
            }
            if (pressStartInMask && !endInMask && !isQuick) return;
        }
        wrap.addEventListener('click', function (e) {
            if (settled) return;
            const t = e.target;
            if (!(t instanceof HTMLElement)) return;
            if (t.getAttribute('data-sfav-dismiss') === id) {
                close(null);
                return;
            }
            if (t.classList && t.classList.contains('modal')) {
                e.stopPropagation();
                e.preventDefault();
                return;
            }
            if (t.id === id + '_ok') submit();
        });
        document.body.appendChild(mask);
        document.body.appendChild(wrap);
        window.addEventListener('mousedown', onDocMouseDown, true);
        window.addEventListener('mouseup', onDocMouseUp, true);
        window.addEventListener('keydown', onKey, true);
        window.addEventListener('selectstart', onSelectStartOrEnd, true);
        window.addEventListener('selectend', onSelectStartOrEnd, true);
        const f = document.getElementById(id + '_favcats');
        if (f) setTimeout(() => f.focus(), 100);
    });
}

function createOutputModal(title, initialMessage) {
    const id = '_out_modal_' + Math.random().toString(36).slice(2, 8);
    const mask = document.createElement('div');
    mask.className = 'modal-backdrop fade show';
    mask.style.zIndex = '1050';
    mask.style.background = 'rgba(0,0,0,0.45)';
    const wrap = document.createElement('div');
    wrap.className = 'modal d-block fade show';
    wrap.style.zIndex = '1055';
    wrap.setAttribute('tabindex', '-1');
    wrap.innerHTML =
        '<div class="modal-dialog modal-dialog-centered modal-lg">' +
        '<div class="modal-content" data-output-clickable="1">' +
        '<div class="modal-header" data-output-clickable="1"><h5 class="modal-title" data-output-clickable="1">' + escapeHtml(title) + '</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="' + id + '" aria-label="Close" data-output-clickable="1"></button></div>' +
        '<div class="modal-body" id="' + id + '_body" data-output-clickable="1">' + (initialMessage || '') + '</div>' +
        '<div class="modal-footer" data-output-clickable="1">' +
        '<button type="button" class="btn btn-danger btn-sm d-none" id="' + id + '_cancel" data-output-clickable="1">终止任务</button>' +
        '<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="' + id + '" data-output-clickable="1">关闭</button>' +
        '</div></div></div>';
    const CLOSE_CLICK_MS = 400;
    let _pressStartIn = false;
    let _pressStartInMask = false;
    let _pressStartTs = 0;
    function _isInner(elem) {
        let el = elem;
        while (el && el.nodeType === 1) {
            if (el.getAttribute && el.getAttribute('data-output-clickable') === '1') return true;
            if (el.tagName && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'BUTTON' || el.tagName === 'A')) return true;
            el = el.parentNode;
        }
        return false;
    }
    function _isMask(elem) {
        let el = elem;
        while (el && el.nodeType === 1) {
            if (el === mask) return true;
            el = el.parentNode;
        }
        return false;
    }
    let _closed = false;
    function _close() {
        if (_closed) return;
        _closed = true;
        document.removeEventListener('mousedown', _onDocMouseDown, true);
        document.removeEventListener('mouseup', _onDocMouseUp, true);
        document.removeEventListener('selectstart', _onSelStartOrEnd, true);
        document.removeEventListener('selectend', _onSelStartOrEnd, true);
        if (wrap.parentNode) wrap.parentNode.removeChild(wrap);
        if (mask.parentNode) mask.parentNode.removeChild(mask);
    }
    function _onDocMouseDown(e) {
        if (_closed) return;
        if (e.button != null && e.button !== 0) return;
        const target = e.target;
        _pressStartIn = _isInner(target);
        _pressStartInMask = !_pressStartIn && _isMask(target);
        _pressStartTs = Date.now();
    }
    function _onDocMouseUp(e) {
        if (_closed) return;
        if (e.button != null && e.button !== 0) return;
        const target = e.target;
        const endIn = _isInner(target);
        const endMask = !endIn && _isMask(target);
        const dur = Date.now() - _pressStartTs;
        const quick = dur <= CLOSE_CLICK_MS;
        if (_pressStartIn && !endIn && !quick) return;
        if (_pressStartIn && endMask && quick) return;
        if (_pressStartInMask && endMask && quick) { _close(); return; }
        if (_pressStartInMask && !endMask && !quick) return;
    }
    function _onSelStartOrEnd() {
        if (_closed) return;
        const sel = window.getSelection ? window.getSelection() : null;
        if (!sel || sel.rangeCount === 0) return;
        const range = sel.getRangeAt(0);
        const sc = range.startContainer, ec = range.endContainer;
        function _s(el) {
            if (!el) return false;
            let n = el.nodeType === 1 ? el : el.parentNode;
            while (n && n.nodeType === 1) {
                if (_isInner(n)) return true;
                if (n === wrap) return false;
                n = n.parentNode;
            }
            return false;
        }
        if (_s(sc) && !_s(ec)) {
            _pressStartIn = true;
            _pressStartTs = Date.now();
        }
    }
    const cancelBtn = function () { return document.getElementById(id + '_cancel'); };
    wrap.addEventListener('click', function (e) {
        if (_closed) return;
        const t = e.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.id === id + '_cancel') {
            t.disabled = true;
            t.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>已请求终止…';
            api('stop_download', { form: { action: 'stop_download' } }).catch(function () {});
            const body = document.getElementById(id + '_body');
            if (body) {
                body.innerHTML =
                    '<div class="text-warning"><i class="fas fa-hourglass-half me-1"></i>已请求终止任务，PHP 会在下一次轮询时杀掉 Python 子进程（约 1s 内）…</div>' +
                    (body.innerHTML ? '<hr class="my-2"><div>' + body.innerHTML + '</div>' : '');
            }
            return;
        }
        const d = t.getAttribute('data-bs-dismiss');
        if (d === id) { _close(); return; }
        if (t.classList && t.classList.contains('modal')) {
            e.stopPropagation();
            e.preventDefault();
            return;
        }
    });
    document.addEventListener('mousedown', _onDocMouseDown, true);
    document.addEventListener('mouseup', _onDocMouseUp, true);
    document.addEventListener('selectstart', _onSelStartOrEnd, true);
    document.addEventListener('selectend', _onSelStartOrEnd, true);
    document.body.appendChild(mask);
    document.body.appendChild(wrap);
    return {
        id: id,
        bodyId: id + '_body',
        close: _close,
        showCancel: function () {
            const b = cancelBtn();
            if (b) b.classList.remove('d-none');
        },
        hideCancel: function () {
            const b = cancelBtn();
            if (b) b.classList.add('d-none');
        },
    };
}

function updateOutputModal(handle, htmlContent, scrollToBottom) {
    const el = document.getElementById(handle.bodyId);
    if (!el) return;
    el.innerHTML = htmlContent;
    if (scrollToBottom) {
        try { el.scrollTop = el.scrollHeight; } catch (_) {}
    }
}
