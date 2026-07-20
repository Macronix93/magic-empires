<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $query = "SELECT m.mapx, m.mapy, m.fieldtype, m.kingdomid, IFNULL(b.buildinglevel, 1) AS buildinglevel 
              FROM map m 
              LEFT JOIN buildings b ON m.kingdomid = b.kingdomid AND b.buildingid = 0
              ORDER BY m.mapy, m.mapx";
    $result = $db_instance->execute_query($query);

    $map_data = [];

    while ($row = $result->fetch_assoc()) {
        $map_data[] = [
            (int)$row["mapx"],
            (int)$row["mapy"],
            (int)$row["fieldtype"],
            (int)$row["kingdomid"],
            (int)$row["buildinglevel"]
        ];
    }

    header('Content-Type: application/json');

    echo json_encode($map_data);
} else {
    change_location("map.php");
}