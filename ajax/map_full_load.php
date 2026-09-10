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

    $query = "SELECT m.mapx, m.mapy, m.fieldtype, ft.fieldname,
              CASE 
                WHEN m.kingdomid = -2 AND (r.mapx IS NULL OR r.expires_at < UNIX_TIMESTAMP()) THEN -1
                WHEN m.kingdomid = -3 AND (mc.mapx IS NULL OR mc.expires_at < UNIX_TIMESTAMP()) THEN -1
                WHEN m.kingdomid = -4 AND (ak.mapx IS NULL OR ak.expires_at < UNIX_TIMESTAMP()) THEN -1
                ELSE m.kingdomid 
            END AS kingdomid, 
            COALESCE(k.username, ak.kingdom_name, '') as username,
            COALESCE(k.kingdomname, ak.kingdom_name, '') as kingdomname,
            COALESCE(b_tc.buildinglevel, ak.tc_level, 1) AS buildinglevel,
            u.ranking_points AS score,
            CASE WHEN m.kingdomid > 0 AND k.wallhp <= (
               (IFNULL(b_wall.buildinglevel, 1) * " . DEFAULT_WALL_HP . " + 
                IFNULL(t_wall.techlevel, 0) * " . RESEARCH_WALL_HP_INC . ") / 2
            ) THEN 1 ELSE 0 END as is_burning,
            IFNULL(mc.level, 0) AS monsterlevel,
            COALESCE(r.expires_at, mc.expires_at, ak.expires_at, 0) as expires_at,
            k.userid as owner_id,
            u.guildid,
            e_mov.my_troop_icon,
            CASE 
                WHEN k.userid = ? THEN 0
                WHEN e_mov.targetx IS NOT NULL THEN 1 
                ELSE 0 
            END as has_outgoing_event
          FROM map m 
          JOIN field_types ft ON m.fieldtype = ft.fieldid
          LEFT JOIN kingdoms k ON m.kingdomid = k.id
          LEFT JOIN users u ON k.userid = u.id
          LEFT JOIN buildings b_tc ON m.kingdomid = b_tc.kingdomid AND b_tc.buildingid = 0
          LEFT JOIN buildings b_wall ON m.kingdomid = b_wall.kingdomid AND b_wall.buildingid = 3
          LEFT JOIN techs t_wall ON m.kingdomid = t_wall.kingdomid AND t_wall.techid = " . TechTypes::TECH_TYPE_WALL_HP_INC . "
          LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
          LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
          LEFT JOIN abandoned_kingdoms ak ON m.mapx = ak.mapx AND m.mapy = ak.mapy
          LEFT JOIN (
              SELECT e.targetx, e.targety, 
                     SUBSTRING_INDEX(
                        GROUP_CONCAT(
                            sl.icon 
                            ORDER BY 
                                (CASE 
                                    WHEN sl.category IN (0,1,2) THEN 1
                                    WHEN sl.id = 15 THEN 2
                                    WHEN sl.id IN (11, 13) THEN 3
                                    WHEN sl.id IN (9, 10) THEN 4
                                    WHEN sl.id = 12 THEN 5
                                    ELSE 6 
                                 END) ASC,
                                st.soldiercount DESC
                        ), ',', 1
                     ) as my_troop_icon
              FROM events e
              JOIN sent_troops st ON e.eventid = st.eventid
              JOIN soldier_list sl ON st.soldierid = sl.id
              WHERE e.userid = ? AND e.actionid = ?
              GROUP BY e.targetx, e.targety
          ) e_mov ON e_mov.targetx = m.mapx AND e_mov.targety = m.mapy
          ORDER BY m.mapy, m.mapx";

    $result = $db_instance->execute_query($query, [
        $uid,
        $uid,
        ActionTypes::ACTION_SEND_TROOPS
    ]);

    $map_data = [];

    while ($row = $result->fetch_assoc()) {
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
            $row["my_troop_icon"] ?? "",
            (int)($row["guildid"] ?? -1),
            (int)($row["has_outgoing_event"] ?? 0)
        ];
    }

    $world_event_manager = new WorldEvent($db_instance);
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