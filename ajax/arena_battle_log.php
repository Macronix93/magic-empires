<?php
require_once("../includes/ajax_core.php");

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

$atk_cards = Arena::build_battle_cards($atk_data["troops"] ?? [], $atk_data["losses"] ?? []);
$def_cards = Arena::build_battle_cards($def_data["troops"] ?? [], $def_data["losses"] ?? []);

if ($is_defender) {
    $left_cards = $def_cards;
    $left_label = "Verteidigung";
    $right_cards = $atk_cards;
    $right_label = e($battle["attacker_name"]);
} else {
    $left_cards = $atk_cards;
    $left_label = $is_attacker ? "Angreifer" : e($battle["attacker_name"]);
    $right_cards = $def_cards;
    $right_label = e($battle["defender_name"]);
}

$grid_html = BattleReportRenderer::render_vs_grid($left_cards, $right_cards, $left_label, $right_label);

$outcome_type = $is_draw ? "neutral" : ($is_won ? "success" : "error");
$outcome_title = $is_draw ? "Unentschieden!" : ($is_won ? "Sieg!" : "Niederlage!");

$my_elo_change = $is_attacker ? (int)$battle["attacker_elo_change"] : (int)$battle["defender_elo_change"];
$my_elo_after = $is_attacker ? (int)$battle["attacker_elo_after"] : (int)$battle["defender_elo_after"];

$change_html = Arena::format_elo_change($my_elo_change, $is_draw);

$date_str = date("d.m.Y H:i:s", $battle["created_at"]);
$role_str = $is_attacker ? "Angriff" : "Verteidigung";

$main_text = "<div class='arena-battle-log'>Wertungs-Änderung: $change_html (Neu: <b>" . fnum($my_elo_after) . " ELO</b>)</div>";
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