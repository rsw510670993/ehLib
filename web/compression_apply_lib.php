<?php

/**
 * 审核通过后立即应用整本压缩候选。
 *
 * 只应用 compression_info.pages 中 used_webp=true 且体积严格变小的页面。
 * 原图只在事务期间暂存于候选工作目录；数据库提交成功后连同候选一起删除。
 * 文件操作或数据库提交任一步失败时，按相反顺序恢复原图，不保留永久备份。
 */
function compression_remove_dir_recursive(string $dir): bool {
    if (!is_dir($dir)) return true;
    $items = @scandir($dir);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            if (!compression_remove_dir_recursive($path)) return false;
        } elseif (!@unlink($path)) {
            return false;
        }
    }
    return @rmdir($dir);
}

function apply_compression_candidates_pdo(PDO $pdo, int $gallery_id): array {
    global $root;
    if ($gallery_id < 1) throw new RuntimeException('gallery_id 无效');

    $lock_path = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'apply_compress_' . $gallery_id . '.lock';
    $lock = @fopen($lock_path, 'c+');
    if (!$lock) throw new RuntimeException('无法创建应用锁文件');
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        throw new RuntimeException('该画廊正在应用压缩，请稍后刷新');
    }

    $changes = [];
    $work_dir = '';
    $rollback_dir = '';

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT id,source,source_id,local_path,compression_status,compression_info
               FROM galleries WHERE id=?"
        );
        $stmt->execute([$gallery_id]);
        $gallery = $stmt->fetch();
        if (!$gallery) throw new RuntimeException('找不到画廊 id=' . $gallery_id);
        if ((string)($gallery['compression_status'] ?? '') !== 'user_review_required') {
            throw new RuntimeException('当前状态不允许应用: ' . (string)($gallery['compression_status'] ?? ''));
        }

        $info = @json_decode((string)($gallery['compression_info'] ?? ''), true);
        if (!is_array($info)) throw new RuntimeException('压缩信息缺失或格式错误');
        $pages = $info['pages'] ?? [];
        if (!is_array($pages)) throw new RuntimeException('压缩页清单格式错误');

        $download_base = normalize_path(resolve_download_path());
        $download_real = realpath($download_base);
        if ($download_real === false || !is_dir($download_real)) throw new RuntimeException('下载根目录不存在');
        $download_real = normalize_path($download_real);

        $local_raw = trim((string)($gallery['local_path'] ?? ''));
        $is_absolute = preg_match('/^[A-Za-z]:[\\\\\/]/', $local_raw)
            || substr($local_raw, 0, 1) === '/'
            || substr($local_raw, 0, 2) === '\\\\';
        $gallery_path = $local_raw === ''
            ? ''
            : normalize_path($is_absolute ? $local_raw : $root . DIRECTORY_SEPARATOR . $local_raw);
        if ($gallery_path === '' || !is_dir($gallery_path) || !is_path_within($gallery_path, $download_real)) {
            $source_dir = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($gallery['source'] ?? ''));
            $source_id_dir = str_replace('/', '_', (string)($gallery['source_id'] ?? ''));
            $gallery_path = normalize_path(
                $download_real . DIRECTORY_SEPARATOR . $source_dir . DIRECTORY_SEPARATOR . $source_id_dir
            );
        }
        $gallery_real = realpath($gallery_path);
        if (
            $gallery_real === false
            || !is_dir($gallery_real)
            || !is_path_within($gallery_real, $download_real)
            || strcasecmp(rtrim(normalize_path($gallery_real), DIRECTORY_SEPARATOR), rtrim($download_real, DIRECTORY_SEPARATOR)) === 0
        ) {
            throw new RuntimeException('图库目录不存在或不在下载根目录内');
        }
        $gallery_path = normalize_path($gallery_real);

        $work_dir = resolve_compress_info_work_dir($info['work_dir'] ?? '');
        $compress_root = realpath(resolve_compress_work_path());
        $work_real = $work_dir !== '' ? realpath($work_dir) : false;
        $compress_root_normalized = $compress_root === false ? '' : rtrim(normalize_path($compress_root), DIRECTORY_SEPARATOR);
        $work_normalized = $work_real === false ? '' : normalize_path($work_real);
        if (
            $compress_root === false
            || $work_real === false
            || !is_dir($work_real)
            || !is_path_within($work_real, $compress_root)
            || strcasecmp(dirname($work_normalized), $compress_root_normalized) !== 0
            || basename($work_normalized) !== (string)$gallery_id
        ) {
            throw new RuntimeException('压缩候选目录不存在或越界，请重新压缩');
        }
        $work_dir = normalize_path($work_real);

        $operations = [];
        $seen_stems = [];
        foreach ($pages as $page) {
            if (empty($page['used_webp'])) continue;
            $name = (string)($page['name'] ?? '');
            if ($name === '' || basename($name) !== $name) throw new RuntimeException('原图文件名无效');
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $stem = pathinfo($name, PATHINFO_FILENAME);
            if (!ctype_digit($stem) || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                throw new RuntimeException('不允许应用非数字页文件: ' . $name);
            }
            if (isset($seen_stems[$stem])) throw new RuntimeException('压缩页清单存在重复页: ' . $stem);
            $seen_stems[$stem] = true;

            $original = $gallery_path . DIRECTORY_SEPARATOR . $name;
            $original_real = realpath($original);
            if (
                $original_real === false
                || !is_file($original_real)
                || !is_path_within($original_real, $gallery_path)
            ) {
                throw new RuntimeException('原图不存在: ' . $name);
            }
            $original = normalize_path($original_real);

            $candidate_name = $stem . '.webp';
            $candidate = $work_dir . DIRECTORY_SEPARATOR . $candidate_name;
            $candidate_real = realpath($candidate);
            if (
                $candidate_real === false
                || !is_file($candidate_real)
                || !is_path_within($candidate_real, $work_dir)
            ) {
                throw new RuntimeException('候选图不存在: ' . $candidate_name);
            }
            $candidate = normalize_path($candidate_real);

            $original_size = (int)@filesize($original);
            $candidate_size = (int)@filesize($candidate);
            if ($original_size < 1 || $candidate_size < 1) throw new RuntimeException('无法读取图片体积: ' . $name);
            if (isset($page['orig_bytes']) && (int)$page['orig_bytes'] !== $original_size) {
                throw new RuntimeException('原图已在压缩后发生变化，请重新压缩: ' . $name);
            }
            if (isset($page['webp_bytes']) && (int)$page['webp_bytes'] !== $candidate_size) {
                throw new RuntimeException('候选图体积与审核记录不一致: ' . $candidate_name);
            }
            if ($candidate_size >= $original_size) throw new RuntimeException('候选图未变小，拒绝替换: ' . $name);

            $target = $gallery_path . DIRECTORY_SEPARATOR . $candidate_name;
            if (strcasecmp($target, $original) !== 0 && file_exists($target)) {
                throw new RuntimeException('目标文件已存在，拒绝覆盖: ' . $candidate_name);
            }
            $operations[] = [
                'name' => $name,
                'original' => $original,
                'candidate' => $candidate,
                'target' => $target,
                'original_size' => $original_size,
                'candidate_size' => $candidate_size,
            ];
        }
        if (empty($operations)) throw new RuntimeException('没有可应用的变小候选图');

        $rollback_dir = $work_dir . DIRECTORY_SEPARATOR . '.apply_rollback_' . bin2hex(random_bytes(5));
        if (!@mkdir($rollback_dir, 0755)) throw new RuntimeException('无法创建事务回滚暂存目录');

        foreach ($operations as $op) {
            $tmp = $gallery_path . DIRECTORY_SEPARATOR
                . '.ehlib-apply-' . $gallery_id . '-' . bin2hex(random_bytes(5)) . '.tmp';
            if (!@copy($op['candidate'], $tmp)) {
                throw new RuntimeException('无法复制候选图: ' . basename($op['candidate']));
            }
            if (
                (int)@filesize($tmp) !== $op['candidate_size']
                || @hash_file('sha256', $tmp) !== @hash_file('sha256', $op['candidate'])
            ) {
                @unlink($tmp);
                throw new RuntimeException('候选图复制校验失败: ' . basename($op['candidate']));
            }
            $change = $op;
            $change['tmp'] = $tmp;
            $change['rollback'] = $rollback_dir . DIRECTORY_SEPARATOR . $op['name'];
            $change['original_moved'] = false;
            $change['applied'] = false;
            $changes[] = $change;
        }

        foreach ($changes as $idx => $change) {
            if (!@rename($change['original'], $change['rollback'])) {
                throw new RuntimeException('无法暂存原图: ' . $change['name']);
            }
            $changes[$idx]['original_moved'] = true;
            if (!@rename($change['tmp'], $change['target'])) {
                throw new RuntimeException('无法写入压缩图: ' . basename($change['target']));
            }
            $changes[$idx]['applied'] = true;
        }

        $applied_at = gmdate('c');
        $saved_bytes = 0;
        foreach ($operations as $op) $saved_bytes += $op['original_size'] - $op['candidate_size'];
        $info['applied_at'] = $applied_at;
        $info['applied_pages_count'] = count($operations);
        $info['applied_saved_bytes'] = $saved_bytes;
        $info['work_dir'] = '';
        unset($info['apply_backup_dir'], $info['candidate_archive_dir']);

        $new_size = 0;
        foreach (@scandir($gallery_path) ?: [] as $item) {
            $path = $gallery_path . DIRECTORY_SEPARATOR . $item;
            if (!is_file($path)) continue;
            $image_ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (in_array($image_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $new_size += (int)@filesize($path);
            }
        }

        $update = $pdo->prepare(
            "UPDATE galleries
                SET compression_status='applied', compression_info=?, file_size=?, updated_at=?
              WHERE id=? AND compression_status='user_review_required'"
        );
        $update->execute([
            json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $new_size,
            $applied_at,
            $gallery_id,
        ]);
        if ($update->rowCount() !== 1) throw new RuntimeException('状态已变化，取消应用');
        $pdo->commit();

        $cleanup_ok = compression_remove_dir_recursive($work_dir);
        $result = [
            'new_status' => 'applied',
            'applied_pages_count' => count($operations),
            'saved_bytes' => $saved_bytes,
            'file_size' => $new_size,
        ];
        if (!$cleanup_ok) $result['cleanup_warning'] = '替换已完成，但压缩临时目录未能完全删除';
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable $_ignored) {}
        }
        $rollback_errors = [];
        for ($idx = count($changes) - 1; $idx >= 0; $idx--) {
            $change = $changes[$idx];
            if (!empty($change['applied']) && file_exists($change['target']) && !@unlink($change['target'])) {
                $rollback_errors[] = '移除新图失败: ' . basename($change['target']);
            }
            if (
                !empty($change['original_moved'])
                && is_file($change['rollback'])
                && !file_exists($change['original'])
                && !@rename($change['rollback'], $change['original'])
            ) {
                $rollback_errors[] = '原图恢复失败: ' . $change['name'];
            }
            if (!empty($change['tmp']) && is_file($change['tmp'])) @unlink($change['tmp']);
        }
        if ($rollback_dir !== '' && is_dir($rollback_dir)) @rmdir($rollback_dir);
        $message = $e->getMessage();
        if (!empty($rollback_errors)) $message .= '；回滚告警：' . implode('，', $rollback_errors);
        throw new RuntimeException($message, 0, $e);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}
