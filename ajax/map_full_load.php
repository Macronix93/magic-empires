<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $query = "SELECT m.mapx, m.mapy, m.fieldtype, 
            CASE 
                WHEN m.kingdomid = -2 AND (r.expires_at < UNIX_TIMESTAMP()) THEN -1
                WHEN m.kingdomid = -3 AND (mc.expires_at < UNIX_TIMESTAMP()) THEN -1
                ELSE m.kingdomid 
            END AS kingdomid, 
            k.username, k.kingdomname, u.score,
            IFNULL(b_tc.buildinglevel, 1) AS buildinglevel,
            CASE WHEN m.kingdomid > 0 AND k.wallhp <= (
               (IFNULL(b_wall.buildinglevel, 1) * " . DEFAULT_WALL_HP . " + 
                IFNULL(t_wall.techlevel, 0) * " . RESEARCH_WALL_HP_INC . ") / 2
            ) THEN 1 ELSE 0 END as is_burning,
            IFNULL(mc.level, 0) AS monsterlevel
          FROM map m 
          LEFT JOIN kingdoms k ON m.kingdomid = k.id
          LEFT JOIN users u ON k.userid = u.id
          LEFT JOIN buildings b_tc ON m.kingdomid = b_tc.kingdomid AND b_tc.buildingid = 0
          LEFT JOIN buildings b_wall ON m.kingdomid = b_wall.kingdomid AND b_wall.buildingid = 3
          LEFT JOIN techs t_wall ON m.kingdomid = t_wall.kingdomid AND t_wall.techid = " . TechTypes::TECH_TYPE_WALL_HP_INC . "
          LEFT JOIN monster_camps mc ON m.mapx = mc.mapx AND m.mapy = mc.mapy
          LEFT JOIN resource_tiles_data r ON m.mapx = r.mapx AND m.mapy = r.mapy
          ORDER BY m.mapy, m.mapx";
    $result = $db_instance->execute_query($query);

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
            (int)($row["userid"] ?? 0)
        ];
    }

    header('Content-Type: application/json');

    echo json_encode($map_data);
} else {
    change_location("map.php");
}