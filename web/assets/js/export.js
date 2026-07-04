// ─── Export ───
async function doExport() {
    clearOutput('export_output');
    document.getElementById('export_output').classList.add('show');
    document.getElementById('export_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>导出中...';
    document.getElementById('export_table_wrapper').style.display = 'none';

    const res = await api('export');
    if (!res.ok) {
        showOutput('export_output', res.output || '导出失败', true);
        return;
    }

    showOutput('export_output', `导出成功: ${res.file || ''}，共 ${(res.galleries || []).length} 条记录`, false);

    const galleries = res.galleries || [];
    if (galleries.length === 0) return;

    document.getElementById('export_count').textContent = `共 ${galleries.length} 条记录`;
    document.getElementById('export_table_body').innerHTML = galleries.map(g =>
        `<tr>
            <td><span class="badge ${g.source === 'nhentai' ? 'bg-danger' : 'bg-info'}">${g.source}</span></td>
            <td class="font-monospace">${g.source_id}</td>
            <td>${g.title}</td>
            <td>${g.artist || '-'}</td>
            <td>${g.total_pages}p</td>
        </tr>`
    ).join('');
    document.getElementById('export_table_wrapper').style.display = 'block';
}
