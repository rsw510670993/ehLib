let tvSelectedSid = '';

async function tvLoadList() {
    tvSelectedSid = '';
    document.getElementById('tv_verify_btn').disabled = true;
    document.getElementById('tv_result').classList.remove('show');
    document.getElementById('tv_search_input').value = '';
    document.getElementById('tv_search_results').style.display = 'none';
}

document.getElementById('tv_search_input').addEventListener('input', function () {
    const q = this.value.trim();
    tvSelectedSid = '';
    document.getElementById('tv_verify_btn').disabled = true;
    const container = document.getElementById('tv_search_results');
    if (q.length < 1) {
        container.style.display = 'none';
        return;
    }
    container.innerHTML = '<div class="list-group-item text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>搜索中...</div>';
    container.style.display = 'block';
    api('list_cached', { form: { source: 'exhentai', q: q } }).then(data => {
        const galleries = data.galleries || [];
        if (galleries.length === 0) {
            container.innerHTML = '<div class="list-group-item text-muted small">无匹配结果</div>';
            return;
        }
        container.innerHTML = '';
        for (const g of galleries) {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            const title = (g.title || '').substring(0, 80);
            let text = title || g.source_id;
            if (g.artist) text += `  (${g.artist})`;
            item.textContent = text;
            item.title = g.source_id;
            item.addEventListener('click', function () {
                tvSelectedSid = g.source_id;
                document.getElementById('tv_verify_btn').disabled = false;
                container.querySelectorAll('.active').forEach(el => el.classList.remove('active'));
                item.classList.add('active');
                container.style.display = 'none';
                document.getElementById('tv_search_input').value = text;
            });
            container.appendChild(item);
        }
    }).catch(() => {
        container.innerHTML = '<div class="list-group-item text-danger small">搜索失败</div>';
    });
});

function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function tvImgToBase64(url) {
    try {
        const resp = await fetch(url, { cache: 'no-store' });
        const blob = await resp.blob();
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onloadend = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    } catch {
        return '';
    }
}

async function tvVerify() {
    const sid = tvSelectedSid;
    if (!sid) return;

    const btn = document.getElementById('tv_verify_btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>校对中...';

    const out = document.getElementById('tv_result');
    out.innerHTML = '<div>正在校对，请等待...</div>';
    out.classList.add('show');

    const thumbUrl = 'api.php?action=serve_cache_thumb&source=exhentai&source_id=' + encodeURIComponent(sid);
    const oldThumbData = await tvImgToBase64(thumbUrl);

    try {
        const data = await api('verify_single', { form: { source: 'exhentai', source_id: sid } });

        if (data.error) {
            out.innerHTML = `<span class="error">错误: ${escapeHtml(data.error)}</span>`;
            return;
        }

        let html = '<div class="row g-3">';

        html += '<div class="col-md-6 text-center">';
        html += '<div class="small" style="color:#94a3b8">旧封面</div>';
        if (oldThumbData) {
            html += '<img src="' + oldThumbData + '" style="max-width:100%;max-height:200px;object-fit:contain;border:1px solid #444;border-radius:4px">';
        } else {
            html += '<div class="small" style="color:#94a3b8">(无)</div>';
        }
        html += '</div>';

        html += '<div class="col-md-6 text-center">';
        html += '<div class="small" style="color:#94a3b8">新封面 <span class="badge bg-success">已更新</span></div>';
        html += '<img src="' + thumbUrl + '?' + Date.now() + '" style="max-width:100%;max-height:200px;object-fit:contain;border:1px solid #444;border-radius:4px" onerror="this.style.display=\'none\'">';
        html += '</div>';

        html += '</div>';

        html += '<div class="mb-2 mt-3"><span class="info">校对完成</span></div>';
        if (data.diff && Object.keys(data.diff).length > 0) {
            html += '<table class="table table-sm table-dark mt-2" style="font-size:.82rem">';
            html += '<thead><tr><th>字段</th><th>旧值</th><th>新值</th></tr></thead><tbody>';
            for (const [field, [ov, nv]] of Object.entries(data.diff)) {
                const fname = { artist: '作者', uploaded_at: '上传时间', category: '分类', language: '语言', title_jp: '日文标题', group_name: '社团', tags: '标签', total_pages: '页数', cover_url: '封面URL' }[field] || field;
                html += '<tr><td>' + escapeHtml(fname) + '</td><td style="color:#f87171;max-width:300px;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(ov || '(空)') + '</td><td style="color:#22c55e;max-width:300px;overflow:hidden;text-overflow:ellipsis">' + escapeHtml(nv || '(空)') + '</td></tr>';
            }
            html += '</tbody></table>';
        } else {
            html += '<div><span class="info">✓ 无差异，数据一致</span></div>';
        }
        if (data.new && data.new.thumb_path) {
            html += '<div class="mt-2 small" style="color:#94a3b8">封面文件: ' + escapeHtml(data.new.thumb_path) + '</div>';
        }
        out.innerHTML = html;
    } catch (e) {
        out.innerHTML = '<span class="error">请求失败: ' + escapeHtml(e.message) + '</span>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-play me-1"></i>开始校对';
    }
}