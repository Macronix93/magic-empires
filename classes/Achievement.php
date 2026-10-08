<?php

class Achievement
{
    public static function check(int $user_id, int|array|null $filter_req_types = null): array
    {
        if ($user_id <= 0) return [];
        $db = Database::get_instance()->get_connection();

        $unlocked_res = $db->execute_query(
            "SELECT achievement_id FROM user_achievements WHERE user_id = ?", [$user_id]
        );
        $unlocked_ids = [];
        while ($row = $unlocked_res->fetch_assoc()) {
            $unlocked_ids[] = (int)$row["achievement_id"];
        }

        $filter_sql = "";
        if ($filter_req_types !== null) {
            $types_list = is_array($filter_req_types) ? array_map('intval', $filter_req_types) : [$filter_req_types];
            if (!empty($types_list)) {
                $filter_sql = " AND req_type IN (" . implode(',', $types_list) . ") ";
            }
        }

        $metrics = self::get_player_metrics($user_id, is_int($filter_req_types) ? $filter_req_types : null);

        $placeholders = !empty($unlocked_ids) ? implode(',', $unlocked_ids) : '0';
        $open_achs = $db->query("SELECT * FROM achievements WHERE id NOT IN ($placeholders) $filter_sql ORDER BY category ASC, req_value ASC");

        $newly_unlocked = [];

        while ($ach = $open_achs->fetch_assoc()) {
            $req_type = (int)$ach["req_type"];
            $req_val = (int)$ach["req_value"];
            $cur_val = $metrics[$req_type] ?? 0;

            if ($cur_val >= $req_val) {
                if (self::grant_achievement($user_id, $ach)) {
                    $newly_unlocked[] = $ach;
                }
            }
        }

        return $newly_unlocked;
    }

    public static function unlock(int $user_id, int $req_type): bool
    {
        if ($user_id <= 0) return false;
        $db = Database::get_instance()->get_connection();

        $ach = $db->execute_query(
            "SELECT * FROM achievements WHERE req_type = ? LIMIT 1",
            [$req_type]
        )->fetch_assoc();

        if (!$ach) return false;
        $ach_id = (int)$ach["id"];

        $check = $db->execute_query(
            "SELECT 1 FROM user_achievements WHERE user_id = ? AND achievement_id = ?",
            [$user_id, $ach_id]
        );
        if ($check->num_rows > 0) return false;

        return self::grant_achievement($user_id, $ach);
    }

    private static function grant_achievement(int $user_id, array $ach): bool
    {
        $db = Database::get_instance()->get_connection();
        $ach_id = (int)$ach["id"];
        $now = time();
        $reward = (int)$ach["reward_coins"];
        $is_claimed = ($reward <= 0) ? 1 : 0;

        $db->execute_query(
            "INSERT IGNORE INTO user_achievements (user_id, achievement_id, unlocked_at, is_claimed) VALUES (?, ?, ?, ?)",
            [$user_id, $ach_id, $now, $is_claimed]
        );

        if ($db->affected_rows === 0) {
            return false;
        }

        $user_data = $db->execute_query("SELECT username, gender FROM users WHERE id = ?", [$user_id])->fetch_assoc();
        $uname = $user_data["username"] ?? "Herrscher";
        $gender = $user_data["gender"] ?? 'm';

        $display_title = ($gender === 'f' && !empty($ach["title_f"])) ? $ach["title_f"] : $ach["title"];

        $msg_json = [
            "template" => "achievement_unlocked",
            "name" => $ach["name"],
            "title" => $display_title,
            "description" => $ach["description"],
            "reward_coins" => $reward
        ];
        Messages::send_server_message($user_id, $uname, MessageCategories::CATEGORY_DEFAULT, $msg_json);

        send_user_push(
            $user_id,
            "🏆 Neue Errungenschaft!",
            "Du hast „{$ach["name"]}“ freigeschaltet! Neuer Titel: „{$display_title}“",
            "events",
            "achievements.php"
        );

        Logger::get_instance()->log_game("ACHIEVEMENT", "UNLOCKED", [
            "achievement_id" => $ach_id,
            "code" => $ach["code"] ?? "",
            "title" => $display_title
        ]);

        return true;
    }

    public static function claim_reward(int $user_id, int $achievement_id): bool
    {
        $db = Database::get_instance()->get_connection();

        $db->begin_transaction();
        try {
            $res = $db->execute_query("
                SELECT ua.id, a.reward_coins, u.username
                FROM user_achievements ua
                JOIN achievements a ON ua.achievement_id = a.id
                JOIN users u ON ua.user_id = u.id
                WHERE ua.user_id = ? AND ua.achievement_id = ? AND ua.is_claimed = 0 AND a.reward_coins > 0
                FOR UPDATE
            ", [$user_id, $achievement_id]);
            $row = $res->fetch_assoc();

            if (!$row) {
                $db->rollback();
                return false;
            }

            $coins = (int)$row["reward_coins"];
            $user_obj = new User($user_id, $row["username"]);
            $user_obj->give_user_coins($coins);

            $db->execute_query("UPDATE user_achievements SET is_claimed = 1 WHERE id = ?", [$row["id"]]);
            $db->commit();
            return true;
        } catch (Throwable) {
            $db->rollback();
            return false;
        }
    }

    public static function get_claimable_count(int $user_id): int
    {
        if ($user_id <= 0) return 0;

        $db = Database::get_instance()->get_connection();

        return (int)$db->execute_query("
            SELECT COUNT(*)
            FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            WHERE ua.user_id = ? AND ua.is_claimed = 0 AND a.reward_coins > 0
        ", [$user_id])->fetch_column();
    }

    public static function get_all_with_status(int $user_id): array
    {
        $db = Database::get_instance()->get_connection();

        $query = "
            SELECT a.*, 
                   ua.unlocked_at, 
                   (ua.id IS NOT NULL) AS is_unlocked,
                   IFNULL(ua.is_claimed, 0) AS is_claimed
            FROM achievements a
            LEFT JOIN user_achievements ua ON a.id = ua.achievement_id AND ua.user_id = ?
            ORDER BY a.category, a.req_type, a.req_value
        ";
        return $db->execute_query($query, [$user_id])->fetch_all(MYSQLI_ASSOC);
    }

    public static function get_unlocked_titles(int $user_id): array
    {
        $db = Database::get_instance()->get_connection();

        $gender = $db->execute_query(
            "SELECT gender FROM users WHERE id = ?",
            [$user_id]
        )->fetch_column() ?: 'm';

        $query = "
            SELECT a.id, 
                   IF(? = 'f' AND a.title_f IS NOT NULL AND a.title_f != '', a.title_f, a.title) AS title_name
            FROM achievements a
            JOIN user_achievements ua ON a.id = ua.achievement_id
            WHERE ua.user_id = ?
            ORDER BY a.category, a.req_value
        ";
        $res = $db->execute_query($query, [$gender, $user_id]);

        $titles = [];
        $default_alias_id = null;

        while ($row = $res->fetch_assoc()) {
            if ($row["title_m"] === "Freiherr" || $row["title_f"] === "Freifrau") {
                $default_alias_id = (int)$row["id"];
                continue;
            }

            $titles[] = [
                "id" => (int)$row["id"],
                "title" => ($gender === 'f') ? $row["title_f"] : $row["title_m"],
                "title_m" => $row["title_m"],
                "title_f" => $row["title_f"]
            ];
        }

        array_unshift($titles, [
            "id" => -1,
            "title" => ($gender === 'f') ? "Freifrau" : "Freiherr",
            "title_m" => "Freiherr",
            "title_f" => "Freifrau",
            "alias_id" => $default_alias_id
        ]);

        return $titles;
    }

    private static function get_kingdom_metrics(mysqli $db, int $user_id): array
    {
        $k_data = $db->execute_query("
            SELECT 
                COUNT(*) AS total_kingdoms, 
                SUM(IF(creation_method = ?, 1, 0)) AS founded_kingdoms
            FROM kingdoms 
            WHERE userid = ?
        ", [KingdomCreationTypes::KINGDOM_CREATION_FOUNDED, $user_id])->fetch_assoc();

        return [
            AchievementTypes::ACHIEVEMENT_KINGDOMS_COUNT => (int)($k_data["total_kingdoms"] ?? 0),
            AchievementTypes::ACHIEVEMENT_FOUNDED_KINGDOMS => (int)($k_data["founded_kingdoms"] ?? 0)
        ];
    }

    public static function get_player_metrics(int $user_id, ?int $filter_req_type = null): array
    {
        if ($user_id <= 0) return [];
        $db = Database::get_instance()->get_connection();

        if ($filter_req_type === AchievementTypes::ACHIEVEMENT_SCORE) {
            $score = (int)$db->execute_query("SELECT score FROM users WHERE id = ?", [$user_id])->fetch_column();
            return [AchievementTypes::ACHIEVEMENT_SCORE => $score];
        }

        if ($filter_req_type === AchievementTypes::ACHIEVEMENT_KINGDOMS_COUNT || $filter_req_type === AchievementTypes::ACHIEVEMENT_FOUNDED_KINGDOMS) {
            return self::get_kingdom_metrics($db, $user_id);
        }

        $user_row = $db->execute_query("SELECT score FROM users WHERE id = ?", [$user_id])->fetch_assoc();
        if (!$user_row) return [];

        $k_metrics = self::get_kingdom_metrics($db, $user_id);

        $tech_data = $db->execute_query("
            SELECT 
                SUM(IF(tl.origin_building = " . BuildingTypes::BUILDING_UNIVERSITY . " AND t.techlevel >= tl.maxlevel, 1, 0)) AS max_uni_techs,
                SUM(IF(tl.origin_building = " . BuildingTypes::BUILDING_SMITHY . " AND t.techlevel >= tl.maxlevel, 1, 0)) AS max_smithy_techs,
                MAX(IF(t.techid = " . TechTypes::TECH_TYPE_ANCESTRAL_RITES . ", t.techlevel, 0)) AS rites_level
            FROM techs t
            JOIN tech_list tl ON t.techid = tl.id
            JOIN kingdoms k ON t.kingdomid = k.id
            WHERE k.userid = ?
        ", [$user_id])->fetch_assoc();

        $p_stats = new Stats()->get_player_stats($user_id);

        return [
            // kingdoms
            AchievementTypes::ACHIEVEMENT_KINGDOMS_COUNT => $k_metrics[AchievementTypes::ACHIEVEMENT_KINGDOMS_COUNT],
            AchievementTypes::ACHIEVEMENT_FOUNDED_KINGDOMS => $k_metrics[AchievementTypes::ACHIEVEMENT_FOUNDED_KINGDOMS],

            // users, buildings, techs
            AchievementTypes::ACHIEVEMENT_SCORE => (int)$user_row["score"],
            AchievementTypes::ACHIEVEMENT_MAX_UNI_TECH => (int)($tech_data["max_uni_techs"] ?? 0),
            AchievementTypes::ACHIEVEMENT_MAX_SMITHY_TECH => (int)($tech_data["max_smithy_techs"] ?? 0),
            AchievementTypes::ACHIEVEMENT_ANCESTRAL_RITES => (int)($tech_data["rites_level"] ?? 0),

            // player_stats
            AchievementTypes::ACHIEVEMENT_EVENT_ATTENDED => (int)($p_stats["events_attended"] ?? 0),
            AchievementTypes::ACHIEVEMENT_MINES_DEPLETED => (int)($p_stats["mines_depleted"] ?? 0),
            AchievementTypes::ACHIEVEMENT_TRADES_COUNT => (int)($p_stats["trades_count"] ?? 0),
            AchievementTypes::ACHIEVEMENT_RESOURCES_LOOTED => (int)($p_stats["resources_looted"] ?? 0),
            AchievementTypes::ACHIEVEMENT_MONSTER_KILLS => (int)($p_stats["monster_kills"] ?? 0),
            AchievementTypes::ACHIEVEMENT_CAMPS_CLEARED => (int)($p_stats["camps_cleared"] ?? 0),
            AchievementTypes::ACHIEVEMENT_EVENT_DAMAGE => (int)($p_stats["event_damage_total"] ?? 0),
            AchievementTypes::ACHIEVEMENT_SPY_COUNT => (int)($p_stats["spy_count"] ?? 0),
            AchievementTypes::ACHIEVEMENT_UNITS_FALLEN_PVP => (int)($p_stats["units_fallen_pvp"] ?? 0),
            AchievementTypes::ACHIEVEMENT_RESOURCES_STOLEN => (int)($p_stats["resources_stolen"] ?? 0),
            AchievementTypes::ACHIEVEMENT_MINE_CAPTURED => (int)($p_stats["mines_captured"] ?? 0),
            AchievementTypes::ACHIEVEMENT_MINE_DEFENDED => (int)($p_stats["mines_defended"] ?? 0),
            AchievementTypes::ACHIEVEMENT_ALCHEMY_CLAIMED => (int)($p_stats["alchemy_claimed"] ?? 0),
        ];
    }

    public static function has(int $user_id, int $req_type): bool
    {
        if ($user_id <= 0) return false;
        $db = Database::get_instance()->get_connection();
        $res = $db->execute_query("
            SELECT 1 FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            WHERE ua.user_id = ? AND a.req_type = ?
            LIMIT 1
        ", [$user_id, $req_type]);

        return $res->num_rows > 0;
    }
}