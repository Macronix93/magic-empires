<?php
require_once("../includes/core.php");

$user->check_user_login();
$uid = $user->get_user_id();

// Get kingdoms of user (+ shrine alignment)
$kingdoms = $db_instance->execute_query("
    SELECT k.id, k.kingdomname, k.mapx, k.mapy, k.alignment,
           IFNULL(b.buildinglevel, 0) AS barracks_level,
           IFNULL(t.techlevel, 0) AS barracks_expansion_level
    FROM kingdoms k 
    LEFT JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = ?
    LEFT JOIN techs t ON k.id = t.kingdomid AND t.techid = ?
    WHERE k.userid = ? 
    ORDER BY k.created_at, k.id
", [BuildingTypes::BUILDING_BARRACKS, TechTypes::TECH_TYPE_BARRACKS_EXPANSION, $uid])->fetch_all(MYSQLI_ASSOC);

$k_ids = array_map("intval", array_column($kingdoms, "id"));
$k_ids_str = !empty($k_ids) ? implode(',', $k_ids) : '0';

// Military and shrine techs
$k_techs = [];
if (!empty($k_ids)) {
    $res_techs = $db_instance->execute_query("
        SELECT kingdomid, techid, techlevel 
        FROM techs 
        WHERE kingdomid IN ($k_ids_str) 
          AND techid IN (?, ?, ?, ?, ?, ?, ?)
    ", [
            TechTypes::TECH_TYPE_BLADES,
            TechTypes::TECH_TYPE_SHIELDWALL,
            TechTypes::TECH_TYPE_LANCE_RIDING,
            TechTypes::TECH_TYPE_CUIRASS,
            TechTypes::TECH_TYPE_ARROWHEADS,
            TechTypes::TECH_TYPE_DOUBLET,
            TechTypes::TECH_TYPE_ANCESTRAL_RITES
    ]);
    while ($t = $res_techs->fetch_assoc()) {
        $k_techs[(int)$t["kingdomid"]][(int)$t["techid"]] = (int)$t["techlevel"];
    }
}

// Get all troops in one query (+ atk and def)
$query = "
    SELECT 
        t.kingdom_id, t.type, t.soldierid, SUM(t.cnt) AS total_count,
        sl.id, sl.soldiername, sl.icon, sl.category, sl.attack, sl.defense, t.owner_name
    FROM (
        SELECT kingdomid AS kingdom_id, soldierid, soldiercount AS cnt, 'garrison' AS type, '' AS owner_name FROM soldiers 
        WHERE kingdomid IN ($k_ids_str)
        UNION ALL
        SELECT source_kingdom_id AS kingdom_id, soldierid, soldiercount AS cnt, 'away' AS type, '' AS owner_name FROM sent_troops 
        WHERE source_kingdom_id IN ($k_ids_str)
        UNION ALL
        SELECT source_kingdom_id AS kingdom_id, soldier_id AS soldierid, soldiercount AS cnt, 'away' AS type, '' AS owner_name FROM stationed_troops 
        WHERE owner_id = ?
        UNION ALL
        SELECT kingdom_id, soldier_id AS soldierid, soldiercount AS cnt, 'away' AS type, '' AS owner_name FROM mine_stationed_troops 
        WHERE user_id = ?
        UNION ALL
        SELECT e.targetid AS kingdom_id, st.soldierid, st.soldiercount AS cnt, 'incoming_station' AS type, '' AS owner_name FROM events e
        JOIN sent_troops st ON e.eventid = st.eventid
        WHERE e.userid = ? AND e.actionid = ? AND e.targetid IN ($k_ids_str) AND e.kingdomid != e.targetid
        UNION ALL
        SELECT st.target_kingdom_id AS kingdom_id, st.soldier_id AS soldierid, st.soldiercount AS cnt, 'support' AS type, u.username AS owner_name 
        FROM stationed_troops st
        JOIN users u ON st.owner_id = u.id
        WHERE st.target_kingdom_id IN ($k_ids_str) AND st.owner_id != ?
    ) t
    JOIN soldier_list sl ON t.soldierid = sl.id
    WHERE t.cnt > 0
    GROUP BY t.kingdom_id, t.type, t.owner_name, t.soldierid, sl.id, sl.soldiername, sl.icon, sl.category, sl.attack, sl.defense
    ORDER BY sl.category, sl.id
";

$res_troops = $db_instance->execute_query($query, [
        $uid,                            // owner_id (stationed_troops away)
        $uid,                            // user_id (mines away)
        $uid,                            // e.userid (incoming stationing)
        ActionTypes::ACTION_SEND_TROOPS, // e.actionid
        $uid                             // owner_id != ? (support)
])->fetch_all(MYSQLI_ASSOC);

$global_troops = [];
$kingdom_troops = [];
$support_troops_by_k = [];
$support_totals_by_k = [];
$soldier_meta = [];
$total_units_all = 0;
$total_away_all = 0;
$total_units_per_k = [];
$away_by_kingdom = [];
$kingdom_capacities = [];
$total_capacity_all = 0;
$incoming_station_by_k = [];
$incoming_station_totals = [];

foreach ($kingdoms as $k) {
    $kid = (int)$k["id"];
    $kingdom_troops[$kid] = [];
    $support_troops_by_k[$kid] = [];
    $support_totals_by_k[$kid] = 0;
    $total_units_per_k[$kid] = 0;
    $away_by_kingdom[$kid] = 0;
    $incoming_station_by_k[$kid] = [];
    $incoming_station_totals[$kid] = 0;

    $b_lvl = (int)$k["barracks_level"];
    $exp_lvl = (int)$k["barracks_expansion_level"];
    $cap = ($b_lvl > 0) ? (int)(TROOP_LIMIT_BASE + TROOP_LIMIT_FACTOR * pow($b_lvl, TROOP_LIMIT_EXPONENT)) + ($exp_lvl * BARRACKS_EXPANSION_CAPACITY_PER_LEVEL) : TROOP_LIMIT_BASE;
    $kingdom_capacities[$kid] = $cap;
    $total_capacity_all += $cap;
}

foreach ($res_troops as $row) {
    $kid = (int)$row["kingdom_id"];
    $type = $row["type"];
    $sid = (int)$row["soldierid"];
    $cnt = (int)$row["total_count"];
    $sname = $row["soldiername"];
    $icon = $row["icon"];
    $category = (int)$row["category"];

    if (!isset($soldier_meta[$sid])) {
        $soldier_meta[$sid] = [
                "attack" => (int)$row["attack"],
                "defense" => (int)$row["defense"],
                "category" => $category
        ];
    }

    if ($type === "incoming_station") {
        if (!isset($incoming_station_by_k[$kid])) {
            $incoming_station_by_k[$kid] = [];
            $incoming_station_totals[$kid] = 0;
        }

        $incoming_station_by_k[$kid][] = [
                "id" => $sid,
                "name" => $sname,
                "icon" => $icon,
                "count" => $cnt
        ];

        $incoming_station_totals[$kid] += $cnt;
    } elseif ($type === "support") {
        $owner_name = $row["owner_name"] ?: "Verbündeter";

        if (!isset($support_troops_by_k[$kid][$owner_name])) {
            $support_troops_by_k[$kid][$owner_name] = [
                    "total" => 0,
                    "units" => []
            ];
        }

        if (!isset($support_troops_by_k[$kid][$owner_name]["units"][$sid])) {
            $support_troops_by_k[$kid][$owner_name]["units"][$sid] = [
                    "id" => $sid,
                    "name" => $sname,
                    "icon" => $icon,
                    "count" => 0
            ];
        }

        $support_troops_by_k[$kid][$owner_name]["units"][$sid]["count"] += $cnt;
        $support_troops_by_k[$kid][$owner_name]["total"] += $cnt;
        $support_totals_by_k[$kid] += $cnt;
    } else {
        if (!isset($global_troops[$sid])) {
            $global_troops[$sid] = [
                    "name" => $sname,
                    "icon" => $icon,
                    "category" => $category,
                    "count" => 0
            ];
        }
        $global_troops[$sid]["count"] += $cnt;
        $total_units_all += $cnt;

        if ($type === "away") {
            $away_by_kingdom[$kid] = ($away_by_kingdom[$kid] ?? 0) + $cnt;
            $total_away_all += $cnt;
        }

        if (!isset($kingdom_troops[$kid][$sid])) {
            $kingdom_troops[$kid][$sid] = [
                    "name" => $sname,
                    "icon" => $icon,
                    "count" => 0
            ];
        }
        $kingdom_troops[$kid][$sid]["count"] += $cnt;
        $total_units_per_k[$kid] += $cnt;
    }
}

// Battle power per kingdom
$k_powers = [];
$global_power = [
        "total_atk" => 0, "total_def" => 0,
        "pure_base_atk" => 0, "pure_base_def" => 0,
        "total_smithy_atk" => 0, "total_smithy_def" => 0,
        "total_shrine_atk" => 0,
        "cat_atk" => [
                SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
                SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
                SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
                SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
        ],
        "cat_def" => [
                SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
                SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
                SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
                SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
        ]
];

foreach ($kingdoms as $k) {
    $kid = (int)$k["id"];
    $inf_a = ($k_techs[$kid][TechTypes::TECH_TYPE_BLADES] ?? 0) * SMITHY_INF_ATK_BONUS;
    $inf_d = ($k_techs[$kid][TechTypes::TECH_TYPE_SHIELDWALL] ?? 0) * SMITHY_INF_DEF_BONUS;
    $cav_a = ($k_techs[$kid][TechTypes::TECH_TYPE_LANCE_RIDING] ?? 0) * SMITHY_CAV_ATK_BONUS;
    $cav_d = ($k_techs[$kid][TechTypes::TECH_TYPE_CUIRASS] ?? 0) * SMITHY_CAV_DEF_BONUS;
    $arc_a = ($k_techs[$kid][TechTypes::TECH_TYPE_ARROWHEADS] ?? 0) * SMITHY_ARC_ATK_BONUS;
    $arc_d = ($k_techs[$kid][TechTypes::TECH_TYPE_DOUBLET] ?? 0) * SMITHY_ARC_DEF_BONUS;

    $k_align = (int)($k["alignment"] ?? 0);
    $rites_lvl = $k_techs[$kid][TechTypes::TECH_TYPE_ANCESTRAL_RITES] ?? 0;
    $shrine_atk_mult = 1.0;
    if ($k_align === AlignmentTypes::ALIGN_WAR) {
        $shrine_atk_mult += (SHRINE_WAR_DEFAULT_BONUS + ($rites_lvl * SHRINE_TECH_STEP));
    }

    // All troops available in the kingdom (own + support)
    $units_to_calc = [];
    foreach (($kingdom_troops[$kid] ?? []) as $sid => $u) {
        $units_to_calc[$sid] = ($units_to_calc[$sid] ?? 0) + $u["count"];
    }
    foreach (($support_troops_by_k[$kid] ?? []) as $sup_data) {
        foreach ($sup_data["units"] as $sid => $su) {
            $units_to_calc[$sid] = ($units_to_calc[$sid] ?? 0) + $su["count"];
        }
    }

    $stat = [
            "total_atk" => 0, "total_def" => 0,
            "pure_base_atk" => 0, "pure_base_def" => 0,
            "total_smithy_atk" => 0, "total_smithy_def" => 0,
            "total_shrine_atk" => 0,
            "cat_atk" => [
                    SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
                    SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
                    SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
                    SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
            ],
            "cat_def" => [
                    SoldierTypes::SOLDIER_TYPE_INFANTRY => 0,
                    SoldierTypes::SOLDIER_TYPE_CAVALRY => 0,
                    SoldierTypes::SOLDIER_TYPE_ARCHERS => 0,
                    SoldierTypes::SOLDIER_TYPE_SPECIAL => 0
            ]
    ];

    foreach ($units_to_calc as $sid => $sol_count) {
        if ($sol_count <= 0 || !isset($soldier_meta[$sid])) continue;
        $base_atk = $soldier_meta[$sid]["attack"];
        $base_def = $soldier_meta[$sid]["defense"];
        $cat = $soldier_meta[$sid]["category"];

        $b_atk = match ($cat) {
            SoldierTypes::SOLDIER_TYPE_INFANTRY => $inf_a,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => $cav_a,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => $arc_a,
            default => 0
        };
        $b_def = match ($cat) {
            SoldierTypes::SOLDIER_TYPE_INFANTRY => $inf_d,
            SoldierTypes::SOLDIER_TYPE_CAVALRY => $cav_d,
            SoldierTypes::SOLDIER_TYPE_ARCHERS => $arc_d,
            default => 0
        };

        $unit_atk_with_shrine = (int)round($base_atk * $shrine_atk_mult);
        $unit_shrine_gain = $unit_atk_with_shrine - $base_atk;

        $final_atk = $sol_count * ($unit_atk_with_shrine + $b_atk);
        $final_def = $sol_count * ($base_def + $b_def);
        $stat["pure_base_atk"] += ($sol_count * $base_atk);
        $stat["pure_base_def"] += ($sol_count * $base_def);
        $stat["total_smithy_atk"] += ($sol_count * $b_atk);
        $stat["total_smithy_def"] += ($sol_count * $b_def);
        $stat["total_shrine_atk"] += ($sol_count * $unit_shrine_gain);
        $stat["total_atk"] += $final_atk;
        $stat["total_def"] += $final_def;

        if (isset($stat["cat_atk"][$cat])) {
            $stat["cat_atk"][$cat] += $final_atk;
            $stat["cat_def"][$cat] += $final_def;
        }
    }

    $k_powers[$kid] = $stat;

    // Add to global values
    $global_power["total_atk"] += $stat["total_atk"];
    $global_power["total_def"] += $stat["total_def"];
    $global_power["pure_base_atk"] += $stat["pure_base_atk"];
    $global_power["pure_base_def"] += $stat["pure_base_def"];
    $global_power["total_smithy_atk"] += $stat["total_smithy_atk"];
    $global_power["total_smithy_def"] += $stat["total_smithy_def"];
    $global_power["total_shrine_atk"] += $stat["total_shrine_atk"];
    for ($c = 0; $c <= 3; $c++) {
        $global_power["cat_atk"][$c] += $stat["cat_atk"][$c];
        $global_power["cat_def"][$c] += $stat["cat_def"][$c];
    }
}

// Helper function for stat rendering
$render_power_stat = function (string $type, int $total, int $pure_base, int $smithy_bonus, int $shrine_bonus, array $cat_vals, string $id_suffix): string {
    $is_atk = ($type === "atk");
    $icon = get_resource_icon($is_atk ? ResourceTypes::RESOURCE_TYPE_ATTACK : ResourceTypes::RESOURCE_TYPE_DEFENSE);
    $title = $is_atk ? "Angriffs-Bonus:" : "Verteidigungs-Bonus:";
    $has_bonus = $is_atk ? ($smithy_bonus > 0 || $shrine_bonus > 0) : ($smithy_bonus > 0);
    $val_class = $has_bonus ? "passed" : "";
    $pop_id = "pop_{$type}_$id_suffix";

    $render_split_row = function (string $label, int $val): string {
        if ($val <= 0) return "";
        return "<div class='split-content' style='gap: 15px;'><span>$label:</span>" . fnum($val, true) . "</div>";
    };

    $cat_rows = $render_split_row("Infanterie", $cat_vals[SoldierTypes::SOLDIER_TYPE_INFANTRY])
            . $render_split_row("Kavallerie", $cat_vals[SoldierTypes::SOLDIER_TYPE_CAVALRY])
            . $render_split_row("Schützen", $cat_vals[SoldierTypes::SOLDIER_TYPE_ARCHERS])
            . $render_split_row("Spezial", $cat_vals[SoldierTypes::SOLDIER_TYPE_SPECIAL]);

    return "
    <span class='popup $val_class' id='$pop_id' style='display: inline-flex; align-items: center; gap: 4px;'>
        $icon <span>" . fnum($total, false, true) . "</span>
        <div id='{$pop_id}_box' class='popupbox' style='text-align: left; min-width: 220px;'>
            <b>$title</b>
            <div class='split-content' style='gap: 15px;'><span>Basis:</span><span>" . fnum($pure_base, true) . "</span></div>
            " . ($smithy_bonus > 0 ? "<div class='split-content passed' style='gap: 15px;'><span>Schmiede:</span><span>+" . fnum($smithy_bonus, true) . "</span></div>" : "") . "
            " . ($shrine_bonus > 0 ? "<div class='split-content passed' style='gap: 15px;'><span>Schrein:</span><span>+" . fnum($shrine_bonus, true) . "</span></div>" : "") . "
            <hr style='margin: 6px 0; border: 0; border-top: 1px solid rgba(212, 175, 55, 0.4);'>
            <b>Gattungen:</b>
            $cat_rows
        </div>
    </span>";
};

$global_limit_class = ($total_capacity_all > 0 && $total_units_all > $total_capacity_all) ? "error" : "passed";
?>

<div id="modal-title-data" data-title="Gesamte Streitkräfte" data-width="700px" style="display: none;"></div>

<div style="padding: 10px; max-width: 680px; max-height: 1000px; margin: 0 auto; text-align: left;">
    <div class="all-troops-sum-box">
        <div class="all-troops-sum-text">
            <b class="<?= $global_limit_class ?>">
                <?= fnum($total_units_all) ?> / <?= fnum($total_capacity_all) ?> Truppen<br>
                Unterwegs: <?= fnum($total_away_all) ?>
            </b>
        </div>

        <div class="all-troops-sum-stats">
            <?= $render_power_stat("atk", $global_power["total_atk"], $global_power["pure_base_atk"], $global_power["total_smithy_atk"], $global_power["total_shrine_atk"], $global_power["cat_atk"], "global") ?>
            <?= $render_power_stat("def", $global_power["total_def"], $global_power["pure_base_def"], $global_power["total_smithy_def"], 0, $global_power["cat_def"], "global") ?>
        </div>

        <?php if (!empty($global_troops)): ?>
            <div style="display: flex; flex-wrap: wrap; gap: 6px; justify-content: center;">
                <?php foreach ($global_troops as $u): ?>
                    <div class="unit-badge" title="<?= e($u["name"]) ?>">
                        <img src="images/icons/<?= e($u["icon"]) ?>.png" class="ressource-icons" alt="">
                        <b><?= fnum($u["count"]) ?></b>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align: center; opacity: 0.6;"><i>Keine Truppen in deinem gesamten Reich vorhanden.</i>
            </div>
        <?php endif; ?>
    </div>

    <div class="all-troops-table">
        <table class="table" style="width: 100%; margin: 0; border: none; box-shadow: none;">
            <colgroup>
                <col style="width: 44%;">
                <col style="width: 56%;">
            </colgroup>
            <thead>
            <tr>
                <td class="td-gradient td-center"><b>Königreich</b></td>
                <td class="td-gradient td-center"><b>Anzahl Truppen</b></td>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($kingdoms as $k):
                $kid = (int)$k["id"];
                $k_name = e($k["kingdomname"]);
                $kx = (int)$k["mapx"];
                $ky = (int)$k["mapy"];
                $k_link = "<a href='map.php?startx=$kx&starty=$ky' data-on-click='mapJump' data-x='$kx' data-y='$ky'>" . e($k["kingdomname"]) . "</a>";

                $k_units = array_values($kingdom_troops[$kid] ?? []);
                $k_support_groups = $support_troops_by_k[$kid] ?? [];
                $support_total = $support_totals_by_k[$kid] ?? 0;

                $k_total = $total_units_per_k[$kid] ?? 0;
                $k_cap = $kingdom_capacities[$kid] ?? TROOP_LIMIT_BASE;
                $k_away = $away_by_kingdom[$kid] ?? 0;
                $cap_class = ($k_total > $k_cap) ? "error" : "";
                $cap_display = fnum($k_total) . " / " . fnum($k_cap);

                $inc_count = $incoming_station_totals[$kid] ?? 0;
                $inc_troops = $incoming_station_by_k[$kid] ?? [];
                $inc_display = "";
                if ($inc_count !== 0) {
                    $pop_id = "pop_inc_station_" . $kid;
                    $badges_html = "<div style='display: flex; flex-wrap: wrap; gap: 4px; justify-content: center;'>";

                    foreach ($inc_troops as $it) {
                        $badges_html .= "
                                <div class='unit-badge' title='" . e($it["name"]) . "' style='padding: 2px 5px;'>
                                    <img src='images/icons/" . e($it["icon"]) . ".png' class='ressource-icons' alt=''>
                                    <b>" . fnum($it["count"]) . "</b>
                                </div>";
                    }

                    $badges_html .= "</div>";
                    $inc_display = " <span class='popup' id='$pop_id' style='color: var(--link-color); font-weight: bold;'>(" . fnum($inc_count) . ")
                            <div id='{$pop_id}_box' class='popupbox' style='text-align: left; min-width: 140px;'><b>Stationierung im Anmarsch:</b><br>$badges_html</div></span>";
                }

                $support_info_text = ($support_total > 0) ? "<br>Hilfe: <b>" . fnum($support_total) . "</b>" : "";
                $kp = $k_powers[$kid] ?? ["total_atk" => 0, "total_def" => 0, "pure_base_atk" => 0, "pure_base_def" => 0, "total_smithy_atk" => 0, "total_smithy_def" => 0, "total_shrine_atk" => 0, "cat_atk" => [0, 0, 0, 0], "cat_def" => [0, 0, 0, 0]];
                ?>
                <tr>
                    <td style="vertical-align: middle; padding: 10px;">
                        <div style="word-break: break-word; margin-bottom: 4px;">
                            <b class="all-troops-kingdom-name"><?= $k_link ?></b>
                        </div>

                        <div class="all-troops-stats-per-kingdom">
                            <?= $render_power_stat("atk", $kp["total_atk"], $kp["pure_base_atk"], $kp["total_smithy_atk"], $kp["total_shrine_atk"], $kp["cat_atk"], 'k_' . $kid) ?>
                            <?= $render_power_stat("def", $kp["total_def"], $kp["pure_base_def"], $kp["total_smithy_def"], 0, $kp["cat_def"], 'k_' . $kid) ?>
                        </div>

                        <div class="all-troops-count">
                            Gesamt: <b class="<?= $cap_class ?>"><?= $cap_display ?></b><?= $inc_display ?><br>
                            Unterwegs: <b><?= fnum($k_away) ?></b><?= $support_info_text ?>
                        </div>
                    </td>
                    <td style="vertical-align: middle; padding: 10px;">
                        <?php if (!empty($k_units)): ?>
                            <div class="all-troops-unit-list">
                                <?php foreach ($k_units as $u): ?>
                                    <div class="unit-badge" title="<?= e($u["name"]) ?>">
                                        <img src="images/icons/<?= e($u["icon"]) ?>.png" alt="">
                                        <b><?= fnum($u["count"]) ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <small style="opacity: 0.5;"><i>Keine eigenen Truppen</i></small>
                        <?php endif; ?>

                        <?php if (!empty($k_support_groups)): ?>
                            <?php foreach ($k_support_groups as $supporter_name => $sup_data): ?>
                                <hr>
                                <div class="all-troops-supporter">
                                    Unterstützung <?= e($supporter_name) ?> (<?= fnum($sup_data["total"]) ?>):
                                </div>
                                <div class="all-troops-unit-list">
                                    <?php foreach ($sup_data["units"] as $su): ?>
                                        <div class="unit-badge"
                                             title="<?= e($su["name"]) ?> (von <?= e($supporter_name) ?>)"
                                             style="border-color: rgba(52, 152, 219, 0.5); background: rgba(52, 152, 219, 0.12);">
                                            <img src="images/icons/<?= e($su["icon"]) ?>.png" alt="">
                                            <b><?= fnum($su["count"]) ?></b>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>