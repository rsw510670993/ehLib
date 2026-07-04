const API = 'api.php';

function showToast(msg, type = 'success') {
    const c = document.getElementById('toast_container');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${type} border-0 show`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    c.appendChild(el);
    setTimeout(() => { el.remove(); }, 4000);
}

function showOutput(id, text, isError = false) {
    const el = document.getElementById(id);
    el.classList.add('show');
    el.className = 'output-box show';
    el.innerHTML = `<span class="${isError ? 'error' : 'info'}">${text.replace(/</g,'&lt;').replace(/\n/g,'<br>')}</span>`;
}

function clearOutput(id) {
    const el = document.getElementById(id);
    el.classList.remove('show');
    el.innerHTML = '';
}

function setButtonBusy(id, busy, busyHtml) {
    var btn = document.getElementById(id);
    if (!btn) return true;
    if (busy) {
        if (btn.disabled) return false;
        btn.dataset.idleHtml = btn.innerHTML;
        btn.disabled = true;
        if (busyHtml) btn.innerHTML = busyHtml;
        return true;
    }
    btn.disabled = false;
    if (btn.dataset.idleHtml) {
        btn.innerHTML = btn.dataset.idleHtml;
        delete btn.dataset.idleHtml;
    }
    return true;
}
async function api(method, params = {}) {
    let url = API + '?action=' + method;
    let opts = {};
    if (params.body) {
        opts.method = 'POST';
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(params.body);
    } else if (params.form) {
        opts.method = 'POST';
        opts.body = new URLSearchParams(params.form);
    }
    const resp = await fetch(url, opts);
    const text = await resp.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        return { ok: false, error: 'Invalid JSON response', raw: text.substring(0,200) };
    }
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = 0, size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return size.toFixed(1) + ' ' + units[i];
}

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function escapeAttr(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
