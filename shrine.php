<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_SHRINE);
$current_kingdom = $result['current_kingdom'];
$kingdom = $result['kingdom'];

if (isset($_POST["choose_align"])) {
    $choice = (int)$_POST["choose_align"];
    $cost = SHRINE_CHANGE_COST;

    if ($kingdom->get_kingdom_gold() < $cost) {
        $error = "Du hast nicht genug Gold für das Opferritual!";
    } else if ($choice == $kingdom->get_kingdom_alignment() && $choice != AlignmentTypes::ALIGN_NONE) {
        $error = "Diese Gesinnung ist bereits aktiv!";
    } else if ($choice == AlignmentTypes::ALIGN_NONE && $kingdom->get_kingdom_alignment() == AlignmentTypes::ALIGN_NONE) {
        $error = "Derzeit ist keine Gesinnung aktiv!";
    } else {
        $db_instance->execute_query("UPDATE kingdoms SET alignment = ?, gold = gold - ? WHERE id = ?", [$choice, $cost, $current_kingdom]);
        $logger->log_game("ECONOMY", "SHRINE_ALIGNMENT_CHANGE", ["alignment" => $choice], $current_kingdom);

        // Recalculate old per-hour values
        $kingdom->set_kingdom_alignment($choice);
        $kingdom->recalculate_production();

        change_location("shrine.php");
        exit;
    }
}

if (isset($_POST["reset_align"])) {
    $db_instance->execute_query("UPDATE kingdoms SET alignment = 0 WHERE id = ?", [$current_kingdom]);

    // Recalculate old per-hour values
    $kingdom->set_kingdom_alignment(0);
    $kingdom->recalculate_production();

    change_location("shrine.php");
    exit;
}

/*
 * HTML Content
 */
$current_align = $kingdom->get_kingdom_alignment();
$current_mod = $kingdom->get_shrine_modifier() * 100;
$malus_val = SHRINE_MALUS_BASE * 100;

$aligns = [
    AlignmentTypes::ALIGN_WAR => [
        "name" => "Kriegsgott",
        "desc" => "+$current_mod% Angriffskraft",
        "malus" => "-$malus_val% Goldertrag"
    ],
    AlignmentTypes::ALIGN_TRADE => [
        "name" => "Handelsgöttin",
        "desc" => "+$current_mod% Goldertrag",
        "malus" => "-$malus_val% Mauer-Verteidigung"
    ],
    AlignmentTypes::ALIGN_NATURE => [
        "name" => "Naturgeist",
        "desc" => "+$current_mod% Nahrung & Holz",
        "malus" => "-$malus_val% Steinertrag"
    ]
];

$view .= "<h3>Wähle die Gesinnung für dieses Königreich</h3>";
$view .= "<p>Ein Wechsel erfordert ein Opfer von " . fnum(SHRINE_CHANGE_COST) . " Gold.</p>";

$disabled = $current_align == AlignmentTypes::ALIGN_NONE;
$view .= "
    <div style='text-align: center; margin-bottom: 20px;'>
        <form method='POST'>
            <input type='hidden' name='reset_align' value='1'>
            <button type='submit' " . ($disabled ? "disabled" : "") . ">Gesinnung ablegen</button>
        </form>
    </div>";

foreach ($aligns as $id => $data) {
    $active = ($current_align == $id) ? " border: 2px solid var(--link-color); background: var(--box-selected);" : "";

    $view .= "
    <div class='box-container' style='margin: 0;$active'>
        <div class='box-header'>{$data['name']}</div>
        <div class='box-content box-content-bg' style='padding: 10px;'>
            <span class='passed'>{$data['desc']}</span><br>
            <span class='error'>{$data['malus']}</span><br><br>
            <form method='POST'>
                <input type='hidden' name='choose_align' value='$id'>
                <button type='submit' " . ($current_align == $id ? "disabled" : "") . ">
                    " . ($current_align == $id ? "Bereits gewählt" : "Dienen") . "
                </button>
            </form>
        </div>
    </div><br>";
}

/*
 * HTML Section
 */
$title = "Schrein der Ahnen";
$header = "Schrein der Ahnen";
include("layout/base.php");