const API = 'api.php';

function imageApiUrl(source, sourceId, page) {
    return API + '?action=serve_image&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId) + '&page=' + encodeURIComponent(page);
}

function fallbackImageOnError(img) {
    var fallback = img.dataset ? img.dataset.fallback : '';
    if (fallback && img.src.indexOf(fallback) === -1) {
        img.dataset.fallback = '';
        img.src = fallback;
        return;
    }
    img.style.display = 'none';
}


function confirmDialog(options) {
    options = options || {};
    return new Promise(function(resolve) {
        var modalEl = document.getElementById('confirm_modal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            resolve(window.confirm(options.message || '确定要继续吗？'));
            return;
        }
        var titleEl = document.getElementById('confirm_modal_title');
        var bodyEl = document.getElementById('confirm_modal_body');
        var okBtn = document.getElementById('confirm_modal_ok');
        var cancelBtn = document.getElementById('confirm_modal_cancel');
        titleEl.textContent = options.title || '确认操作';
        bodyEl.innerHTML = '';
        var message = document.createElement('div');
        message.className = 'confirm-message';
        message.textContent = options.message || '确定要继续吗？';
        bodyEl.appendChild(message);
        if (options.detail) {
            var detail = document.createElement('div');
            detail.className = 'confirm-detail';
            detail.textContent = options.detail;
            bodyEl.appendChild(detail);
        }
        okBtn.textContent = options.okText || '确认';
        cancelBtn.textContent = options.cancelText || '取消';
        okBtn.className = 'btn ' + (options.okClass || 'btn-danger');

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var settled = false;
        var cleanup = function() {
            okBtn.removeEventListener('click', onOk);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
        };
        var onOk = function() {
            settled = true;
            cleanup();
            modal.hide();
            resolve(true);
        };
        var onHidden = function() {
            cleanup();
            if (!settled) resolve(false);
        };
        okBtn.addEventListener('click', onOk);
        modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
        modal.show();
    });
}
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
