<?php
session_set_cookie_params([
    'httponly' => true,
    'secure' => false,
    'samesite' => 'Strict'
]);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    header("Location: index.html?error=empty");
    exit();
}

if (!isset($_SESSION['tries'])) {
    $_SESSION['tries'] = 0;
}

if ($_SESSION['tries'] > 5) {
    die("Too many attempts. Try later.");
}

$usersFile = __DIR__ . "/users.txt";
$users = file($usersFile, FILE_IGNORE_NEW_LINES);

$login = false;

foreach($users as $line){

    $parts = explode(":", trim($line), 2);
    if(count($parts) !== 2) continue;

    $user = $parts[0];
    $hash = $parts[1];

    if($username === $user && password_verify($password, $hash)){
        $login = true;
        break;
    }
}

if($login){
    session_regenerate_id(true);
    $_SESSION['user'] = $username;
    $_SESSION['tries'] = 0;

    header("Location: home.php");
    exit();
} else {
    $_SESSION['tries']++;
    header("Location: index.html?error=1");
    exit();
}
?>
