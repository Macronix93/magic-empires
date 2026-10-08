<?php

class Stats
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::get_instance()->get_connection();
    }

    public static function update_player_stat(int $user_id, string $column, int $increment = 1): void
    {
        $db = Database::get_instance()->get_connection();
        $query = "INSERT INTO player_stats (userid, `$column`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `$column` = `$column` + VALUES(`$column`)";
        $db->execute_query($query, [$user_id, $increment]);

        $stat_map = [
            "mines_depleted" => AchievementTypes::ACHIEVEMENT_MINES_DEPLETED,
            "monster_kills" => AchievementTypes::ACHIEVEMENT_MONSTER_KILLS,
            "camps_cleared" => AchievementTypes::ACHIEVEMENT_CAMPS_CLEARED,
            "resources_looted" => AchievementTypes::ACHIEVEMENT_RESOURCES_LOOTED,
            "trades_count" => AchievementTypes::ACHIEVEMENT_TRADES_COUNT,
            "spy_count" => AchievementTypes::ACHIEVEMENT_SPY_COUNT,
            "units_fallen_pvp" => AchievementTypes::ACHIEVEMENT_UNITS_FALLEN_PVP,
            "resources_stolen" => AchievementTypes::ACHIEVEMENT_RESOURCES_STOLEN,
            "event_damage_total" => AchievementTypes::ACHIEVEMENT_EVENT_DAMAGE,
            "mines_captured" => AchievementTypes::ACHIEVEMENT_MINE_CAPTURED,
            "mines_defended" => AchievementTypes::ACHIEVEMENT_MINE_DEFENDED,
            "events_attended" => AchievementTypes::ACHIEVEMENT_EVENT_ATTENDED,
            "alchemy_claimed" => AchievementTypes::ACHIEVEMENT_ALCHEMY_CLAIMED,
        ];

        if (isset($stat_map[$column])) {
            Achievement::check($user_id, $stat_map[$column]);
        }
    }

    public static function update_global_stat(string $name, int $increment = 1): void
    {
        $db = Database::get_instance()->get_connection();
        $query = "UPDATE system_settings SET value = value + ? WHERE name = ?";
        $db->execute_query($query, [$increment, $name]);
    }

    public function sync_user_score(int $user_id): array
    {
        $breakdown = $this->calculate_user_score_breakdown($user_id);

        $this->db->execute_query(
            "UPDATE users SET score = ?, ranking_points = ? WHERE id = ?",
            [$breakdown["total"], $breakdown["total"], $user_id]
        );

        Achievement::check($user_id, AchievementTypes::ACHIEVEMENT_SCORE);
        return $breakdown;
    }

    public function get_player_stats(int $user_id): array
    {
        $stats = $this->db->execute_query("SELECT * FROM player_stats WHERE userid = ?", [$user_id])->fetch_assoc();
        if ($stats) {
            return $stats;
        }

        $cols = [
            "units_produced", "units_upgraded", "units_fallen_pvp", "units_fallen_pve",
            "units_defeated_pvp", "monster_kills", "buildings_upgraded", "trades_count",
            "camps_cleared", "res_tiles_cleared", "spy_count", "resources_stolen",
            "resources_looted", "mines_depleted", "special_resources_mined", "event_damage_total"
        ];

        $defaults = array_fill_keys($cols, 0);
        foreach (["food", "wood", "stone", "gold"] as $rk) {
            $defaults["trade_sent_" . $rk] = 0;
            $defaults["trade_received_" . $rk] = 0;
        }

        return $defaults;
    }

    public function calculate_user_score_breakdown(int $user_id): array
    {
        // Building Score (TC, Wall, Storage Level 1 excluded!)
        $res_b = $this->db->execute_query("
            SELECT IFNULL(SUM(
                IF(b.buildingid IN (?, ?, ?), 
                   GREATEST(0, (b.buildinglevel * (b.buildinglevel + 1) / 2) - 1) * bl.buildingscore, 
                   (b.buildinglevel * (b.buildinglevel + 1) / 2) * bl.buildingscore)
            ), 0) 
            FROM buildings b 
            JOIN building_list bl ON b.buildingid = bl.id 
            JOIN kingdoms k ON b.kingdomid = k.id 
            WHERE k.userid = ?
        ", [BuildingTypes::BUILDING_TOWNCENTER, BuildingTypes::BUILDING_WALL, BuildingTypes::BUILDING_STORAGE, $user_id]);
        $score_buildings = (int)$res_b->fetch_column();

        // Tech Score
        $res_t = $this->db->execute_query("
            SELECT IFNULL(SUM((t.techlevel * (t.techlevel + 1) / 2) * tl.techscore), 0) 
            FROM techs t 
            JOIN tech_list tl ON t.techid = tl.id 
            JOIN kingdoms k ON t.kingdomid = k.id 
            WHERE k.userid = ?
        ", [$user_id]);
        $score_techs = (int)$res_t->fetch_column();

        // Troop Score
        $hero_id = Soldiers::SOLDIER_HERO;
        $res_u = $this->db->execute_query("
            SELECT IFNULL(SUM(total_count * sl.scoregain), 0) 
            FROM (
                SELECT soldierid, SUM(soldiercount) AS total_count 
                FROM soldiers 
                WHERE kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?) 
                GROUP BY soldierid
                UNION ALL 
                SELECT st.soldierid, SUM(st.soldiercount) AS total_count 
                FROM sent_troops st 
                JOIN events e ON st.eventid = e.eventid 
                WHERE e.userid = ? 
                GROUP BY st.soldierid
                UNION ALL 
                SELECT soldier_id AS soldierid, SUM(soldiercount) AS total_count 
                FROM stationed_troops 
                WHERE owner_id = ? 
                GROUP BY soldier_id
                UNION ALL 
                SELECT mst.soldier_id AS soldierid, SUM(mst.soldiercount) AS total_count 
                FROM mine_stationed_troops mst 
                WHERE mst.user_id = ? 
                GROUP BY mst.soldier_id
                UNION ALL 
                SELECT e.buildingid AS soldierid, SUM(e.soldiergoal) AS total_count 
                FROM events e 
                WHERE e.userid = ? AND e.actionid = ? 
                GROUP BY e.buildingid
            ) AS all_units 
            JOIN soldier_list sl ON all_units.soldierid = sl.id 
            WHERE sl.id != ?
        ", [$user_id, $user_id, $user_id, $user_id, $user_id, ActionTypes::ACTION_UPGRADE_TROOPS, $hero_id]);
        $score_troops = (int)$res_u->fetch_column();

        $sum = $score_buildings + $score_techs + $score_troops;

        return [
            "buildings" => $score_buildings,
            "techs" => $score_techs,
            "troops" => $score_troops,
            "total" => $sum,
            "perc_b" => $sum > 0 ? round(($score_buildings / $sum) * 100, 1) : 0,
            "perc_t" => $sum > 0 ? round(($score_techs / $sum) * 100, 1) : 0,
            "perc_u" => $sum > 0 ? round(($score_troops / $sum) * 100, 1) : 0
        ];
    }

    public function get_global_stats(): array
    {
        $stats_query = "
            SELECT 
                -- Resources of all kingdoms
                SUM(k.food) as total_f, SUM(k.wood) as total_w, SUM(k.stone) as total_s, SUM(k.gold) as total_g,
                SUM(k.foodperhour) as ph_f, SUM(k.woodperhour) as ph_w, SUM(k.stoneperhour) as ph_s, SUM(k.goldperhour) as ph_g,
                SUM(k.villager) as total_pop,
                -- Resource Fields
                (SELECT IFNULL(SUM(food), 0) FROM resource_tiles_data) as map_food,
                (SELECT IFNULL(SUM(wood), 0) FROM resource_tiles_data) as map_wood,
                (SELECT IFNULL(SUM(stone), 0) FROM resource_tiles_data) as map_stone,
                (SELECT IFNULL(SUM(gold), 0) FROM resource_tiles_data) as map_gold,
                (SELECT IFNULL(SUM(food + wood + stone + gold), 0) FROM resource_tiles_data WHERE expires_at > UNIX_TIMESTAMP()) as map_total,
                -- Player Data
                (SELECT COUNT(*) FROM users WHERE status = 1) as total_users,
                (SELECT COUNT(*) FROM users WHERE lastactivity > (UNIX_TIMESTAMP() - 86400)) as active_users_24h,
                (SELECT SUM(coins) FROM users) as total_coins,
                -- World Map
                (SELECT COUNT(*) FROM map WHERE kingdomid > 0) as occupied_fields,
                (SELECT COUNT(*) FROM resource_tiles_data WHERE expires_at > UNIX_TIMESTAMP()) as resource_tiles,
                (SELECT COUNT(*) FROM monster_camps WHERE expires_at > UNIX_TIMESTAMP()) as monster_camps,
                (SELECT COUNT(*) FROM mines WHERE expires_at > UNIX_TIMESTAMP()) as active_mines,
                -- Military
                ((SELECT IFNULL(SUM(soldiercount), 0) FROM soldiers) + (SELECT IFNULL(SUM(soldiercount), 0) FROM sent_troops)) as total_soldiers,
                (SELECT IFNULL(AVG(buildinglevel), 0) FROM buildings) as avg_building_lvl,
                (SELECT IFNULL(SUM(techlevel), 0) FROM techs) as total_tech_lvls,
                (SELECT IFNULL(value, 0) FROM system_settings WHERE name = 'total_fallen_soldiers') as total_fallen,
                (SELECT IFNULL(value, 0) FROM system_settings WHERE name = 'total_slain_monsters') as total_monsters_slain,
                (SELECT IFNULL(value, 0) FROM system_settings WHERE name = 'total_battles') as total_battles,
                -- Misc
                (SELECT COUNT(*) FROM messages) as total_msgs,
                (SELECT IFNULL(value, 0) FROM system_settings WHERE name = 'total_trades') as total_trades,
                (SELECT IFNULL(SUM(supplyvalue), 0) FROM marketplace) as market_volume
            FROM kingdoms k
        ";
        return $this->db->execute_query($stats_query)->fetch_assoc() ?: [];
    }
}