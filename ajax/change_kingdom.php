<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $chosen = $_POST["choosekingdom"] ?? null;
    if (isset($chosen) && is_numeric($chosen)) {
        $chosen = (int)$chosen;
        $res = $db_instance->execute_query("SELECT userid FROM kingdoms WHERE id = ?", [$chosen]);
        $row_check = $res->fetch_assoc();

        if ($row_check && (int)$row_check["userid"] === $user->get_user_id()) {
            $_SESSION["kingdomid"] = $chosen;
            $user->set_current_kingdom($chosen);

            $k = new Kingdom($chosen);
            $cmd_stats = $k->get_command_stats();

            $target_name = $k->get_kingdom_name();
            $target_x = $k->get_kingdom_map_x();
            $target_y = $k->get_kingdom_map_y();

            $res_troops = $db_instance->execute_query("SELECT soldierid, soldiercount FROM soldiers WHERE kingdomid = ?", [$chosen]);
            $troops = [];
            while ($t = $res_troops->fetch_assoc()) {
                $troops[(int)$t["soldierid"]] = (int)$t["soldiercount"];
            }

            $uid = $user->get_user_id();
            $gid = $user->get_user_guild_id();
            $all_user_kingdoms = $db_instance->execute_query(
                "SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY created_at", [$uid]
            )->fetch_all(MYSQLI_ASSOC);

            $res_sidebar = $db_instance->execute_query("
                SELECT 
                    (SELECT 1 FROM world_events WHERE is_active = 1 AND end_time > UNIX_TIMESTAMP() LIMIT 1) AS has_event,
                    (SELECT COUNT(*) FROM marketplace WHERE (guild_id = 0 OR (guild_id > 0 AND guild_id = ?))) AS market_count,
                    (SELECT 1 FROM events WHERE guild_id = ? AND actionid = " . ActionTypes::ACTION_RESEARCH_TECH . " LIMIT 1) AS guild_research_active,
                    (SELECT gtl.name FROM guild_projects gp JOIN guild_tech_list gtl ON gp.tech_id = gtl.id WHERE gp.guild_id = ? LIMIT 1) AS guild_project_name,
                    (SELECT CASE 
                        WHEN input_amount = 0 AND output_amount >= 1 THEN 'ready'
                        WHEN input_amount > 0 THEN 'running'
                        ELSE ''
                    END FROM kingdom_alchemy WHERE kingdom_id = ? LIMIT 1) AS alchemy_status
            ", [$gid, $gid, $gid, $chosen])->fetch_assoc();

            $sidebar_data = [
                "has_world_event" => !empty($res_sidebar["has_event"]),
                "market_offers" => (int)($res_sidebar["market_count"] ?? 0),
                "guild_status" => '',
                "alchemy_status" => $res_sidebar["alchemy_status"] ?? ''
            ];

            if ($gid > 0) {
                if (!empty($res_sidebar["guild_research_active"])) {
                    $sidebar_data["guild_status"] = '<img src="images/icons/icon_time.png" class="ressource-icons" title="Gildenforschung läuft..." alt="Forschung">';
                } elseif (!empty($res_sidebar["guild_project_name"])) {
                    $sidebar_data["guild_status"] = '<img src="images/icons/icon_hammer.png" class="ressource-icons" title="Projekt aktiv: ' . e($res_sidebar["guild_project_name"]) . '" alt="Projekt">';
                }
            }

            ob_start();

            $kingdom = $k;
            include(__DIR__ . "/../layout/right.php");
            $sidebar_html = ob_get_clean();

            echo json_encode([
                "success" => true,
                "sidebar_html" => $sidebar_html,
                "kingdom" => [
                    "id" => $chosen,
                    "name" => $target_name,
                    "x" => $target_x,
                    "y" => $target_y,
                    "marchMultiplier" => $k->get_march_speed_multiplier(),
                    "troops" => $troops,
                    "guildId" => $user->get_user_guild_id(),
                    "guildSupportSpeedLvl" => Guild::get_user_guild_tech_level($user->get_user_id(), GuildTechTypes::GUILD_TECH_SUPPORT_SPEED),
                    "occupiedCommands" => $cmd_stats["occupied"],
                    "maxCommands" => $cmd_stats["max"],
                    "commandsFull" => $cmd_stats["is_full"]
                ]
            ]);
            exit;
        }
    }
    echo json_encode(["success" => false]);
} else {
    change_location("overview.php");
}