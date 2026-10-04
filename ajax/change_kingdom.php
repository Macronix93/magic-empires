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

            $sidebar_data = $user->get_sidebar_data($chosen);

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