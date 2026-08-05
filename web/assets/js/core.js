const API = 'api.php';


function onCoverLoad(img) {
    if (img.naturalWidth > img.naturalHeight) {
        img.style.objectFit = 'contain';
    }
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
        var CLOSE_CLICK_MS = 400;
        var settled = false;
        var _pressStartInDialog = false;
        var _pressStartInBackdrop = false;
        var _pressStartTs = 0;
        function _isConfirmInner(elem) {
            var el = elem;
            while (el && el.nodeType === 1) {
                if (el.getAttribute && el.getAttribute('data-confirm-clickable') === '1') return true;
                if (el === okBtn || el === cancelBtn) return true;
                if (el.tagName && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'BUTTON' || el.tagName === 'A')) return true;
                el = el.parentNode;
            }
            return false;
        }
        function _isBackdrop(elem) {
            var el = elem;
            while (el && el.nodeType === 1) {
                if (el === modalEl) return true;
                if (el.classList && el.classList.contains('modal-backdrop') && el.parentNode && el.parentNode === document.body) return true;
                el = el.parentNode;
            }
            return false;
        }
        function _onDocMouseDown(e) {
            if (settled) return;
            if (e.button != null && e.button !== 0) return;
            var target = e.target;
            _pressStartInDialog = _isConfirmInner(target);
            _pressStartInBackdrop = !_pressStartInDialog && _isBackdrop(target);
            _pressStartTs = Date.now();
        }
        function _onDocMouseUp(e) {
            if (settled) return;
            if (e.button != null && e.button !== 0) return;
            var target = e.target;
            var insideEnd = _isConfirmInner(target);
            var endBackdrop = !insideEnd && _isBackdrop(target);
            var dur = Date.now() - _pressStartTs;
            var quick = dur <= CLOSE_CLICK_MS;
            if (_pressStartInDialog && !insideEnd && !quick) return;
            if (_pressStartInDialog && endBackdrop && quick) return;
            if (_pressStartInBackdrop && endBackdrop && quick) {
                settled = true;
                cleanup();
                modal.hide();
                resolve(false);
                return;
            }
            if (_pressStartInBackdrop && !endBackdrop && !quick) return;
        }
        function _onSelStartOrEnd() {
            if (settled) return;
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || sel.rangeCount === 0) return;
            var range = sel.getRangeAt(0);
            var sc = range.startContainer, ec = range.endContainer;
            function _s(el) {
                if (!el) return false;
                var n = el.nodeType === 1 ? el : el.parentNode;
                while (n && n.nodeType === 1) {
                    if (n.getAttribute && n.getAttribute('data-confirm-clickable') === '1') return true;
                    if (n === modalEl) return false;
                    n = n.parentNode;
                }
                return false;
            }
            if (_s(sc) && !_s(ec)) {
                _pressStartInDialog = true;
                _pressStartTs = Date.now();
            }
        }
        function _onKey(e) {
            if (settled) return;
            if (e.key === 'Escape') {
                settled = true;
                cleanup();
                modal.hide();
                resolve(false);
                return;
            }
            if (e.key === 'Enter' && !(e.target && e.target.tagName === 'TEXTAREA')) {
                onOk();
            }
        }
        var cleanup = function() {
            okBtn.removeEventListener('click', onOk);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            document.removeEventListener('mousedown', _onDocMouseDown, true);
            document.removeEventListener('mouseup', _onDocMouseUp, true);
            document.removeEventListener('keydown', _onKey, true);
            document.removeEventListener('selectstart', _onSelStartOrEnd, true);
            document.removeEventListener('selectend', _onSelStartOrEnd, true);
        };
        var onOk = function() {
            if (settled) return;
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
        document.addEventListener('mousedown', _onDocMouseDown, true);
        document.addEventListener('mouseup', _onDocMouseUp, true);
        document.addEventListener('keydown', _onKey, true);
        document.addEventListener('selectstart', _onSelStartOrEnd, true);
        document.addEventListener('selectend', _onSelStartOrEnd, true);
        modal.show();
    });
}
function promptDialog(options) {
    options = options || {};
    return new Promise(function(resolve) {
        var modalEl = document.getElementById('prompt_modal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            resolve(prompt(options.message || '请输入：', options.defaultValue || ''));
            return;
        }
        var titleEl = document.getElementById('prompt_modal_title');
        var msgEl = document.getElementById('prompt_modal_message');
        var inputEl = document.getElementById('prompt_modal_input');
        var okBtn = document.getElementById('prompt_modal_ok');
        var cancelBtn = document.getElementById('prompt_modal_cancel');
        titleEl.textContent = options.title || '输入';
        msgEl.textContent = options.message || '';
        inputEl.value = options.defaultValue || '';
        okBtn.textContent = options.okText || '确定';
        cancelBtn.textContent = options.cancelText || '取消';

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var CLOSE_CLICK_MS = 400;
        var settled = false;
        var _pressStartInDialog = false;
        var _pressStartInBackdrop = false;
        var _pressStartTs = 0;
        function _isPromptInner(elem) {
            var el = elem;
            while (el && el.nodeType === 1) {
                if (el.getAttribute && el.getAttribute('data-prompt-clickable') === '1') return true;
                if (el === okBtn || el === cancelBtn || el === inputEl) return true;
                if (el.tagName && (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'BUTTON' || el.tagName === 'A')) return true;
                el = el.parentNode;
            }
            return false;
        }
        function _isBackdrop(elem) {
            var el = elem;
            while (el && el.nodeType === 1) {
                if (el === modalEl) return true;
                if (el.classList && el.classList.contains('modal-backdrop') && el.parentNode && el.parentNode === document.body) return true;
                el = el.parentNode;
            }
            return false;
        }
        function _onDocMouseDown(e) {
            if (settled) return;
            if (e.button != null && e.button !== 0) return;
            var target = e.target;
            _pressStartInDialog = _isPromptInner(target);
            _pressStartInBackdrop = !_pressStartInDialog && _isBackdrop(target);
            _pressStartTs = Date.now();
        }
        function _onDocMouseUp(e) {
            if (settled) return;
            if (e.button != null && e.button !== 0) return;
            var target = e.target;
            var insideEnd = _isPromptInner(target);
            var endBackdrop = !insideEnd && _isBackdrop(target);
            var dur = Date.now() - _pressStartTs;
            var quick = dur <= CLOSE_CLICK_MS;
            if (_pressStartInDialog && !insideEnd && !quick) return;
            if (_pressStartInDialog && endBackdrop && quick) return;
            if (_pressStartInBackdrop && endBackdrop && quick) {
                settled = true;
                cleanup();
                modal.hide();
                resolve(null);
                return;
            }
            if (_pressStartInBackdrop && !endBackdrop && !quick) return;
        }
        function _onSelStartOrEnd() {
            if (settled) return;
            var sel = window.getSelection ? window.getSelection() : null;
            if (!sel || sel.rangeCount === 0) return;
            var range = sel.getRangeAt(0);
            var sc = range.startContainer, ec = range.endContainer;
            function _s(el) {
                if (!el) return false;
                var n = el.nodeType === 1 ? el : el.parentNode;
                while (n && n.nodeType === 1) {
                    if (n.getAttribute && n.getAttribute('data-prompt-clickable') === '1') return true;
                    if (n === modalEl) return false;
                    n = n.parentNode;
                }
                return false;
            }
            if (_s(sc) && !_s(ec)) {
                _pressStartInDialog = true;
                _pressStartTs = Date.now();
            }
        }
        var cleanup = function() {
            okBtn.removeEventListener('click', onOk);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            inputEl.removeEventListener('keydown', onKeydown);
            document.removeEventListener('mousedown', _onDocMouseDown, true);
            document.removeEventListener('mouseup', _onDocMouseUp, true);
            document.removeEventListener('selectstart', _onSelStartOrEnd, true);
            document.removeEventListener('selectend', _onSelStartOrEnd, true);
        };
        var onOk = function() {
            if (settled) return;
            settled = true;
            cleanup();
            modal.hide();
            resolve(inputEl.value);
        };
        var onHidden = function() {
            cleanup();
            if (!settled) resolve(null);
        };
        var onKeydown = function(e) {
            if (e.key === 'Enter') onOk();
        };
        okBtn.addEventListener('click', onOk);
        modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
        inputEl.addEventListener('keydown', onKeydown);
        document.addEventListener('mousedown', _onDocMouseDown, true);
        document.addEventListener('mouseup', _onDocMouseUp, true);
        document.addEventListener('selectstart', _onSelStartOrEnd, true);
        document.addEventListener('selectend', _onSelStartOrEnd, true);
        modal.show();
        setTimeout(function() { inputEl.focus(); }, 100);
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

// 将 tag 转为 ExHentai 检索语法: type:"name$"
function tagToExhentaiSyntax(type, name) {
    if (!type || !name) return '';
    return type + ':"' + name + '$"';
}

// 将 tag 追加到爬取关键词输入框 (去重)
function addTagToCrawlKeyword(type, name) {
    var syntax = tagToExhentaiSyntax(type, name);
    if (!syntax) return;
    var input = document.getElementById('crawl_keyword');
    if (!input) return;
    var current = input.value.trim();
    // 已包含该 tag 则不重复添加
    if (current.indexOf(syntax) !== -1) return;
    input.value = current ? current + ' ' + syntax : syntax;
    input.focus();
}
