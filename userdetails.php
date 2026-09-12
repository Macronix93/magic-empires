<?php
require_once("includes/core.php");
check_user_login($user);

$title = "Account-Info";
$header = "Account-Info";
$script_files = ["timer"];

include("layout/base.php");