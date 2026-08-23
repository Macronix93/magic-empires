<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $query = "SELECT m.mapx, m.mapy, m.fieldtype, ft.fieldname,
              CASE 
                WHEN m.kingdomid = -2 AND (r.mapx IS NULL OR r.expires_at < UNIX_TIMESTAMP()) THEN -1
                WHEN m.kingdomid = -3 AND (mc.mapx IS NULL OR mc.expires_at < UNIX_TIMESTAMP()) THEN -1
                ELSE m.kingdomid 
            END AS kingdomid, 
            k.username, k.kingdomname, u.ranking_points AS score,
            IFNULL(b_tc.buildinglevel, 1) AS buildinglevel,
            CASE WHEN m.kingdomid > 0 AND k.wallhp <= (
               (IFNULL(b_wall.buildinglevel, 1) * " . DEFAULT_WALL_HP . " + 
                IFNULL(t_wall.techlevel, 0) * " . RESEARCH_WALL_HP_INC . ") / 2
            ) THEN 1 ELSE 0 END as is_burning,
            IFNULL(mc.level, 0) AS monsterlevel,
            COALESCE(r.expires_at, mc.expires_at, 0) as expires_at,
            k.userid as owner_id,
            e_mov.my_troop_icon
          FROM map m 
          JOIN field_types ft ON m.fieldtype = ft.fieldid
          LEFT JOIN kingdoms k ON m.kingdomid = k.id
          LEFT JOIN users u ON k.userid = u.id
          LEFT JOIN buildings b_tc ON m.kingdomid = b_tc.kingdomid AND b_tc.buildingid = 0
          LEFT JOIN buildings b_wall ON m.kingdomid = b_wall.kingdomid AND b_wall.buildingid = 3
          LEFT JOIN techs t_wall ON m.kingdomid = t_wall.kingdomid AND t_wall.techid = " . TechTypes::TECH_TYPE_WALL_HP_INC . "
          LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
          LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
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
    $result = $db_instance->execute_query($query, [$user->get_user_id(), ActionTypes::ACTION_SEND_TROOPS]);

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
            $row["my_troop_icon"] ?? ""
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

    header("Content-Type: application/json");

    echo json_encode($response);
} else {
    change_location("map.php");
}