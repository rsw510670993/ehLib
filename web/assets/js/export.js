function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function doExport() {
    const out = document.getElementById('export_output');
    out.classList.add('show');
    out.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>打包中...';
    document.getElementById('export_table_wrapper').style.display = 'none';

    const res = await api('export');
    if (!res.ok) {
        out.innerHTML = '<span class="error">导出失败: ' + escapeHtml(res.output || '') + '</span>';
        return;
    }

    const file = res.file || '';
    const url = res.download_url || 'data/' + file;
    out.innerHTML = '<i class="fas fa-check-circle" style="color:#22c55e"></i> 导出成功<br>' +
        '<a href="' + url + '" class="btn btn-success btn-sm mt-2" download><i class="fas fa-download me-1"></i>下载导出包 (' + file + ')</a>' +
        '<br><span class="small" style="color:#94a3b8">包含: 元数据JSON + 数据库 + 封面图</span>';
}