<?php
require_once("includes/core.php");

check_user_login($user);

require_once("includes/content/halloffame_data.php");

$active_cat = $_GET["cat"] ?? "ranking";
if (!isset($categories[$active_cat])) {
    $active_cat = "ranking";
}

$view = "<div class='tab' id='hof-tabs'>";
foreach ($categories as $id => $data) {
    $active = ($id === $active_cat) ? "active" : "";

    $view .= "<div class='tablinks $active' data-on-click='filterHallOfFame' data-category='$id'>{$data["label"]}</div>";
}
$view .= "</div>";

$view .= "<div id='hof-container'>";

foreach ($categories as $id => $data) {
    $is_active = ($id === $active_cat);
    $display = $is_active ? "block" : "none";
    $loaded_attr = $is_active ? "data-loaded='true'" : "data-loaded='false'";

    $view .= "<div id='hof_content_$id' class='js-hof-tab' style='display: $display;' $loaded_attr>";

    if ($is_active) {
        $view .= render_hof_tab_content($data, $db_instance, $user);
    }

    $view .= "</div>";
}

$view .= "</div>";

$title = "Hall of Fame";
$header = "Ruhmeshalle";
$script_files = ["halloffame", "userinfo", "guild"];

include("layout/base.php");