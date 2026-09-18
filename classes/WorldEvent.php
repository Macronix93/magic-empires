<?php

class WorldEvent
{
    private object $mysqli;

    public function __construct()
    {
        $this->mysqli = Database::get_instance()->get_connection();
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
                    combined.count * (
                        (sl.attack * (CASE 
                            WHEN k.alignment = " . AlignmentTypes::ALIGN_WAR . " 
                            THEN (1.0 + (SELECT base_bonus FROM shrine_alignments WHERE id = 1) + (IFNULL(t_shrine.techlevel, 0) * " . SHRINE_TECH_STEP . ")) 
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
                FROM (
                    SELECT kingdomid, soldierid, soldiercount AS count FROM soldiers
                    
                    UNION ALL

                    SELECT e.kingdomid, st.soldierid, st.soldiercount AS count 
                    FROM sent_troops st
                    JOIN events e ON st.eventid = e.eventid
                ) as combined
                JOIN soldier_list sl ON combined.soldierid = sl.id
                JOIN kingdoms k ON combined.kingdomid = k.id
                LEFT JOIN techs t_inf ON t_inf.kingdomid = k.id AND t_inf.techid = " . TechTypes::TECH_TYPE_BLADES . "
                LEFT JOIN techs t_cav ON t_cav.kingdomid = k.id AND t_cav.techid = " . TechTypes::TECH_TYPE_LANCE_RIDING . "
                LEFT JOIN techs t_arc ON t_arc.kingdomid = k.id AND t_arc.techid = " . TechTypes::TECH_TYPE_ARROWHEADS . "
                LEFT JOIN techs t_shrine ON t_shrine.kingdomid = k.id AND t_shrine.techid = " . TechTypes::TECH_TYPE_ANCESTRAL_RITES . "
            ";

            $server_power = (int)$this->mysqli->query($query)->fetch_column();

            if ($server_power < 5000) $server_power = 50000;

            $total_hp = (int)($server_power * WORLD_EVENT_POWER_FACTOR);
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

        $old_damage = 0;
        if ($type === "DAMAGE") {
            $res_old = $this->mysqli->execute_query(
                "SELECT total_damage FROM world_event_participants WHERE event_id = ? AND userid = ?",
                [$event_id, $user_id]
            );
            $old_damage = (int)($res_old->fetch_column() ?? 0);
        }

        try {
            if ($type === "BOSS_HP") {
                $res = $this->mysqli->execute_query(
                    "SELECT current_hp FROM world_events WHERE id = ? FOR UPDATE",
                    [$event_id]
                );
                $boss = $res->fetch_assoc();

                if (!$boss || $boss["current_hp"] <= 0) {
                    $this->mysqli->rollback();
                    return -1; // Boss already dead
                }

                $old_hp = (int)$boss["current_hp"];
                $actual_damage = min($damage, $old_hp);
                $new_hp = $old_hp - $actual_damage;

                $this->mysqli->execute_query(
                    "UPDATE world_events SET current_hp = current_hp - ? WHERE id = ?",
                    [$actual_damage, $event_id]
                );

                if ($old_hp > 0 && $new_hp <= 0) {
                    $new_end_time = time() + WORLD_EVENT_HP_REWARD_TIME;

                    $this->mysqli->execute_query(
                        "UPDATE world_events SET end_time = ? WHERE id = ?",
                        [$new_end_time, $event_id]
                    );

                    $this->broadcast_defeat_notification($event_id);
                }
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

            if ($type === "DAMAGE") {
                $new_damage = $old_damage + $actual_damage;
                $diff = $this->calculate_dmg_threshold_diff($old_damage, $new_damage);

                if ($diff["coins"] > 0 || $diff["gold"] > 0) {
                    if ($diff["coins"] > 0) {
                        $u_obj = new User($user_id, "");
                        $u_obj->give_user_coins($diff["coins"]);
                    }

                    if ($diff["gold"] > 0 && $kingdom_id > 0) {
                        $g_gold_lvl = Guild::get_user_guild_tech_level($user_id, GuildTechTypes::GUILD_TECH_EVENT_GOLD);
                        $gold_mult = 1.0 + ($g_gold_lvl * GUILD_BONUS_EVENT_GOLD_PER_LVL);
                        $final_event_gold = (int)round($diff["gold"] * $gold_mult);

                        $k_obj = new Kingdom($kingdom_id);
                        $k_obj->give_kingdom_gold($final_event_gold);
                        $diff["gold"] = $final_event_gold;
                    }

                    $rewards_text = [];
                    if ($diff["coins"] > 0) $rewards_text[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <b>+{$diff["coins"]} Münzen</b>";
                    if ($diff["gold"] > 0) $rewards_text[] = get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <b>+" . fnum($diff["gold"]) . "</b>";

                    $msg = "<div class='battle-report'>" . BattleReportRenderer::render_outcome_box(
                            "Event: Neue Stufe erreicht!",
                            "Dein Angriff auf den Welten-Boss hat eine neue Belohnungsstufe freigeschaltet:<br><br><div style='text-align: center;'>" . implode(" und ", $rewards_text) . "</div>
                                <br>Die Schätze wurden deiner Schatzkammer und deinem Lager gutgeschrieben (unter Berücksichtigung deiner Maximalkapazitäten).",
                            0, 0,
                            "Gesamtschaden: " . fnum($new_damage, true),
                            "success"
                        ) . "</div>";

                    $u_name = $this->mysqli->execute_query("SELECT username FROM users WHERE id = ?", [$user_id])->fetch_column() ?: "Spieler";
                    send_server_message($user_id, $u_name, $msg, MessageCategories::CATEGORY_EVENT);
                }
            }

            update_player_stat($user_id, "event_damage_total", $actual_damage);

            return $actual_damage;
        } catch (Exception $e) {
            error_log("WorldEvent Error: " . $e->getMessage());
            return 0;
        }
    }

    private function calculate_dmg_threshold_diff(int $old_damage, int $new_damage): array
    {
        $diff_coins = 0;
        $diff_gold = 0;

        foreach (WORLD_EVENT_DAMAGE_TIERS as $threshold => $rewards) {
            if ($new_damage >= $threshold && $old_damage < $threshold) {
                $diff_coins += $rewards["coins"];
                $diff_gold += $rewards["gold"];
            }
        }

        return [
            "coins" => $diff_coins,
            "gold" => $diff_gold
        ];
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

        $users = broadcast_server_message($msg, MessageCategories::CATEGORY_EVENT);

        foreach ($users as $u) {
            send_user_push(
                (int)$u["id"],
                "👹 Weltenboss gesichtet: " . $monster["name"],
                "Ein mächtiger Gegner ist im Auge des Sturms erschienen!",
                "events",
                "events.php"
            );
        }
    }

    public function broadcast_defeat_notification(int $event_id): void
    {
        $res = $this->mysqli->execute_query("SELECT monster_index FROM world_events WHERE id = ?", [$event_id]);
        $ev = $res->fetch_assoc();

        $pool = $this->get_monster_pool();
        $monster = $pool[$ev["monster_index"]] ?? $pool[0];

        $title = "DAS MONSTER IST GEFALLEN!";
        $text = "<div style='margin: 10px; text-align: center;'><img src='images/icons/{$monster["icon"]}.png' alt=''></div>";
        $text .= "Ein gewaltiges Jubeln bricht in allen Reichen aus! Die Bestie <b>" . e($monster["name"]) . "</b> wurde besiegt.<br><br>";
        $text .= "Alle Teilnehmer werden nach Ablauf des Zeitlimits für ihren Mut belohnt.";

        $msg = "<div class='battle-report'>";
        $msg .= BattleReportRenderer::render_outcome_box($title, $text, 0, 0, "", "success");
        $msg .= "</div>";

        $users = broadcast_server_message($msg, MessageCategories::CATEGORY_EVENT);

        foreach ($users as $u) {
            send_user_push(
                (int)$u["id"],
                "🏆 Weltenboss besiegt!",
                "Das Monster " . $monster["name"] . " wurde von den Spielern bezwungen!",
                "events",
                "events.php"
            );
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
            ResourceTypes::RESOURCE_TYPE_GOLD => (int)round(($base_res * (mt_rand(WORLD_EVENT_HP_RES_VAR_MIN, WORLD_EVENT_HP_RES_VAR_MAX) / 100))
                * (1.0 + (Guild::get_user_guild_tech_level($user_id, GuildTechTypes::GUILD_TECH_EVENT_GOLD) * GUILD_BONUS_EVENT_GOLD_PER_LVL)))
        ];

        // Calc soldiers
        $reward_soldiers = [];
        $num_slots = ($tc_lvl >= WORLD_EVENT_HP_SLOT_HIGH_TC) ? 3 : ($tc_lvl >= WORLD_EVENT_HP_SLOT_MID_TC ? 2 : WORLD_EVENT_HP_SLOT_LOW);

        $conquerors_given = 0;
        $rams_given = 0;

        for ($i = 0; $i < $num_slots; $i++) {
            $special_chance = WORLD_EVENT_HP_SPECIAL_CHANCE_BASE + ($tc_lvl * WORLD_EVENT_HP_SPECIAL_CHANCE_TC_MULT);

            if (mt_rand(1, 100) <= $special_chance) {
                // --- SPECIAL POOL ---
                $roll = mt_rand(1, 100);

                if ($roll <= WORLD_EVENT_HP_CHANCE_CONQUEROR && $conquerors_given < WORLD_EVENT_HP_MAX_CONQUEROR) {
                    // CONQUEROR
                    $sid = Soldiers::SOLDIER_CONQUEROR;
                    $count = WORLD_EVENT_HP_MAX_CONQUEROR;

                    $conquerors_given++;
                } elseif ($roll <= (WORLD_EVENT_HP_CHANCE_CONQUEROR + WORLD_EVENT_HP_CHANCE_RAM) && $rams_given < WORLD_EVENT_HP_MAX_RAM) {
                    // RAM
                    $sid = Soldiers::SOLDIER_RAM;
                    $count = WORLD_EVENT_HP_MAX_RAM;

                    $rams_given++;
                } else {
                    // OTHER SPECIAL UNITS (Scout, Raider, Thief)
                    $sid = [Soldiers::SOLDIER_SCOUT, Soldiers::SOLDIER_RAIDER, Soldiers::SOLDIER_THIEF][array_rand([0, 1, 2])];

                    $min_scaled = (int)(WORLD_EVENT_HP_UNIT_SPEC_MIN * ($tc_lvl / 2));
                    $max_scaled = (int)(WORLD_EVENT_HP_UNIT_SPEC_MAX * ($tc_lvl / 2));

                    $count = mt_rand(max(1, $min_scaled), max(1, $max_scaled));
                }
            } else {
                // --- STANDARD POOL ---
                $sid = mt_rand(0, 8); // Militia to Elf Archer

                $min_scaled = (int)(WORLD_EVENT_HP_UNIT_STD_MIN * $avg_lvl);
                $max_scaled = (int)(WORLD_EVENT_HP_UNIT_STD_MAX * $avg_lvl);

                $count = mt_rand(max(1, $min_scaled), max(1, $max_scaled));
            }

            $reward_soldiers[] = ["id" => $sid, "count" => $count];
        }

        return ["resources" => $loot, "soldiers" => $reward_soldiers];
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

    public function get_current_duration(): int
    {
        $event = $this->get_active_event();
        if (!$event) return WORLD_EVENT_DMG_ATTACK_DURATION;

        return ($event["event_type"] === "BOSS_HP")
            ? WORLD_EVENT_HP_ATTACK_DURATION
            : WORLD_EVENT_DMG_ATTACK_DURATION;
    }
}