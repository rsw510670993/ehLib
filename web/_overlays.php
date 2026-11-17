        <div class="modal fade" id="confirm_modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content" data-confirm-clickable="1">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirm_modal_title">确认操作</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="confirm_modal_body" data-confirm-clickable="1"></div>
                    <div class="modal-footer" data-confirm-clickable="1">
                        <button type="button" class="btn btn-outline-secondary" id="confirm_modal_cancel" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="confirm_modal_ok" data-confirm-clickable="1">确认</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="prompt_modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content" data-prompt-clickable="1">
                    <div class="modal-header">
                        <h5 class="modal-title" id="prompt_modal_title">输入</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" data-prompt-clickable="1">
                        <div id="prompt_modal_message" class="mb-2" data-prompt-clickable="1"></div>
                        <input type="text" class="form-control form-control-sm" id="prompt_modal_input" data-prompt-clickable="1">
                    </div>
                    <div class="modal-footer" data-prompt-clickable="1">
                        <button type="button" class="btn btn-outline-secondary" id="prompt_modal_cancel" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="prompt_modal_ok" data-prompt-clickable="1">确定</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- ═══ 阅读器 ═══ -->
        <div id="reader_page" class="section-hidden">
            <div class="reader-header">
                <div class="reader-header-top">
                    <button class="btn-close-reader reader-menu-btn" id="reader_menu_btn" onclick="readerToggleDrawer()" title="缩略图"><i class="fas fa-bars"></i></button>
                    <button class="btn-close-reader" onclick="closeReader()" title="关闭 (Esc)"><i class="fas fa-arrow-left"></i></button>
                    <span class="reader-title" id="reader_title"></span>
                    <span class="reader-meta" id="reader_meta"></span>
                    <div class="reader-pagenav">
                        <button onclick="readerPrevPage()" title="上一页 (←)"><i class="fas fa-chevron-left"></i></button>
                        <input type="number" class="page-input" id="reader_page_input" min="1" onchange="readerGoToPage(parseInt(this.value))">
                        <span class="mode-badge" id="reader_mode_badge" data-mode="single">单页</span>
                        <span id="reader_page_total"></span>
                        <button onclick="readerNextPage()" title="下一页 (→)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <button class="btn-toggle-details" id="reader_toggle_details" onclick="readerToggleDetails()" title="详细信息"><i class="fas fa-chevron-down"></i></button>
                </div>
                <div class="reader-header-details" id="reader_header_details">
                    <div class="reader-meta-row">
                        <span class="meta-item"><span class="meta-k">作者</span><span class="meta-v" id="reader_detail_artist"></span></span>
                        <span class="meta-item"><span class="meta-k">语言</span><span class="meta-v" id="reader_detail_lang"></span></span>
                        <span class="meta-item"><span class="meta-k">分类</span><span class="meta-v" id="reader_detail_category"></span></span>
                        <span class="meta-item"><span class="meta-k">上传</span><span class="meta-v" id="reader_detail_uploaded"></span></span>
                        <span class="meta-spacer"></span>
                        <span class="meta-count" id="reader_tag_count"></span>
                        <button type="button" class="reader-meta-btn" id="reader_refresh_meta" onclick="readerRefreshMetaCurrent()">
                            <i class="fas fa-rotate"></i> 刷新元数据
                        </button>
                    </div>
                    <div class="detail-tags" id="reader_detail_tags"></div>
                    <button type="button" class="tag-toggle" id="reader_tag_toggle" hidden></button>
                    <div id="reader_tag_warning"></div>
                </div>
            </div>
            <div class="reader-body">
                <div class="reader-thumbstrip" id="reader_thumbstrip">
                    <div id="reader_thumb_list"></div>
                </div>
                <div class="reader-drawer-scrim" id="reader_drawer_scrim" onclick="readerToggleDrawer(false)"></div>
                <div class="reader-main" id="reader_main">
                    <div class="page-stage" id="reader_page_stage">
                        <img class="page-img" id="reader_main_img" alt="loading...">
                        <img class="page-img" id="reader_second_img" alt="" hidden>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ 压缩审核比较 Modal（单本）════ -->
        <div class="modal fade compress-compare-modal" id="compress_compare_modal" tabindex="-1" aria-hidden="true"
             data-bs-backdrop="static">
            <div class="modal-dialog modal-fullscreen-xl-down modal-dialog-centered modal-dialog-scrollable" style="max-width:98vw;">
                <div class="modal-content">
                    <div class="modal-header py-2 align-items-start">
                        <div style="flex:1;min-width:0">
                            <h5 class="modal-title d-inline-block me-2" id="cc_title">压缩审核比较</h5>
                            <span id="cc_badge" class="badge me-2"></span>
                            <div class="small text-muted mt-1" id="cc_meta"></div>
                            <div class="small text-muted" id="cc_params" style="margin-top:2px;"></div>
                            <div id="cc_upgrade_hint" class="small text-info mt-1" style="display:none">
                                <i class="fas fa-info-circle me-1"></i>
                                如需发起其他漫画的压缩或查看任务进度，请前往「工具 & 维护 → 图片压缩」。
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 align-items-start">
                            <button type="button" class="btn btn-sm btn-success me-1" id="cc_btn_approve" title="审核通过后立即应用所有变小的候选并删除原图">
                                <i class="fas fa-check me-1"></i>通过并应用
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning me-1" id="cc_btn_rerun" title="用AVIF默认参数 65/5/5% 重跑一次整本压缩">
                                <i class="fas fa-arrows-rotate me-1"></i>整本重做（默认参数）
                            </button>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0" style="display:flex;min-height:78vh;max-height:82vh;">
                        <div id="cc_thumbs" class="cc-thumbs"></div>
                        <div id="cc_main" class="cc-main" style="flex:1;display:flex;flex-direction:column;min-width:0;background:#111;color:#e2e8f0;">
                            <div id="cc_toolbar" class="cc-toolbar d-none">
                                <div class="cc-page-nav">
                                    <button id="cc_prev" class="btn btn-sm btn-dark"><i class="fas fa-chevron-left"></i></button>
                                    <span id="cc_page_label" class="small mx-2">1 / 7</span>
                                    <button id="cc_next" class="btn btn-sm btn-dark"><i class="fas fa-chevron-right"></i></button>
                                </div>
                                <div class="small text-muted flex-grow-1 text-center">← / → 翻页 · 1 = Fit · 2 = 100% · Esc 关闭</div>
                            </div>
                            <div id="cc_pane_row" class="d-none" style="flex:1;display:flex;gap:2px;min-height:0;border-top:1px solid #1e293b;">
                                <div class="cc-pane" data-side="orig">
                                    <div class="cc-pane-head small" style="background:#1e293b;color:#94a3b8;padding:2px 6px;display:flex;justify-content:space-between;align-items:center;">
                                        <span><i class="fas fa-file-image me-1"></i>原图 <span id="cc_orig_tag"></span></span>
                                        <span class="d-flex align-items-center gap-1">
                                            <span class="btn-group btn-group-sm">
                                                <button class="btn btn-dark cc-zoom" data-zoom="fit" data-side="orig">Fit</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="100" data-side="orig">100%</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="200" data-side="orig">200%</button>
                                            </span>
                                            <button class="btn btn-dark btn-sm cc-copy-path" data-side="orig" title="复制此图磁盘路径"><i class="far fa-copy"></i></button>
                                        </span>
                                    </div>
                                    <div class="cc-scroll" id="cc_scroll_orig"><img id="cc_img_orig" alt=""></div>
                                    <div class="cc-meta small" id="cc_meta_orig" style="background:#1e293b;color:#94a3b8;padding:2px 6px;min-height:22px;"></div>
                                </div>
                                <div class="cc-pane-divider"></div>
                                <div class="cc-pane" data-side="cmp">
                                    <div class="cc-pane-head small" style="background:#1e293b;color:#94a3b8;padding:2px 6px;display:flex;justify-content:space-between;align-items:center;">
                                        <span><i class="fas fa-compress me-1"></i>压缩候选 <span id="cc_cmp_tag"></span></span>
                                        <span class="d-flex align-items-center gap-1">
                                            <span class="btn-group btn-group-sm">
                                                <button class="btn btn-dark cc-zoom" data-zoom="fit" data-side="cmp">Fit</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="100" data-side="cmp">100%</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="200" data-side="cmp">200%</button>
                                            </span>
                                            <button class="btn btn-dark btn-sm cc-copy-path" data-side="cmp" title="复制此图磁盘路径"><i class="far fa-copy"></i></button>
                                        </span>
                                    </div>
                                    <div class="cc-scroll" id="cc_scroll_cmp">
                                        <div id="cc_cmp_placeholder" class="text-center text-muted px-3" style="display:none"></div>
                                        <img id="cc_img_cmp" alt="">
                                    </div>
                                    <div class="cc-meta small" id="cc_meta_cmp" style="background:#1e293b;color:#94a3b8;padding:2px 6px;min-height:22px;"></div>
                                </div>
                            </div>
                            <div id="cc_empty" class="d-flex align-items-center justify-content-center h-100 text-muted">
                                <div class="text-center">
                                    <i class="fas fa-hourglass-half fa-3x mb-2"></i>
                                    <div id="cc_empty_text">加载压缩审核信息…</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
