<?php
// The password you want to use
$plain_password = 'cashier'; 

$hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

echo "Hashed password for 'admin':<br>";
echo $hashed_password;
?>