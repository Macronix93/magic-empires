<?php
require_once("includes/core.php");

check_user_login($user);

$categories = [
    "ranking" => [
        "label" => "Weltrangliste",
        "title" => "Höchste Punktzahl",
        "limit" => 5,
        "query" => "SELECT username, id as uid, ranking_points as val 
                    FROM users 
                    WHERE status = 1 AND ranking_points > 0 
                    ORDER BY val DESC LIMIT 10"
    ],
    "monster" => [
        "label" => "Monster",
        "title" => "Besiegte Monster",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, s.monster_kills as val FROM player_stats s JOIN users u ON s.userid = u.id WHERE s.monster_kills > 0 ORDER BY val DESC LIMIT 20"
    ],
    "loot" => [
        "label" => "Plünderung",
        "title" => "Erbeutete Ressourcen",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, s.resources_looted as val FROM player_stats s JOIN users u ON s.userid = u.id WHERE s.resources_looted > 0 ORDER BY val DESC LIMIT 20"
    ],
    "event" => [
        "label" => "Events",
        "title" => "Event-Schaden",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, s.event_damage_total as val FROM player_stats s JOIN users u ON s.userid = u.id WHERE s.event_damage_total > 0 ORDER BY val DESC LIMIT 20"
    ],
    "expansion" => [
        "label" => "Königreiche",
        "title" => "Anzahl Königreiche",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, COUNT(k.id) as val FROM users u JOIN kingdoms k ON u.id = k.userid GROUP BY u.id ORDER BY val DESC LIMIT 20"
    ],
    "center" => [
        "label" => "Zentrum",
        "title" => "Höchstes Dorfzentrum",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, MAX(b.buildinglevel) as val FROM users u JOIN kingdoms k ON u.id = k.userid JOIN buildings b ON k.id = b.kingdomid WHERE b.buildingid = 0 GROUP BY u.id ORDER BY val DESC LIMIT 20"
    ],
    "build" => [
        "label" => "Architektur",
        "title" => "Gesamt Gebäude-Upgrades",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, s.buildings_upgraded as val FROM player_stats s JOIN users u ON s.userid = u.id WHERE s.buildings_upgraded > 0 ORDER BY val DESC LIMIT 20"
    ],
    "martyr" => [
        "label" => "Märtyrer",
        "title" => "Truppenverluste (PvP)",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, s.units_fallen_pvp as val FROM player_stats s JOIN users u ON s.userid = u.id WHERE s.units_fallen_pvp > 0 ORDER BY val DESC LIMIT 20"
    ],
    "coins" => [
        "label" => "Schatzkammer",
        "title" => "Höchstes Münzlimit",
        "limit" => 20,
        "query" => "SELECT u.username, u.id as uid, 
                    (2 * (" . BOOST_COIN_BASE . " + " . BOOST_COIN_FACTOR . " * GREATEST(0, MAX(b.buildinglevel) - 1))) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    JOIN buildings b ON k.id = b.kingdomid 
                    WHERE b.buildingid IN (" . BuildingTypes::BUILDING_MILL . ", " . BuildingTypes::BUILDING_SAWMILL . ", " . BuildingTypes::BUILDING_STONEMINE . ", " . BuildingTypes::BUILDING_GOLDMINE . ") 
                    GROUP BY u.id 
                    ORDER BY val DESC LIMIT 20"
    ]
];

$view = "<div class='tab'>";
$first = true;
foreach ($categories as $id => $data) {
    $active = $first ? "active" : "";
    $view .= "<div class='tablinks $active' data-on-click='filterHallOfFame' data-category='$id'>{$data["label"]}</div>";
    $first = false;
}
$view .= "</div>";

$view .= "<div id='hof-container'>";

$is_first_tab = true;
foreach ($categories as $id => $data) {
    $display = $is_first_tab ? "block" : "none";

    $view .= "<div id='hof_content_$id' class='js-hof-tab' style='display: $display;'>";
    $view .= "<h3 class='title-border' style='margin-top: 25px;'>Top {$data["limit"]}: {$data["title"]}</h3>";
    $view .= "<table class='table' style='max-width: 600px; width: 100%; table-layout: fixed;'>
              <colgroup>
                <col style='width: 15%;'>
                <col style='width: 55%;'>
                <col style='width: 30%;'>
              </colgroup>
                <tr>
                    <td class='td-center td-gradient'><b>#</b></td>
                    <td class='td-gradient'><b>Herrscher</b></td>
                    <td class='td-center td-gradient'><b>Wert</b></td>
                </tr>";

    $res = $db_instance->query($data["query"]);

    $rank = 1;
    if ($res && $res->num_rows > 0) {
        while ($row = $res->fetch_assoc()) {
            $is_me = ($row["uid"] == $user->get_user_id());
            $style = $is_me ? " background: rgba(255, 255, 255, 0.2);" : "";

            $player = new User($row["uid"], $row["username"]);
            $avatar = $player->get_avatar() ?? "";

            $rank_class = match ($rank) {
                1 => "rank-gold",
                2 => "rank-silver",
                3 => "rank-bronze",
                default => ""
            };

            $sender_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $row["uid"] . "' data-title='Spieler-Info'  class='$rank_class'>" . e($row["username"]) . "</a>";

            $view .= "<tr>
                        <td class='td-center $rank_class' style='$style'>$rank</td>
                        <td style='overflow: hidden; text-overflow: ellipsis; white-space: nowrap; $style'>
                            <div class='image-and-user'>
                                <img class='user-image' src='$avatar' alt=''>
                                $sender_link
                            </div>
                        </td>
                        <td class='td-center $rank_class' style='$style'>" . fnum($row["val"]) . "</td>
                      </tr>";
            $rank++;
        }
    } else {
        $view .= "<tr><td colspan='3' class='td-center'>Noch keine Einträge vorhanden.</td></tr>";
    }

    $view .= "</table></div>";
    $is_first_tab = false;
}

$view .= "</div>";

$title = "Hall of Fame";
$header = "Ruhmeshalle";
$script_files = ["halloffame", "userinfo"];

include("layout/base.php");