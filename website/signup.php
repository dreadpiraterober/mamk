<?php
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if($username === '' || $password === ''){
    die("Missing fields");
}

$usersFile = "users.txt";

if(file_exists($usersFile)){

    $users = file($usersFile, FILE_IGNORE_NEW_LINES);

    foreach($users as $line){

        $parts = explode(":", $line, 2);

        if(count($parts) !== 2){
            continue;
        }

        if($parts[0] === $username){
            die("Username already exists");
        }
    }
}

$hash = password_hash($password, PASSWORD_DEFAULT);

file_put_contents(
    $usersFile,
    $username . ":" . $hash . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

header("Location: index.html");
exit();
?>
