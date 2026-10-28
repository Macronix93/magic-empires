<?php
require_once("../includes/core.php");
$user->check_user_login();
$uid = $user->get_user_id();

$kingdoms = $db_instance->execute_query("
    SELECT k.id, k.kingdomname, k.mapx, k.mapy, IFNULL(b.buildinglevel, 0) AS barracks_level
    FROM kingdoms k
    LEFT JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = ?
    WHERE k.userid = ? 
    ORDER BY k.created_at, k.id
", [BuildingTypes::BUILDING_BARRACKS, $uid])->fetch_all(MYSQLI_ASSOC);

$query = "
    SELECT 
        t.kingdom_id,
        t.soldierid,
        SUM(t.cnt) AS total_count,
        sl.id,
        sl.soldiername,
        sl.icon,
        sl.category
    FROM (
        SELECT kingdomid AS kingdom_id, soldierid, soldiercount AS cnt 
        FROM soldiers 
        WHERE kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?)
        UNION ALL
        SELECT source_kingdom_id AS kingdom_id, soldierid, soldiercount AS cnt 
        FROM sent_troops 
        WHERE source_kingdom_id IN (SELECT id FROM kingdoms WHERE userid = ?)
        UNION ALL
        SELECT source_kingdom_id AS kingdom_id, soldier_id AS soldierid, soldiercount AS cnt 
        FROM stationed_troops 
        WHERE owner_id = ?
        UNION ALL
        SELECT kingdom_id, soldier_id AS soldierid, soldiercount AS cnt 
        FROM mine_stationed_troops 
        WHERE user_id = ?
    ) t
    JOIN soldier_list sl ON t.soldierid = sl.id
    WHERE t.cnt > 0
    GROUP BY t.kingdom_id, t.soldierid, sl.id, sl.soldiername, sl.icon, sl.category
    ORDER BY sl.category, sl.id
";

$res_troops = $db_instance->execute_query($query, [$uid, $uid, $uid, $uid])->fetch_all(MYSQLI_ASSOC);

$global_troops = [];
$kingdom_troops = [];
$total_units_all = 0;
$total_units_per_k = [];
$kingdom_capacities = [];
$total_capacity_all = 0;

// Initialize all kingdoms and calculate capacity
foreach ($kingdoms as $k) {
    $kid = (int)$k["id"];
    $kingdom_troops[$kid] = [];
    $total_units_per_k[$kid] = 0;

    $b_lvl = (int)$k["barracks_level"];
    $cap = ($b_lvl <= 0)
            ? TROOP_LIMIT_BASE
            : (int)(TROOP_LIMIT_BASE + TROOP_LIMIT_FACTOR * pow($b_lvl, TROOP_LIMIT_EXPONENT));

    $kingdom_capacities[$kid] = $cap;
    $total_capacity_all += $cap;
}

foreach ($res_troops as $row) {
    $kid = (int)$row["kingdom_id"];
    $sid = (int)$row["soldierid"];
    $cnt = (int)$row["total_count"];

    // Global sum
    if (!isset($global_troops[$sid])) {
        $global_troops[$sid] = [
                "name" => $row["soldiername"],
                "icon" => $row["icon"],
                "category" => (int)$row["category"],
                "count" => 0
        ];
    }
    $global_troops[$sid]["count"] += $cnt;
    $total_units_all += $cnt;

    // Per kingdom
    if (isset($kingdom_troops[$kid])) {
        $kingdom_troops[$kid][] = [
                "name" => $row["soldiername"],
                "icon" => $row["icon"],
                "count" => $cnt
        ];
        $total_units_per_k[$kid] += $cnt;
    }
}

$global_limit_class = ($total_units_all > $total_capacity_all) ? "error" : "passed";
?>

<div id="modal-title-data" data-title="Gesamte Streitkräfte" data-width="700px" style="display: none;"></div>

<div style="padding: 10px; max-width: 680px; margin: 0 auto; text-align: left;">
    <div style="background: rgba(0,0,0,0.35); border: 1px solid var(--border-gold); border-radius: 5px; padding: 12px 15px; margin-bottom: 25px;">
        <div style="margin-bottom: 12px; font-size: 16px;">
            <b class="<?= $global_limit_class ?>">
                <?= fnum($total_units_all) ?> / <?= fnum($total_capacity_all) ?> Truppen
            </b>
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

    <div class="title-border">Aufteilung nach Königreichen</div>

    <div style="max-height: 600px; overflow-y: auto; border: 2px solid var(--box-header); box-shadow: 0 5px 15px rgba(0,0,0,0.5);">
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
                $c_link = "<a href='map.php?startx=$kx&starty=$ky' data-on-click='mapJump' data-x='$kx' data-y='$ky'>$kx:$ky</a>";
                $k_units = $kingdom_troops[$kid] ?? [];
                $k_total = $total_units_per_k[$kid] ?? 0;
                $k_cap = $kingdom_capacities[$kid] ?? TROOP_LIMIT_BASE;
                $cap_class = ($k_total > $k_cap) ? "error" : "";
                ?>
                <tr>
                    <td style="vertical-align: middle; padding: 10px;">
                        <div style="word-break: break-word;">
                            <b><?= $k_name ?></b> <small>(<?= $c_link ?>)</small>
                        </div>
                        <div style="font-size: 13px; opacity: 0.8; margin-top: 4px;">
                            Gesamt: <b class="<?= $cap_class ?>"><?= fnum($k_total) ?> / <?= fnum($k_cap) ?></b>
                        </div>
                    </td>
                    <td style="vertical-align: middle; padding: 10px;">
                        <?php if (!empty($k_units)): ?>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                <?php foreach ($k_units as $u): ?>
                                    <div class="unit-badge" title="<?= e($u["name"]) ?>" style="padding: 3px 6px;">
                                        <img src="images/icons/<?= e($u["icon"]) ?>.png"
                                             style="width: 20px; height: 20px; vertical-align: middle;" alt="">
                                        <b style="font-size: 12px;"><?= fnum($u["count"]) ?></b>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="font-size: 13px; opacity: 0.5;"><i>Keine Truppen vorhanden</i></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>