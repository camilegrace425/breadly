<?php
//Must start the session to access it
session_start();

//Clear all session data
$_SESSION = array();

//Invalidate the session
session_destroy();

//Send user back to login
header('Location: login.php');
exit(); 
?>