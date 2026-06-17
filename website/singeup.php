<?php

echo "Enter password: ";
$password = trim(fgets(STDIN));

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\n=== HASH GENERATED ===\n";
echo $hash . "\n";

?>