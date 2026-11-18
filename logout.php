<?php
// Version 1.0
require_once __DIR__ . '/config.php';

$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
// Version 1.0
