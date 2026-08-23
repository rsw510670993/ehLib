// ─── Cookies ───
async function loadCookies() {
    const data = await api('get_config');
    if (!data.config || !data.config.cookies) return;
    const c = data.config.cookies;
    if (c.nhentai) {
        document.getElementById('cookie_nh_cf_clearance').value = c.nhentai.cf_clearance || '';
    }
    if (c.exhentai) {
        document.getElementById('cookie_ex_ipb_member_id').value = c.exhentai.ipb_member_id || '';
        document.getElementById('cookie_ex_ipb_pass_hash').value = c.exhentai.ipb_pass_hash || '';
        document.getElementById('cookie_ex_cf_clearance').value = c.exhentai.cf_clearance || '';
    }
}

async function saveCookies() {
    const data = await api('get_config');
    const cfg = data.config || {};
    if (!cfg.cookies) cfg.cookies = { nhentai: {}, exhentai: {} };
    cfg.cookies.nhentai = {
        cf_clearance: document.getElementById('cookie_nh_cf_clearance').value,
    };
    cfg.cookies.exhentai = {
        ipb_member_id: document.getElementById('cookie_ex_ipb_member_id').value,
        ipb_pass_hash: document.getElementById('cookie_ex_ipb_pass_hash').value,
    };
    const cf = document.getElementById('cookie_ex_cf_clearance').value;
    if (cf) cfg.cookies.exhentai.cf_clearance = cf;
    const res = await api('save_config', { body: cfg });
    if (res.ok) showToast('Cookie 已保存', 'success');
    else showToast('保存失败: ' + (res.error || ''), 'danger');
}

async function parseExCookieString() {
    const raw = document.getElementById('cookie_ex_raw').value.trim();
    if (!raw) { showToast('请先粘贴 Cookie 字符串', 'warning'); return; }
    const res = await api('parse_cookie_string', { form: { action: 'parse_cookie_string', cookie_string: raw } });
    if (!res.ok) { showToast('解析失败: ' + (res.error || ''), 'danger'); return; }
    const p = res.parsed || {};
    let filled = 0;
    if (p.ipb_member_id) { document.getElementById('cookie_ex_ipb_member_id').value = p.ipb_member_id; filled++; }
    if (p.ipb_pass_hash) { document.getElementById('cookie_ex_ipb_pass_hash').value = p.ipb_pass_hash; filled++; }
    if (p.cf_clearance) { document.getElementById('cookie_ex_cf_clearance').value = p.cf_clearance; filled++; }
    showToast(`解析成功，已填入 ${filled} 个字段`, 'success');
}

// ─── Settings ───
function readBoundedSetting(id, fallback, min, max, integer) {
    const input = document.getElementById(id);
    let value = input && input.value.trim() !== '' ? Number(input.value) : fallback;
    if (!Number.isFinite(value)) value = fallback;
    value = Math.max(min, Math.min(max, value));
    if (integer) value = Math.round(value);
    if (input) input.value = String(value);
    return value;
}

function updateAvifSettingsState() {
    const enabledInput = document.getElementById('set_dl_avif_enabled');
    const disabled = !enabledInput || !enabledInput.checked;
    ['set_dl_avif_quality', 'set_dl_avif_speed', 'set_dl_avif_min_savings'].forEach((id) => {
        const input = document.getElementById(id);
        if (input) input.disabled = disabled;
    });
}
window.updateAvifSettingsState = updateAvifSettingsState;

async function loadSettings() {
    const data = await api('get_config');
    if (!data.config) return;
    const dl = data.config.download || {};
    const req = data.config.request || {};
    const br = data.config.browser || {};
    document.getElementById('set_dl_path').value = dl.path || './downloads';
    document.getElementById('set_dl_concurrent').value = dl.max_concurrent || 3;
    document.getElementById('set_dl_retry').value = dl.retry_times || 3;
    document.getElementById('set_dl_retry_delay').value = dl.retry_delay || 5;
    document.getElementById('set_dl_avif_enabled').checked =
        dl.convert_to_avif ?? (dl.convert_to_webp !== false);
    document.getElementById('set_dl_avif_quality').value = dl.avif_quality ?? 65;
    document.getElementById('set_dl_avif_speed').value = dl.avif_speed ?? 5;
    document.getElementById('set_dl_avif_min_savings').value =
        dl.avif_min_savings_percent ?? dl.webp_min_savings_percent ?? 5;
    updateAvifSettingsState();
    document.getElementById('set_req_ua').value = req.user_agent || '';
    document.getElementById('set_req_delay').value = req.delay_between_requests || 1.5;
    document.getElementById('set_browser_headless').value = br.headless ? 'true' : 'false';
}

async function saveSettings() {
    const data = await api('get_config');
    const cfg = data.config || {};
    const currentDownload = cfg.download || {};
    cfg.download = Object.assign({}, currentDownload, {
        path: document.getElementById('set_dl_path').value,
        max_concurrent: parseInt(document.getElementById('set_dl_concurrent').value) || 3,
        retry_times: parseInt(document.getElementById('set_dl_retry').value) || 3,
        retry_delay: parseInt(document.getElementById('set_dl_retry_delay').value) || 5,
        convert_to_avif: document.getElementById('set_dl_avif_enabled').checked,
        avif_quality: readBoundedSetting('set_dl_avif_quality', 65, 1, 100, true),
        avif_speed: readBoundedSetting('set_dl_avif_speed', 5, 0, 10, true),
        avif_min_savings_percent: readBoundedSetting('set_dl_avif_min_savings', 5, 0, 100, false),
    });
    delete cfg.download.convert_to_webp;
    delete cfg.download.webp_quality;
    delete cfg.download.webp_method;
    delete cfg.download.webp_min_savings_percent;
    cfg.request = {
        user_agent: document.getElementById('set_req_ua').value,
        delay_between_requests: parseFloat(document.getElementById('set_req_delay').value) || 1.5,
    };
    cfg.browser = {
        headless: document.getElementById('set_browser_headless').value === 'true',
    };
    const res = await api('save_config', { body: cfg });
    if (res.ok) showToast('设置已保存', 'success');
    else showToast('保存失败: ' + (res.error || ''), 'danger');
}

// —————— 排除标签黑名单 tag_blacklist ——————
async function loadBlacklistTags() {
    const tbody = document.getElementById('bl_tags_tbody');
    if (!tbody) return;
    try {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-1"></i>加载中…</td></tr>`;
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), 8000);
        const url = (window.API || 'api.php') + '?action=list_blacklist_tags';
        let res;
        try {
            const resp = await fetch(url, { signal: ctrl.signal });
            clearTimeout(t);
            const text = await resp.text();
            try { res = JSON.parse(text); } catch(e) { res = { ok: false, error: '响应非 JSON: ' + (text||'').slice(0,80), raw: text.slice(0,200) }; }
        } catch (fe) {
            clearTimeout(t);
            if (fe && fe.name === 'AbortError') res = { ok: false, error: '请求超时（8s）' };
            else res = { ok: false, error: (fe && fe.message) ? fe.message : String(fe) };
        }
        if (!res || res.error || res.ok === false) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-danger">加载失败：${escapeHtml(res?.error || '接口无响应')}</td></tr>`;
            try { showToast('加载排除列表失败：' + (res?.error || '接口无响应'), 'danger'); } catch(e) { console.error(e); }
            return;
        }
        const tags = Array.isArray(res.tags) ? res.tags : [];
        if (tags.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-muted">暂无排除项</td></tr>`;
            return;
        }
        tbody.innerHTML = tags.map((t, i) => {
            const tt = escapeHtml(t.tag_type || '');
            const tv = escapeHtml(t.tag_value || '');
            const ca = escapeHtml(t.created_at || '');
            return `<tr>
                <td class="text-muted small">${i + 1}</td>
                <td><code class="small">${tt || '*'}</code></td>
                <td><span class="text-break">${tv}</span></td>
                <td class="small text-muted">${ca}</td>
                <td class="text-end"><button class="btn btn-sm btn-outline-danger" onclick="deleteBlacklistTag(${+t.id})" title="删除"><i class="fas fa-trash"></i></button></td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error('[loadBlacklistTags]', e);
        const msg = e && e.message ? e.message : String(e);
        try { tbody.innerHTML = `<tr><td colspan="5" class="text-center py-4 text-danger">异常：${escapeHtml(msg)}</td></tr>`; } catch(_) {}
        try { showToast('加载排除列表异常：' + msg, 'danger'); } catch(_) {}
    }
}

async function addBlacklistTag() {
    const typeSel = document.getElementById('bl_tag_type');
    const valInp = document.getElementById('bl_tag_value');
    if (!typeSel || !valInp) return;
    const tag_value = valInp.value.trim();
    if (!tag_value) { try { showToast('值不能为空', 'warning'); } catch(_) {} return; }
    const tag_type = typeSel.value || '*';
    try {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), 8000);
        const url = (window.API || 'api.php') + '?action=add_blacklist_tag';
        let res;
        try {
            const resp = await fetch(url, {
                method: 'POST',
                signal: ctrl.signal,
                body: new URLSearchParams({ tag_type, tag_value })
            });
            clearTimeout(t);
            const text = await resp.text();
            try { res = JSON.parse(text); } catch(e) { res = { ok:false, error:'响应非 JSON: ' + (text||'').slice(0,80) }; }
        } catch (fe) {
            clearTimeout(t);
            if (fe && fe.name === 'AbortError') res = { ok:false, error:'请求超时（8s）' };
            else res = { ok:false, error:(fe && fe.message) ? fe.message : String(fe) };
        }
        if (!res || res.error || res.ok === false) {
            try { showToast('添加失败：' + (res?.error || '接口无响应'), 'danger'); } catch(_) {}
            return;
        }
        try { showToast(res.message || '已加入排除', 'success'); } catch(_) {}
        valInp.value = '';
        await loadBlacklistTags();
    } catch (e) {
        console.error('[addBlacklistTag]', e);
        try { showToast('添加异常：' + (e.message || String(e)), 'danger'); } catch(_) {}
    }
}

async function deleteBlacklistTag(id) {
    if (!id) return;
    try {
        if (!confirm('确认从排除列表移除？')) return;
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), 8000);
        const url = (window.API || 'api.php') + '?action=delete_blacklist_tag';
        let res;
        try {
            const resp = await fetch(url, {
                method: 'POST',
                signal: ctrl.signal,
                body: new URLSearchParams({ id: String(+id) })
            });
            clearTimeout(t);
            const text = await resp.text();
            try { res = JSON.parse(text); } catch(e) { res = { ok:false, error:'响应非 JSON: ' + (text||'').slice(0,80) }; }
        } catch (fe) {
            clearTimeout(t);
            if (fe && fe.name === 'AbortError') res = { ok:false, error:'请求超时（8s）' };
            else res = { ok:false, error:(fe && fe.message) ? fe.message : String(fe) };
        }
        if (!res || res.error || res.ok === false) {
            try { showToast('删除失败：' + (res?.error || '接口无响应'), 'danger'); } catch(_) {}
            return;
        }
        try { showToast(res.message || '已移除', 'success'); } catch(_) {}
        await loadBlacklistTags();
    } catch (e) {
        console.error('[deleteBlacklistTag]', e);
        try { showToast('删除异常：' + (e.message || String(e)), 'danger'); } catch(_) {}
    }
}

// 保证 inline onclick 能找到（<button onclick="addBlacklistTag()"> 是在 window 作用域查找）
window.loadBlacklistTags = loadBlacklistTags;
window.addBlacklistTag = addBlacklistTag;
window.deleteBlacklistTag = deleteBlacklistTag;

document.addEventListener('shown.bs.tab', async (e) => {
    try {
        const target = e.target && typeof e.target.getAttribute === 'function' ? e.target.getAttribute('data-bs-target') : '';
        if (target === '#tab_blacklist_tags') await loadBlacklistTags();
    } catch(err) { console.error(err); }
});
document.addEventListener('DOMContentLoaded', () => {
    try {
        const act = document.querySelector('button[data-bs-target="#tab_blacklist_tags"].active');
        if (act) loadBlacklistTags();
    } catch(err) { console.error(err); }
});
