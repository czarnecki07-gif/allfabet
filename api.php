<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

$DATA_DIR = __DIR__ . '/data';
$UPLOAD_DIR = __DIR__ . '/uploads';
$PROJECTS_FILE = $DATA_DIR . '/projects.json';
$CONFIG_FILE = $DATA_DIR . '/config.json';

if (!is_dir($DATA_DIR)) @mkdir($DATA_DIR, 0755, true);
if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0755, true);
if (!file_exists($PROJECTS_FILE)) file_put_contents($PROJECTS_FILE, json_encode(['projects'=>[]]));
if (!file_exists($CONFIG_FILE)) {
    file_put_contents($CONFIG_FILE, json_encode([
        'password_hash' => password_hash('allfabet123', PASSWORD_DEFAULT),
        'contact' => [
            'pl' => "email@allfabet.pl\n+48 123 456 789\nWarszawa, Polska",
            'en' => "email@allfabet.pl\n+48 123 456 789\nWarsaw, Poland"
        ]
    ], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}
$htaccess = $UPLOAD_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "php_flag engine off\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n  Require all denied\n</FilesMatch>\n");
}

function out($d, $c=200) { http_response_code($c); echo json_encode($d); exit; }
function readConfig() { global $CONFIG_FILE; return json_decode(file_get_contents($CONFIG_FILE), true); }
function writeConfig($c) { global $CONFIG_FILE; file_put_contents($CONFIG_FILE, json_encode($c, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); }
function readProjects() { global $PROJECTS_FILE; return json_decode(file_get_contents($PROJECTS_FILE), true); }
function writeProjects($p) { global $PROJECTS_FILE; file_put_contents($PROJECTS_FILE, json_encode($p, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); }
function requireAdmin() { if (empty($_SESSION['admin'])) out(['error' => 'Brak autoryzacji'], 401); }

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'get_all':
        $cfg = readConfig();
        out([
            'projects' => readProjects()['projects'] ?? [],
            'contact' => $cfg['contact'] ?? []
        ]);
        break;

    case 'check_session':
        out(['logged_in' => !empty($_SESSION['admin'])]);
        break;

    case 'login':
        $pass = $_POST['password'] ?? '';
        $cfg = readConfig();
        if (password_verify($pass, $cfg['password_hash'])) {
            $_SESSION['admin'] = true;
            out(['ok' => true]);
        }
        out(['error' => 'Nieprawidłowe hasło'], 401);
        break;

    case 'logout':
        session_destroy();
        out(['ok' => true]);
        break;

    case 'change_password':
        requireAdmin();
        $old = $_POST['old'] ?? ''; $new = $_POST['new'] ?? '';
        if (strlen($new) < 6) out(['error' => 'Hasło min. 6 znaków'], 400);
        $cfg = readConfig();
        if (!password_verify($old, $cfg['password_hash'])) out(['error' => 'Obecne hasło nieprawidłowe'], 400);
        $cfg['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
        writeConfig($cfg);
        out(['ok' => true]);
        break;

    case 'save_contact':
        requireAdmin();
        $lang = $_POST['lang'] ?? 'pl';
        $cfg = readConfig();
        if (!isset($cfg['contact']) || !is_array($cfg['contact'])) $cfg['contact'] = [];
        $cfg['contact'][$lang] = $_POST['contact'] ?? '';
        writeConfig($cfg);
        out(['ok' => true]);
        break;

    case 'save_project':
        requireAdmin();
        $translationsJson = $_POST['translations'] ?? '{}';
        $translations = json_decode($translationsJson, true);
        if (!is_array($translations) || empty($translations)) out(['error' => 'Brak tłumaczeń'], 400);
        
        // Walidacja
        $valid = false;
        foreach ($translations as $t) {
            if (!empty($t['title']) && !empty($t['desc'])) { $valid = true; break; }
        }
        if (!$valid) out(['error' => 'Tytuł i opis wymagane w co najmniej jednym języku'], 400);
        
        // Sanityzacja
        $clean = [];
        foreach ($translations as $lang => $t) {
            if (!preg_match('/^[a-z]{2}$/', $lang)) continue;
            $clean[$lang] = [
                'title' => trim($t['title'] ?? ''),
                'desc' => trim($t['desc'] ?? '')
            ];
        }
        
        $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
        $image = $_POST['existing_image'] ?? '';

        // Upload
        if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $mime = mime_content_type($_FILES['image']['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) out(['error' => 'Dozwolone: JPG, PNG, GIF, WEBP'], 400);
            if ($_FILES['image']['size'] > 5 * 1024 * 1024) out(['error' => 'Plik > 5MB'], 400);

            // Usuń stary
            $data = readProjects();
            foreach ($data['projects'] as $p) {
                if ($id !== null && $p['id'] === $id && !empty($p['image'])) {
                    $old = __DIR__ . '/' . $p['image'];
                    if (file_exists($old)) @unlink($old);
                }
            }
            $filename = 'uploads/' . uniqid('img_', true) . '.' . $allowed[$mime];
            move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $filename);
            $image = $filename;
        }

        $data = readProjects();
        $list = $data['projects'] ?? [];

        if ($id !== null) {
            foreach ($list as &$p) {
                if ($p['id'] === $id) {
                    $p['translations'] = $clean;
                    $p['image'] = $image;
                    break;
                }
            }
            unset($p);
        } else {
            $newId = 1;
            if (!empty($list)) $newId = max(array_column($list, 'id')) + 1;
            $list[] = ['id' => $newId, 'translations' => $clean, 'image' => $image];
        }
        $data['projects'] = array_values($list);
        writeProjects($data);
        out(['ok' => true]);
        break;

    case 'delete_project':
        requireAdmin();
        $id = (int)($_POST['id'] ?? 0);
        $data = readProjects();
        $list = $data['projects'] ?? [];
        foreach ($list as $p) {
            if ($p['id'] === $id && !empty($p['image'])) {
                $f = __DIR__ . '/' . $p['image'];
                if (file_exists($f)) @unlink($f);
            }
        }
        $list = array_values(array_filter($list, fn($p) => $p['id'] !== $id));
        $data['projects'] = $list;
        writeProjects($data);
        out(['ok' => true]);
        break;

    default:
        out(['error' => 'Nieznana akcja'], 400);
}