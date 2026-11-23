<?php
require_once("../includes/ajax_core.php");

$event_id = get_int("id");
if ($event_id <= 0) {
    echo show_error_box("Ungültige Alarm-ID.");
    exit;
}

$uid = $user->get_user_id();
$now = time();

$query = "
    SELECT e.eventid, e.arrivaltime, e.targetid, e.kingdomid AS source_id,
           k.kingdomname, IFNULL(t_intel.techlevel, 0) AS intel_level
    FROM events e
    JOIN kingdoms k ON e.targetid = k.id
    JOIN buildings b ON k.id = b.kingdomid AND b.buildingid = " . BuildingTypes::BUILDING_WATCHTOWER . "
    LEFT JOIN kingdom_boosts kb_wt ON k.id = kb_wt.kingdomid
        AND kb_wt.resource_type = " . ResourceTypes::RESOURCE_TYPE_WATCHTOWER . "
        AND kb_wt.ticks_remaining > 0
    LEFT JOIN techs t_intel ON k.id = t_intel.kingdomid
        AND t_intel.techid = " . TechTypes::TECH_TYPE_ARCANE_INTEL . "
    WHERE e.eventid = ?
      AND k.userid = ?
      AND e.userid != k.userid
      AND e.actionid = " . ActionTypes::ACTION_SEND_TROOPS . "
      AND e.is_processing = 0
      AND e.arrivaltime > ?
      AND e.arrivaltime <= (? + (b.buildinglevel * " . WATCHTOWER_DETECTION_PER_LEVEL . ") + IFNULL(kb_wt.boost_amount, 0))
      AND EXISTS (
          SELECT 1 FROM sent_troops st
          WHERE st.eventid = e.eventid
          HAVING MAX(st.soldierid NOT IN (" . Soldiers::SOLDIER_SCOUT . ", " . Soldiers::SOLDIER_THIEF . ")) = 1
              OR COUNT(DISTINCT st.soldierid) > 1
      )
    UNION ALL
    SELECT e.eventid, e.arrivaltime, e.targetid, e.kingdomid AS source_id,
           CONCAT('Mine (Stufe ', mn.level, ')') AS kingdomname, 1 AS intel_level
    FROM events e
    JOIN mines mn ON e.targetx = mn.mapx AND e.targety = mn.mapy
    JOIN mine_stationed_troops mst ON mn.id = mst.mine_id
    JOIN users u_sender ON e.userid = u_sender.id
    JOIN users u_target ON mst.user_id = u_target.id
    WHERE e.eventid = ?
      AND mst.user_id = ?
      AND mst.soldiercount > 0
      AND e.userid != ?
      AND (u_sender.guildid <= 0 OR u_sender.guildid != u_target.guildid)
      AND e.targetid = " . MapFieldTypes::MAP_FIELD_MINE . "
      AND e.actionid = " . ActionTypes::ACTION_SEND_TROOPS . "
      AND e.is_processing = 0
      AND e.arrivaltime > ?
      AND mn.level > 1
      AND EXISTS (
          SELECT 1 FROM sent_troops st
          WHERE st.eventid = e.eventid
            AND st.soldierid != " . Soldiers::SOLDIER_SCOUT . "
      )
    LIMIT 1
";

$row = $db_instance->execute_query($query, [
        $event_id, $uid, $now, $now,
        $event_id, $uid, $uid, $now
])->fetch_assoc();

if (!$row) {
    echo show_error_box("Dieser Angriff ist bereits eingetroffen oder nicht mehr verfügbar.");
    exit;
}

$wt_data = Conquest::build_watchtower_alert_data($row, $now);
$content = Messages::render_message_template($wt_data);
?>
<div id="modal-title-data" data-title="Wachturm-Meldung: <?= e($row["kingdomname"]) ?>" data-width="900px"
     style="display: none;"></div>
<div class="shared-report-modal">
    <div class="server-bubble" style="margin: 0; box-sizing: border-box; text-align: left;">
        <?= $content ?>
    </div>
</div>