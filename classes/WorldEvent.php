<?php

class WorldEvent
{
    private object $mysqli;

    public function __construct(object $db_conn)
    {
        $this->mysqli = $db_conn;
    }

    public function get_active_event(): ?array
    {
        $res = $this->mysqli->execute_query("SELECT * FROM world_events WHERE is_active = 1 AND end_time > ? LIMIT 1", [time()]);
        return $res->fetch_assoc();
    }

    public function spawn_event(string $event_type): void
    {
        $now = time();
        $end_time = $now + WORLD_EVENT_DURATION;

        // Event Reset
        $this->mysqli->query("UPDATE world_events SET is_active = 0 WHERE is_active = 1");

        // Random monster
        $monster_index = array_rand($this->get_monster_pool());

        $server_power = 0;
        $total_hp = 0;

        if ($event_type === "BOSS_HP") {
            $query = "
                SELECT SUM(
                    s.soldiercount * (
                        (sl.attack * (CASE 
                            WHEN k.alignment = " . AlignmentTypes::ALIGN_WAR . " 
                            THEN (1.0 + " . (float)($this->mysqli->query("SELECT base_bonus FROM shrine_alignments WHERE id = 1")->fetch_column() ?? 0.08) . " + (IFNULL(t_shrine.techlevel, 0) * " . SHRINE_TECH_STEP . ")) 
                            ELSE 1.0 
                          END))
                        +
                        (CASE 
                            WHEN sl.category = " . SoldierTypes::SOLDIER_TYPE_INFANTRY . " THEN IFNULL(t_inf.techlevel, 0) * " . SMITHY_INF_ATK_BONUS . "
                            WHEN sl.category = " . SoldierTypes::SOLDIER_TYPE_CAVALRY . " THEN IFNULL(t_cav.techlevel, 0) * " . SMITHY_CAV_ATK_BONUS . "
                            WHEN sl.category = " . SoldierTypes::SOLDIER_TYPE_ARCHERS . " THEN IFNULL(t_arc.techlevel, 0) * " . SMITHY_ARC_ATK_BONUS . "
                            ELSE 0 
                         END)
                    )
                ) as total_power
                FROM soldiers s
                JOIN soldier_list sl ON s.soldierid = sl.id
                JOIN kingdoms k ON s.kingdomid = k.id
                -- Joins für die verschiedenen ATK-Techs
                LEFT JOIN techs t_inf ON t_inf.kingdomid = k.id AND t_inf.techid = " . TechTypes::TECH_TYPE_BLADES . "
                LEFT JOIN techs t_cav ON t_cav.kingdomid = k.id AND t_cav.techid = " . TechTypes::TECH_TYPE_LANCE_RIDING . "
                LEFT JOIN techs t_arc ON t_arc.kingdomid = k.id AND t_arc.techid = " . TechTypes::TECH_TYPE_ARROWHEADS . "
                LEFT JOIN techs t_shrine ON t_shrine.kingdomid = k.id AND t_shrine.techid = " . TechTypes::TECH_TYPE_ANCESTRAL_RITES . "
            ";

            $server_power = (int)$this->mysqli->query($query)->fetch_column();

            if ($server_power < 5000) $server_power = 50000;

            $total_hp = (int)($server_power * 5);
        }

        $this->mysqli->execute_query(
            "INSERT INTO world_events (event_type, start_time, end_time, total_hp, current_hp, monster_index) VALUES (?, ?, ?, ?, ?, ?)",
            [$event_type, $now, $end_time, $total_hp, $total_hp, $monster_index]
        );

        $pool = $this->get_monster_pool();
        $monster = $pool[$monster_index];

        Logger::get_instance()->log_game("ADMIN", "WORLD_EVENT_SPAWN", [
            "type" => $event_type,
            "monster_name" => $monster["name"],
            "total_hp" => $total_hp,
            "server_power" => $server_power
        ]);
    }

    public function record_damage(int $event_id, int $user_id, int $damage, string $type, int $kingdom_id): int
    {
        if ($type === "DAMAGE") {
            $check = $this->mysqli->execute_query(
                "SELECT attempts_used FROM world_event_participants WHERE event_id = ? AND userid = ?",
                [$event_id, $user_id]
            )->fetch_assoc();

            if ($check && $check["attempts_used"] >= WORLD_EVENT_MAX_ATTEMPTS) {
                return -2;
            }
        }

        $actual_damage = $damage;

        if ($type === "BOSS_HP") {
            $res = $this->mysqli->execute_query("SELECT current_hp FROM world_events WHERE id = ?", [$event_id]);
            $current_hp = (int)$res->fetch_column();

            if ($current_hp <= 0) {
                return -1;
            }

            $actual_damage = min($damage, $current_hp);

            $this->mysqli->execute_query("UPDATE world_events SET current_hp = current_hp - ? WHERE id = ?", [$actual_damage, $event_id]);
        }

        $this->mysqli->execute_query("
            INSERT INTO world_event_participants (event_id, userid, total_damage, attempts_used, top_kingdom_id, top_kingdom_damage)
            VALUES (?, ?, ?, 1, ?, ?)
            ON DUPLICATE KEY UPDATE 
                top_kingdom_id = IF(? > top_kingdom_damage, ?, top_kingdom_id),
                top_kingdom_damage = IF(? > top_kingdom_damage, ?, top_kingdom_damage),
                total_damage = total_damage + ?,
                attempts_used = attempts_used + 1
        ", [
            $event_id, $user_id, $actual_damage, $kingdom_id, $actual_damage,
            $actual_damage, $kingdom_id,
            $actual_damage, $actual_damage,
            $actual_damage
        ]);

        update_player_stat($user_id, "event_damage_total", $actual_damage);

        return $actual_damage;
    }

    public function broadcast_spawn_notification(string $event_type): void
    {
        $active = $this->get_active_event();
        $pool = $this->get_monster_pool();
        $monster = $pool[$active["monster_index"] ?? 0];

        $title = $monster["name"] . " gesichtet!";

        $text = "<div style='margin: 10px; text-align: center;'><img src='images/icons/{$monster["icon"]}.png' alt='{$monster["name"]}'></div>";
        $text .= ($event_type === "BOSS_HP")
            ? "Ein gewaltiger Boss ist im <b>Auge des Sturms</b> erschienen! Alle sind aufgerufen, ihre Truppen zu senden, um die Bestie gemeinsam zu fällen."
            : "Im <b>Auge des Sturms</b> hat ein neues Event begonnen! Zeige deine Stärke und sichere dir Belohnungen anhand deines persönlich angerichteten Schadens.";
        $text .= " Jeder der mitmacht, erhält Belohnungen! Weitere Infos auf der Event-Seite.";
        $text .= "<div style='margin: 10px; text-align: center;'><button data-on-click='redirect' data-url='events.php'>Zum Event</button></div>";

        $msg = "<div class='battle-report'>";
        $msg .= BattleReportRenderer::render_outcome_box(
            $title,
            $text
        );
        $msg .= "</div>";

        $res_users = $this->mysqli->query("SELECT id, username FROM users WHERE status = 1");
        while ($u = $res_users->fetch_assoc()) {
            send_server_message($u["id"], $u["username"], $msg);
        }
    }

    public function get_last_event_type(): ?string
    {
        $res = $this->mysqli->query("SELECT event_type FROM world_events ORDER BY id DESC LIMIT 1");

        if ($row = $res->fetch_assoc()) {
            return $row["event_type"];
        }

        return null;
    }

    public function get_monster_pool(): array
    {
        return [
            0 => ["name" => "Lich-König", "icon" => "icon_lich", "desc" => "Ein uralter Untoter, der die Seelen der Gefallenen erntet."],
            1 => ["name" => "Ur-Drache", "icon" => "icon_dragon", "desc" => "Ein Titan aus der Zeit der Schöpfung, dessen Atem ganze Reiche verbrennt."],
            2 => ["name" => "Erzelementar", "icon" => "icon_golem", "desc" => "Ein kolossales Wesen aus Stein und Magie, unnachgiebig wie der Berg selbst."],
            3 => ["name" => "Dämonenfürst", "icon" => "icon_devil", "desc" => "Ein Gebieter der Unterwelt, der gekommen ist, um die Welt in Asche zu legen."]
        ];
    }

    public function cleanup_old_events(int $days_to_keep = 4): int
    {
        $threshold = time() - ($days_to_keep * 86400);

        $this->mysqli->execute_query(
            "DELETE FROM world_events WHERE is_rewarded = 1 AND end_time < ?",
            [$threshold]
        );

        return $this->mysqli->affected_rows;
    }

    public function get_user_max_building_avg(int $user_id): float
    {
        $query = "
        SELECT AVG(max_level) as total_avg
        FROM (
            SELECT MAX(buildinglevel) as max_level
            FROM buildings 
            WHERE kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?)
            GROUP BY buildingid
        ) as max_buildings";

        $res = $this->mysqli->execute_query($query, [$user_id]);
        return (float)($res->fetch_column() ?? 1.0);
    }

    public function get_max_tc_level(int $user_id): int
    {
        $res = $this->mysqli->execute_query("
        SELECT MAX(buildinglevel) 
        FROM buildings 
        WHERE buildingid = 0 
        AND kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?)", [$user_id]);
        return (int)($res->fetch_column() ?? 1);
    }

    public function generate_hp_boss_loot(int $user_id): array
    {
        $tc_lvl = $this->get_max_tc_level($user_id);
        $avg_lvl = $this->get_user_max_building_avg($user_id);

        // Calc resources
        $base_res = WORLD_EVENT_HP_RES_BASE * $avg_lvl;
        $loot = [
            ResourceTypes::RESOURCE_TYPE_FOOD => (int)($base_res * (mt_rand(WORLD_EVENT_HP_RES_VAR_MIN, WORLD_EVENT_HP_RES_VAR_MAX) / 100)),
            ResourceTypes::RESOURCE_TYPE_WOOD => (int)($base_res * (mt_rand(WORLD_EVENT_HP_RES_VAR_MIN, WORLD_EVENT_HP_RES_VAR_MAX) / 100)),
            ResourceTypes::RESOURCE_TYPE_STONE => (int)($base_res * (mt_rand(WORLD_EVENT_HP_RES_VAR_MIN, WORLD_EVENT_HP_RES_VAR_MAX) / 100)),
            ResourceTypes::RESOURCE_TYPE_GOLD => (int)($base_res * (mt_rand(WORLD_EVENT_HP_RES_VAR_MIN, WORLD_EVENT_HP_RES_VAR_MAX) / 100))
        ];

        // Calc soldiers
        $reward_soldiers = [];
        $num_slots = ($tc_lvl >= WORLD_EVENT_HP_SLOT_HIGH_TC) ? 3 : ($tc_lvl >= WORLD_EVENT_HP_SLOT_MID_TC ? 2 : WORLD_EVENT_HP_SLOT_LOW);

        for ($i = 0; $i < $num_slots; $i++) {
            $special_chance = WORLD_EVENT_HP_SPECIAL_CHANCE_BASE + ($tc_lvl * WORLD_EVENT_HP_SPECIAL_CHANCE_TC_MULT);

            if (mt_rand(1, 100) <= $special_chance) {
                // SPECIAL POOL
                $sid = [Soldiers::SOLDIER_CONQUEROR, Soldiers::SOLDIER_SCOUT, Soldiers::SOLDIER_RAIDER, Soldiers::SOLDIER_RAM, Soldiers::SOLDIER_THIEF][array_rand([0, 1, 2, 3, 4])];

                $min_scaled = (int)(WORLD_EVENT_HP_UNIT_SPEC_MIN * ($tc_lvl / 2));
                $max_scaled = (int)(WORLD_EVENT_HP_UNIT_SPEC_MAX * ($tc_lvl / 2));
            } else {
                // STANDARD POOL
                $sid = mt_rand(0, 8);

                $min_scaled = (int)(WORLD_EVENT_HP_UNIT_STD_MIN * $avg_lvl);
                $max_scaled = (int)(WORLD_EVENT_HP_UNIT_STD_MAX * $avg_lvl);
            }
            $count = mt_rand(max(1, $min_scaled), max(1, $max_scaled));

            $reward_soldiers[] = ["id" => $sid, "count" => $count];
        }

        return ["resources" => $loot, "soldiers" => $reward_soldiers];
    }

    public function generate_dmg_event_loot(int $damage): array
    {
        if ($damage < WORLD_EVENT_REWARD_MIN_TRESHOLD) {
            return ["coins" => 0, "gold_res" => 0];
        }

        if ($damage >= WORLD_EVENT_REWARD_TRESHOLD_5) $coins = WORLD_EVENT_REWARD_COINS_5;
        else if ($damage >= WORLD_EVENT_REWARD_TRESHOLD_4) $coins = WORLD_EVENT_REWARD_COINS_4;
        else if ($damage >= WORLD_EVENT_REWARD_TRESHOLD_3) $coins = WORLD_EVENT_REWARD_COINS_3;
        else if ($damage >= WORLD_EVENT_REWARD_TRESHOLD_2) $coins = WORLD_EVENT_REWARD_COINS_2;
        else if ($damage >= WORLD_EVENT_REWARD_TRESHOLD_1) $coins = WORLD_EVENT_REWARD_COINS_1;
        else                                               $coins = WORLD_EVENT_REWARD_COINS_MIN;

        $gold_res = (int)($damage / WORLD_EVENT_DMG_GOLD_RATIO);
        $gold_res = min($gold_res, WORLD_EVENT_DMG_GOLD_MAX);

        return ["coins" => $coins, "gold_res" => $gold_res];
    }

    public function get_valid_delivery_kingdom(int $user_id, int $preferred_id): int
    {
        $query = "
            SELECT k.id
            FROM kingdoms k
            JOIN users u ON k.userid = u.id
            WHERE k.userid = ?
            ORDER BY 
                (k.id = ?) DESC,             -- Prio 1: Top Kingdom
                (k.id = u.mainkingdom) DESC,  -- Prio 2: Main Kingdom
                k.id                     -- Prio 3: Some other kingdom
            LIMIT 1
        ";

        $res = $this->mysqli->execute_query($query, [$user_id, $preferred_id]);
        return (int)($res->fetch_column() ?? 0);
    }
}