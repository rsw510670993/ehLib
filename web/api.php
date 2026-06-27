<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$root = realpath(__DIR__ . '/..');
$venv_python = $root . '/venv/Scripts/python.exe';
$python = is_file($venv_python) ? $venv_python : 'python';

function json_exit($data, $ok = true) {
    $data['ok'] = $ok;
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error_exit($msg) {
    json_exit(['error' => $msg], false);
}

function run_python($args, $timeout = 120) {
    global $root, $python;
    $cmd = escapeshellcmd($python) . ' -m ehlib';
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $root);

    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Failed to start process'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();
    $done = false;

    while (!$done) {
        if (time() - $start > $timeout) {
            proc_terminate($proc, 9);
            return ['ok' => false, 'error' => 'Command timed out (' . $timeout . 's)'];
        }

        $r = [$pipes[1], $pipes[2]];
        $w = null;
        $e = null;
        if (stream_select($r, $w, $e, 1) > 0) {
            foreach ($r as $pipe) {
                $data = fread($pipe, 4096);
                if ($data === false) continue;
                if ($pipe === $pipes[1]) $stdout .= $data;
                else $stderr .= $data;
            }
        }

        $status = proc_get_status($proc);
        if (!$status['running']) {
            $remaining1 = stream_get_contents($pipes[1]);
            $remaining2 = stream_get_contents($pipes[2]);
            $stdout .= $remaining1 === false ? '' : $remaining1;
            $stderr .= $remaining2 === false ? '' : $remaining2;
            $done = true;
            $exit_code = $status['exitcode'];
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return [
        'ok' => $exit_code === 0,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'exit_code' => $exit_code,
    ];
}

function run_python_background($args) {
    global $root, $python;
    $cmd = escapeshellcmd($python) . ' -m ehlib';
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $fullCmd = 'start "" /B ' . $cmd;
        $proc = popen($fullCmd, 'r');
        if (is_resource($proc)) {
            pclose($proc);
        }
    } else {
        $fullCmd = 'nohup ' . $cmd . ' > /dev/null 2>&1 &';
        exec($fullCmd);
    }
    return true;
}

function read_config() {
    global $root;
    $path = $root . '/config.yaml';
    if (!is_file($path)) return [];
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    $current_section = '';
    $current_sub = '';
    $stack = [&$config];
    
    foreach ($lines as $line) {
        if (preg_match('/^(\s*)([\w-]+):\s*(.*)$/', $line, $m)) {
            $indent = strlen($m[1]);
            $key = $m[2];
            $value = trim($m[3]);
            
            while (count($stack) - 1 > $indent / 2) {
                array_pop($stack);
            }
            
            $target = &$stack[count($stack) - 1];
            
            if ($value === '') {
                $target[$key] = [];
                $stack[] = &$target[$key];
            } else {
                $value = trim($value, '"\'');
                if ($value === 'true') $value = true;
                elseif ($value === 'false') $value = false;
                elseif (is_numeric($value)) $value = $value + 0;
                $target[$key] = $value;
            }
            unset($target);
        }
    }
    return $config;
}

function write_config($data) {
    global $root;
    $path = $root . '/config.yaml';
    
    function array_to_yaml($data, $indent = 0) {
        $out = '';
        $prefix = str_repeat('  ', $indent);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    $out .= $prefix . $key . ": {}\n";
                } else {
                    $out .= $prefix . $key . ":\n";
                    $out .= array_to_yaml($value, $indent + 1);
                }
            } elseif (is_bool($value)) {
                $out .= $prefix . $key . ': ' . ($value ? 'true' : 'false') . "\n";
            } elseif (is_numeric($value)) {
                $out .= $prefix . $key . ': ' . $value . "\n";
            } else {
                $out .= $prefix . $key . ': "' . str_replace('"', '\"', $value) . "\"\n";
            }
        }
        return $out;
    }
    
    $yaml = array_to_yaml($data);
    file_put_contents($path, $yaml, LOCK_EX);
    return true;
}

// --- Route actions ---
try {
    switch ($action) {
        case 'get_config':
            $config = read_config();
            json_exit(['config' => $config]);
            break;

        case 'save_config':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) error_exit('Invalid JSON body');
            write_config($data);
            json_exit(['message' => 'Configuration saved']);
            break;

        case 'get_galleries':
            $source = $_GET['source'] ?? '';
            $tag_name = $_GET['tag'] ?? '';
            $tags_raw = $_GET['tags'] ?? '';
            $tag_mode = $_GET['tag_mode'] ?? 'any';
            $artist = $_GET['artist'] ?? '';
            $language = $_GET['language'] ?? '';
            $limit = (int)($_GET['limit'] ?? 50);
            $args = ['list'];
            if ($source) { $args[] = '--source'; $args[] = $source; }
            if ($tag_name) { $args[] = '--tag'; $args[] = $tag_name; }
            if ($tags_raw) { $args[] = '--tags'; $args[] = $tags_raw; }
            if ($tag_mode !== 'any') { $args[] = '--tag-mode'; $args[] = $tag_mode; }
            if ($artist) { $args[] = '--artist'; $args[] = $artist; }
            if ($language) { $args[] = '--language'; $args[] = $language; }
            if ($limit !== 50) { $args[] = '--limit'; $args[] = (string)$limit; }
            $result = run_python($args);
            if (!$result['ok']) error_exit($result['stderr'] ?: 'Command failed');
            $lines = array_filter(explode("\n", $result['stdout']));
            $galleries = [];
            foreach ($lines as $line) {
                if (preg_match('/^\[(.+?)\/(.+?)\]\s+(.+?)\s+\((\d+)p\)\s+-\s+(.+)$/', $line, $m)) {
                    $galleries[] = [
                        'source' => $m[1],
                        'source_id' => $m[2],
                        'title' => $m[3],
                        'pages' => (int)$m[4],
                        'downloaded_at' => $m[5],
                    ];
                }
            }
            json_exit(['galleries' => $galleries]);
            break;

        case 'get_gallery_detail':
            $source = $_GET['source'] ?? '';
            $source_id = $_GET['source_id'] ?? '';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare('SELECT id, title, artist, group_name, language, category, total_pages, file_size, downloaded_at FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $gallery = $stmt->fetch();
                if (!$gallery) error_exit('Gallery not found');
                $stmt2 = $pdo->prepare(
                    'SELECT t.type, t.name FROM tags t
                     JOIN gallery_tags gt ON t.id = gt.tag_id
                     JOIN galleries g ON gt.gallery_id = g.id
                     WHERE g.source=? AND g.source_id=?
                     ORDER BY t.type, t.name'
                );
                $stmt2->execute([$source, $source_id]);
                $tags = $stmt2->fetchAll();
                $gallery['tags'] = $tags;
                json_exit(['gallery' => $gallery]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'download':
            $source = $_POST['source'] ?? '';
            $id = $_POST['id'] ?? '';
            $url = $_POST['url'] ?? '';
            $gid = $_POST['gid'] ?? '';
            $token = $_POST['token'] ?? '';

            if ($url) {
                $args = ['download', '--url', $url];
            } elseif ($id && $source) {
                $args = ['download', $source, '--id', $id];
            } elseif ($gid && $token) {
                $args = ['download', '--gid', $gid, '--token', $token];
            } else {
                error_exit('Provide --url, --id+source, or --gid+--token');
            }
            $result = run_python($args, 300);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'batch_download':
            $urls_raw = file_get_contents('php://input');
            $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $urls_raw)));
            $tmpfile = $root . '/data/batch_' . time() . '.txt';
            if (!is_dir($root . '/data')) mkdir($root . '/data', 0755, true);
            file_put_contents($tmpfile, implode("\n", $lines));
            $args = ['batch', '--file', $tmpfile];
            $result = run_python($args, 600);
            @unlink($tmpfile);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'retry':
            $args = ['retry'];
            $result = run_python($args, 600);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'search':
            $source = $_POST['source'] ?? 'nhentai';
            $query = $_POST['query'] ?? '';
            if (!$query) error_exit('Search query required');
            $args = ['search', $source, '--query', $query];
            $result = run_python($args, 60);
            if (!$result['ok']) error_exit($result['stderr'] ?: 'Search failed');
            $lines = array_filter(explode("\n", $result['stdout']));
            $results = [];
            foreach ($lines as $line) {
                if (preg_match('/^\[(.+?)\]\s+(.+)$/', $line, $m)) {
                    $results[] = [
                        'id' => $m[1],
                        'title' => $m[2],
                    ];
                }
            }
            json_exit(['results' => $results, 'raw' => $result['stdout']], true);
            break;

        case 'export':
            $output = $root . '/data/export_' . date('Ymd_His') . '.json';
            if (!is_dir($root . '/data')) mkdir($root . '/data', 0755, true);
            $args = ['export', '--output', $output];
            $result = run_python($args);
            if ($result['ok'] && is_file($output)) {
                $content = json_decode(file_get_contents($output), true);
                json_exit(['galleries' => $content, 'file' => basename($output)]);
            }
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
            ], $result['ok']);
            break;

        case 'get_stats':
            $config = read_config();
            $db_path = $root . '/data/ehlib.db';
            $db_exists = is_file($db_path);
            $count = 0;
            if ($db_exists) {
                try {
                    $pdo = new PDO('sqlite:' . $db_path);
                    $stmt = $pdo->query('SELECT COUNT(*) as cnt FROM galleries');
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $count = (int)$row['cnt'];
                } catch (Exception $e) {}
            }
            json_exit([
                'db_exists' => $db_exists,
                'gallery_count' => $count,
                'db_size' => $db_exists ? filesize($db_path) : 0,
                'config_file' => is_file($root . '/config.yaml'),
                'venv_exists' => is_dir($root . '/venv'),
                'download_path' => $config['download']['path'] ?? './downloads',
            ]);
            break;

        case 'launch_login_helper':
            $source = $_POST['source'] ?? $_GET['source'] ?? '';
            if (!$source) error_exit('Source required (nhentai or exhentai)');
            $args = ['login-helper', 'start', $source];
            run_python_background($args);
            json_exit(['message' => "Login helper started for {$source}"]);
            break;

        case 'check_login_status':
            $source = $_GET['source'] ?? '';
            if (!$source) error_exit('Source required');
            $status_file = $root . '/data/login_' . $source . '.json';
            if (!is_file($status_file)) {
                $config = read_config();
                $saved = $config['cookies'][$source] ?? [];
                $filled = [];
                foreach ($saved as $k => $v) {
                    $filled[$k] = !empty($v);
                }
                $all_filled = !in_array(false, $filled, true);
                json_exit([
                    'status' => $all_filled ? 'completed' : 'idle',
                    'message' => $all_filled ? 'Cookies already configured' : 'Login helper not started',
                    'cookies' => $saved,
                    'is_empty' => true,
                ]);
            }
            $data = json_decode(file_get_contents($status_file), true);
            if ($data['status'] === 'completed') {
                @unlink($status_file);
            }
            json_exit($data);
            break;

        case 'sync_cookies':
            $source = $_POST['source'] ?? $_GET['source'] ?? '';
            if (!$source) error_exit('Source required');
            $args = ['login-helper', 'sync', $source];
            $result = run_python($args, 30);
            $sync_result = json_decode($result['stdout'], true);
            if (!$sync_result) {
                json_exit(['error' => $result['stderr'] ?: 'Sync failed', 'output' => $result['stdout']], false);
            }
            json_exit($sync_result, $sync_result['status'] === 'completed');
            break;

        default:
            error_exit('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_exit($e->getMessage());
}
