<?php
session_start([
    'cookie_lifetime' => 0,
    'cookie_secure'   => isset($_SERVER['HTTPS']), 
    'cookie_httponly' => true,                     
    'cookie_samesite' => 'Strict'                   
]);

$authFile  = "hello.txt";
$postsFile = "posts.json";
$usersFile = "users.txt";

function escape(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header("Location: admin.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!file_exists($authFile)) {
        die("Fatal Error: Authentication database missing.");
    }

    $lines = file($authFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $authenticated = false;

    foreach ($lines as $line) {
        $parts = explode(":", $line, 2);
        if (count($parts) < 2) continue;
        list($fileUser, $fileHash) = $parts;

        if ($username === trim($fileUser) && password_verify($password, trim($fileHash))) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user']      = $username;
            $authenticated               = true;
            break;
        }
    }

    if ($authenticated) {
        header("Location: admin.php");
        exit();
    } else {
        $loginError = "Access Denied.";
    }
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Terminal Login</title>
        <style>
            body { background: #050505; color: #33ff33; font-family: "Courier New", monospace; padding: 10% 10px; text-align: center; }
            .login-box { border: 2px solid #33ff33; display: inline-block; padding: 30px; background: #000; box-shadow: 0 0 15px #005500; max-width: 100%; width: 320px; box-sizing: border-box; }
            input { background: #000; border: 1px solid #33ff33; color: #33ff33; padding: 8px; margin: 10px 0; width: 100%; box-sizing: border-box; font-family: monospace; text-align: center;}
            button { background: #33ff33; color: #000; border: none; padding: 10px 20px; font-weight: bold; cursor: pointer; width: 100%; font-family: monospace; }
            .error { color: #ff3333; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h3>[ AUTH REQUIRED ]</h3>
            <?php if (isset($loginError)) echo "<p class='error'>".escape($loginError)."</p>"; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="OPERATOR" required autocomplete="off">
                <input type="password" name="password" placeholder="PASSKEY" required>
                <button name="login" type="submit">INITIALIZE</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

if (!file_exists($usersFile)) file_put_contents($usersFile, "");
if (!file_exists($postsFile)) file_put_contents($postsFile, json_encode([]));

$users = file($usersFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$posts = json_decode(file_get_contents($postsFile), true) ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Alert: Invalid CSRF Token.");
    }

    if (isset($_POST['action_ban'])) {
        $banUser = trim($_POST['ban_target'] ?? '');
        $newUsers = [];
        
        foreach ($users as $u) {
            $parts = explode(":", $u);
            if (trim($parts[0]) !== $banUser) {
                $newUsers[] = $u;
            }
        }
        file_put_contents($usersFile, implode("\n", $newUsers) . (empty($newUsers) ? "" : "\n"), LOCK_EX);

        $filteredPosts = [];
        foreach ($posts as $p) {
            if (($p['user'] ?? '') === $banUser) {
                if (!empty($p['image'])) {
                    $imgFile = __DIR__ . "/uploads/" . basename($p['image']);
                    if (file_exists($imgFile) && is_file($imgFile)) unlink($imgFile);
                }
            } else {
                $filteredPosts[] = $p;
            }
        }
        file_put_contents($postsFile, json_encode(array_values($filteredPosts), JSON_PRETTY_PRINT), LOCK_EX);
        header("Location: admin.php");
        exit();
    }

    if (isset($_POST['action_delete_post'])) {
        $targetPostId = $_POST['post_id'] ?? '';
        $filteredPosts = [];

        foreach ($posts as $p) {
            if ((string)($p['id'] ?? '') === (string)$targetPostId) {
                if (!empty($p['image'])) {
                    $imgFile = __DIR__ . "/uploads/" . basename($p['image']);
                    if (file_exists($imgFile) && is_file($imgFile)) unlink($imgFile);
                }
            } else {
                $filteredPosts[] = $p;
            }
        }
        file_put_contents($postsFile, json_encode(array_values($filteredPosts), JSON_PRETTY_PRINT), LOCK_EX);
        header("Location: admin.php");
        exit();
    }
}

$metrics = [];
foreach ($posts as $p) {
    if (isset($p['user'])) {
        $u = $p['user'];
        $metrics[$u] = ($metrics[$u] ?? 0) + 1;
    }
}
arsort($metrics);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ROOT@ADMIN_TERMINAL:~#</title>
    <style>
        :root {
            --matrix-green: #33ff33;
            --dark-green: #003300;
            --alert-red: #ff3333;
            --bg-dark: #050505;
            --panel-bg: #000000;
        }

        body { 
            background-color: var(--bg-dark); 
            color: var(--matrix-green); 
            font-family: "Courier New", Courier, monospace; 
            margin: 0; 
            padding: 10px;
            font-size: 14px;
            line-height: 1.4;
        }

        .terminal-container { 
            max-width: 1200px; 
            margin: 0 auto; 
            border: 2px solid var(--matrix-green); 
            background: var(--panel-bg); 
            padding: 15px; 
            box-shadow: 0 0 20px rgba(0, 51, 0, 0.8);
        }

        header { 
            border-bottom: 2px dashed var(--matrix-green); 
            padding-bottom: 10px; 
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .logo { font-size: 1.4em; font-weight: bold; letter-spacing: 2px; }
        .logout-btn { color: var(--alert-red); text-decoration: none; border: 1px solid var(--alert-red); padding: 3px 8px; font-weight: bold; }
        .logout-btn:hover { background: var(--alert-red); color: #000; }

        h2 { font-size: 1.2em; border-left: 4px solid var(--matrix-green); padding-left: 8px; margin-top: 30px; text-transform: uppercase; }

        .search-box {
            width: 100%;
            background: #000;
            border: 1px solid var(--matrix-green);
            color: var(--matrix-green);
            padding: 10px;
            font-family: monospace;
            font-size: 1.1em;
            margin-bottom: 20px;
            box-sizing: border-box;
        }
        .search-box:focus { outline: 1px solid #fff; }

        .table-responsive { 
            width: 100%; 
            overflow-x: auto; 
            border: 1px solid var(--dark-green);
            margin-bottom: 20px;
        }

        table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 700px; 
        }

        th, td { padding: 10px; text-align: left; border-bottom: 1px solid var(--dark-green); }
        th { background: var(--dark-green); color: var(--matrix-green); font-size: 0.9em; }
        tr:hover { background: #0a140a; }

        .btn-action { 
            background: #000; 
            color: var(--matrix-green); 
            border: 1px solid var(--matrix-green); 
            padding: 3px 8px; 
            font-family: monospace; 
            cursor: pointer; 
        }
        .btn-action:hover { background: var(--matrix-green); color: #000; }
        .btn-kill { color: var(--alert-red); border-color: var(--alert-red); }
        .btn-kill:hover { background: var(--alert-red); color: #000; }

        @media (max-width: 600px) {
            body { font-size: 12px; padding: 5px; }
            .terminal-container { padding: 8px; }
        }
    </style>
</head>
<body>

<div class="terminal-container">
    <header>
        <div class="logo">=== CORE_SYS_ADMIN TERMINAL v3.0 ===</div>
        <div>
            <span>OP: [<?php echo escape($_SESSION['admin_user']); ?>]</span> | 
            <a href="?action=logout" class="logout-btn">DISCONNECT</a>
        </div>
    </header>

    <input type="text" id="terminalSearch" class="search-box" placeholder="[ SEARCH_FILTER_QUERY_STRING ... ]" onkeyup="filterTerminalData()">

    <h2>[ ACTIVE INVENTORY POSTINGS ]</h2>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>POST ID</th>
                    <th>USER</th>
                    <th>ITEM TITLE</th>
                    <th>CATEGORY</th>
                    <th>PRICE</th>
                    <th>FULFILLMENT</th>
                    <th>IMAGE REF</th>
                    <th>COMMAND</th>
                </tr>
            </thead>
            <tbody id="postsTableBody">
                <?php if (empty($posts)): ?>
                    <tr class="searchable-row"><td colspan="8">No active system inventory records.</td></tr>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <tr class="searchable-row">
                            <td><code><?php echo escape($post['id'] ?? 'N/A'); ?></code></td>
                            <td><strong><?php echo escape($post['user'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo escape($post['title'] ?? 'N/A'); ?></td>
                            <td><?php echo escape($post['category'] ?? 'N/A'); ?></td>
                            <td><?php echo escape($post['price'] ?? '0.00'); ?></td>
                            <td><?php echo escape($post['ships_from'] ?? '-'); ?> -> <?php echo escape($post['ships_to'] ?? '-'); ?></td>
                            <td><small><?php echo escape($post['image'] ?? 'none'); ?></small></td>
                            <td>
                                <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this post permanently?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="post_id" value="<?php echo escape($post['id'] ?? ''); ?>">
                                    <button type="submit" name="action_delete_post" class="btn-action btn-kill">DEL_POST</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
        
        <div>
            <h2>[ USER BASE INDEX ]</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ACCOUNT HASH</th>
                            <th style="text-align: right;">EXECUTION</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php if (empty($users)): ?>
                            <tr class="searchable-row"><td colspan="2">No tracked profiles data rows.</td></tr>
                        <?php else: ?>
                            <?php foreach ($users as $u): 
                                $parts = explode(":", $u);
                                $name = trim($parts[0]);
                            ?>
                                <tr class="searchable-row">
                                    <td><strong><?php echo escape($name); ?></strong></td>
                                    <td style="text-align: right;">
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('BAN user & purge ALL matching data?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="ban_target" value="<?php echo escape($name); ?>">
                                            <button type="submit" name="action_ban" class="btn-action btn-kill">BAN_USER</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <h2>[ TRAFFIC METRICS ]</h2>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>PROFILED ENTITY</th>
                            <th style="text-align: right;">POSTS TOTAL</th>
                        </tr>
                    </thead>
                    <tbody id="metricsTableBody">
                        <?php if (empty($metrics)): ?>
                            <tr class="searchable-row"><td colspan="2">No analytical loops recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($metrics as $user => $count): ?>
                                <tr class="searchable-row">
                                    <td><?php echo escape($user); ?></td>
                                    <td style="text-align: right; color: #fff;">[<?php echo (int)$count; ?>]</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
function filterTerminalData() {
    var input = document.getElementById('terminalSearch');
    var filter = input.value.toUpperCase();
    var rows = document.getElementsByClassName('searchable-row');

    for (var i = 0; i < rows.length; i++) {
        var textContent = rows[i].textContent || rows[i].innerText;
        if (textContent.toUpperCase().indexOf(filter) > -1) {
            rows[i].style.display = "";
        } else {
            rows[i].style.display = "none";
        }
    }
}
</script>
</body>
</html>
