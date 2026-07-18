// ─── Page switching ───
function switchPage(name) {
    document.querySelectorAll('.page-section').forEach(el => el.classList.add('section-hidden'));
    document.querySelectorAll('.sidebar .nav-link').forEach(el => el.classList.remove('active'));
    document.querySelector(`[data-page="${name}"]`)?.classList.add('active');
    const page = document.getElementById('page_' + name);
    if (page) page.classList.remove('section-hidden');

    const titles = {
        dashboard: ['仪表盘', '系统概览'],
        config: ['站点配置', 'Cookie、系统设置、下载控制'],
        gallery: ['本地图库', '已下载的画廊列表'],
        cache: ['本地缓存', '检索 exhentai 缓存并下载'],
        'test-verify': ['单本校对', '对单个画廊进行封面和元数据校对'],
        export: ['数据导出', '导出元数据+封面+DB'],
    };
    const t = titles[name] || ['页面', ''];
    document.getElementById('page_title').textContent = t[0];
    document.getElementById('page_subtitle').textContent = t[1];

    // load data on page switch
    if (name === 'dashboard') loadDashboard();
    if (name === 'gallery') loadGalleries();
    if (name === 'cache') { loadCacheLanguages(); loadCrawlLanguages(); loadCachePage(1); pollVerifyStatus(); }
    if (name === 'config') { loadCookies(); loadSettings(); }
    if (name === 'test-verify') { tvLoadList(); }
}

document.querySelectorAll('.sidebar .nav-link').forEach(a => {
    a.addEventListener('click', e => { e.preventDefault(); switchPage(a.dataset.page); });
});

// ─── Dashboard ───
async function loadDashboard() {
    const data = await api('get_stats');
    document.getElementById('stat_galleries').textContent = data.gallery_count ?? '-';
    document.getElementById('stat_db').textContent = data.db_exists ? '正常' : '未创建';
    document.getElementById('stat_db').style.color = data.db_exists ? '#22c55e' : '#94a3b8';
    document.getElementById('stat_venv').textContent = data.venv_exists ? '就绪' : '缺失';
    document.getElementById('stat_venv').style.color = data.venv_exists ? '#22c55e' : '#ef4444';
    document.getElementById('stat_config').textContent = data.config_file ? '已配置' : '未配置';
    document.getElementById('stat_config').style.color = data.config_file ? '#22c55e' : '#ef4444';
}

// ─── Auto-load on page enter ───
document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
});
