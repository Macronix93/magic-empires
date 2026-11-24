<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_SHRINE);
$current_kingdom = $result['current_kingdom'];
$kingdom = $result['kingdom'];
$building = $result['building'];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!verify_csrf_token()) {
        $error = "Ungültiger Sicherheits-Token!";
    } else if (isset($_POST["choose_align"])) {
        $choice = post_int("choose_align", -1);
        $res_check = $db_instance->execute_query("SELECT required_level FROM shrine_alignments WHERE id = ?", [$choice]);
        $req_lvl = $res_check->fetch_column();

        if ($req_lvl === false || $req_lvl === null) {
            $error = "Ungültige Gesinnung gewählt!";
        } else if ($building->get_building_level() < (int)$req_lvl) {
            $error = "Dein Schrein hat eine zu niedrige Stufe für diese Gesinnung!";
        } else {
            $db_instance->begin_transaction();
            try {
                $locked_k = $db_instance->execute_query(
                    "SELECT alignment, gold FROM kingdoms WHERE id = ? FOR UPDATE",
                    [$current_kingdom]
                )->fetch_assoc();

                $fresh_align = (int)($locked_k["alignment"] ?? 0);
                $fresh_gold = (int)($locked_k["gold"] ?? 0);

                if ($choice === $fresh_align && $choice !== AlignmentTypes::ALIGN_NONE) {
                    throw new RuntimeException("Diese Gesinnung ist bereits aktiv!");
                }

                if ($choice === AlignmentTypes::ALIGN_NONE && $fresh_align === AlignmentTypes::ALIGN_NONE) {
                    throw new RuntimeException("Derzeit ist keine Gesinnung aktiv!");
                }

                if ($fresh_gold < SHRINE_CHANGE_COST) {
                    throw new RuntimeException("Du hast nicht genug Gold für das Opferritual!");
                }

                $db_instance->execute_query("UPDATE kingdoms SET alignment = ?, gold = gold - ? WHERE id = ?",
                    [$choice, SHRINE_CHANGE_COST, $current_kingdom]
                );

                $kingdom->set_kingdom_alignment($choice);
                $kingdom->recalculate_production();
                $db_instance->commit();

                $logger->log_game("ECONOMY", "SHRINE_ALIGNMENT_CHANGE", ["alignment" => $choice], $current_kingdom);

                Achievement::unlock($user->get_user_id(), AchievementTypes::ACHIEVEMENT_SHRINE_ALIGNMENT);

                $_SESSION["game_success"] = "Das Opferritual war erfolgreich! Die Gesinnung ist nun aktiv.";

                change_location("shrine.php");
            } catch (RuntimeException $e) {
                $db_instance->rollback();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $db_instance->rollback();
                $logger->error("shrine choose_align error: " . $e->getMessage());
                $error = "Interner Fehler beim Opferritual.";
            }
        }
    } else if (isset($_POST["reset_align"])) {
        $db_instance->begin_transaction();
        try {
            $locked_k = $db_instance->execute_query(
                "SELECT alignment FROM kingdoms WHERE id = ? FOR UPDATE",
                [$current_kingdom]
            )->fetch_assoc();

            if ((int)($locked_k["alignment"] ?? 0) === AlignmentTypes::ALIGN_NONE) {
                throw new RuntimeException("Derzeit ist keine Gesinnung aktiv!");
            }

            $db_instance->execute_query("UPDATE kingdoms SET alignment = 0 WHERE id = ?", [$current_kingdom]);
            $kingdom->set_kingdom_alignment(AlignmentTypes::ALIGN_NONE);
            $kingdom->recalculate_production();
            $db_instance->commit();

            $_SESSION["game_success"] = "Die Gesinnung wurde abgelegt. Deine Produktion ist wieder neutral.";

            change_location("shrine.php");
        } catch (RuntimeException $e) {
            $db_instance->rollback();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $db_instance->rollback();
            $logger->error("shrine reset_align error: " . $e->getMessage());
            $error = "Interner Fehler beim Ablegen der Gesinnung.";
        }
    }

    if (!empty($error)) {
        $_SESSION["game_error"] = $error;
        change_location("shrine.php");
    }
}

/*
 * HTML Content
 */
$current_align = $kingdom->get_kingdom_alignment();
$shrine_level = $building->get_building_level();
$res_aligns = $db_instance->execute_query(
    "SELECT * FROM shrine_alignments WHERE required_level <= ? ORDER BY required_level",
    [$shrine_level]
);

$view .= "<h3 style='margin: 0;'>Wähle die Gesinnung für dieses Königreich</h3>";
$view .= "<p>Ein Wechsel erfordert ein Opfer von " . fnum(SHRINE_CHANGE_COST) . " Gold.</p>";

$disabled = $current_align == AlignmentTypes::ALIGN_NONE;
$view .= "
    <div style='text-align: center; margin-bottom: 20px;'>
        <form method='POST'>
            <input type='hidden' name='reset_align' value='1'>
            <button type='submit' " . ($disabled ? "disabled" : "") . ">Gesinnung ablegen</button>
        </form>
    </div>";

while ($data = $res_aligns->fetch_assoc()) {
    $active = ($current_align == $data["id"]) ? " border: 2px solid var(--link-color); background: var(--box-selected);" : "";

    $display_bonus = fdec($kingdom->calculate_shrine_bonus($data["base_bonus"]) * 100);
    $display_malus = fdec($kingdom->calculate_shrine_bonus($data["base_malus"]) * 100);

    $view .= "
    <div class='box-container' style='margin-bottom: 15px;$active'>
        <div class='box-header'>{$data["name"]}</div>
        <div class='box-content box-content-bg' style='padding: 10px;'>
            <span class='passed'>+$display_bonus% {$data["bonus_text"]}</span><br>
            <span class='error'>-$display_malus% {$data["malus_text"]}</span><br><br>
            <form method='POST'>
                <input type='hidden' name='choose_align' value='{$data['id']}'>
                <button type='submit' " . ($current_align == $data["id"] ? "disabled" : "") . ">
                    " . ($current_align == $data["id"] ? "Bereits gewählt" : "Dienen") . "
                </button>
            </form>
        </div>
    </div>";
}

/*
 * HTML Section
 */
$title = "Schrein der Ahnen";
$header = "Schrein der Ahnen" . " (" . $building->get_building_level() . ")";
include("layout/base.php");