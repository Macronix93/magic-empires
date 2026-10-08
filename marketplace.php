<?php
require_once("includes/core.php");

$result = $user->check_user_login_and_kingdom(BuildingTypes::BUILDING_MARKETPLACE);

$current_kingdom = $result['current_kingdom'];
$building = $result['building'];
$building_name = $building->get_building_name();
$kingdom = $result['kingdom'];

$market = new Marketplace($user, $kingdom);
$map = new Map($user);

$daily_info = $market->get_daily_trades_info();
$daily_trades_count = $daily_info["current"];
$max_trades = $daily_info["max"];
$max_capacity = $market->get_max_capacity();

$my_x = $kingdom->get_kingdom_map_x();
$my_y = $kingdom->get_kingdom_map_y();
$my_guild_id = $user->get_user_guild_id();
$in_guild = ($my_guild_id > 0);

if (isset($_GET["accept"])) {
    $res = $market->accept_offer((int)$_GET["accept"], $map);

    if (!$res["success"]) {
        $error = $res["error"];
    } else {
        $flash_box = show_passed_box("Handel akzeptiert! Die Karawanen sind unterwegs.<br>Ankunft in " . $res["arrival_str"]);

        $daily_trades_count++;
    }
}

if (isset($_GET["delete"])) {
    $err = $market->cancel_offer((int)$_GET["delete"]);

    if ($err) {
        $error = $err;
    } else {
        $flash_box = show_passed_box("Angebot gelöscht. Die Ressourcen wurden an das Ursprungskönigreich zurückgegeben.");

        $daily_trades_count = max(0, $daily_trades_count - 1);
    }
}

if (isset($_GET["sv"]) && isset($_GET["dv"]) && $_GET["sv"] !== "" && $_GET["dv"] !== "") {
    $is_guild_only = !empty($_GET["guild_only"]) && $in_guild;
    $err = $market->create_offer((int)$_GET["s"], (int)$_GET["sv"], (int)$_GET["d"], (int)$_GET["dv"], $is_guild_only);

    if ($err) {
        $error = $err;
    } else {
        $daily_trades_count++;
    }
}

if (isset($_GET["send_own"])) {
    $res = $market->send_internal_transport((int)($_GET["target_k"] ?? 0), $_GET["am"] ?? [], $map);

    if (!$res["success"]) {
        $error = $res["error"];
    } else {
        $flash_box = show_passed_box("Transport nach <b>" . e($res["target_name"]) . "</b> gestartet!<br>Ankunft in " . $res["arrival_str"]);

        $daily_trades_count++;
    }
}

// PAGINATION
$rows_per_page = MAX_MARKETPLACE_OFFERS_PER_PAGE;
$current_page = max(1, (int)($_GET["currentpage"] ?? 1));

$num_rows = $db_instance->execute_query(
    "SELECT COUNT(*) FROM marketplace WHERE (guild_id = 0 OR (guild_id > 0 AND guild_id = ?))",
    [$my_guild_id]
)->fetch_row()[0];

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
                <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                    <div style="display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                        <span>Gebühr:</span>' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . '<b id="live-listing-fee">1</b>
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px; white-space: nowrap;">
                        <span>Käufer:</span>' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . '<b id="live-buyer-fee">1</b>
                    </div>
                </div>
                <div id="fee_info_box" class="popupbox" style="text-align: left; min-width: 250px;">
                    <b>Verkäufer (Einstellgebühr):</b><br>
                    Wird sofort fällig. 1 Münze pro ' . fnum(MARKET_LISTING_FEE_STEP) . ' Ressourcen (Angebot).<br>
                    <i class="error">Wird bei Löschung NICHT erstattet. Erst bei Ablauf.</i><br><br>
                    <b>Käufer (Handelsgebühr):</b><br>
                    Wird in das Angebot eingerechnet und vom Käufer bei Annahme bezahlt.
                </div>
            </div>
        </td>
        <td style="width: 18%; text-align: center">
            <div class="market-submit-wrap">
                <input type="submit" value="Abschicken"/>
                ' . ($in_guild ? "
                <label style='display: inline-flex; align-items: center; gap: 6px; user-select: none;'>
                    <input type='checkbox' name='guild_only' id='guild_only' value='1'/>
                    <span>Nur Gilde</span>
                </label>" : '') . '
            </div>
        </td>
    </tr>
</form>
</table><br>';

$query = "
    SELECT m.*, k.mapx, k.mapy 
    FROM marketplace m 
    LEFT JOIN kingdoms k ON m.kingdomid = k.id 
    WHERE (m.guild_id = 0 OR (m.guild_id > 0 AND m.guild_id = ?))
    ORDER BY m.offerid DESC LIMIT ?, ?
";
/** @var mysqli_result $result */
$result = $db_instance->execute_query($query, [$my_guild_id, $offset, $rows_per_page]);

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
        $php_timer_expires = format_time_for_js($remaining);
        $time_str = "<span class='js-countdown' data-seconds='$remaining'>$php_timer_expires</span>";

        if ($is_my_offer) {
            $arrival_time_str = "-";
        } else {
            $seconds = $map->get_arrival_time($my_x, $my_y, $map_x, $map_y, -1, null, false, true);
            $arrival_time_str = convert_sec_to_str($seconds, true);
        }

        $kingdom_coords = "$map_x:$map_y";

        if ($is_my_offer) {
            $param = "delete";
            $btn_class = "btn-delete";
        } else {
            $param = "accept";
            $btn_class = "btn-accept";
        }

        $is_guild_deal = ((int)($row["guild_id"] ?? 0) > 0);
        $guild_icon = $is_guild_deal
            ? "<img src='images/icons/icon_guild.png' class='ressource-icons' alt='Gilde' title='Gildeninternes Angebot'>"
            : "";

        $text_build = "<form action='marketplace.php' method='GET' 
                            data-on-submit='checkMarket' 
                            data-res-type='" . (int)$row["supply"] . "' 
                            data-amount='" . (int)$row["supplyvalue"] . "'>
                            <input type='hidden' name='" . e($param) . "' value='" . e($row["offerid"]) . "'>
                            <input type='submit' class='" . e($btn_class) . "' value=''>
                        </form>";

        $view .= "<tr>
                    <td>
                        <div class='player-info-stack'>
                            <span class='p-name'>$guild_icon {$row["username"]}</span>
                            <span class='p-coords'>(<a href='#' data-on-click='mapJump' data-x='" . e($map_x) . "' data-y='" . e($map_y) . "'><small>$kingdom_coords</small></a>)</span>
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
    $view .= "<span style='opacity: 0.7;'>Es gibt derzeit keine Handelsangebote.</span>";
}

$other_kingdoms_res = $db_instance->execute_query("
    SELECT k.id, k.kingdomname, k.mapx, k.mapy, k.food, k.maxfood, k.wood, k.maxwood, k.stone, k.maxstone, k.gold, k.maxgold,
           (SELECT buildinglevel FROM buildings WHERE kingdomid = k.id AND buildingid = ?) as mkt_lvl
    FROM kingdoms k 
    WHERE k.userid = ? AND k.id != ?
    ORDER BY created_at, id",
    [BuildingTypes::BUILDING_MARKETPLACE, $user->get_user_id(), $current_kingdom]
);

$last_selected_target = isset($_GET["target_k"]) ? (int)$_GET["target_k"] : -1;
$target_kingdoms_data = [];
$arrival_times_cache = [];

if ($other_kingdoms_res->num_rows > 0) {
    $available_markets_count = 0;
    $options_html = "";
    $first_available_target_id = null;

    $rows_other = $other_kingdoms_res->fetch_all(MYSQLI_ASSOC);

    $other_ids = array_column($rows_other, "id");
    $placeholders = implode(',', array_fill(0, count($other_ids), '?'));
    $incoming_res_query = "
        SELECT kingdomid,
               SUM(loot_food + IF(buildingname != '" . TransportTypes::TRANSPORT_TYPE_INTERNAL . "' AND buildingid = " . ResourceTypes::RESOURCE_TYPE_FOOD . ", buildinglevel, 0)) AS inc_food,
               SUM(loot_wood + IF(buildingname != '" . TransportTypes::TRANSPORT_TYPE_INTERNAL . "' AND buildingid = " . ResourceTypes::RESOURCE_TYPE_WOOD . ", buildinglevel, 0)) AS inc_wood,
               SUM(loot_stone + IF(buildingname != '" . TransportTypes::TRANSPORT_TYPE_INTERNAL . "' AND buildingid = " . ResourceTypes::RESOURCE_TYPE_STONE . ", buildinglevel, 0)) AS inc_stone,
               SUM(loot_gold + IF(buildingname != '" . TransportTypes::TRANSPORT_TYPE_INTERNAL . "' AND buildingid = " . ResourceTypes::RESOURCE_TYPE_GOLD . ", buildinglevel, 0)) AS inc_gold
        FROM events
        WHERE actionid = ? AND kingdomid IN ($placeholders)
        GROUP BY kingdomid
    ";
    $inc_params = array_merge([ActionTypes::ACTION_RECEIVE_RESOURCES], $other_ids);
    $res_incoming = $db_instance->execute_query($incoming_res_query, $inc_params);
    $incoming_by_kingdom = [];
    while ($inc = $res_incoming->fetch_assoc()) {
        $incoming_by_kingdom[(int)$inc["kingdomid"]] = $inc;
    }

    foreach ($rows_other as $ok) {
        if (((int)$ok["mkt_lvl"] > 0) && $first_available_target_id === null) {
            $first_available_target_id = (int)$ok["id"];
        }
    }

    $active_target_id = ($last_selected_target > 0) ? $last_selected_target : $first_available_target_id;

    foreach ($rows_other as $ok) {
        $ok_id = (int)$ok["id"];
        $seconds = $map->get_arrival_time($my_x, $my_y, $ok["mapx"], $ok["mapy"], $current_kingdom, null, false, true);
        $arrival_times_cache[$ok_id] = convert_sec_to_str($seconds, true);

        $has_market = ((int)$ok["mkt_lvl"] > 0);
        $selected = ($ok_id == $last_selected_target) ? "selected" : "";

        if ($has_market) {
            $available_markets_count++;
            $options_html .= "<option value='$ok_id' $selected>{$ok["kingdomname"]} ({$ok["mapx"]}:{$ok["mapy"]})</option>";

            $inc = $incoming_by_kingdom[$ok_id] ?? [];
            $target_kingdoms_data[$ok_id] = [
                "name" => $ok["kingdomname"],
                "coords" => $ok["mapx"] . ":" . $ok["mapy"],
                "food" => (int)$ok["food"],
                "maxfood" => (int)$ok["maxfood"],
                "inc_food" => (int)($inc["inc_food"] ?? 0),
                "wood" => (int)$ok["wood"],
                "maxwood" => (int)$ok["maxwood"],
                "inc_wood" => (int)($inc["inc_wood"] ?? 0),
                "stone" => (int)$ok["stone"],
                "maxstone" => (int)$ok["maxstone"],
                "inc_stone" => (int)($inc["inc_stone"] ?? 0),
                "gold" => (int)$ok["gold"],
                "maxgold" => (int)$ok["maxgold"],
                "inc_gold" => (int)($inc["inc_gold"] ?? 0),
            ];
        } else {
            $options_html .= "<option value='$ok_id' disabled style='color: #888;'>{$ok["kingdomname"]} (Kein Marktplatz!)</option>";
        }
    }

    $is_disabled = ($available_markets_count === 0);
    $disabled_attr = $is_disabled ? "disabled" : "";

    $init_target = $target_kingdoms_data[$active_target_id] ?? null;
    $init_time_str = $arrival_times_cache[$active_target_id] ?? "";
    $init_time_display = !empty($init_time_str) ? "(Dauer: $init_time_str)" : "";

    $init_header_name = $init_target ? e($init_target["name"]) : "-";
    $init_food = $init_target ? format_num($init_target["food"] + $init_target["inc_food"]) . " / " . format_num($init_target["maxfood"]) : "-";
    $init_wood = $init_target ? format_num($init_target["wood"] + $init_target["inc_wood"]) . " / " . format_num($init_target["maxwood"]) : "-";
    $init_stone = $init_target ? format_num($init_target["stone"] + $init_target["inc_stone"]) . " / " . format_num($init_target["maxstone"]) : "-";
    $init_gold = $init_target ? format_num($init_target["gold"] + $init_target["inc_gold"]) . " / " . format_num($init_target["maxgold"]) : "-";

    $view .= "<br><br><hr><br><div class='title-border'>Interner Ressourcentransport</div>";
    $view .= '<table class="table internal-transport-table">
                <form action="marketplace.php" method="GET">
                    <input type="hidden" name="send_own" value="1">
                    <tr>
                        <td style="width: 30%;">
                            <label for="target_k">Ziel: <small id="target-arrival-display" style="opacity: 0.7;">' . $init_time_display . '</small></label><br>';

    if ($is_disabled) {
        $view .= '<select name="target_k" id="target_k" class="target-kingdom" disabled>
                    <option value="">-</option>
                  </select>
                  <br><small class="error">Keine Marktplätze verfügbar!</small>';
    } else {
        $view .= '<select name="target_k" id="target_k" class="target-kingdom">' . $options_html . '</select>';
        $view .= '
                <div class="mobile-internal-stock-box" id="target-k-stock-box">
                    <div class="mobile-stock-header" id="target-k-stock-header">
                        Vorräte in <b>' . $init_header_name . '</b>:
                    </div>
                    <div class="mobile-internal-stock-grid">
                        <div class="mobile-stock-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . ' <span id="target-stock-food">' . $init_food . '</span></div>
                        <div class="mobile-stock-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . ' <span id="target-stock-wood">' . $init_wood . '</span></div>
                        <div class="mobile-stock-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . ' <span id="target-stock-stone">' . $init_stone . '</span></div>
                        <div class="mobile-stock-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . ' <span id="target-stock-gold">' . $init_gold . '</span></div>
                    </div>
                </div>';
    }

    $view .= '</td>
                <td style="width: 50%; text-align: center; vertical-align: middle;">
                    <div class="internal-res-grid">
                        <div class="internal-res-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_FOOD) . ' <input type="text" name="am[0]" class="js-internal-res-input" size="6" maxlength="7" 
                            placeholder="0" inputmode="numeric" pattern="[0-9]*" style="width: 80px;" ' . $disabled_attr . '></div>
                        <div class="internal-res-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_WOOD) . ' <input type="text" name="am[1]" class="js-internal-res-input" size="6" maxlength="7" 
                            placeholder="0" inputmode="numeric" pattern="[0-9]*" style="width: 80px;" ' . $disabled_attr . '></div>
                        <div class="internal-res-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_STONE) . ' <input type="text" name="am[2]" class="js-internal-res-input" size="6" maxlength="7" 
                            placeholder="0" inputmode="numeric" pattern="[0-9]*" style="width: 80px;" ' . $disabled_attr . '></div>
                        <div class="internal-res-item">' . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . ' <input type="text" name="am[3]" class="js-internal-res-input" size="6" maxlength="7" 
                            placeholder="0" inputmode="numeric" pattern="[0-9]*" style="width: 80px;" ' . $disabled_attr . '></div>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; margin-top: 20px; flex-direction: column;">
                        <div id="internal-sum-display" style="font-size: 12px; font-weight: bold;">0 / ' . fnum($max_capacity) . '</div>
                        <input type="submit" id="internal-submit" value="Senden" style="width: 150px;" disabled>
                    </div>
                </td>
            </tr>
        </form>
      </table>';

    $view .= "<div id='internal-arrival-data' data-times='" . json_encode($arrival_times_cache) . "' data-stocks='" . e(json_encode($target_kingdoms_data)) . "'></div>";
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
    "max_capacity_per_offer" => $max_capacity,
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
$script_files = ["marketplace", "userinfo", "timer"];

include("layout/base.php");