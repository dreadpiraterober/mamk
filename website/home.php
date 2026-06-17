<?php
session_start();

define('MASTER_KEY', 'midland_city_strong_raw_crypto_key_2026_opsec_clean!');
$donationsBTC = "bc1qqmtrgjlhrcc5pe6errz2yevucj3wy3wunu0rzg";
$dataFile = "posts.json";
$userFile = "users.txt";
$uploadDir = "uploads/";

function encrypt_data($value, $user) {
    $method = "AES-256-CBC";
    $dynamic_key = hash_hmac('sha256', $user, MASTER_KEY);
    $iv_length = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($iv_length);
    $encrypted = openssl_encrypt($value, $method, $dynamic_key, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decrypt_data($value, $user) {
    if (empty($value)) return 'N/A';
    $method = "AES-256-CBC";
    $dynamic_key = hash_hmac('sha256', $user, MASTER_KEY);
    $mix = base64_decode($value);
    if (strpos($mix, '::') === false) return $value;
    list($iv, $encrypted) = explode('::', $mix, 2);
    return openssl_decrypt($encrypted, $method, $dynamic_key, 0, $iv);
}

function strip_exif_raw($image_path) {
    $data = file_get_contents($image_path);
    if (!$data) return false;
    $patterns = [
        "/(\xFF\xE1).{2}(Exif)/s",
        "/(\xFF\xE1)(.*?)(http:\/\/ns\.adobe\.com)/s",
        "/(\xFF\xED).{2}(Photoshop)/s"
    ];
    $clean_data = preg_replace($patterns, '', $data);
    file_put_contents($image_path, $clean_data);
    return true;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.html");
    exit();
}

if (!isset($_SESSION['user']) || $_SESSION['user'] === '') {
    header("Location: index.html");
    exit();
}

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
    file_put_contents($uploadDir . ".htaccess", "Options -Indexes\nDeny from all\n<Files ~ \"^\.(h|jp|pn|ap|av)\">\nAllow from all\n</Files>");
}
if (!file_exists($dataFile)) file_put_contents($dataFile, json_encode([]));

$posts = json_decode(file_get_contents($dataFile), true);
if (!is_array($posts)) $posts = [];

if (isset($_GET['action']) && $_GET['action'] === 'delete_account') {
    $currentUser = $_SESSION['user'];

    if (file_exists($userFile)) {
        $lines = file($userFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $newLines = [];
        foreach ($lines as $line) {
            if (strpos($line, $currentUser . ':') !== 0) {
                $newLines[] = $line;
            }
        }
        file_put_contents($userFile, implode("\n", $newLines) . (empty($newLines) ? "" : "\n"));
    }

    foreach ($posts as $p) {
        if (isset($p['user']) && $p['user'] === $currentUser) {
            if (!empty($p['image'])) {
                $file_to_delete = $uploadDir . $p['image'];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
            }
        }
    }

    $posts = array_values(array_filter($posts, function($p) use ($currentUser) {
        return $p['user'] !== $currentUser;
    }));
    file_put_contents($dataFile, json_encode($posts, JSON_PRETTY_PRINT));

    session_destroy();
    header("Location: index.html");
    exit();
}

if (isset($_GET['delete'])) {
    $target_id = (int)$_GET['delete'];

    foreach ($posts as $p) {
        if ($p['id'] == $target_id && $p['user'] === $_SESSION['user']) {
            if (!empty($p['image'])) {
                $file_to_delete = $uploadDir . $p['image'];
                if (file_exists($file_to_delete)) {
                    unlink($file_to_delete);
                }
            }
            break;
        }
    }

    $posts = array_values(array_filter($posts, function($p) use ($target_id) {
        if ($p['id'] == $target_id) {
            return $p['user'] !== $_SESSION['user'];
        }
        return true;
    }));

    file_put_contents($dataFile, json_encode($posts, JSON_PRETTY_PRINT));
    header("Location: home.php");
    exit();
}

$all_categories = [
    "Cannabis", "Dissociatives", "Ecstasy", "Opioids", "Other", "Precursors",
    "Prescription", "Psychedelics", "Stimulants", "Apparel", "Art", "Biotic materials",
    "Books", "Collectibles", "Computer equipment", "Custom Orders", "Digital goods",
    "Drug paraphernalia", "Electronics", "Erotica", "Fireworks", "Food", "Forgeries",
    "Hardware", "Herbs & Supplements", "Home & Garden", "Jewelry", "Lab Supplies",
    "Lotteries & games", "Medical"
];

if (isset($_POST['action']) && $_POST['action'] == "edit") {
    $target_id = (int)$_POST['id'];
    $title = filter_var(trim($_POST['title']), FILTER_SANITIZE_SPECIAL_CHARS);
    $price = filter_var(trim($_POST['price']), FILTER_VALIDATE_FLOAT);
    $category = in_array($_POST['category'], $all_categories) ? $_POST['category'] : 'Other';
    $ships_from = filter_var(trim($_POST['ships_from']), FILTER_SANITIZE_SPECIAL_CHARS);
    $ships_to = filter_var(trim($_POST['ships_to']), FILTER_SANITIZE_SPECIAL_CHARS);
    $escrow = in_array($_POST['escrow'], ['Yes', 'No']) ? $_POST['escrow'] : 'Yes';
    $contact_type = in_array($_POST['contact_type'], ['Telegram', 'Session', 'XMPP / Jabber', 'Email', 'Other']) ? $_POST['contact_type'] : 'Telegram';

    if (!empty($title) && $price !== false && $price > 0 && !empty($ships_from) && !empty($ships_to) && !empty($_POST['contact_value'])) {
        foreach ($posts as &$p) {
            if ($p['id'] == $target_id && $p['user'] === $_SESSION['user']) {
                $p['title'] = $title;
                $p['price'] = number_format($price, 5, '.', '');
                $p['category'] = $category;
                $p['ships_from'] = $ships_from;
                $p['ships_to'] = $ships_to;
                $p['escrow'] = $escrow;
                $p['contact_type'] = $contact_type;
                $p['contact_value'] = encrypt_data(trim($_POST['contact_value']), $_SESSION['user']);
                if (!empty($_POST['btc'])) {
                    $p['btc'] = encrypt_data(trim($_POST['btc']), $_SESSION['user']);
                }

                if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] == 0) {
                    $tmp_name = $_FILES['image']['tmp_name'];
                    $original_name = basename($_FILES['image']['name']);
                    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                    if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                        if (!empty($p['image']) && file_exists($uploadDir . $p['image'])) {
                            unlink($uploadDir . $p['image']);
                        }
                        $img = time() . "_" . bin2hex(random_bytes(16)) . "." . $ext;
                        if (move_uploaded_file($tmp_name, $uploadDir . $img)) {
                            strip_exif_raw($uploadDir . $img);
                            $p['image'] = $img;
                        }
                    }
                }
                break;
            }
        }
        file_put_contents($dataFile, json_encode(array_values($posts), JSON_PRETTY_PRINT));
        header("Location: home.php");
        exit();
    }
}

$search = $_GET['search'] ?? '';
$catFilter = $_GET['cat'] ?? '';

function ok($p, $s, $c) {
    if ($c && $c != 'ALL') {
        $drug_subs = ["Cannabis", "Dissociatives", "Ecstasy", "Opioids", "Other", "Precursors", "Prescription", "Psychedelics", "Stimulants"];
        if ($c === 'Drugs') {
            if (!in_array($p['category'], $drug_subs)) return false;
        } else {
            if ($p['category'] != $c) return false;
        }
    }
    if ($s && stripos($p['title'], $s) === false && stripos($p['category'], $s) === false) return false;
    return true;
}

$cat_counts = [];
foreach ($posts as $p) {
    if (isset($p['category'])) {
        $cat_counts[$p['category']] = ($cat_counts[$p['category']] ?? 0) + 1;
    }
}
$drug_subs = ["Cannabis", "Dissociatives", "Ecstasy", "Opioids", "Other", "Precursors", "Prescription", "Psychedelics", "Stimulants"];
$drugs_total = 0;
foreach ($drug_subs as $sub) {
    $drugs_total += ($cat_counts[$sub] ?? 0);
}

$upload_error = "";
if (isset($_POST['action']) && $_POST['action'] == "add") {

    $current_time = time();
    if (isset($_SESSION['last_post_time']) && ($current_time - $_SESSION['last_post_time']) < 15) {
        $upload_error = "1";
    }

    $title = filter_var(trim($_POST['title']), FILTER_SANITIZE_SPECIAL_CHARS);
    $price = filter_var(trim($_POST['price']), FILTER_VALIDATE_FLOAT);
    $category = in_array($_POST['category'], $all_categories) ? $_POST['category'] : 'Other';
    $ships_from = filter_var(trim($_POST['ships_from']), FILTER_SANITIZE_SPECIAL_CHARS);
    $ships_to = filter_var(trim($_POST['ships_to']), FILTER_SANITIZE_SPECIAL_CHARS);
    $escrow = in_array($_POST['escrow'], ['Yes', 'No']) ? $_POST['escrow'] : 'Yes';
    $contact_type = in_array($_POST['contact_type'], ['Telegram', 'Session', 'XMPP / Jabber', 'Email', 'Other']) ? $_POST['contact_type'] : 'Telegram';

    if (empty($title) || empty($ships_from) || empty($ships_to) || empty($_POST['contact_value'])) {
        $upload_error = "1";
    }

    $contact_value_encrypted = encrypt_data(trim($_POST['contact_value']), $_SESSION['user']);
    $btc_encrypted = encrypt_data(trim($_POST['btc']), $_SESSION['user']);

    if ($price === false || $price <= 0) {
        $upload_error = "1";
    }

    $img = "";
    if (empty($upload_error) && !empty($_FILES['image']['name']) && $_FILES['image']['error'] == 0) {
        $tmp_name = $_FILES['image']['tmp_name'];
        $original_name = basename($_FILES['image']['name']);
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_exts = ['png', 'jpg', 'jpeg'];

        if (in_array($ext, $allowed_exts)) {
            $img = time() . "_" . bin2hex(random_bytes(16)) . "." . $ext;
            $destination = $uploadDir . $img;

            if (move_uploaded_file($tmp_name, $destination)) {
                strip_exif_raw($destination);
            } else {
                $upload_error = "1";
            }
        } else {
            $upload_error = "1";
        }
    }

    if (empty($upload_error)) {
        $_SESSION['last_post_time'] = $current_time;

        $posts[] = [
            "id" => time(),
            "user" => $_SESSION['user'],
            "title" => $title,
            "price" => number_format($price, 5, '.', ''),
            "category" => $category,
            "ships_from" => $ships_from,
            "ships_to" => $ships_to,
            "escrow" => $escrow,
            "contact_type" => $contact_type,
            "contact_value" => $contact_value_encrypted,
            "btc" => $btc_encrypted,
            "image" => $img
        ];

        file_put_contents($dataFile, json_encode(array_values($posts), JSON_PRETTY_PRINT));
        header("Location: home.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MIDLAND CITY | anonymous market</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { background: #f0f2f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 13px; color: #333333; width: 100%; min-height: 100vh; padding: 10px; }
.container { width: 100%; max-width: 100%; background: #ffffff; padding: 20px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); border: 1px solid #e1e4e8; }

.header { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #eaedf1; padding-bottom: 20px; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
.logo-area { display: flex; align-items: flex-end; gap: 10px; text-decoration: none; }
.logo-text h1 { font-size: 32px; color: #1a1a1a; font-weight: 700; font-family: 'Georgia', serif; line-height: 1; }
.logo-text p { font-size: 13px; color: #5b8c1d; font-style: italic; margin-top: 5px; font-weight: 600; }
.search-box form { display: flex; align-items: center; gap: 8px; }
.search-box label { font-weight: bold; color: #555; font-size: 13px; }
.search-box input[type="text"] { border: 1px solid #cccccc; padding: 8px 12px; width: 250px; font-size: 13px; border-radius: 4px; background: #fafafa; }
.search-box input[type="text"]:focus { outline: none; border-color: #5b8c1d; background: #fff; }
.search-box button { background: #f5f6f7; border: 1px solid #ccd0d5; padding: 8px 16px; font-size: 13px; font-weight: bold; cursor: pointer; border-radius: 4px; color: #444; }

.main-layout { display: grid; grid-template-columns: 240px 1fr; gap: 20px; }
.sidebar { background: #fdfdfd; padding: 15px; border: 1px solid #e1e4e8; border-radius: 4px; height: max-content; }
.sidebar h3 { font-size: 13px; color: #1a1a1a; font-weight: bold; border-bottom: 1px solid #e1e4e8; padding-bottom: 8px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
.cat-list { list-style: none; }
.cat-list li { margin-bottom: 6px; font-size: 13px; }
.cat-list a { text-decoration: none; color: #2e6294; font-weight: 500; }
.cat-list a:hover { text-decoration: underline; color: #1b446c; }
.cat-list .count { color: #888; font-size: 11px; margin-left: 3px; }
.cat-sublist { list-style: none; padding-left: 15px; margin-top: 4px; margin-bottom: 6px; border-left: 1px solid #eee; }
.content { width: 100%; min-width: 0; }

.publish-card { background: #fafbfc; border: 1px solid #e1e4e8; padding: 20px; margin-bottom: 25px; border-radius: 4px; }
.publish-card h4 { color: #222; margin-bottom: 15px; font-size: 14px; border-bottom: 1px solid #e1e4e8; padding-bottom: 8px; font-weight: bold; }
.form-row { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.form-row input, .form-row select { flex: 1; min-width: 180px; padding: 8px 12px; border: 1px solid #ccd0d5; font-size: 13px; background: #fff; border-radius: 4px; }
.form-row input:focus, .form-row select:focus { outline: none; border-color: #5b8c1d; }
.file-row { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; flex-wrap: wrap; gap: 15px; }
.submit-btn { background: #5b8c1d; border: 1px solid #466c16; color: #fff; padding: 9px 20px; font-weight: bold; cursor: pointer; border-radius: 4px; font-size: 13px; }

.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; width: 100%; }
.sr-card { background: #ffffff; display: flex; flex-direction: column; border: 1px solid #dcdfe4; border-radius: 5px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: transform 0.15s, box-shadow 0.15s; position: relative; }
.sr-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.05); border-color: #c8cbd0; }

.card-link-wrapper { text-decoration: none; color: inherit; display: flex; flex-direction: column; height: 100%; width: 100%; }

.card-img { width: 100%; height: 180px; border-bottom: 1px solid #e8ebed; background: #f7f8fa; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.card-img img { width: 100%; height: 100%; object-fit: cover; }
.img-placeholder { color: #999; font-size: 11px; font-weight: bold; text-transform: uppercase; }

.card-body { padding: 15px; display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between; }
.card-title { font-size: 15px; color: #2e6294; line-height: 1.4; display: block; margin-bottom: 10px; font-weight: 600; }
.price-tag { background: #fff5f5; border: 1px solid #ffe3e3; color: #c92a2a; font-size: 16px; font-weight: 700; padding: 6px 10px; border-radius: 4px; text-align: center; margin-bottom: 12px; }

.meta-info { color: #555555; font-size: 11.5px; line-height: 1.6; border-top: 1px dashed #e1e4e8; padding-top: 10px; }
.meta-row { display: flex; justify-content: space-between; margin-bottom: 4px; }
.meta-row span { color: #777; }
.meta-row strong { color: #1a1a1a; font-weight: 600; }

.contact-container { margin-top: 8px; background: #f8f9fa; border: 1px solid #e9ecef; padding: 6px; border-radius: 4px; text-align: center; font-size: 11px; }
.contact-label { font-weight: bold; color: #666; text-transform: uppercase; font-size: 9.5px; margin-bottom: 2px; display: block; }
.contact-value { color: #b21f1f; font-weight: bold; word-break: break-all; }

.actions-panel { margin-top: 12px; border-top: 1px solid #f0f2f5; padding-top: 10px; display: flex; justify-content: space-between; align-items: center; position: relative; z-index: 5; }
.btn-del { color: #dc3545; text-decoration: none; font-weight: bold; font-size: 11px; }
.btn-edit-toggle { color: #2e6294; text-decoration: none; font-weight: bold; font-size: 11px; cursor: pointer; }

.edit-overlay-form { display: none; background: #ffffff; padding: 15px; border-top: 1px solid #dcdfe4; }
.edit-overlay-form input, .edit-overlay-form select { width: 100%; padding: 6px 10px; margin-bottom: 8px; border: 1px solid #ccd0d5; border-radius: 4px; font-size: 12px; }
.edit-actions { display: flex; gap: 8px; margin-top: 5px; }
.edit-actions button { flex: 1; padding: 6px; font-size: 12px; font-weight: bold; border-radius: 4px; cursor: pointer; }
.btn-save { background: #5b8c1d; color: #fff; border: 1px solid #466c16; }
.btn-cancel { background: #f5f6f7; color: #333; border: 1px solid #ccd0d5; }

.empty-state { grid-column: 1 / -1; padding: 50px; text-align: center; color: #777; border: 1px dashed #ccd0d5; background: #fafafa; font-size: 14px; border-radius: 4px; }
.user-panel { border-top: 1px solid #ccd0d5; margin-top: 40px; padding-top: 15px; font-size: 12px; color: #555; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px; align-items: center; }
.user-panel code { background: #f1f3f5; padding: 3px 6px; border: 1px solid #dee2e6; border-radius: 3px; color: #333; font-weight: 600; }
.user-panel a { color: #2e6294; text-decoration: none; font-weight: bold; }
.remove-acc-btn { color: #ffffff !important; background: #dc3545; padding: 5px 12px; border-radius: 4px; border: 1px solid #bd2130; }

@media (max-width: 1024px) {
    .main-layout { grid-template-columns: 1fr; }
    .sidebar { width: 100%; }
}
</style>
<script>
function toggleEditForm(id, event) {
    if(event) event.preventDefault();
    var form = document.getElementById('edit-form-' + id);
    if(form.style.display === 'block') {
        form.style.display = 'none';
    } else {
        form.style.display = 'block';
    }
}
</script>
</head>
<body>

<div class="container">
    <div class="header">
        <a href="home.php" class="logo-area">
            <div class="logo-text">
                <h1>Midland City</h1>
                <p>anonymous market</p>
            </div>
        </a>
        <div class="search-box">
            <form method="GET">
                <label>Search </label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search market...">
                <?php if($catFilter): ?>
                    <input type="hidden" name="cat" value="<?php echo htmlspecialchars($catFilter); ?>">
                <?php endif; ?>
                <button type="submit">Go</button>
            </form>
        </div>
    </div>

    <div class="main-layout">
        <div class="sidebar">
            <h3>Categories</h3>
            <ul class="cat-list">
                <li><a href="?cat=ALL">All Categories</a> <span class="count">(<?php echo count($posts); ?>)</span></li>
                <li><a href="?cat=Drugs">Drugs</a> <span class="count">(<?php echo $drugs_total; ?>)</span>
                    <ul class="cat-sublist">
                        <?php foreach(["Cannabis", "Dissociatives", "Ecstasy", "Opioids", "Other", "Precursors", "Prescription", "Psychedelics", "Stimulants"] as $sub): ?>
                            <li><a href="?cat=<?php echo $sub; ?>"><?php echo $sub; ?></a> <span class="count">(<?php echo $cat_counts[$sub] ?? 0; ?>)</span></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <?php
                $other_cats = ["Apparel", "Art", "Biotic materials", "Books", "Collectibles", "Computer equipment", "Custom Orders", "Digital goods", "Drug paraphernalia", "Electronics", "Erotica", "Fireworks", "Food", "Forgeries", "Hardware", "Herbs & Supplements", "Home & Garden", "Jewelry", "Lab Supplies", "Lotteries & games", "Medical"];
                foreach($other_cats as $oc): ?>
                    <li><a href="?cat=<?php echo urlencode($oc); ?>"><?php echo $oc; ?></a> <span class="count">(<?php echo $cat_counts[$oc] ?? 0; ?>)</span></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="content">
            <div class="publish-card">
                <h4>Publish New Market Listing</h4>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <input type="text" name="title" placeholder="Item Title" required>
                        <input type="text" name="price" placeholder="Price in BTC" required>
                        <select name="category" required>
                            <option value="" disabled selected>-- Select Category --</option>
                            <?php foreach($all_categories as $category_item): ?>
                                <option value="<?php echo $category_item; ?>"><?php echo $category_item; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <input type="text" name="ships_from" placeholder="Ships From" required>
                        <input type="text" name="ships_to" placeholder="Ships To" required>
                        <select name="escrow" required>
                            <option value="" disabled selected>-- Escrow --</option>
                            <option value="Yes">Yes (Escrow Enabled)</option>
                            <option value="No">No (Finalize Early)</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <select name="contact_type" required>
                            <option>Telegram</option>
                            <option>Session</option>
                            <option>XMPP / Jabber</option>
                            <option>Email</option>
                            <option>Other</option>
                        </select>
                        <input type="text" name="contact_value" placeholder="Contact Details" required>
                        <input type="text" name="btc" placeholder="Custom Payout Wallet Address" required>
                    </div>
                    <div class="file-row">
                        <input type="file" name="image" accept="image/png,image/jpeg,image/jpg">
                        <button type="submit" class="submit-btn">Publish Listing</button>
                    </div>
                </form>
            </div>

            <div class="product-grid">
                <?php
                $displayed = 0;
                foreach($posts as $p):
                    if(!ok($p,$search,$catFilter)) continue;
                    $displayed++;
                    $real_contact = decrypt_data($p['contact_value'], $p['user']);
                ?>
                <div class="sr-card">
                    <a href="#" class="card-link-wrapper" onclick="event.preventDefault();">
                        <div class="card-img">
                            <?php if(!empty($p['image']) && file_exists($uploadDir.$p['image'])): ?>
                                <img src="uploads/<?php echo htmlspecialchars($p['image']); ?>" alt="product">
                            <?php else: ?>
                                <div class="img-placeholder">No Image Available</div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div>
                                <span class="card-title"><?php echo htmlspecialchars($p['title']); ?></span>
                                <div class="price-tag">฿<?php echo htmlspecialchars($p['price']); ?></div>
                                <div class="meta-info">
                                    <div class="meta-row"><span>Category:</span> <strong><?php echo htmlspecialchars($p['category']); ?></strong></div>
                                    <div class="meta-row"><span>Ships From:</span> <strong><?php echo htmlspecialchars($p['ships_from'] ?? 'N/A'); ?></strong></div>
                                    <div class="meta-row"><span>Ships To:</span> <strong><?php echo htmlspecialchars($p['ships_to'] ?? 'N/A'); ?></strong></div>
                                    <div class="meta-row"><span>Escrow:</span> <strong><?php echo htmlspecialchars($p['escrow'] ?? 'Yes'); ?></strong></div>
                                    <div class="meta-row"><span>Seller:</span> <strong><?php echo htmlspecialchars($p['user']); ?></strong></div>
                                </div>
                            </div>
                            <div class="contact-container">
                                <span class="contact-label">Contact [<?php echo htmlspecialchars($p['contact_type'] ?? 'Telegram'); ?>]</span>
                                <span class="contact-value"><?php echo htmlspecialchars($real_contact); ?></span>
                            </div>
                        </div>
                    </a>

                    <?php if($p['user'] === $_SESSION['user']): ?>
                    <div class="actions-panel">
                        <span class="btn-edit-toggle" onclick="toggleEditForm(<?php echo $p['id']; ?>, event)">[Edit]</span>
                        <a href="?delete=<?php echo $p['id']; ?>" class="btn-del" onclick="return confirm('Wipe listing?');">[Delete]</a>
                    </div>

                    <div class="edit-overlay-form" id="edit-form-<?php echo $p['id']; ?>">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                            
                            <input type="text" name="title" value="<?php echo htmlspecialchars($p['title']); ?>" placeholder="Title" required>
                            <input type="text" name="price" value="<?php echo htmlspecialchars($p['price']); ?>" placeholder="Price" required>
                            
                            <select name="category" required>
                                <?php foreach($all_categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>" <?php echo ($p['category'] === $cat) ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="ships_from" value="<?php echo htmlspecialchars($p['ships_from'] ?? ''); ?>" placeholder="Ships From" required>
                            <input type="text" name="ships_to" value="<?php echo htmlspecialchars($p['ships_to'] ?? ''); ?>" placeholder="Ships To" required>
                            
                            <select name="escrow" required>
                                <option value="Yes" <?php echo (($p['escrow'] ?? 'Yes') === 'Yes') ? 'selected' : ''; ?>>Escrow: Yes</option>
                                <option value="No" <?php echo (($p['escrow'] ?? 'Yes') === 'No') ? 'selected' : ''; ?>>Escrow: No</option>
                            </select>

                            <select name="contact_type" required>
                                <?php foreach(['Telegram', 'Session', 'XMPP / Jabber', 'Email', 'Other'] as $ct): ?>
                                    <option value="<?php echo $ct; ?>" <?php echo (($p['contact_type'] ?? 'Telegram') === $ct) ? 'selected' : ''; ?>><?php echo $ct; ?></option>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" name="contact_value" value="<?php echo htmlspecialchars($real_contact); ?>" placeholder="Contact Details" required>
                            <input type="text" name="btc" placeholder="Update Payout Wallet (Leave blank to keep same)">
                            <input type="file" name="image" accept="image/png,image/jpeg,image/jpg">
                            
                            <div class="edit-actions">
                                <button type="submit" class="btn-save">Save</button>
                                <button type="button" class="btn-cancel" onclick="toggleEditForm(<?php echo $p['id']; ?>, event)">Cancel</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if($displayed === 0): ?>
                    <div class="empty-state">No listings found matching your criteria.</div>
                <?php endif; ?>
            </div>

            <div class="user-panel">
                <div>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['user']); ?></strong></div>
                <div>Donations: <code><?php echo $donationsBTC; ?></code></div>
                <div>
                    <a href="?action=logout">[Logout]</a>
                    &nbsp;&nbsp;&nbsp;&nbsp;
                    <a href="?action=delete_account" class="remove-acc-btn">[Delete Account]</a>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
