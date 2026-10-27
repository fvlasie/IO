<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

session_unset();      // remove all session variables
session_destroy();    // destroy the session

header("Location: index.php");
exit;
