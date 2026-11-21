<?php
// Version 1.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

remember_clear();
$_SESSION = [];
session_destroy();
header('Location: index.php');
exit;
// Version 1.1