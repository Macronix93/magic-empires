<?php
require_once("includes/core.php");

$result = check_user_login_and_kingdom($user, $db_instance, BuildingTypes::BUILDING_MARKETPLACE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$u_id = $user->get_user_id();
$trade_check = $db_instance->execute_query("SELECT daily_trades_count, last_trade_reset FROM users WHERE id = ?", [$u_id])->fetch_assoc();
$daily_trades_count = (int)$trade_check["daily_trades_count"];
$today_start = strtotime("today midnight");

// Daily reset
if ((int)$trade_check["last_trade_reset"] < $today_start) {
    $daily_trades_count = 0;
    $db_instance->execute_query("UPDATE users SET daily_trades_count = 0, last_trade_reset = ? WHERE id = ?", [time(), $u_id]);
}

$max_trades = MAX_DAILY_TRADES;
$max_capacity = $building->get_building_level() * MARKET_CAPACITY_PER_LEVEL;

$my_x = $kingdom->get_kingdom_map_x();
$my_y = $kingdom->get_kingdom_map_y();
$map = new Map($db_instance, $user);

$default_supply = ResourceTypes::RESOURCE_TYPE_FOOD;
$default_demand = ResourceTypes::RESOURCE_TYPE_WOOD;

$res_map = [
    ResourceTypes::RESOURCE_TYPE_FOOD => "food",
    ResourceTypes::RESOURCE_TYPE_WOOD => "wood",
    ResourceTypes::RESOURCE_TYPE_STONE => "stone",
    ResourceTypes::RESOURCE_TYPE_GOLD => "gold"
];

if (isset($_GET["accept"])) {
    $accept_id = (int)$_GET["accept"];

    $result = $db_instance->execute_query("
        SELECT m.*, k.mapx, k.mapy, u.ip AS seller_ip, u.device_id AS seller_device
        FROM marketplace m 
        JOIN kingdoms k ON m.kingdomid = k.id 
        JOIN users u ON m.userid = u.id
        WHERE m.offerid = ?", [$accept_id]);
    $row = $result->fetch_assoc();

    if ($row && $row["userid"] != $user->get_user_id()) {
        if ($daily_trades_count >= $max_trades) {
            $error = "Du hast dein tägliches Limit von $max_trades Handelsaktionen bereits erreicht!";
            $row = null;
        } else {

            $buyer_device = $_SESSION["device_id"] ?? '';
            $is_same_device = (!empty($row["seller_device"]) && $row["seller_device"] === $buyer_device);

            if ($is_same_device) {
                $error = "Handel zwischen Accounts am selben Gerät ist nicht gestattet!";
                $row = null;
            } else if ($row["seller_ip"] === $_SERVER["REMOTE_ADDR"]) {
                $logger->log_game("TRADE", "SAME_IP_TRADE", [
                    "seller_id" => $row["userid"],
                    "buyer_id" => $user->get_user_id(),
                    "ip" => $_SERVER["REMOTE_ADDR"]
                ]);
            }

            if ($row) {
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

                    $now = time();

                    $buyer_seconds = $map->get_arrival_time($my_x, $my_y, $row["mapx"], $row["mapy"], $current_kingdom, null, false, true);
                    $buyer_arrival_time = $now + $buyer_seconds;
                    $seller_seconds = $map->get_arrival_time($my_x, $my_y, $row["mapx"], $row["mapy"], $row["kingdomid"], null, false, true);
                    $seller_arrival_time = $now + $seller_seconds;

                    $kingdom->modify_resource((int)$demand, -$demand_value);
                    $user->give_user_coins(-$coins_cost);

                    // Buyer receives supply
                    $db_instance->execute_query(
                        "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [ActionTypes::ACTION_RECEIVE_RESOURCES, $user->get_user_id(), $current_kingdom, $supply, $supply_value, "Warenlieferung", $buyer_arrival_time]
                    );

                    // Seller receives demand
                    $db_instance->execute_query(
                        "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [ActionTypes::ACTION_RECEIVE_RESOURCES, $creator_id, $row["kingdomid"], $demand, $demand_value, "Handelserlös", $seller_arrival_time]
                    );

                    $buyer_arrival_str = convert_sec_to_str($buyer_seconds);
                    $seller_arrival_str = convert_sec_to_str($seller_seconds);
                    $loot = [$supply => $supply_value];
                    $cost = [$demand => $demand_value];

                    $seller_message = "<div class='battle-report'>";
                    $seller_message .= BattleReportRenderer::render_outcome_box(
                        "Handelsangebot angenommen",
                        "Der Spieler <b>" . $user->get_user_name() . "</b> hat deine Warenlieferung aus " . $kingdom->get_kingdom_name() . " akzeptiert.",
                        0, 0,
                        "Deine Karawane bringt den Erlös in <b>$seller_arrival_str</b> zurück.",
                        "neutral",
                        $cost
                    );
                    $seller_message .= "</div>";

                    send_server_message($creator_id, $creator_name, $seller_message, MessageCategories::CATEGORY_TRADE);

                    // Delete the offer and send a confirmation text
                    $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$accept_id]);

                    // Update daily trades count for the user
                    $db_instance->execute_query("UPDATE users SET daily_trades_count = daily_trades_count + 1 WHERE id = ?", [$u_id]);
                    $daily_trades_count++;

                    $logger->log_game("TRADE", "OFFER_ACCEPT", [
                        "offer_id" => $accept_id,
                        "seller_id" => $creator_id,
                        "resource" => $supply,
                        "amount" => $supply_value,
                        "cost_res" => $demand,
                        "cost_amount" => $demand_value,
                        "from_kingdom" => $row["kingdomid"],
                        "to_kingdom" => $kingdom->get_kingdom_id()
                    ], $current_kingdom);

                    update_global_stat("total_trades");

                    $s_name = $res_map[$supply];
                    $d_name = $res_map[$demand];
                    update_player_stat($u_id, "trades_count");
                    update_player_stat($u_id, "trade_received_" . $s_name, $supply_value);
                    update_player_stat($u_id, "trade_sent_" . $d_name, $demand_value);
                    update_player_stat($creator_id, "trades_count");
                    update_player_stat($creator_id, "trade_sent_" . $s_name, $supply_value);
                    update_player_stat($creator_id, "trade_received_" . $d_name, $demand_value);

                    $view .= show_passed_box("Handel akzeptiert! Die Karawanen sind unterwegs.<br>Ankunft in " . $buyer_arrival_str);
                }
            }
        }
    } else {
        $error = "Dieses Angebot existiert nicht oder ist von einem deiner Königreiche!";
    }
} else if (isset($_GET["delete"])) {
    $delete_id = (int)$_GET["delete"];

    $result = $db_instance->execute_query("SELECT supply, supplyvalue, kingdomid FROM marketplace 
                                      WHERE offerid = ? AND userid = ?", [$delete_id, $user->get_user_id()]);
    $row = $result->fetch_assoc();

    if ($row) {
        $supply = $row["supply"];
        $supply_value = $row["supplyvalue"];
        $origin_kingdom_id = $row["kingdomid"];
        $origin_kingdom = new Kingdom($db_instance, $origin_kingdom_id);

        // Give supply resources back to kingdom
        $origin_kingdom->modify_resource((int)$row["supply"], (int)$row["supplyvalue"]);

        // Delete the marketplace offer
        $db_instance->execute_query("DELETE FROM marketplace WHERE offerid = ?", [$delete_id]);
        $view .= show_passed_box("Angebot gelöscht. Die Ressourcen wurden an das Ursprungskönigreich zurückgegeben.");

        // Refund daily offer count
        $db_instance->execute_query("UPDATE users SET daily_trades_count = GREATEST(0, daily_trades_count - 1) WHERE id = ?", [$u_id]);
        $daily_trades_count--;

        $logger->log_game("TRADE", "OFFER_DELETE", [
            "offer_id" => $delete_id,
            "refund_res" => $row["supply"],
            "refund_amount" => $row["supplyvalue"]
        ], $current_kingdom);
    } else {
        $error = "Dieses Angebot existiert nicht oder ist nicht von deinem aktuellen Königreich!";
    }
} else if (isset($_GET["sv"]) && isset($_GET["dv"]) && $_GET["sv"] !== "" && $_GET["dv"] !== "") {
    $supply_value = (int)$_GET["sv"];
    $demand_value = (int)$_GET["dv"];
    $supply = (int)$_GET["s"];
    $demand = (int)$_GET["d"];

    if ($supply < 0 || $supply > 3 || $demand < 0 || $demand > 3) {
        $error = "Diese Ressource gibt es nicht!";
    } else if ($supply == $demand) {
        $error = "Die Ressourcentypen dürfen nicht gleich sein!";
    } else {
        if ($supply_value <= 0 || $demand_value <= 0) {
            $error = "Die Mengen müssen größer als 0 sein!";
        } else {
            $listing_fee = calculate_listing_fee($supply_value);

            if ($user->get_user_coins() < $listing_fee) {
                $error = "Du hast nicht genug Münzen für die Einstellgebühr (Benötigt: $listing_fee " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . ")!";
            } else {
                if ($supply_value > $max_capacity || $demand_value > $max_capacity) {
                    $error = "Dein Marktplatz kann maximal " . fnum($max_capacity) . " Ressourcen pro Angebot handhaben!";
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
                        $ratio1 = $supply_value / $demand_value;
                        $ratio2 = $demand_value / $supply_value;
                        $grace = 0.01;

                        if ($ratio1 > MAX_MARKET_RATIO + $grace || $ratio2 > MAX_MARKET_RATIO + $grace) {
                            $error = "Das Handelsverhältnis ist zu extrem! (Maximal 1:" . MAX_MARKET_RATIO . " erlaubt)";
                        } else {
                            // Check if there is already an offer for this kingdom
                            $result = $db_instance->execute_query("SELECT offerid FROM marketplace WHERE kingdomid = ?", [$current_kingdom]);
                            $offer_id = $result->fetch_assoc()['offerid'] ?? 0;

                            if ($offer_id != 0) {
                                $error = "Du hast bereits ein Angebot für dieses Königreich am laufen!";
                            } else {
                                if ($daily_trades_count >= $max_trades) {
                                    $error = "Du hast heute bereits $max_trades Angebote erstellt oder angenommen!";
                                } else {
                                    $user->give_user_coins(-$listing_fee);

                                    // No offer found for the kingdom - insert to database
                                    $calculated_fee = calculate_market_fee($supply, $supply_value, $demand, $demand_value);
                                    $expires_at = time() + MARKET_OFFER_DURATION;

                                    $query = "INSERT INTO marketplace (userid, username, kingdomid, supply, supplyvalue, demand, demandvalue, coins, expires_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?);";
                                    $result = $db_instance->execute_query($query, [
                                        $user->get_user_id(), $user->get_user_name(), $current_kingdom, $supply, $supply_value, $demand, $demand_value, $calculated_fee, $expires_at]);

                                    // Increase daily trades count
                                    $db_instance->execute_query("UPDATE users SET daily_trades_count = daily_trades_count + 1 WHERE id = ?", [$u_id]);
                                    $daily_trades_count++;

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

                                    $logger->log_game("TRADE", "OFFER_CREATE", [
                                        "supply_res" => $supply,
                                        "supply_amount" => $supply_value,
                                        "demand_res" => $demand,
                                        "demand_amount" => $demand_value,
                                        "fee" => $calculated_fee,
                                        "listing_fee_paid" => $listing_fee
                                    ], $current_kingdom);
                                }
                            }
                        }
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


    $res_target = $db_instance->execute_query("SELECT id, mapx, mapy, kingdomname FROM kingdoms WHERE id = ? AND userid = ?", [$target_id, $user->get_user_id()]);
    $target_row = $res_target->fetch_assoc();

    if ($target_row && $target_id != $current_kingdom) {
        if ($daily_trades_count >= $max_trades) {
            $error = "Du hast dein tägliches Limit von $max_trades Handelsaktionen bereits erreicht!";
        } else {
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
            } else if (!$has_enough) {
                $error = "Du hast nicht genug Ressourcen für diesen Transport!";
            } else {
                $arrival_data = $map->calculate_arrival_data($my_x, $my_y, $target_row["mapx"], $target_row["mapy"], true);
                $seconds = $arrival_data["seconds"];
                $arrival_time = $arrival_data["timestamp"];

                $kingdom->modify_resource($res_type, -$amount);

                $db_instance->execute_query(
                    "INSERT INTO events (actionid, userid, kingdomid, buildingid, buildinglevel, buildingname, arrivaltime, targetid, targetx, targety, buildingtime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        ActionTypes::ACTION_RECEIVE_RESOURCES,
                        $user->get_user_id(),
                        $target_id,
                        $res_type,
                        $amount,
                        "Interner Transport",
                        $arrival_time,
                        $current_kingdom,
                        $my_x,
                        $my_y,
                        time()
                    ]
                );

                $db_instance->execute_query("UPDATE users SET daily_trades_count = daily_trades_count + 1 WHERE id = ?", [$user->get_user_id()]);
                $daily_trades_count++;

                $logger->log_game("TRADE", "INTERNAL_TRANSPORT", [
                    "target_kingdom" => $target_id,
                    "resource" => $res_type,
                    "amount" => $amount
                ], $current_kingdom);

                $view .= show_passed_box("Transport nach " . $target_row["kingdomname"] . " gestartet!<br>Ankunft in " . convert_sec_to_str($seconds));
            }
        }
    } else {
        $error = "Ungültiges Ziel-Königreich!";
    }
}

// PAGINATION
$rows_per_page = 10;
$current_page = max(1, (int)($_GET["currentpage"] ?? 1));

$num_rows = $db_instance->execute_query("SELECT COUNT(*) FROM marketplace")->fetch_row()[0];
$total_pages = ceil($num_rows / $rows_per_page);
if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;

$offset = ($current_page - 1) * $rows_per_page;

/*
 * HTML Content Part
 */
$view .= "<div class='info-box' style='background-color: rgba(212, 175, 55, 0.1); border: 1px solid var(--border-gold); margin-bottom: 20px; max-width: 500px;'>
    <img src='images/icons/icon_building10.png' class='buildable-icons' alt='Marktplatz'>
    <span>
        <b>Heutige Handelsaktionen:</b> $daily_trades_count von $max_trades<br>
        <b>Kapazität:</b> Max. " . fnum($max_capacity) . " pro Angebot
    </span>
</div>";

$view .= '<form action="marketplace.php" method="GET" 
      data-on-submit="checkMarket" 
      data-type-field="d" 
      data-amount-field="dv"
      data-is-listing="true">
    <table class="table" style="margin-bottom: 15px;">
    <tr>
        <td>
            <label for="sv">Ich biete:</label>
            <br>
            <input type="text"
                   name="sv"
                   id="sv"
                   size="5"
                   maxlength="6"
                   inputmode="numeric" pattern="[0-9]*" placeholder="0">
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
                   maxlength="6"
                   inputmode="numeric" pattern="[0-9]*" placeholder="0">
            <label>
                <select name="d" id="d">
                    <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '" selected>Holz</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                    <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                </select>
            </label>
        </td>
        <td style="width: 20%; text-align: center; font-size: 13px;">
            <div class="popup" id="fee_info">
                <div style="margin-bottom: 5px; padding-bottom: 3px;">
                    Gebühr: ' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . ' <b id="live-listing-fee">1</b>
                </div>
                <div>
                    Käufer: ' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . ' <b id="live-buyer-fee">1</b>
                </div>
                <div id="fee_info_box" class="popupbox" style="text-align: left; min-width: 250px;">
                    <b>Verkäufer (Einstellgebühr):</b><br>
                    Wird sofort fällig. 1 Münze pro ' . fnum(MARKET_LISTING_FEE_STEP) . ' Ressourcen (Angebot).<br>
                    <i class="error">Wird bei Löschung/Ablauf NICHT erstattet.</i><br><br>
                    <b>Käufer (Handelsgebühr):</b><br>
                    Wird in das Angebot eingerechnet und vom Käufer bei Annahme bezahlt.
                </div>
            </div>
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
            ORDER BY m.offerid DESC
            LIMIT ?, ?
";
/** @var mysqli_result $result */
$result = $db_instance->execute_query($query, [$offset, $rows_per_page]);

if ($result->num_rows > 0) {
    $view .= "<div class='title-border'>Aktuelle Handelsangebote</div>";
    $view .= '<table class="table marketplace-table">
                <colgroup>
                    <col style="width: 30%;"> <!-- Spieler -->
                    <col style="width: 20%;"> <!-- Bietet/Benötigt -->
                    <col style="width: 20%;"> <!-- Ankunft -->
                    <col style="width: 20%;"> <!-- Endet in -->
                    <col style="width: 15%;"> <!-- Gebühr -->
                    <col style="width: 5%;">  <!-- Aktion -->
                </colgroup>
                <tr>
                    <td class="td-center td-gradient">
                        <b>Spieler</b>
                    </td>
                    <td class="td-center td-gradient">
                        <div class="header-stack">
                            <span>Bietet</span>
                            <span class="arrow">⟺</span>
                            <span>Benöt.</span>
                        </div>
                    </td>
                    <td class="td-center td-gradient">
                        <b>Ankunft</b>
                    </td>
                    <td class="td-center td-gradient">
                        <b>Endet in</b>
                    </td>
                    <td class="td-center td-gradient" colspan="2">
                        <b>Gebühr</b>
                    </td>
                </tr>';

    foreach ($result as $row) {
        $map_x = $row["mapx"];
        $map_y = $row["mapy"];
        $is_my_offer = ($row["userid"] == $user->get_user_id());
        $remaining = $row["expires_at"] - time();
        $time_str = convert_sec_to_str($remaining, true);

        if ($is_my_offer) {
            $arrival_time_str = "-";
        } else {
            $seconds = $map->get_arrival_time($my_x, $my_y, $map_x, $map_y, -1, null, false, true);
            $arrival_time_str = convert_sec_to_str($seconds, true);
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
                    <td>
                        <div class='player-info-stack'>
                            <span class='p-name'>{$row["username"]}</span>
                            <span class='p-coords'>(<a href='#' data-on-click='mapJump' data-x='" . e($map_x) . "' data-y='" . e($map_y) . "'>$kingdom_coords</a>)</span>
                        </div>
                    </td>
                    <td class='td-center'>
                        <div class='trade-item-stack'>
                            <span>" . get_resource_icon($row["supply"]) . fnum($row["supplyvalue"]) . "</span>
                            <span class='trade-arrow'>&#10234;</span> 
                            <span>" . get_resource_icon($row["demand"]) . fnum($row["demandvalue"]) . "</span>
                        </div>
                    </td>
                    <td class='td-center'>$arrival_time_str</td>
                    <td class='td-center'>$time_str</td>
                    <td class='td-center'><span style='display: inline-block; white-space: nowrap;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " {$row["coins"]}</span></td>
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

$arrival_times_cache = [];

if ($other_kingdoms_res->num_rows > 0) {
    $view .= "<br><hr><br><div class='title-border'>Interner Ressourcentransport</div>";
    $view .= '<table class="table internal-transport-table">
                <form action="marketplace.php" method="GET">
                    <input type="hidden" name="send_own" value="1">
                    <tr>
                        <td>
                            <label for="target_k">Ziel: <small id="target-arrival-display" style=" opacity: 0.7; margin-left: 10px;"></small></label><br>
                            <select name="target_k" id="target_k" style="width: 100%; max-width: 300px;">';

    foreach ($other_kingdoms_res as $ok) {
        $seconds = $map->get_arrival_time($my_x, $my_y, $ok["mapx"], $ok["mapy"], $current_kingdom, null, false, true);
        $arrival_times_cache[$ok["id"]] = convert_sec_to_str($seconds, true);

        $view .= "<option value='{$ok["id"]}'>{$ok["kingdomname"]} ({$ok["mapx"]}:{$ok["mapy"]})</option>";
    }

    $view .= '      </select>
                </td>
                <td>
                    <label for="am">Menge:</label><br>
                    <input type="text" name="am" id="am" size="6" maxlength="7" style="width: 80px;" inputmode="numeric" pattern="[0-9]*" placeholder="0">
                    <select name="rt" id="rt" style="width: 110px;">
                        <option value="' . ResourceTypes::RESOURCE_TYPE_FOOD . '">Nahrung</option>
                        <option value="' . ResourceTypes::RESOURCE_TYPE_WOOD . '">Holz</option>
                        <option value="' . ResourceTypes::RESOURCE_TYPE_STONE . '">Stein</option>
                        <option value="' . ResourceTypes::RESOURCE_TYPE_GOLD . '">Gold</option>
                    </select>
                </td>
                <td style="text-align: center;">
                    <input type="submit" value="Senden" style="width: 150px;">
                </td>
            </tr>
        </form>
        </table>';

    $view .= "<div id='internal-arrival-data' data-times='" . json_encode($arrival_times_cache) . "'></div>";
}

$storage_info = [
    ResourceTypes::RESOURCE_TYPE_FOOD => ["cur" => $kingdom->get_kingdom_food(), "max" => $kingdom->get_kingdom_max_food()],
    ResourceTypes::RESOURCE_TYPE_WOOD => ["cur" => $kingdom->get_kingdom_wood(), "max" => $kingdom->get_kingdom_max_wood()],
    ResourceTypes::RESOURCE_TYPE_STONE => ["cur" => $kingdom->get_kingdom_stone(), "max" => $kingdom->get_kingdom_max_stone()],
    ResourceTypes::RESOURCE_TYPE_GOLD => ["cur" => $kingdom->get_kingdom_gold(), "max" => $kingdom->get_kingdom_max_gold()],
];

$market_config = [
    "base" => MARKET_BASE_FEE,
    "max_ratio" => MAX_MARKET_RATIO,
    "listing_fee_step" => MARKET_LISTING_FEE_STEP,
    "factors" => [
        ResourceTypes::RESOURCE_TYPE_FOOD => MARKET_FEE_MULTIPLIER_FOOD,
        ResourceTypes::RESOURCE_TYPE_WOOD => MARKET_FEE_MULTIPLIER_WOOD,
        ResourceTypes::RESOURCE_TYPE_STONE => MARKET_FEE_MULTIPLIER_STONE,
        ResourceTypes::RESOURCE_TYPE_GOLD => MARKET_FEE_MULTIPLIER_GOLD
    ]
];

$view .= '<div id="market-configs" 
               data-storage="' . e(json_encode($storage_info)) . '" 
               data-config="' . e(json_encode($market_config)) . '"></div>';

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