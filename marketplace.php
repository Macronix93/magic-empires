<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_MARKETPLACE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$my_x = $kingdom->get_kingdom_map_x();
$my_y = $kingdom->get_kingdom_map_y();
$map = new Map($db_instance, $user);

$default_supply = ResourceTypes::RESOURCE_TYPE_FOOD; // Nahrung
$default_demand = ResourceTypes::RESOURCE_TYPE_WOOD; // Holz

if (isset($_GET["accept"])) {
    $result = $db_instance->execute_query("
        SELECT m.*, k.mapx, k.mapy 
        FROM marketplace m 
        JOIN kingdoms k ON m.kingdomid = k.id 
        WHERE m.offerid = ?", [$_GET["accept"]]);
    $row = $result->fetch_assoc();

    if ($row && $row["userid"] != $user->get_user_id()) {
        $supply = $row["supply"];
        $supply_value = $row["supplyvalue"];
        $demand = $row["demand"];
        $demand_value = $row["demandvalue"];
        $coins_cost = $row["coins"];

        // Check if kingdom has enough resources to handle the trade
        if ($demand == ResourceTypes::RESOURCE_TYPE_FOOD && $kingdom->get_kingdom_food() < $demand_value) {
            $error = "Soviel Nahrung kannst du nicht aufbringen!";
        } else if ($demand == ResourceTypes::RESOURCE_TYPE_WOOD && $kingdom->get_kingdom_wood() < $demand_value) {
            $error = "Soviel Holz kannst du nicht aufbringen!";
        } else if ($demand == ResourceTypes::RESOURCE_TYPE_STONE && $kingdom->get_kingdom_stone() < $demand_value) {
            $error = "Soviel Stein kannst du nicht aufbringen!";
        } else if ($demand == ResourceTypes::RESOURCE_TYPE_GOLD && $kingdom->get_kingdom_gold() < $demand_value) {
            $error = "Soviel Gold kannst du nicht aufbringen!";
        } else if ($user->get_user_coins() < $coins_cost) {
            $error = "Deine Münzen reichen nicht für das Handelsangebot!";
        } else {
            $other_kingdom = new Kingdom($db_instance, $row["kingdomid"]);
            $creator_id = $row["userid"];
            $creator_name = $row["username"];

            $arrival_data = $map->calculate_arrival_data($my_x, $my_y, $row["mapx"], $row["mapy"]);
            $seconds = $arrival_data["seconds"];
            $arrival_time = $arrival_data["timestamp"];

            $kingdom->modify_resource((int)$demand, -$demand_value);
            $user->give_user_coins(-$coins_cost);

            // Buyer receives supply
            $db_instance->execute_query(
                "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [ActionTypes::ACTION_RECEIVE_RESOURCES, $user->get_user_id(), $current_kingdom, $supply, $supply_value, "Warenlieferung", $arrival_time]
            );

            // Seller receives demand
            $db_instance->execute_query(
                "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [ActionTypes::ACTION_RECEIVE_RESOURCES, $creator_id, $row["kingdomid"], $demand, $demand_value, "Handelserlös", $arrival_time]
            );

            $arrival_str = convert_sec_to_str($seconds);
            $seller_message = "Dein Handelsangebot<br><br>" .
                get_resource_icon($supply) . " " . fnum($supply_value) . " gegen " .
                get_resource_icon($demand) . " " . fnum($demand_value) . "<br><br>" .
                "wurde vom Spieler \"" . $user->get_user_name() . "\"(Königreich: " . $kingdom->get_kingdom_name() . ") angenommen!<br><br>" .
                "Die Karawane trifft in " . $arrival_str . " in deinem Königreich ein.";

            send_server_message($creator_id, $creator_name, $seller_message, MessageCategories::CATEGORY_TRADE);

            // Delete the offer and send a confirmation text
            $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["accept"]]);

            $view .= show_passed_box("Handel akzeptiert! Die Karawanen sind unterwegs.<br>Ankunft in " . convert_sec_to_str($seconds));
        }
    } else {
        $error = "Dieses Angebot existiert nicht oder ist von einem deiner Königreiche!";
    }
} else if (isset($_GET["delete"])) {
    $result = $db_instance->execute_query("SELECT supply, supplyvalue, kingdomid FROM marketplace 
                                      WHERE offerid = ? AND userid = ?", [$_GET["delete"], $user->get_user_id()]);
    $row = $result->fetch_assoc();

    if ($row) {
        $supply = $row["supply"];
        $supply_value = $row["supplyvalue"];
        $origin_kingdom_id = $row["kingdomid"];
        $origin_kingdom = new Kingdom($db_instance, $origin_kingdom_id);

        // Give supply resources back to kingdom
        $origin_kingdom->modify_resource((int)$row["supply"], (int)$row["supplyvalue"]);

        // Delete the marketplace offer
        $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$_GET["delete"]]);
        $view .= show_passed_box("Angebot gelöscht. Die Ressourcen wurden an das Ursprungskönigreich zurückgegeben.");
    } else {
        $error = "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
    }
} else if (!empty($_GET["sv"]) && !empty($_GET["dv"])) {
    $supply_value = $_GET["sv"];
    $demand_value = $_GET["dv"];
    $supply = $_GET["s"];
    $demand = $_GET["d"];

    if ($supply < 0 || $supply > 3 || $demand < 0 || $demand > 3) {
        $error = "Diese Ressource gibt es nicht!";
    } else if ($supply == $demand) {
        $error = "Die Ressourcentypen dürfen nicht gleich sein!";
    } else {
        if ($supply_value <= 0 || !is_numeric($supply_value) || $demand_value <= 0 || !is_numeric($demand_value) || $supply_value > 99999 || $demand_value > 99999) {
            $error = "Die Werte müssen zwischen 1 und 99999 liegen!";
        } else {
            // Check if kingdom has enough ressources to handle the trade
            if ($supply == ResourceTypes::RESOURCE_TYPE_FOOD && $kingdom->get_kingdom_food() < $supply_value) {
                $error = "Soviel Nahrung kannst du nicht bieten!";
            } else if ($supply == ResourceTypes::RESOURCE_TYPE_WOOD && $kingdom->get_kingdom_wood() < $supply_value) {
                $error = "Soviel Holz kannst du nicht bieten!";
            } else if ($supply == ResourceTypes::RESOURCE_TYPE_STONE && $kingdom->get_kingdom_stone() < $supply_value) {
                $error = "Soviel Stein kannst du nicht bieten!";
            } else if ($supply == ResourceTypes::RESOURCE_TYPE_GOLD && $kingdom->get_kingdom_gold() < $supply_value) {
                $error = "Soviel Gold kannst du nicht bieten!";
            } else {
                // Check if there is already an offer for this kingdom
                $result = $db_instance->execute_query("SELECT offerid FROM marketplace WHERE kingdomid = ?", [$current_kingdom]);
                $offer_id = $result->fetch_assoc()['offerid'] ?? 0;

                if ($offer_id != 0) {
                    $error = "Du hast bereits ein Angebot für dieses Königreich am laufen!";
                } else {
                    // No offer found for the kingdom - insert to database
                    $calculated_fee = calculate_market_fee($supply, $supply_value, $demand, $demand_value);
                    $expires_at = time() + MARKET_OFFER_DURATION;

                    $query = "INSERT INTO marketplace (userid, username, kingdomid, supply, supplyvalue, demand, demandvalue, coins, expires_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?);";
                    $result = $db_instance->execute_query($query, [
                        $user->get_user_id(), $user->get_user_name(), $current_kingdom, $supply, $supply_value, $demand, $demand_value, $calculated_fee, $expires_at]);

                    switch ($supply) {
                        case ResourceTypes::RESOURCE_TYPE_FOOD:
                            $kingdom->give_kingdom_food(-$supply_value);
                            break;
                        case ResourceTypes::RESOURCE_TYPE_WOOD:
                            $kingdom->give_kingdom_wood(-$supply_value);
                            break;
                        case ResourceTypes::RESOURCE_TYPE_STONE:
                            $kingdom->give_kingdom_stone(-$supply_value);
                            break;
                        case ResourceTypes::RESOURCE_TYPE_GOLD:
                            $kingdom->give_kingdom_gold(-$supply_value);
                            break;
                    }
                }
            }
        }
    }
}

if (isset($_GET["send_own"])) {
    $target_id = (int)$_GET["target_k"];
    $res_type = (int)$_GET["rt"];
    $amount = (int)$_GET["am"];

    // 1. Prüfen, ob das Ziel-Königreich dem User gehört und nicht das aktuelle ist
    $res_target = $db_instance->execute_query("SELECT id, mapx, mapy, kingdomname FROM kingdoms WHERE id = ? AND userid = ?", [$target_id, $user->get_user_id()]);
    $target_row = $res_target->fetch_assoc();

    if ($target_row && $target_id != $current_kingdom) {
        // 2. Ressourcen-Check
        $has_enough = false;

        switch ($res_type) {
            case ResourceTypes::RESOURCE_TYPE_FOOD:
                $has_enough = ($kingdom->get_kingdom_food() >= $amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_WOOD:
                $has_enough = ($kingdom->get_kingdom_wood() >= $amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_STONE:
                $has_enough = ($kingdom->get_kingdom_stone() >= $amount);
                break;
            case ResourceTypes::RESOURCE_TYPE_GOLD:
                $has_enough = ($kingdom->get_kingdom_gold() >= $amount);
                break;
        }

        if ($amount <= 0) {
            $error = "Bitte gib eine Menge größer als 0 an!";
        } elseif (!$has_enough) {
            $error = "Du hast nicht genug Ressourcen für diesen Transport!";
        } else {
            $arrival_data = $map->calculate_arrival_data($my_x, $my_y, $target_row["mapx"], $target_row["mapy"]);
            $seconds = $arrival_data["seconds"];
            $arrival_time = $arrival_data["timestamp"];

            $kingdom->modify_resource($res_type, -$amount);

            $db_instance->execute_query(
                "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?)",
                [ActionTypes::ACTION_RECEIVE_RESOURCES, $user->get_user_id(), $target_id, $res_type, $amount, "Interner Transport", $arrival_time]
            );

            $view .= show_passed_box("Transport nach " . $target_row["kingdomname"] . " gestartet!<br>Ankunft in " . convert_sec_to_str($seconds));
        }
    } else {
        $error = "Ungültiges Ziel-Königreich!";
    }
}

// PAGINATION
$rows_per_page = 15;
$current_page = isset($_GET["currentpage"]) ? max(1, (int)$_GET["currentpage"]) : 1;

$num_rows = $db_instance->execute_query("SELECT COUNT(*) FROM marketplace")->fetch_row()[0];
$total_pages = ceil($num_rows / $rows_per_page);
if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

$offset = ($current_page - 1) * $rows_per_page;

/*
 * HTML Content Part
 */
$view .= '<table class="table">
<form action="marketplace.php" method="GET" 
      data-on-submit="checkMarket" 
      data-type-field="d" 
      data-amount-field="dv"
      data-is-listing="true">
    <tr>
        <td>
            <label for="sv">Ich biete:</label>
            <br>
            <input type="text"
                   name="sv"
                   id="sv"
                   size="5"
                   maxlength="5">
            <label>
                <select name="s" id="s">
                    <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '">Holz</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                </select>
            </label>
        </td>
        <td>
            <label for="dv">Ich suche:</label>
            <br>
            <input type="text"
                   name="dv"
                   id="dv"
                   size="5"
                   maxlength="5">
            <label>
                <select name="d" id="d">
                    <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '" selected>Holz</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                </select>
            </label>
        </td>
        <td style="width: 15%; text-align: center;">
            ' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . ' <b id="live-fee">1</b>
        </td>
        <td style="text-align: center">
            <input type="submit" value="Abschicken"/>
        </td>
    </tr>
</form>
</table><br>';

$query = "
            SELECT m.*, k.mapx, k.mapy 
            FROM marketplace m 
            LEFT JOIN kingdoms k 
            ON m.kingdomid = k.id
";
/** @var mysqli_result $result */
$result = $db_instance->execute_query($query);

if ($result->num_rows > 0) {
    $view .= "<h3>Aktuelle Handelsangebote</h3>";
    $view .= '<table class="table">
                <colgroup>
                    <col style="width: 20%;"> <!-- Spieler -->
                    <col style="width: 30%;"> <!-- Bietet/Benötigt -->
                    <col style="width: 20%;"> <!-- Ankunft -->
                    <col style="width: 20%;"> <!-- Endet in -->
                    <col style="width: 12%;"> <!-- Gebühr -->
                    <col style="width: 8%;">  <!-- Aktion -->
                </colgroup>
                <tr>
                    <td class="td-center td-gradient">
                        <b>Spieler</b>
                    </td>
                    <td class="td-center td-gradient">
                        <b>Bietet / Benötigt</b>
                    </td>
                    <td class="td-center td-gradient">
                        <b>Ankunft</b>
                    </td>
                    <td class="td-center td-gradient">
                        <b>Endet in</b>
                    </td>
                    <td class="td-center td-gradient">
                        <b>Gebühr</b>
                    </td>
                    <td class="td-center td-gradient"></td>
                </tr>';

    foreach ($result as $row) {
        $map_x = $row["mapx"];
        $map_y = $row["mapy"];
        $is_my_offer = ($row["userid"] == $user->get_user_id());
        $remaining = $row["expires_at"] - time();
        $time_str = convert_sec_to_str($remaining);

        if ($is_my_offer) {
            $arrival_time_str = "-";
        } else {
            $seconds = $map->get_arrival_time($my_x, $my_y, $map_x, $map_y);
            $arrival_time_str = convert_sec_to_str($seconds);
        }

        $kingdom_coords = "$map_x:$map_y";

        if ($is_my_offer) {
            $action = "&#10060;";
            $param = "delete";
            $btn_class = "btn-delete";
            $title_attr = "Angebot löschen";
        } else {
            $action = "&#9989;";
            $param = "accept";
            $btn_class = "btn-accept";
            $title_attr = "Angebot annehmen";
        }

        $text_build = "<form action='marketplace.php' method='GET' 
                            data-on-submit='checkMarket' 
                            data-res-type='" . (int)$row["supply"] . "' 
                            data-amount='" . (int)$row["supplyvalue"] . "'>
                            <input type='hidden' name='" . e($param) . "' value='" . e($row["offerid"]) . "'>
                            <input type='submit' value='' class='" . e($btn_class) . "'>
                        </form>";

        $view .= "<tr>
                    <td>{$row["username"]} (<a href='#' data-on-click='mapJump' data-x='" . e($map_x) . "' data-y='" . e($map_y) . "'>$kingdom_coords</a>)</td>
                    <td class='td-center' style='white-space: nowrap;'>
                        " . get_resource_icon($row["supply"]) . " " . fnum($row["supplyvalue"]) . " 
                        <span style='color: #888;'>&#10234;</span> 
                        " . get_resource_icon($row["demand"]) . " " . fnum($row["demandvalue"]) . "
                    </td>
                    <td class='td-center'>$arrival_time_str</td>
                    <td class='td-center'>$time_str</td>
                    <td class='td-center'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " {$row["coins"]}</td>
                    <td class='td-center'>$text_build</td>
                </tr>";
    }
    $view .= '</table>';

    // --- PAGINATION BAR ---
    if ($total_pages > 1) {
        $view .= '<div class="pagination-container"><div class="pagination-bar">';

        if ($current_page > 1) {
            $view .= "<a href='marketplace.php?currentpage=1' class='page-link'>&laquo;</a>";
            $prev = $current_page - 1;
            $view .= "<a href='marketplace.php?currentpage=$prev' class='page-link'>&lsaquo;</a>";
        }

        $range = 2;
        for ($x = ($current_page - $range); $x < (($current_page + $range) + 1); $x++) {
            if ($x > 0 && $x <= $total_pages) {
                $active = ($x == $current_page) ? "active" : "";
                if ($x == $current_page) {
                    $view .= "<span class='page-link active'>$x</span>";
                } else {
                    $view .= "<a href='marketplace.php?currentpage=$x' class='page-link'>$x</a>";
                }
            }
        }

        if ($current_page < $total_pages) {
            $next = $current_page + 1;
            $view .= "<a href='marketplace.php?currentpage=$next' class='page-link'>&rsaquo;</a>";
            $view .= "<a href='marketplace.php?currentpage=$total_pages' class='page-link'>&raquo;</a>";
        }
        $view .= '</div></div>';
    }
} else {
    $view .= "Es gibt derzeit keine Handelsangebote.";
}

$other_kingdoms_res = $db_instance->execute_query("SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? AND id != ?", [$user->get_user_id(), $current_kingdom]);

if ($other_kingdoms_res->num_rows > 0) {
    $view .= "<br><hr><br><div class='title-border'>Interner Ressourcentransport</div>";
    $view .= '<table class="table">
                    <form action="marketplace.php" method="GET">
                        <input type="hidden" name="send_own" value="1">
                        <tr>
                            <td>
                                <label for="target_k">Ziel:</label>
                                <select name="target_k" id="target_k" style="width: 180px;">';

    foreach ($other_kingdoms_res as $ok) {
        $view .= "<option value='{$ok["id"]}'>{$ok["kingdomname"]} ({$ok["mapx"]}:{$ok["mapy"]})</option>";
    }

    $view .= '  </select>
                    </td>
                    <td>
                        <label for="am">Menge:</label>
                        <input type="text" name="am" id="am" size="6" maxlength="7">
                        <select name="rt" id="rt">
                            <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                            <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '">Holz</option>
                            <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                            <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                        </select>
                    </td>
                    <td class="td-center">
                        <input type="submit" value="Transport senden">
                    </td>
                </tr>
            </form>
            </table><br>';
}

$storage_info = [
    ResourceTypes::RESOURCE_TYPE_FOOD => ["cur" => $kingdom->get_kingdom_food(), "max" => $kingdom->get_kingdom_max_food()],
    ResourceTypes::RESOURCE_TYPE_WOOD => ["cur" => $kingdom->get_kingdom_wood(), "max" => $kingdom->get_kingdom_max_wood()],
    ResourceTypes::RESOURCE_TYPE_STONE => ["cur" => $kingdom->get_kingdom_stone(), "max" => $kingdom->get_kingdom_max_stone()],
    ResourceTypes::RESOURCE_TYPE_GOLD => ["cur" => $kingdom->get_kingdom_gold(), "max" => $kingdom->get_kingdom_max_gold()],
];

$view .= "<script>window.curKingdomStorage = " . json_encode($storage_info) . ";</script>";

$market_config = [
    "base" => MARKET_BASE_FEE,
    "factors" => [
        ResourceTypes::RESOURCE_TYPE_FOOD => MARKET_FEE_MULTIPLIER_FOOD,
        ResourceTypes::RESOURCE_TYPE_WOOD => MARKET_FEE_MULTIPLIER_WOOD,
        ResourceTypes::RESOURCE_TYPE_STONE => MARKET_FEE_MULTIPLIER_STONE,
        ResourceTypes::RESOURCE_TYPE_GOLD => MARKET_FEE_MULTIPLIER_GOLD
    ]
];

$view .= "<script>window.marketConfig = " . json_encode($market_config) . ";</script>";

/*
 * HTML Section
 */
$title = $building_name;
$header = $building_name . " (" . $building->get_building_level() . ")";
$script_files = ["marketplace", "userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");