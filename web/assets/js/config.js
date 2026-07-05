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
    document.getElementById('set_req_ua').value = req.user_agent || '';
    document.getElementById('set_req_delay').value = req.delay_between_requests || 1.5;
    document.getElementById('set_browser_headless').value = br.headless ? 'true' : 'false';
}

async function saveSettings() {
    const data = await api('get_config');
    const cfg = data.config || {};
    cfg.download = {
        path: document.getElementById('set_dl_path').value,
        max_concurrent: parseInt(document.getElementById('set_dl_concurrent').value) || 3,
        retry_times: parseInt(document.getElementById('set_dl_retry').value) || 3,
        retry_delay: parseInt(document.getElementById('set_dl_retry_delay').value) || 5,
    };
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
