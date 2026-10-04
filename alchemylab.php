<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_ALCHEMY_LAB);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];
$lab_level = $building->get_building_level();

$alchemy = new Alchemy();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["start_transmutation"])) {
        $from_res = (int)$_POST["from_res"];
        $to_res = (int)$_POST["to_res"];
        $amount = (int)str_replace(['.', ','], '', $_POST["amount"] ?? "0");

        if ($amount <= 0) {
            $error = "Bitte gib eine Menge größer als 0 an.";
        } else {
            $err = $alchemy->start_transmutation($current_kingdom, $from_res, $to_res, $amount, $lab_level, $kingdom);

            if ($err) {
                $error = $err;
            } else {
                $_SESSION["game_success"] = "Die Umwandlung wurde erfolgreich gestartet!";

                change_location("alchemylab.php");
                exit;
            }
        }
    }

    if (isset($_POST["claim_output"])) {
        $err = $alchemy->claim($current_kingdom, $lab_level, $kingdom);

        if ($err) {
            $error = $err;
        } else {
            $_SESSION["game_success"] = "Umgewandelte Ressourcen erfolgreich ins Lager transferiert!";

            change_location("alchemylab.php");
            exit;
        }
    }

    if (isset($_POST["top_up_input"])) {
        $amount = (int)str_replace(['.', ','], '', $_POST["top_up_amount"] ?? "0");
        $err = $alchemy->top_up($current_kingdom, $amount, $lab_level, $kingdom);

        if ($err) {
            $error = $err;
        } else {
            $_SESSION["game_success"] = "Ressourcen erfolgreich in den Kessel nachgelegt!";

            change_location("alchemylab.php");
            exit;
        }
    }

    if (isset($_POST["cancel_transmutation"])) {
        $err = $alchemy->cancel($current_kingdom, $lab_level, $kingdom);

        if ($err) {
            $error = $err;
        } else {
            $_SESSION["game_success"] = "Prozess abgebrochen. Verbleibende Rohstoffe wurden zurückerstattet.";

            change_location("alchemylab.php");
            exit;
        }
    }
}

$state = $alchemy->get_state($current_kingdom, $lab_level);

$res_names = [
    ResourceTypes::RESOURCE_TYPE_FOOD => "Nahrung",
    ResourceTypes::RESOURCE_TYPE_WOOD => "Holz",
    ResourceTypes::RESOURCE_TYPE_STONE => "Stein",
    ResourceTypes::RESOURCE_TYPE_GOLD => "Gold"
];
$capacity = Alchemy::get_capacity($lab_level);
$speed = Alchemy::get_speed_per_hour($lab_level);

$stocks = [
    ResourceTypes::RESOURCE_TYPE_FOOD => $kingdom->get_kingdom_food(),
    ResourceTypes::RESOURCE_TYPE_WOOD => $kingdom->get_kingdom_wood(),
    ResourceTypes::RESOURCE_TYPE_STONE => $kingdom->get_kingdom_stone(),
    ResourceTypes::RESOURCE_TYPE_GOLD => $kingdom->get_kingdom_gold()
];

/*
 * HTML Content Part
 */
$view .= "
<div class='box-container' style='max-width: 450px; margin: 0 auto 20px auto;'>
    <div class='box-header'>Labor-Status</div>
    <div class='box-content box-content-bg' style='padding: 15px;'>
        <div class='split-content'><span>Kessel-Kapazität:</span> <b>" . fnum($capacity, true) . "</b></div>
        <div class='split-content'><span>Umwandlungs-Tempo:</span> <b class='passed'>" . fnum($speed) . " Res. / Std.</b></div>
    </div>
</div>";

$has_active_process = ($state["input_resource"] !== -1 || (float)$state["output_amount"] > 0);

if ($has_active_process) {
    $in_res = $state["input_resource"];
    $out_res = $state["target_resource"];
    $in_amt = $state["input_amount"];
    $ready_to_claim = (int)round((float)$state["output_amount"]);

    $step = ($in_res !== -1 && $out_res !== -1) ? Alchemy::get_step_size($in_res, $out_res) : 1;
    $conversion_rate = ($in_res !== -1 && $out_res !== -1) ? Alchemy::get_conversion_rate($in_res, $out_res) : 0;
    $output_per_step = max(1, (int)round($step * $conversion_rate));

    $max_output_buffer = ($in_res !== -1 && $out_res !== -1) ? Alchemy::get_max_output_buffer($in_res, $out_res, $lab_level) : 0;
    $is_buffer_full = ($max_output_buffer > 0 && ($max_output_buffer - $ready_to_claim) < $output_per_step);
    $is_insufficient_input = ($in_amt > 0 && $in_amt < $step);
    $is_paused = ($is_buffer_full || $is_insufficient_input);

    $speed = Alchemy::get_speed_per_hour($lab_level, $in_res);
    $usable_input = $in_amt - ($in_amt % $step);
    $rem_time_sec = ($speed > 0 && $usable_input > 0 && !$is_paused) ? (int)ceil(($usable_input / $speed) * 3600) : 0;

    $stock = ($in_res !== -1) ? ($stocks[$in_res] ?? 0) : 0;
    $free_space = max(0, $capacity - $in_amt);
    $raw_max_top_up = min($stock, $free_space);
    $max_top_up = $raw_max_top_up - ($raw_max_top_up % $step);

    $input_per_sec = $speed / 3600;
    $output_per_sec = $input_per_sec * $conversion_rate;

    $header_text = ($in_amt > 0) ? "Laufende Umwandlung" : "Umwandlung abgeschlossen";

    $view .= "
    <div class='box-container active-guild-project' style='max-width: 450px; margin-bottom: 20px;'>
        <div class='box-header'>$header_text</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>";

    if ($in_res !== -1) {
        $view .= "
            <div style='display: flex; align-items: center; justify-content: center; gap: 15px; font-size: 1.2em; margin-bottom: 15px;'>
                <span>" . get_resource_icon($in_res) . " <b>$res_names[$in_res]</b></span>
                <span style='color: var(--link-color); font-weight: bold;'>&rarr;</span>
                <span>" . get_resource_icon($out_res) . " <b>$res_names[$out_res]</b></span>
            </div>

            <div class='split-content' style='margin-bottom: 5px;'>
                <span>Im Kessel verbleibend:</span>
                <b><span id='live-kettle-in'>" . fnum($in_amt) . "</span> / " . fnum($capacity) . "</b>
            </div>
            
            <div class='tick-progress-bg' style='height: 8px; margin-bottom: 12px;'>
                <div class='tick-progress-fill' id='alchemy-live-bar' style='width: 0;'></div>
            </div>";

        $has_remainder = ($usable_input > 0 && ($in_amt % $step) > 0);
        $zero_text = $has_remainder ? "Zu wenig im Kessel" : "Kessel leer";

        $view .= "
            <div class='split-content' style='margin-bottom: 10px;'>
                <span>Restdauer:</span>
                <b id='alchemy-countdown-wrap'>" . (
            $is_buffer_full
                ? "<span class='error'>Puffer voll (Pausiert)</span>"
                : ($is_insufficient_input
                ? "Zu wenig im Kessel"
                : ($usable_input > 0
                    ? "<span class='js-countdown' id='alchemy-countdown' data-seconds='$rem_time_sec' data-no-reload='true' data-zero-text='$zero_text'>-</span>"
                    : "<span class='passed'>Kessel leer</span>"
                )
            )
            ) . "</b>
            </div>";
    }

    $buffer_display = ($max_output_buffer > 0)
        ? " <small style='opacity: 0.8;' title='Maximaler Ausgabepuffer'>/ " . fnum($max_output_buffer) . "</small>"
        : "";

    $view .= "
            <div style='background: rgba(0,0,0,0.3); border: 1px solid var(--border-gold); border-radius: 5px; padding: 12px; margin: 15px 0;'>
                <div class='split-content'>
                    <span>Bereit zur Abholung:</span>
                    <div><span class='passed'>" . get_resource_icon($out_res) . " <b id='live-claim-val'>" . fnum($ready_to_claim) . "</b></span>$buffer_display</div>
                </div>
                <form method='POST' style='margin-top: 10px; text-align: center;'>
                    <button type='submit' name='claim_output' id='btn-claim' " . ($ready_to_claim <= 0 ? "disabled" : "") . ">
                        Ressourcen einsammeln
                    </button>
                </form>
            </div>";

    if ($in_res !== -1) {
        $is_top_up_disabled = ($free_space <= 0 || $stock <= 0) ? "disabled" : "";
        $view .= "
            <hr style='margin-top: 20px;'>
            <h4 style='margin-top: 20px;'>Rohstoffe nachfüllen</h4>
            <form method='POST'>
                <div style='display: flex; gap: 5px; justify-content: center; align-items: center; max-width: 340px; margin: 0 auto;'>
                    " . get_resource_icon($in_res) . "
                    <input type='text' name='top_up_amount' id='top_up_amount' placeholder='0' inputmode='numeric' pattern='[0-9]*' class='js-numeric-input' style='width: 70px;' $is_top_up_disabled>
                    <input type='button' value='Max.' data-on-click='fillAlchemyTopUpMax' $is_top_up_disabled>
                    <input type='submit' name='top_up_input' id='btn_top_up' value='Nachlegen' disabled>
                </div>
                <small style='opacity: 0.7;'>Frei im Kessel: <span id='live-free-space'>" . fnum($free_space) . "</span> | Min.: " . fnum($step) . " | Vorrat: " . fnum($stock) . "</small>
            </form>";
    }

    if ($in_amt > 0) {
        $view .= "
            <form method='POST' id='form-cancel-alchemy' style='margin-top: 20px;'>
                <input type='hidden' name='cancel_transmutation' value='1'>
                <button type='button' data-on-click='confirmCancelAlchemy'>
                    Umwandlung abbrechen & Kessel leeren
                </button>
            </form>";
    }

    $view .= "</div></div>";

    $view .= "
    <div id='alchemy-active-data' 
         data-in-amt='$in_amt' 
         data-out-amt='{$state["output_amount"]}' 
         data-in-rate='$input_per_sec' 
         data-out-rate='$output_per_sec' 
         data-capacity='$capacity'
         data-stock='$stock'
         data-max-output='$max_output_buffer'
         data-step='$step'
         style='display: none;'></div>";
} else {
    $view .= "
    <div class='box-container' style='max-width: 450px; margin: 0 auto;'>
        <div class='box-header'>Neue Umwandlung starten</div>
        <form method='POST' class='box-content box-content-bg' style='padding: 20px;'>
            <table class='table' style='width: 100%; border: none; margin-bottom: 15px;'>
                <tr>
                    <td style='width: 55%;'>Ausgangs-Rohstoff:</td>
                    <td>
                        <select name='from_res' id='alchemy_from_res' style='width: 100%;'>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_FOOD . "'>Nahrung</option>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_WOOD . "'>Holz</option>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_STONE . "'>Stein</option>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_GOLD . "'>Gold</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Ziel-Rohstoff:</td>
                    <td>
                        <select name='to_res' id='alchemy_to_res' style='width: 100%;'>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_GOLD . "'>Gold</option>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_STONE . "'>Stein</option>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_WOOD . "'>Holz</option>
                            <option value='" . ResourceTypes::RESOURCE_TYPE_FOOD . "'>Nahrung</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>Menge einfüllen:</td>
                    <td>
                        <div style='display: flex; gap: 4px; align-items: center;'>
                            <input type='text' name='amount' id='alchemy_amount' placeholder='0' class='js-numeric-input' inputmode='numeric' pattern='[0-9]*' style='width: 100%;' required>
                            <input type='button' value='Max.' data-on-click='fillAlchemyStartMax'>
                        </div>
                    </td>
                </tr>
            </table>

            <div id='alchemy-preview'>
                <div class='split-content'><span>Erwarteter Ertrag:</span> <b id='prev_yield'>0</b></div>
                <div class='split-content'><span>Umwandlungsdauer:</span> <b id='prev_time'>0 Sek.</b></div>
            </div>

            <button type='submit' name='start_transmutation' id='btn_start_transmutation' disabled>Umwandlung beginnen</button>
        </form>
    </div>

    <div id='alchemy-config' 
         data-stocks='" . e(json_encode($stocks)) . "'
         data-weights='" . e(json_encode(ALCHEMY_RESOURCE_WEIGHTS)) . "'
         data-capacity='$capacity'
         data-speed='$speed'
         style='display: none;'></div>";
}

$title = $building_name;
$header = $building_name . " (" . $lab_level . ")";
$script_files = ["timer", "alchemylab"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");