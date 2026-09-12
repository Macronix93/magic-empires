<?php

$categories = [
    "ranking" => [
        "label" => "Weltrangliste",
        "title" => "Höchste Punktzahl",
        "limit" => 10,
        "type" => "player",
        "query" => "SELECT username, id as uid, ranking_points as val 
                    FROM users 
                    WHERE status = 1
                    ORDER BY val DESC, uid"
    ],
    "monster" => [
        "label" => "Monster",
        "title" => "Besiegte Monster",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.monster_kills as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.monster_kills > 0 
                    ORDER BY val DESC, uid"
    ],
    "martyr" => [
        "label" => "Märtyrer",
        "title" => "Truppenverluste (PvP)",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.units_fallen_pvp as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.units_fallen_pvp > 0 
                    ORDER BY val DESC, uid"
    ],
    "pve_martyr" => [
        "label" => "Monster-Opfer",
        "title" => "Truppenverluste (PvE)",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.units_fallen_pve as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.units_fallen_pve > 0 
                    ORDER BY val DESC, uid"
    ],
    "loot" => [
        "label" => "Plünderung",
        "title" => "Erbeutete Ressourcen",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.resources_looted as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.resources_looted > 0 
                    ORDER BY val DESC, uid"
    ],
    "thieves" => [
        "label" => "Diebesgilde",
        "title" => "Gestohlene Rohstoffe",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.resources_stolen as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.resources_stolen > 0 
                    ORDER BY val DESC, uid"
    ],
    "event" => [
        "label" => "Events",
        "title" => "Event-Schaden",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.event_damage_total as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.event_damage_total > 0 
                    ORDER BY val DESC, uid"
    ],
    "expansion" => [
        "label" => "Königreiche",
        "title" => "Anzahl Königreiche",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, COUNT(k.id) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    GROUP BY u.id 
                    ORDER BY val DESC, uid"
    ],
    "center" => [
        "label" => "Zentrum",
        "title" => "Höchstes Dorfzentrum",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, MAX(b.buildinglevel) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    JOIN buildings b ON k.id = b.kingdomid 
                    WHERE b.buildingid = " . BuildingTypes::BUILDING_TOWNCENTER . " 
                    GROUP BY u.id 
                    ORDER BY val DESC, uid"
    ],
    "builder_sum" => [
        "label" => "Bau-Magnat",
        "title" => "Summe Gebäude-Stufen",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, SUM(b.buildinglevel) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    JOIN buildings b ON k.id = b.kingdomid 
                    GROUP BY u.id 
                    ORDER BY val DESC, uid"
    ],
    "research_sum" => [
        "label" => "Forschungs-Magnat",
        "title" => "Summe Universitäts-Forschungen",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, SUM(t.techlevel) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    JOIN techs t ON k.id = t.kingdomid 
                    WHERE t.techid < " . TechTypes::TECH_TYPE_BLADES . " AND t.techlevel > 0
                    GROUP BY u.id 
                    ORDER BY val DESC, uid"
    ],
    "smithy_sum" => [
        "label" => "Schmiede-Magnat",
        "title" => "Summe Schmiede-Verbesserungen",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, SUM(t.techlevel) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    JOIN techs t ON k.id = t.kingdomid 
                    WHERE t.techid >= " . TechTypes::TECH_TYPE_BLADES . " AND t.techlevel > 0
                    GROUP BY u.id 
                    ORDER BY val DESC, uid"
    ],
    "guild_research" => [
        "label" => "Gilden-Forschung",
        "title" => "Erforschte Gilden-Technologien",
        "limit" => 20,
        "type" => "guild",
        "query" => "SELECT g.name as gname, g.tag, g.id as gid, SUM(gt.level) as val 
                    FROM guilds g 
                    JOIN guild_techs gt ON g.id = gt.guild_id 
                    WHERE gt.level > 0 
                    GROUP BY g.id, g.name, g.tag 
                    ORDER BY val DESC, gid"
    ],
    "coins" => [
        "label" => "Schatzkammer",
        "title" => "Höchstes Münzlimit",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, 
                    (2 * (" . BOOST_COIN_BASE . " + " . BOOST_COIN_FACTOR . " * GREATEST(0, MAX(b.buildinglevel) - 1))) as val 
                    FROM users u 
                    JOIN kingdoms k ON u.id = k.userid 
                    JOIN buildings b ON k.id = b.kingdomid 
                    WHERE b.buildingid IN (" . BuildingTypes::BUILDING_MILL . ", " . BuildingTypes::BUILDING_SAWMILL . ", " . BuildingTypes::BUILDING_STONEMINE . ", " . BuildingTypes::BUILDING_GOLDMINE . ") 
                    GROUP BY u.id 
                    ORDER BY val DESC, uid"
    ],
    "merchants" => [
        "label" => "Händler",
        "title" => "Handelsabschlüsse",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.trades_count as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.trades_count > 0 
                    ORDER BY val DESC, uid"
    ],
    "spies" => [
        "label" => "Spionage",
        "title" => "Spionagemissionen",
        "limit" => 20,
        "type" => "player",
        "query" => "SELECT u.username, u.id as uid, s.spy_count as val 
                    FROM player_stats s 
                    JOIN users u ON s.userid = u.id 
                    WHERE s.spy_count > 0 
                    ORDER BY val DESC, uid"
    ]
];

function render_hof_tab_content(array $data, mysqli $db, User $current_user): string
{
    $is_guild = ($data["type"] ?? "player") === "guild";
    $second_col_title = $is_guild ? "Gilde" : "Herrscher";

    $html = "<h3 class='title-border' style='margin-top: 25px;'>Top {$data["limit"]}: {$data["title"]}</h3>";
    $html .= "<table class='table' style='max-width: 600px; width: 100%; table-layout: fixed;'>
              <colgroup>
                <col style='width: 15%;'>
                <col style='width: 55%;'>
                <col style='width: 30%;'>
              </colgroup>
                <tr>
                    <td class='td-center td-gradient'><b>#</b></td>
                    <td class='td-gradient'><b>$second_col_title</b></td>
                    <td class='td-center td-gradient'><b>Wert</b></td>
                </tr>";

    $final_query = $data["query"] . " LIMIT " . (int)$data["limit"];
    $res = $db->query($final_query);

    $rank = 1;
    if ($res && $res->num_rows > 0) {
        $guild_logic = new Guild($db, $current_user);

        while ($row = $res->fetch_assoc()) {
            $rank_class = match ($rank) {
                1 => "rank-gold",
                2 => "rank-silver",
                3 => "rank-bronze",
                default => ""
            };

            if ($is_guild) {
                $is_me = ($row["gid"] == $current_user->get_user_guild_id());
                $style = $is_me ? " background: rgba(212, 175, 55, 0.2);" : "";

                $badge = $guild_logic->render_badge((int)$row["gid"], $row["tag"], $row["gname"]);
                $cell_content = "<div style='cursor: pointer;' data-on-click='openGuildInfo' data-id='{$row["gid"]}'>$badge</div>";
            } else {
                $is_me = ($row["uid"] == $current_user->get_user_id());
                $style = $is_me ? " background: rgba(255, 255, 255, 0.2);" : "";

                $player = new User($row["uid"], $row["username"]);
                $cell_content = $player->render_user();
            }

            $html .= "<tr>
                        <td class='td-center $rank_class' style='$style'>$rank</td>
                        <td style='overflow: hidden; text-overflow: ellipsis; white-space: nowrap; $style'>
                            $cell_content
                        </td>
                        <td class='td-center $rank_class' style='$style'>" . fnum($row["val"]) . "</td>
                      </tr>";
            $rank++;
        }
    } else {
        $html .= "<tr><td colspan='3' class='td-center'>Noch keine Einträge vorhanden.</td></tr>";
    }

    $html .= "</table>";
    return $html;
}