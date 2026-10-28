<?php
require_once("../includes/core.php");

if (!isset($_SERVER["HTTP_X_REQUESTED_WITH"]) || strtolower($_SERVER["HTTP_X_REQUESTED_WITH"]) !== "xmlhttprequest") {
    change_location("arena.php");
    exit;
}

$user->check_user_login();
$uid = $user->get_user_id();
$battle_id = (int)($_GET["id"] ?? 0);

if ($battle_id <= 0) {
    echo show_error_box("Ungültige Kampf-ID.");
    exit;
}

$res = $db_instance->execute_query("
    SELECT ab.*, u_atk.username AS attacker_name, u_def.username AS defender_name
    FROM arena_battles ab
    JOIN users u_atk ON ab.attacker_id = u_atk.id
    JOIN users u_def ON ab.defender_id = u_def.id
    WHERE ab.id = ? AND (ab.attacker_id = ? OR ab.defender_id = ? OR ?)
", [$battle_id, $uid, $uid, (int)$user->is_admin()]);

$battle = $res->fetch_assoc();

if (!$battle) {
    echo show_error_box("Kampfbericht nicht gefunden oder Zugriff verweigert.");
    exit;
}

$details = json_decode($battle["details_json"], true);
if (!$details) {
    echo show_error_box("Kampfdaten konnten nicht geladen werden.");
    exit;
}

$is_attacker = ((int)$battle["attacker_id"] === $uid);
$is_defender = ((int)$battle["defender_id"] === $uid);
$winner_id = (int)$battle["winner_id"];
$is_won = ($winner_id === $uid);
$is_draw = ($winner_id === 0);

$atk_data = $details["attacker"] ?? [];
$def_data = $details["defender"] ?? [];

$atk_cards = [];
foreach (($atk_data["troops"] ?? []) as $u) {
    $key = $u["key"] ?? $u["id"];
    $atk_cards[] = [
            "id" => $u["id"],
            "key" => $key,
            "initial" => $u["count"],
            "losses" => $atk_data["losses"][$key] ?? 0,
            "atk" => $u["atk"],
            "def" => $u["def"],
            "h_lvl" => $u["h_lvl"] ?? null,
            "s_id" => $u["s_id"] ?? null,
            "is_fictional" => false
    ];
}

$def_cards = [];
foreach (($def_data["troops"] ?? []) as $u) {
    $key = $u["key"] ?? $u["id"];
    $def_cards[] = [
            "id" => $u["id"],
            "key" => $key,
            "initial" => $u["count"],
            "losses" => $def_data["losses"][$key] ?? 0,
            "atk" => $u["atk"],
            "def" => $u["def"],
            "h_lvl" => $u["h_lvl"] ?? null,
            "s_id" => $u["s_id"] ?? null,
            "is_fictional" => false
    ];
}

// Eigene Einheiten IMMER links anzeigen
if ($is_defender) {
    $left_cards = $def_cards;
    $left_label = "Deine Verteidigung";
    $right_cards = $atk_cards;
    $right_label = "Angreifer: " . e($battle["attacker_name"]);
} else {
    $left_cards = $atk_cards;
    $left_label = $is_attacker ? "Deine Angreifer" : "Angreifer: " . e($battle["attacker_name"]);
    $right_cards = $def_cards;
    $right_label = "Verteidigung: " . e($battle["defender_name"]);
}

$grid_html = BattleReportRenderer::render_vs_grid($left_cards, $right_cards, $left_label, $right_label);

$outcome_type = $is_draw ? "neutral" : ($is_won ? "success" : "error");
$outcome_title = $is_draw ? "Unentschieden!" : ($is_won ? "Sieg!" : "Niederlage!");

$my_elo_change = $is_attacker ? (int)$battle["attacker_elo_change"] : (int)$battle["defender_elo_change"];
$my_elo_after = $is_attacker ? (int)$battle["attacker_elo_after"] : (int)$battle["defender_elo_after"];

if ($is_draw || $my_elo_change === 0) {
    $change_str = "+/- 0";
    $change_style = "style='color: #ffffff;'";
    $change_class = "";
} else {
    $change_str = ($my_elo_change > 0) ? "+$my_elo_change" : "$my_elo_change";
    $change_class = ($my_elo_change > 0) ? "passed" : "error";
    $change_style = "";
}

$date_str = date("d.m.Y H:i:s", $battle["created_at"]);
$role_str = $is_attacker ? "Angriff" : "Verteidigung";

$main_text = "<div class='arena-battle-log'>Wertungs-Änderung: <b class='$change_class' $change_style>$change_str ELO</b> (Neu: <b>" . fnum($my_elo_after) . " ELO</b>)</div>";
$sub_text = "<div class='arena-battle-log'>Duell vom $date_str ($role_str).</div>";

$outcome_html = BattleReportRenderer::render_outcome_box($outcome_title, $main_text, 0, 0, $sub_text, $outcome_type);

$opp_name = $is_attacker ? e($battle["defender_name"]) : e($battle["attacker_name"]);
?>
<div id="modal-title-data" data-title="Kampfbericht: vs <?= $opp_name ?>" data-width="650px"
     style="display: none;"></div>
<div class="battle-report" style="padding: 5px; max-width: 650px; margin: 0 auto;">
    <div class="title-border" style="margin-top: 0;">
        Arena-Duell: <?= $is_attacker ? "Angriff auf $opp_name" : "Verteidigung gegen $opp_name" ?>
    </div>
    <?= $grid_html ?>
    <?= $outcome_html ?>
</div>