<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $uid = $user->get_user_id();

    $cache_file = sys_get_temp_dir() . "/me_map_cache_" . $uid . ".json";

    // 2 seconds cache
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 2) {
        header("Content-Type: application/json");
        readfile($cache_file);
        exit;
    }

    $now = time();

    $query = "SELECT m.mapx, m.mapy, m.fieldtype, ft.fieldname,
              CASE 
                WHEN m.kingdomid = " . MapFieldTypes::MAP_FIELD_RESOURCE_TILE . " AND (r.mapx IS NULL OR r.expires_at < $now) THEN " . MapFieldTypes::MAP_FIELD_EMPTY . "
                WHEN m.kingdomid = " . MapFieldTypes::MAP_FIELD_MONSTER_CAMP . " AND (mc.mapx IS NULL OR mc.expires_at < $now) THEN " . MapFieldTypes::MAP_FIELD_EMPTY . "
                WHEN m.kingdomid = " . MapFieldTypes::MAP_FIELD_ABANDONED_KINGDOM . " AND (ak.mapx IS NULL OR ak.expires_at < $now) THEN " . MapFieldTypes::MAP_FIELD_EMPTY . "
                WHEN m.kingdomid = " . MapFieldTypes::MAP_FIELD_MINE . " AND (mn.mapx IS NULL OR mn.expires_at < $now) THEN " . MapFieldTypes::MAP_FIELD_EMPTY . "
                ELSE m.kingdomid 
            END AS kingdomid, 
            COALESCE(k.username, ak.kingdom_name, u_mn.username, '') AS username,
            COALESCE(k.kingdomname, ak.kingdom_name, CONCAT('Mine Stufe ', mn.level), '') AS kingdomname,
            COALESCE(b_tc.buildinglevel, ak.tc_level, mn.level, 1) AS buildinglevel,
            u.ranking_points AS score,
            CASE WHEN m.kingdomid > 0 AND k.wallhp <= (
               (IFNULL(b_wall.buildinglevel, 1) * " . DEFAULT_WALL_HP . " + IFNULL(t_wall.techlevel, 0) * " . RESEARCH_WALL_HP_INC . ") / 2
            ) THEN 1 ELSE 0 END AS is_burning,
            IFNULL(mc.level, IFNULL(mn.level, 0)) AS monsterlevel,
            COALESCE(r.expires_at, mc.expires_at, ak.expires_at, mn.expires_at, 0) AS expires_at,
            COALESCE(k.userid, mn.claimed_user_id, 0) AS owner_id,
            COALESCE(u.guildid, mn.claimed_guild_id, -1) AS guildid,
            e_mov.my_troop_icon,
            CASE 
                WHEN k.userid = ? THEN 0
                WHEN e_mov.targetx IS NOT NULL THEN 1 
                ELSE 0 
            END AS has_outgoing_event,
            mn.work_done, mn.work_total, mn.max_troops,
            IFNULL(mst_agg.current_mine_troops, 0) AS current_mine_troops,
            IFNULL(mst_agg.current_mine_atk, 0) AS current_mine_atk,
            IFNULL(mst_agg.my_mine_troops, 0) AS my_mine_troops,
            mst_agg.my_mine_troop_icon
          FROM map m 
          JOIN field_types ft ON m.fieldtype = ft.fieldid
          LEFT JOIN kingdoms k ON m.kingdomid = k.id
          LEFT JOIN users u ON k.userid = u.id
          LEFT JOIN buildings b_tc ON m.kingdomid = b_tc.kingdomid AND b_tc.buildingid = " . BuildingTypes::BUILDING_TOWNCENTER . "
          LEFT JOIN buildings b_wall ON m.kingdomid = b_wall.kingdomid AND b_wall.buildingid = " . BuildingTypes::BUILDING_WALL . "
          LEFT JOIN techs t_wall ON m.kingdomid = t_wall.kingdomid AND t_wall.techid = " . TechTypes::TECH_TYPE_WALL_HP_INC . "
          LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
          LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
          LEFT JOIN abandoned_kingdoms ak ON m.mapx = ak.mapx AND m.mapy = ak.mapy
          LEFT JOIN mines mn ON m.mapx = mn.mapx AND m.mapy = mn.mapy
          LEFT JOIN users u_mn ON mn.claimed_user_id = u_mn.id
          LEFT JOIN (
              SELECT 
                  mst.mine_id,
                  SUM(mst.soldiercount) AS current_mine_troops,
                  SUM(mst.soldiercount * mst.unit_atk) AS current_mine_atk,
                  SUM(CASE WHEN mst.user_id = ? THEN mst.soldiercount ELSE 0 END) AS my_mine_troops,
                  SUBSTRING_INDEX(
                      GROUP_CONCAT(
                          CASE WHEN mst.user_id = ? THEN sl.icon ELSE NULL END
                          ORDER BY (CASE WHEN mst.user_id = ? THEN mst.soldiercount ELSE 0 END) DESC
                      ), ',', 1
                  ) AS my_mine_troop_icon
              FROM mine_stationed_troops mst
              JOIN soldier_list sl ON mst.soldier_id = sl.id
              GROUP BY mst.mine_id
          ) mst_agg ON mst_agg.mine_id = mn.id
          LEFT JOIN (
              SELECT e.targetx, e.targety, 
                     SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            sl.icon 
                            ORDER BY 
                                (CASE 
                                    WHEN sl.category IN (0,1,2) THEN 1
                                    WHEN sl.id = " . Soldiers::SOLDIER_RAM . " THEN 2
                                    WHEN sl.id IN (" . Soldiers::SOLDIER_THIEF . ", " . Soldiers::SOLDIER_RAIDER . ") THEN 3
                                    WHEN sl.id IN (" . Soldiers::SOLDIER_CONQUEROR . ", " . Soldiers::SOLDIER_SETTLER_WAGON . ") THEN 4
                                    WHEN sl.id = " . Soldiers::SOLDIER_SCOUT . " THEN 5
                                    ELSE 6 
                                 END) ASC,
                                st.soldiercount DESC
                        ), ',', 1
                     ) as my_troop_icon
              FROM events e
              JOIN sent_troops st ON e.eventid = st.eventid
              JOIN soldier_list sl ON st.soldierid = sl.id
              WHERE e.userid = ? AND e.actionid = " . ActionTypes::ACTION_SEND_TROOPS . "
              GROUP BY e.targetx, e.targety
          ) e_mov ON e_mov.targetx = m.mapx AND e_mov.targety = m.mapy
          ORDER BY m.mapy, m.mapx";

    $result = $db_instance->execute_query($query, [
        $uid,                  // has_outgoing_event k.userid check
        $uid,   // mst_agg my_mine_troops
        $uid,   // mst_agg my_mine_troop_icon CASE
        $uid,   // mst_agg my_mine_troop_icon ORDER BY
        $uid                   // e_mov e.userid
    ]);

    $map_data = [];

    while ($row = $result->fetch_assoc()) {
        $troop_icon_display = $row["my_troop_icon"] ?? "";
        if (empty($troop_icon_display) && (int)$row["kingdomid"] === MapFieldTypes::MAP_FIELD_MINE && (int)($row["my_mine_troops"] ?? 0) > 0) {
            $troop_icon_display = $row["my_mine_troop_icon"] ?? "";
        }

        $mine_info = null;
        if ((int)$row["kingdomid"] === MapFieldTypes::MAP_FIELD_MINE) {
            $w_done = (int)$row["work_done"];
            $w_total = max(1, (int)$row["work_total"]);
            $m_atk = (int)$row["current_mine_atk"];

            $max_rate = $w_total / MINE_MIN_DURATION_SECONDS;
            $rate_per_sec = min($m_atk * MINE_WORK_RATE_FACTOR, $max_rate);
            $rem_work = max(0, $w_total - $w_done);
            $est_seconds = ($rate_per_sec > 0) ? (int)ceil($rem_work / $rate_per_sec) : 0;

            $mine_info = [
                "w_done" => $w_done,
                "w_total" => $w_total,
                "cur_troops" => (int)$row["current_mine_troops"],
                "max_troops" => (int)$row["max_troops"],
                "my_troops" => (int)$row["my_mine_troops"],
                "est_seconds" => $est_seconds
            ];
        }

        $map_data[] = [
            (int)$row["mapx"],
            (int)$row["mapy"],
            (int)$row["fieldtype"],
            (int)$row["kingdomid"],
            (int)$row["buildinglevel"],
            (int)$row["is_burning"],
            (int)$row["monsterlevel"],
            $row["username"] ?? "",
            $row["kingdomname"] ?? "",
            (int)($row["score"] ?? 0),
            (int)($row["owner_id"] ?? 0),
            $row["fieldname"] ?? "",
            (int)($row["expires_at"] ?? 0),
            $troop_icon_display,
            (int)($row["guildid"] ?? -1),
            (int)($row["has_outgoing_event"] ?? 0),
            $mine_info
        ];
    }

    $world_event_manager = new WorldEvent();
    $active_event = $world_event_manager->get_active_event();

    $event_info = ["is_active" => false];

    if ($active_event) {
        $pool = $world_event_manager->get_monster_pool();
        $monster = $pool[$active_event["monster_index"]] ?? $pool[0];

        $event_info = [
            "is_active" => true,
            "type" => $active_event["event_type"],
            "current_hp" => (int)$active_event["current_hp"],
            "total_hp" => (int)$active_event["total_hp"],
            "end_time" => (int)$active_event["end_time"],
            "monster_name" => $monster["name"],
            "monster_icon" => $monster["icon"]
        ];
    }

    $response = [
        "map_data" => $map_data,
        "event_info" => $event_info
    ];

    $json = json_encode($response);
    file_put_contents($cache_file, $json);

    header("Content-Type: application/json");
    echo $json;
} else {
    change_location("map.php");
}