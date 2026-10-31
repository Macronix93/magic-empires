<?php

class Arena
{
    private mysqli $db;
    private int $user_id;

    public function __construct(int $user_id)
    {
        $this->db = Database::get_instance()->get_connection();
        $this->user_id = $user_id;

        if ($this->user_id > 0 && $this->is_participating()) {
            $this->check_daily_reset();
            $this->regenerate_coins();
        }
    }

    public function is_participating(?int $uid = null): bool
    {
        $target_id = $uid ?? $this->user_id;
        if ($target_id <= 0) return false;

        $res = $this->db->execute_query(
            "SELECT 1 FROM arena_players WHERE user_id = ? AND defense_troops IS NOT NULL LIMIT 1",
            [$target_id]
        );
        return $res->num_rows > 0;
    }

    public function check_daily_reset(): void
    {
        $data = $this->get_player_data();
        if (!$data) return;

        $now = time();
        $today_start = strtotime("today midnight");
        $last_reset = (int)($data["last_reroll_reset"] ?? 0);

        // At midnight: refresh rerolls and enemies
        if ($last_reset < $today_start) {
            $this->db->execute_query("
                UPDATE arena_players 
                SET daily_rerolls = 0, last_reroll_reset = ?, current_opponents = NULL 
                WHERE user_id = ?
            ", [$now, $this->user_id]);
        }
    }

    public function get_player_data(?int $uid = null): ?array
    {
        $target_id = $uid ?? $this->user_id;
        $res = $this->db->execute_query("
            SELECT ap.*, u.username 
            FROM arena_players ap
            JOIN users u ON ap.user_id = u.id
            WHERE ap.user_id = ?
        ", [$target_id]);

        $row = $res->fetch_assoc();
        if (!$row) return null;

        $row["defense_troops"] = json_decode($row["defense_troops"] ?? "{}", true);
        $row["defense_techs"] = json_decode($row["defense_techs"] ?? "{}", true);
        $row["current_opponents"] = json_decode($row["current_opponents"] ?? "[]", true);
        return $row;
    }

    public function regenerate_coins(): void
    {
        $data = $this->get_player_data();
        if (!$data || $data["coins"] >= ARENA_MAX_COINS) {
            return;
        }

        $now = time();
        $seconds_per_coin = ARENA_COIN_REGEN_HOURS * 3600;
        $elapsed = max(0, $now - (int)$data["last_coin_update"]);

        if ($elapsed >= $seconds_per_coin) {
            $coins_gained = (int)floor($elapsed / $seconds_per_coin);
            $new_coins = min(ARENA_MAX_COINS, (int)$data["coins"] + $coins_gained);

            $remainder = $elapsed % $seconds_per_coin;
            $new_update_time = ($new_coins >= ARENA_MAX_COINS) ? $now : ($now - $remainder);

            $this->db->execute_query("
                UPDATE arena_players 
                SET coins = ?, last_coin_update = ? 
                WHERE user_id = ?
            ", [$new_coins, $new_update_time, $this->user_id]);
        }
    }

    public static function render_opponents_html(array $opponents, array $player_data, int $uid): string
    {
        if (empty($opponents)) {
            return show_warning_box("Aktuell befinden sich keine weiteren Spieler in deiner Wertungs-Reichweite. Versuche es später noch einmal!");
        }

        // Load cooldowns
        $opp_ids = array_column($opponents, "user_id");
        $cooldowns = [];
        if (!empty($opp_ids)) {
            $placeholders = implode(',', array_fill(0, count($opp_ids), '?'));
            $res_cd = Database::get_instance()->get_connection()->execute_query("
                SELECT defender_id, MAX(created_at) as last_battle 
                FROM arena_battles 
                WHERE attacker_id = ? AND defender_id IN ($placeholders)
                GROUP BY defender_id
            ", array_merge([$uid], $opp_ids));
            while ($row = $res_cd->fetch_assoc()) {
                $cooldowns[(int)$row["defender_id"]] = (int)$row["last_battle"];
            }
        }

        $now = time();
        $html = "";
        foreach ($opponents as $opp) {
            $opp_id = (int)$opp["user_id"];
            $opp_elo = (int)$opp["elo"];
            $guild_badge = !empty($opp["guild_tag"]) ? " <small style='color: var(--link-color);'>[" . e($opp["guild_tag"]) . "]</small>" : "";

            [$win_gain,] = Arena::calculate_elo((int)$player_data["elo"], $opp_elo, $uid, $uid);
            [$loss_gain,] = Arena::calculate_elo((int)$player_data["elo"], $opp_elo, $opp_id, $uid);
            $gain_text = "<span class='passed'>+$win_gain</span> / <span class='error'>$loss_gain</span>";

            $last_battle_time = $cooldowns[$opp_id] ?? 0;
            $cooldown_left = max(0, ($last_battle_time + ARENA_OPPONENT_ATTACK_COOLDOWN) - $now);

            if ($cooldown_left > 0) {
                $button_html = "
                    <button type='button' class='btn-accept' disabled style='min-width: 120px; font-variant-numeric: tabular-nums;' title='Gegner-Cooldown aktiv'>
                        <span class='js-countdown' data-seconds='$cooldown_left' data-keep-params='true' data-zero-text='Bereit'>" . format_time_for_js($cooldown_left) . "</span>
                    </button>";
            } else {
                $is_no_coins = ($player_data["coins"] <= 0);
                $btn_title = $is_no_coins ? "title='Keine Arena-Münzen übrig!'" : "";
                $button_html = " <button type='button' class='btn-accept'  
                                    data-on-click='openArenaAttackInline'  
                                    data-id='$opp_id'  
                                    data-name='" . e($opp["username"]) . "'  
                                    data-elo='$opp_elo' " . ($is_no_coins ? "disabled" : "") . " $btn_title style='min-width: 120px;'> Herausfordern </button>";
            }

            $html .= "
            <div class='box-container opponent-card' style='margin-bottom: 0;'>
                <div class='box-content box-content-bg' style='padding: 10px 15px; display: flex; align-items: center; justify-content: space-between;'>
                    <div style='display: flex; align-items: center; gap: 12px;'>
                        <img src='{$opp["avatar"]}' class='user-image' style='width: 42px; height: 42px;' alt=''>
                        <div style='text-align: left;'>
                            <b>" . e($opp["username"]) . "$guild_badge</b><br>
                            <span style='color: var(--link-color); font-weight: bold;'>" . fnum($opp_elo) . " ELO</span> 
                            <small>($gain_text)</small>
                        </div>
                    </div>
                    <div>
                        $button_html
                    </div>
                </div>
            </div>";
        }
        return $html;
    }

    public static function calculate_elo(int $attacker_elo, int $defender_elo, int $winner_id, int $attacker_id): array
    {
        if ($winner_id === 0) {
            return [0, 0];
        }

        $expected_attacker = 1.0 / (1.0 + pow(10, ($defender_elo - $attacker_elo) / 400));
        $expected_defender = 1.0 - $expected_attacker;

        $score_attacker = ($winner_id === $attacker_id) ? 1.0 : 0.0;
        $score_defender = 1.0 - $score_attacker;

        $k = ARENA_ELO_K_FACTOR;
        $change_attacker = (int)round($k * ($score_attacker - $expected_attacker));
        $change_defender = (int)round($k * ($score_defender - $expected_defender));

        $loss_factor = ARENA_ELO_LOSS_FACTOR;
        if ($change_attacker < 0) {
            $change_attacker = (int)round($change_attacker * $loss_factor);
        }
        if ($change_defender < 0) {
            $change_defender = (int)round($change_defender * $loss_factor);
        }

        return [$change_attacker, $change_defender];
    }

    public static function check_and_process_season_reset(bool $force = false, bool $distribute_rewards = true): int
    {
        $db = Database::get_instance()->get_connection();
        $now = time();
        $info = self::get_season_time_info();
        $current_month_key = date('Y-m', $now);
        $last_rewarded_month = (string)($db->execute_query(
            "SELECT value FROM system_settings WHERE name = 'arena_season_rewarded_month'"
        )->fetch_column() ?? '');

        // PHASE 1: Active phase finished -> give users rewards
        if ($force || (!$info["is_active"] && $last_rewarded_month !== $current_month_key)) {
            $players_res = $db->query("
                SELECT ap.user_id, ap.elo, ap.wins, ap.losses, ap.draws, ap.season_battles, ap.reward_kingdom_id,
                       u.username, u.mainkingdom
                FROM arena_players ap
                JOIN users u ON ap.user_id = u.id
                WHERE ap.defense_troops IS NOT NULL 
                  AND ap.season_battles > 0 
                  AND u.status = 1
                ORDER BY ap.elo DESC, ap.wins DESC, ap.user_id
            ");
            $ranked_players = $players_res->fetch_all(MYSQLI_ASSOC);
            $total_rewarded = count($ranked_players);
            $rank = 1;

            $messages_to_send = [];
            $stats_to_update = [];

            $db->begin_transaction();
            try {
                $db->execute_query("DELETE FROM arena_season_history WHERE season_month = ?", [$current_month_key]);

                foreach ($ranked_players as $p) {
                    $uid = (int)$p["user_id"];
                    $uname = $p["username"];
                    $old_elo = (int)$p["elo"];
                    $preferred_kid = (int)($p["reward_kingdom_id"] ?? 0);

                    if ($distribute_rewards) {
                        if ($rank <= 3) {
                            $stats_to_update[] = [$uid, "arena_top3_count"];
                        }
                        if ($rank <= 5) {
                            $stats_to_update[] = [$uid, "arena_top5_count"];
                        }

                        $reward = null;
                        foreach (ARENA_SEASON_REWARDS as $max_rank => $rew) {
                            if ($rank <= $max_rank) {
                                $reward = $rew;
                                break;
                            }
                        }

                        if ($reward) {
                            $u_obj = new User($uid, $uname);
                            if ($reward["coins"] > 0) {
                                $u_obj->give_user_coins($reward["coins"]);
                            }

                            $target_kid = 0;
                            if ($preferred_kid > 0) {
                                $check_k = $db->execute_query("SELECT id FROM kingdoms WHERE id = ? AND userid = ?", [$preferred_kid, $uid]);
                                if ($check_k->num_rows > 0) $target_kid = $preferred_kid;
                            }
                            if ($target_kid <= 0) {
                                $target_kid = (int)($db->execute_query("
                                    SELECT id FROM kingdoms WHERE userid = ? 
                                    ORDER BY (id = (SELECT mainkingdom FROM users WHERE id = ?)) DESC, id LIMIT 1
                                ", [$uid, $uid])->fetch_column() ?? 0);
                            }

                            $player_max_tc = self::get_player_max_tc($uid);
                            $scaled_res = (int)round($reward["res"] / MAX_BUILDING_LEVEL * $player_max_tc);
                            $res_loot = [];
                            if ($scaled_res > 0 && $target_kid > 0) {
                                $k_obj = new Kingdom($target_kid);
                                $k_obj->give_kingdom_food($scaled_res);
                                $k_obj->give_kingdom_wood($scaled_res);
                                $k_obj->give_kingdom_stone($scaled_res);
                                $k_obj->give_kingdom_gold($scaled_res);
                                $res_loot = [
                                    ResourceTypes::RESOURCE_TYPE_COINS => $reward["coins"],
                                    ResourceTypes::RESOURCE_TYPE_FOOD => $scaled_res,
                                    ResourceTypes::RESOURCE_TYPE_WOOD => $scaled_res,
                                    ResourceTypes::RESOURCE_TYPE_STONE => $scaled_res,
                                    ResourceTypes::RESOURCE_TYPE_GOLD => $scaled_res
                                ];
                            }

                            $messages_to_send[] = [
                                "uid" => $uid,
                                "uname" => $uname,
                                "json" => [
                                    "template" => "arena_season_reward",
                                    "rank" => $rank,
                                    "tier_label" => $reward["label"],
                                    "elo" => $old_elo,
                                    "loot" => $res_loot
                                ]
                            ];
                        }
                    }

                    // Entry for seasonal archive
                    $db->execute_query("
                        INSERT INTO arena_season_history 
                        (season_month, rank_pos, user_id, elo, wins, losses, draws, battles, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ", [
                        $current_month_key,
                        $rank,
                        $uid,
                        $old_elo,
                        (int)$p["wins"],
                        (int)$p["losses"],
                        (int)$p["draws"],
                        (int)$p["season_battles"],
                        $now
                    ]);

                    // Reset players in arena
                    $db->execute_query("
                    UPDATE arena_players 
                        SET elo = ?, coins = ?, last_season_rank = ?, wins = 0, losses = 0, draws = 0, 
                            season_battles = 0, daily_rerolls = 0, hero_level = 1, hero_xp = 0, active_hero_skill_id = 1, 
                            last_reroll_reset = ?, current_opponents = NULL, defense_troops = NULL, defense_techs = NULL, 
                            reward_kingdom_id = NULL, last_coin_update = ?, updated_at = ?
                        WHERE user_id = ?
                    ", [ARENA_DEFAULT_ELO, ARENA_MAX_COINS, $rank, $now, $now, $now, $uid]);

                    $rank++;
                }

                // Reset inactive players
                $db->execute_query("
                    UPDATE arena_players 
                    SET elo = ?, coins = ?, wins = 0, losses = 0, draws = 0, season_battles = 0, last_season_rank = NULL, 
                        daily_rerolls = 0, hero_level = 1, hero_xp = 0, active_hero_skill_id = 1, last_reroll_reset = ?, 
                        current_opponents = NULL, defense_troops = NULL, defense_techs = NULL, reward_kingdom_id = NULL, 
                        last_coin_update = ?, updated_at = ?
                    WHERE season_battles = 0
                ", [ARENA_DEFAULT_ELO, ARENA_MAX_COINS, $now, $now, $now]);

                // Clear battles and hero skills
                /* @noinspection SqlWithoutWhere */
                $db->query("DELETE FROM arena_battles");
                /* @noinspection SqlWithoutWhere */
                $db->query("DELETE FROM arena_hero_skills");

                // Mark month as rewarded
                if ($distribute_rewards) {
                    $db->execute_query("
                    INSERT INTO system_settings (name, value) 
                    VALUES ('arena_season_rewarded_month', ?) 
                    ON DUPLICATE KEY UPDATE value = VALUES(value)
                ", [$current_month_key]);
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                Logger::get_instance()->error("Fehler beim Arena Saison-Reset: " . $e->getMessage());
                return 0;
            }

            try {
                $db->query("ALTER TABLE arena_battles AUTO_INCREMENT = 1");
            } catch (Throwable) {
            }

            foreach ($messages_to_send as $m) {
                Messages::send_server_message($m["uid"], $m["uname"], MessageCategories::CATEGORY_DEFAULT, $m["json"]);
            }
            foreach ($stats_to_update as $s) {
                Stats::update_player_stat($s[0], $s[1]);
            }

            return $total_rewarded;
        }

        return 0;
    }

    public static function get_season_time_info(): array
    {
        $now = time();
        $year = (int)date('Y', $now);
        $month = (int)date('n', $now);
        $days_in_month = (int)date('t', $now);
        $active_end_day = $days_in_month - 2;

        $end_active = mktime(23, 59, 59, $month, $active_end_day, $year);
        $start_next = mktime(0, 0, 0, $month + 1, 1, $year);

        $is_active = ($now <= $end_active);
        $current_day = (int)date('j', $now);

        return [
            "is_active" => $is_active,
            "seconds_until_pause" => max(0, $end_active - $now),
            "seconds_until_next_season" => max(0, $start_next - $now),
            "current_day" => $current_day,
            "total_active_days" => $active_end_day,
            "days_in_month" => $days_in_month
        ];
    }

    public static function get_player_max_tc(int $user_id): int
    {
        $db = Database::get_instance()->get_connection();
        $res = $db->execute_query("
            SELECT IFNULL(MAX(b.buildinglevel), 1)
            FROM buildings b
            JOIN kingdoms k ON b.kingdomid = k.id
            WHERE b.buildingid = ? AND k.userid = ?
        ", [BuildingTypes::BUILDING_TOWNCENTER, $user_id]);
        return max(1, (int)($res->fetch_column() ?? 1));
    }

    public static function has_available_coins(int $user_id): bool
    {
        static $cache = [];
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }

        if ($user_id <= 0 || !self::is_season_active()) return false;

        $arena = new self($user_id);
        if (!$arena->is_participating()) return false;

        $data = $arena->get_player_data();

        return $cache[$user_id] = ($data && (int)$data["coins"] > 0);
    }

    public static function is_season_active(): bool
    {
        static $active = null;

        if ($active !== null) {
            return $active;
        }

        return $active = self::get_season_time_info()["is_active"];
    }

    public function get_rerolls_left(): int
    {
        $data = $this->get_player_data();
        if (!$data) return 0;
        $today_start = strtotime("today midnight");
        $last_reset = (int)($data["last_reroll_reset"] ?? 0);
        if ($last_reset < $today_start) {
            return ARENA_MAX_REROLLS_PER_DAY;
        }
        $used = (int)($data["daily_rerolls"] ?? 0);
        return max(0, ARENA_MAX_REROLLS_PER_DAY - $used);
    }

    public function save_defense_setup(array $troops, array $techs, int $reward_kingdom_id = 0): ?string
    {
        $error = self::validate_setup($troops, $techs);
        if ($error !== null) {
            return $error;
        }

        $now = time();
        $troops_json = json_encode($troops);
        $techs_json = json_encode($techs);

        $valid_reward_kid = null;
        if ($reward_kingdom_id > 0) {
            $check = $this->db->execute_query(
                "SELECT id FROM kingdoms WHERE id = ? AND userid = ?",
                [$reward_kingdom_id, $this->user_id]
            );
            if ($check->num_rows > 0) {
                $valid_reward_kid = $reward_kingdom_id;
            }
        }

        if ($this->is_participating()) {
            $this->db->execute_query("
                UPDATE arena_players 
                SET defense_troops = ?, 
                    defense_techs = ?, 
                    reward_kingdom_id = ?, 
                    defense_hero_participates = 1, 
                    updated_at = ? 
                WHERE user_id = ?
            ", [$troops_json, $techs_json, $valid_reward_kid, $now, $this->user_id]);
        } else {
            $this->db->execute_query("
                INSERT INTO arena_players 
                    (user_id, elo, coins, last_coin_update, defense_troops, defense_techs, reward_kingdom_id, defense_hero_participates, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE 
                    defense_troops = VALUES(defense_troops),
                    defense_techs = VALUES(defense_techs),
                    reward_kingdom_id = VALUES(reward_kingdom_id),
                    defense_hero_participates = 1,
                    updated_at = VALUES(updated_at)
            ", [
                $this->user_id,
                ARENA_DEFAULT_ELO,
                ARENA_MAX_COINS,
                $now,
                $troops_json,
                $techs_json,
                $valid_reward_kid,
                $now
            ]);
        }

        return null;
    }

    public static function validate_setup(array $troops, array $techs): ?string
    {
        $total_units = 0;

        foreach ($troops as $id => $count) {
            $cnt = max(0, (int)$count);

            if (!in_array((int)$id, ARENA_ALLOWED_SOLDIER_IDS)) {
                return "Ungültige Einheit gewählt.";
            }

            $total_units += $cnt;
        }

        if ($total_units !== ARENA_ARMY_UNIT_LIMIT) {
            return "Du musst exakt " . fnum(ARENA_ARMY_UNIT_LIMIT) . " Einheiten aufstellen (Aktuell: " . fnum($total_units) . ").";
        }

        $total_tech_points = 0;

        foreach ($techs as $id => $lvl) {
            $level = max(0, (int)$lvl);

            if (!in_array((int)$id, ARENA_ALLOWED_TECH_IDS)) {
                return "Ungültige Technologie gewählt.";
            }

            if ($level > ARENA_MAX_TECH_LEVEL) {
                return "Eine Technologie darf maximal Stufe " . ARENA_MAX_TECH_LEVEL . " haben.";
            }

            $total_tech_points += $level;
        }

        if ($total_tech_points > ARENA_MAX_TECH_POINTS) {
            return "Du hast zu viele Technologie-Punkte verteilt (Maximal " . ARENA_MAX_TECH_POINTS . ").";
        }

        return null;
    }

    public function get_seconds_until_next_coin(): int
    {
        $data = $this->get_player_data();

        if (!$data || $data["coins"] >= ARENA_MAX_COINS) {
            return 0;
        }

        $seconds_per_coin = ARENA_COIN_REGEN_HOURS * 3600;
        $elapsed = max(0, time() - (int)$data["last_coin_update"]);
        return max(0, $seconds_per_coin - ($elapsed % $seconds_per_coin));
    }

    public function get_or_roll_opponents(bool $force_reroll = false): array
    {
        $player = $this->get_player_data();
        if (!$player) return [];

        $current_ids = $player["current_opponents"] ?? [];
        if (!$force_reroll && !empty($current_ids)) {
            $opponents = $this->fetch_opponents_by_ids($current_ids);
            if (count($opponents) === count($current_ids)) {
                return $opponents;
            }
        }

        if ($force_reroll) {
            $this->db->execute_query("
                UPDATE arena_players 
                SET daily_rerolls = daily_rerolls + 1, last_reroll_reset = ? 
                WHERE user_id = ?
            ", [time(), $this->user_id]);
        }

        $my_elo = (int)$player["elo"];
        $min_elo = $my_elo - ARENA_ROLL_ELO_WINDOW;
        $max_elo = $my_elo + ARENA_ROLL_ELO_WINDOW;

        $res = $this->db->execute_query("
            SELECT ap.user_id FROM arena_players ap 
            JOIN users u ON ap.user_id = u.id 
            WHERE ap.user_id != ? 
              AND ap.defense_troops IS NOT NULL 
              AND u.status = 1 
              AND u.is_banned = 0 
              AND ap.elo BETWEEN ? AND ?
            ORDER BY RAND() LIMIT ?
        ", [$this->user_id, $min_elo, $max_elo, ARENA_ROLL_COUNT]);

        $selected_ids = array_column($res->fetch_all(MYSQLI_ASSOC), "user_id");

        if (count($selected_ids) < ARENA_ROLL_COUNT) {
            $needed = ARENA_ROLL_COUNT - count($selected_ids);
            $exclude_ids = array_merge([$this->user_id], $selected_ids);
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '?'));

            $fallback_res = $this->db->execute_query("
                SELECT ap.user_id FROM arena_players ap 
                JOIN users u ON ap.user_id = u.id 
                WHERE ap.user_id NOT IN ($placeholders) 
                  AND ap.defense_troops IS NOT NULL 
                  AND u.status = 1 
                  AND u.is_banned = 0 
                ORDER BY ABS(ap.elo - ?), RAND() LIMIT ?
            ", array_merge($exclude_ids, [$my_elo, $needed]));

            $fallback_ids = array_column($fallback_res->fetch_all(MYSQLI_ASSOC), "user_id");
            $selected_ids = array_merge($selected_ids, $fallback_ids);
        }

        $this->db->execute_query("
            UPDATE arena_players 
            SET current_opponents = ? 
            WHERE user_id = ?
        ", [json_encode($selected_ids), $this->user_id]);

        return $this->fetch_opponents_by_ids($selected_ids);
    }

    private function fetch_opponents_by_ids(array $ids): array
    {
        if (empty($ids)) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $res = $this->db->execute_query("
            SELECT ap.user_id, ap.elo, ap.wins, ap.losses, u.username, u.guildid, g.tag as guild_tag
            FROM arena_players ap
            JOIN users u ON ap.user_id = u.id
            LEFT JOIN guilds g ON u.guildid = g.id
            WHERE ap.user_id IN ($placeholders)
              AND ap.defense_troops IS NOT NULL
        ", $ids);

        $opponents = [];

        while ($row = $res->fetch_assoc()) {
            $u_obj = new User((int)$row["user_id"], $row["username"]);
            $row["avatar"] = $u_obj->get_avatar();
            $opponents[] = $row;
        }

        return $opponents;
    }

    public function attack_opponent(int $defender_id, array $attack_troops, array $attack_techs): array
    {
        if (!$this->is_participating()) {
            return ["success" => false, "error" => "Du musst zuerst eine eigene Verteidigung aufstellen!"];
        }

        $attacker = $this->get_player_data();
        if ((int)$attacker["coins"] < 1) {
            return ["success" => false, "error" => "Du hast keine Arena-Coins mehr!"];
        }

        if ($defender_id === $this->user_id) {
            return ["success" => false, "error" => "Du kannst dich nicht selbst herausfordern."];
        }

        $cooldown_left = $this->get_opponent_cooldown_left($defender_id);
        if ($cooldown_left > 0) {
            return [
                "success" => false,
                "error" => "Du kannst diesen Herrscher erst in " . convert_sec_to_str($cooldown_left) . " wieder herausfordern!"
            ];
        }

        $defender = $this->get_player_data($defender_id);
        if (!$defender || empty($defender["defense_troops"])) {
            return ["success" => false, "error" => "Dieser Gegner ist nicht mehr verfügbar."];
        }

        $val_error = self::validate_setup($attack_troops, $attack_techs);
        if ($val_error !== null) {
            return ["success" => false, "error" => $val_error];
        }

        $res_soldiers = $this->db->query("SELECT id, soldiername, category, attack, defense, icon FROM soldier_list");
        $soldier_meta = [];
        while ($s = $res_soldiers->fetch_assoc()) {
            $soldier_meta[(int)$s["id"]] = $s;
        }

        $atk_prepared = $this->prepare_combat_units($attack_troops, $attack_techs, $soldier_meta);
        $def_prepared = $this->prepare_combat_units($defender["defense_troops"], $defender["defense_techs"], $soldier_meta);

        // Load Hero
        $atk_data = $this->get_hero_full_data();
        $atk_hero = $atk_data["hero"];
        $atk_skill = $atk_data["active_skill"];

        $def_arena = new Arena($defender_id);
        $def_data = $def_arena->get_hero_full_data();
        $def_hero = $def_data["hero"];
        $def_skill = $def_data["active_skill"];

        // Add attacker hero
        $atk_prepared[] = [
            "id" => "hero_1",
            "key" => "atk_hero",
            "count" => 1,
            "category" => SoldierTypes::SOLDIER_TYPE_SPECIAL,
            "atk" => (int)$atk_hero["atk"],
            "def" => (int)$atk_hero["def"],
            "target_cat" => (int)$atk_skill["target_cat"],
            "rps_bonus" => (float)$atk_skill["rps_bonus"],
            "target_focus" => 1.0,
            "h_lvl" => (int)$atk_hero["level"],
            "s_id" => (int)$atk_skill["id"]
        ];

        // Add defender hero
        $def_prepared[] = [
            "id" => "hero_1",
            "key" => "def_hero",
            "count" => 1,
            "category" => SoldierTypes::SOLDIER_TYPE_SPECIAL,
            "atk" => (int)$def_hero["atk"],
            "def" => (int)$def_hero["def"],
            "target_cat" => (int)$def_skill["target_cat"],
            "rps_bonus" => (float)$def_skill["rps_bonus"],
            "target_focus" => 1.0,
            "h_lvl" => (int)$def_hero["level"],
            "s_id" => (int)$def_skill["id"]
        ];

        $combat_result = CombatEngine::calculate_pvp($atk_prepared, $def_prepared);

        // Get winner
        $winner_id = 0;

        Stats::update_player_stat($this->user_id, "arena_battles");
        Stats::update_player_stat($defender_id, "arena_battles");

        if ($combat_result["attacker_won"]) {
            $winner_id = $this->user_id;

            Stats::update_player_stat($this->user_id, "arena_wins");
            Stats::update_player_stat($defender_id, "arena_losses");
        } else if ($combat_result["defender_won"]) {
            $winner_id = $defender_id;

            Stats::update_player_stat($defender_id, "arena_wins");
            Stats::update_player_stat($this->user_id, "arena_losses");
        }

        // Calculate elo
        [$atk_elo_change, $def_elo_change] = self::calculate_elo(
            (int)$attacker["elo"],
            (int)$defender["elo"],
            $winner_id,
            $this->user_id
        );

        $min_elo = ARENA_MIN_ELO;
        $new_atk_elo = max($min_elo, (int)$attacker["elo"] + $atk_elo_change);
        $new_def_elo = max($min_elo, (int)$defender["elo"] + $def_elo_change);

        $this->db->begin_transaction();
        try {
            $now = time();

            $new_coins = (int)$attacker["coins"] - 1;
            $new_update_time = ((int)$attacker["coins"] >= ARENA_MAX_COINS) ? $now : (int)$attacker["last_coin_update"];

            $atk_win_inc = ($winner_id === $this->user_id) ? 1 : 0;
            $atk_loss_inc = ($winner_id === $defender_id) ? 1 : 0;
            $atk_draw_inc = ($winner_id === 0) ? 1 : 0;

            $this->db->execute_query("
                UPDATE arena_players 
                SET coins = ?, 
                    last_coin_update = ?, 
                    elo = ?, 
                    wins = wins + ?, 
                    losses = losses + ?, 
                    draws = draws + ?, 
                    season_battles = season_battles + 1,
                    updated_at = ?
                WHERE user_id = ?
            ", [$new_coins, $new_update_time, $new_atk_elo, $atk_win_inc, $atk_loss_inc, $atk_draw_inc, $now, $this->user_id]);

            // Update defender
            $def_win_inc = ($winner_id === $defender_id) ? 1 : 0;
            $def_loss_inc = ($winner_id === $this->user_id) ? 1 : 0;
            $def_draw_inc = ($winner_id === 0) ? 1 : 0;

            $this->db->execute_query("
                UPDATE arena_players 
                SET elo = ?, 
                    wins = wins + ?, 
                    losses = losses + ?, 
                    draws = draws + ?, 
                    season_battles = season_battles + 1,
                    updated_at = ? 
                WHERE user_id = ?
            ", [$new_def_elo, $def_win_inc, $def_loss_inc, $def_draw_inc, $now, $defender_id]);

            // Distribute XP
            $hero_leveled_up = false;
            $skill_leveled_up = false;
            $new_hero_level = $atk_hero["level"];
            $new_skill_level = $atk_skill["level"];

            $today_start = strtotime("today midnight");
            $fights_today = (int)$this->db->execute_query("
                SELECT COUNT(*) FROM arena_battles 
                WHERE attacker_id = ? AND defender_id = ? AND created_at >= ?
            ", [$this->user_id, $defender_id, $today_start])->fetch_column();

            if ($combat_result["attacker_won"]) {
                $atk_outcome = "win";
            } else if ($combat_result["defender_won"]) {
                $atk_outcome = "loss";
            } else {
                $atk_outcome = "draw";
            }

            $atk_xp_gained = self::calculate_battle_xp($atk_elo_change, $atk_outcome, $fights_today);

            if ($atk_xp_gained > 0) {
                $r_hero = $this->add_hero_xp($atk_xp_gained);
                $hero_leveled_up = $r_hero["leveled_up"];
                $new_hero_level = $r_hero["level"];

                $r_skill = $this->add_skill_xp($atk_skill["id"], $atk_xp_gained);
                $skill_leveled_up = $r_skill["leveled_up"];
                $new_skill_level = $r_skill["level"];
            }

            if ($combat_result["defender_won"]) {
                $def_outcome = "win";
            } else if ($combat_result["attacker_won"]) {
                $def_outcome = "loss";
            } else {
                $def_outcome = "draw";
            }

            $def_xp_gained = self::calculate_battle_xp($def_elo_change, $def_outcome);

            if ($def_xp_gained > 0) {
                $def_arena->add_hero_xp($def_xp_gained);
                $def_arena->add_skill_xp($def_skill["id"], $def_xp_gained);
            }

            // Battle details
            $strip_troops = function (array $troops): array {
                return array_map(function ($u) {
                    $entry = [
                        "id" => $u["id"],
                        "key" => $u["key"] ?? $u["id"],
                        "count" => (int)$u["count"],
                        "atk" => (int)$u["atk"],
                        "def" => (int)$u["def"]
                    ];

                    if (!empty($u["h_lvl"])) {
                        $entry["h_lvl"] = (int)$u["h_lvl"];
                        $entry["s_id"] = (int)$u["s_id"];
                    }

                    return $entry;
                }, $troops);
            };

            $battle_details = [
                "attacker" => [
                    "id" => $this->user_id,
                    "name" => $attacker["username"],
                    "elo_before" => (int)$attacker["elo"],
                    "elo_after" => $new_atk_elo,
                    "elo_change" => $atk_elo_change,
                    "troops" => $strip_troops($atk_prepared),
                    "losses" => $combat_result["attacker_losses"]
                ],
                "defender" => [
                    "id" => $defender_id,
                    "name" => $defender["username"],
                    "elo_before" => (int)$defender["elo"],
                    "elo_after" => $new_def_elo,
                    "elo_change" => $def_elo_change,
                    "troops" => $strip_troops($def_prepared),
                    "losses" => $combat_result["defender_losses"]
                ],
                "combat" => [
                    "winner_id" => $winner_id,
                    "total_atk_units" => $combat_result["total_atk_units"],
                    "total_def_units" => $combat_result["total_def_units"],
                    "total_atk_losses" => $combat_result["total_atk_losses"],
                    "total_def_losses" => $combat_result["total_def_losses"]
                ]
            ];

            $this->db->execute_query("
                INSERT INTO arena_battles 
                (attacker_id, defender_id, winner_id, attacker_elo_change, defender_elo_change, attacker_elo_after, defender_elo_after, details_json, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $this->user_id,
                $defender_id,
                $winner_id,
                $atk_elo_change,
                $def_elo_change,
                $new_atk_elo,
                $new_def_elo,
                json_encode($battle_details),
                $now
            ]);

            $this->db->commit();

            // Prepare unit badges
            $atk_cards = [];
            foreach ($atk_prepared as $u) {
                $key = $u["key"] ?? $u["id"];
                $atk_cards[] = [
                    "id" => $u["id"],
                    "initial" => $u["count"],
                    "losses" => $combat_result["attacker_losses"][$key] ?? 0,
                    "atk" => $u["atk"],
                    "def" => $u["def"],
                    "h_lvl" => $u["h_lvl"] ?? null,
                    "s_id" => $u["s_id"] ?? null,
                    "is_fictional" => false
                ];
            }

            $def_cards = [];
            foreach ($def_prepared as $u) {
                $key = $u["key"] ?? $u["id"];
                $def_cards[] = [
                    "id" => $u["id"],
                    "initial" => $u["count"],
                    "losses" => $combat_result["defender_losses"][$key] ?? 0,
                    "atk" => $u["atk"],
                    "def" => $u["def"],
                    "h_lvl" => $u["h_lvl"] ?? null,
                    "s_id" => $u["s_id"] ?? null,
                    "is_fictional" => false
                ];
            }

            $grid_html = BattleReportRenderer::render_vs_grid(
                $atk_cards,
                $def_cards,
                "Deine Angreifer",
                "Verteidigung: " . e($defender["username"])
            );

            $is_won = ($winner_id === $this->user_id);
            $is_draw = ($winner_id === 0);
            $outcome_type = $is_draw ? "neutral" : ($is_won ? "success" : "error");
            $outcome_title = $is_draw ? "Unentschieden!" : ($is_won ? "Sieg!" : "Niederlage!");

            if ($is_draw || $atk_elo_change === 0) {
                $change_str = "+/- 0";
                $change_style = "style='color: #ffffff;'";
                $change_class = "";
            } else {
                $change_str = ($atk_elo_change > 0) ? "+$atk_elo_change" : "$atk_elo_change";
                $change_class = ($atk_elo_change > 0) ? "passed" : "error";
                $change_style = "";
            }

            // Show hero and skill in report
            $xp_text = ($atk_xp_gained > 0) ? "<span class='passed'>+$atk_xp_gained XP</span>" : "<span style='color: rgb(230, 220, 200);'>0 XP</span>";
            $h_up = $hero_leveled_up ? " <b style='color: #ba55d3;'>★ HELD LEVEL UP! ★</b>" : "";
            $s_up = $skill_leveled_up ? " <b style='color: #ba55d3;'>★ SKILL LEVEL UP! ★</b>" : "";
            $hero_xp_notice = "<br><span style='color: var(--link-color);'>" .
                "Held (Stufe $new_hero_level): $xp_text$h_up<br>" . "{$atk_skill["name"]} (Stufe $new_skill_level): $xp_text$s_up" . "</span>";

            $main_text = "<div class='arena-battle-log'>Wertungs-Änderung: <b class='$change_class' $change_style>$change_str ELO</b> (Neu: <b>" . fnum($new_atk_elo) . " ELO</b>)" . $hero_xp_notice . "</div>";

            $outcome_html = BattleReportRenderer::render_outcome_box(
                $outcome_title,
                $main_text,
                0,
                0,
                "",
                $outcome_type
            );

            $battle_html = "
            <div class='battle-report' style='padding: 5px; margin: 0 auto; text-align: left;'>
                <div class='title-border' style='margin-top: 0;'>Arena-Duell: vs " . e($defender["username"]) . "</div>
                $grid_html
                $outcome_html
                <div style='text-align: center; margin-top: 15px;'>
                    <button type='button' data-on-click='navigate' data-url='arena.php?tab=battle' style='padding: 8px 30px; font-weight: bold;'>
                        Weiter
                    </button>
                </div>
            </div>";

            return [
                "success" => true,
                "winner_id" => $winner_id,
                "attacker_won" => ($winner_id === $this->user_id),
                "is_draw" => ($winner_id === 0),
                "atk_elo_change" => $atk_elo_change,
                "def_elo_change" => $def_elo_change,
                "new_coins" => $new_coins,
                "battle_details" => $battle_details,
                "battle_html" => $battle_html
            ];
        } catch (Throwable $e) {
            $this->db->rollback();
            return ["success" => false, "error" => "Fehler bei der Kampfabwicklung: " . $e->getMessage()];
        }
    }

    public function get_opponent_cooldown_left(int $defender_id): int
    {
        $last_battle = (int)$this->db->execute_query("
            SELECT MAX(created_at) 
            FROM arena_battles 
            WHERE attacker_id = ? AND defender_id = ?
        ", [$this->user_id, $defender_id])->fetch_column();

        if ($last_battle <= 0) return 0;
        return max(0, ($last_battle + ARENA_OPPONENT_ATTACK_COOLDOWN) - time());
    }

    private function prepare_combat_units(array $troops, array $techs, array $soldier_meta): array
    {
        $inf_a = (int)($techs[TechTypes::TECH_TYPE_BLADES] ?? 0) * SMITHY_INF_ATK_BONUS;
        $inf_d = (int)($techs[TechTypes::TECH_TYPE_SHIELDWALL] ?? 0) * SMITHY_INF_DEF_BONUS;
        $cav_a = (int)($techs[TechTypes::TECH_TYPE_LANCE_RIDING] ?? 0) * SMITHY_CAV_ATK_BONUS;
        $cav_d = (int)($techs[TechTypes::TECH_TYPE_CUIRASS] ?? 0) * SMITHY_CAV_DEF_BONUS;
        $arc_a = (int)($techs[TechTypes::TECH_TYPE_ARROWHEADS] ?? 0) * SMITHY_ARC_ATK_BONUS;
        $arc_d = (int)($techs[TechTypes::TECH_TYPE_DOUBLET] ?? 0) * SMITHY_ARC_DEF_BONUS;

        $prepared = [];
        foreach ($troops as $id => $count) {
            $cnt = (int)$count;

            if ($cnt <= 0 || !isset($soldier_meta[$id])) continue;

            $meta = $soldier_meta[$id];
            $cat = (int)$meta["category"];

            $b_atk = match ($cat) {
                SoldierTypes::SOLDIER_TYPE_INFANTRY => $inf_a,
                SoldierTypes::SOLDIER_TYPE_CAVALRY => $cav_a,
                SoldierTypes::SOLDIER_TYPE_ARCHERS => $arc_a,
                default => 0
            };

            $b_def = match ($cat) {
                SoldierTypes::SOLDIER_TYPE_INFANTRY => $inf_d,
                SoldierTypes::SOLDIER_TYPE_CAVALRY => $cav_d,
                SoldierTypes::SOLDIER_TYPE_ARCHERS => $arc_d,
                default => 0
            };

            $prepared[] = [
                "id" => (int)$id,
                "key" => (int)$id,
                "name" => $meta["soldiername"],
                "icon" => $meta["icon"],
                "count" => $cnt,
                "category" => $cat,
                "atk" => (int)$meta["attack"] + $b_atk,
                "def" => (int)$meta["defense"] + $b_def
            ];
        }
        return $prepared;
    }

    public function get_hero_full_data(): array
    {
        $p = $this->get_player_data();
        $hero_lvl = (int)($p["hero_level"] ?? 1);
        $hero_xp = (int)($p["hero_xp"] ?? 0);
        $active_skill_id = (int)($p["active_hero_skill_id"] ?? 1);

        if (!isset(ARENA_HERO_SKILLS[$active_skill_id])) {
            $active_skill_id = 1;
        }

        $hero_updated = false;
        while ($hero_lvl < ARENA_HERO_MAX_LEVEL) {
            $needed = self::get_hero_xp_needed($hero_lvl);
            if ($hero_xp >= $needed) {
                $hero_xp -= $needed;
                $hero_lvl++;
                $hero_updated = true;
            } else {
                break;
            }
        }
        if ($hero_lvl >= ARENA_HERO_MAX_LEVEL) {
            $hero_xp = 0;
            $hero_updated = true;
        }
        if ($hero_updated) {
            $this->db->execute_query(
                "UPDATE arena_players SET hero_level = ?, hero_xp = ? WHERE user_id = ?",
                [$hero_lvl, $hero_xp, $this->user_id]
            );
        }

        $base_atk = (int)round(ARENA_HERO_BASE_ATK * pow(ARENA_HERO_STAT_FACTOR, $hero_lvl - 1));
        $base_def = (int)round(ARENA_HERO_BASE_DEF * pow(ARENA_HERO_STAT_FACTOR, $hero_lvl - 1));

        $res = $this->db->execute_query(
            "SELECT skill_id, level, xp FROM arena_hero_skills WHERE user_id = ?",
            [$this->user_id]
        );
        $progress = [];
        while ($r = $res->fetch_assoc()) {
            $progress[(int)$r["skill_id"]] = $r;
        }

        $skills = [];
        foreach (ARENA_HERO_SKILLS as $sid => $cfg) {
            $s_lvl = (int)($progress[$sid]["level"] ?? 1);
            $s_xp = (int)($progress[$sid]["xp"] ?? 0);

            $skill_updated = false;
            while ($s_lvl < ARENA_SKILL_MAX_LEVEL) {
                $s_needed = self::get_skill_xp_needed($s_lvl);
                if ($s_xp >= $s_needed) {
                    $s_xp -= $s_needed;
                    $s_lvl++;
                    $skill_updated = true;
                } else {
                    break;
                }
            }
            if ($s_lvl >= ARENA_SKILL_MAX_LEVEL) {
                $s_xp = 0;
                $skill_updated = true;
            }
            if ($skill_updated) {
                $this->db->execute_query("
                    INSERT INTO arena_hero_skills (user_id, skill_id, level, xp)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE level = VALUES(level), xp = VALUES(xp)
                ", [$this->user_id, $sid, $s_lvl, $s_xp]);
            }

            $s_needed = self::get_skill_xp_needed($s_lvl);
            $s_rps = self::get_skill_rps_bonus($s_lvl);

            $skills[$sid] = array_merge($cfg, [
                "level" => $s_lvl,
                "xp" => $s_xp,
                "xp_needed" => $s_needed,
                "rps_bonus" => $s_rps,
                "is_active" => ($sid === $active_skill_id)
            ]);
        }

        $active_skill = $skills[$active_skill_id];

        return [
            "hero" => [
                "level" => $hero_lvl,
                "xp" => $hero_xp,
                "xp_needed" => self::get_hero_xp_needed($hero_lvl),
                "atk" => $base_atk,
                "def" => $base_def,
                "participates" => (int)($p["defense_hero_participates"] ?? 1)
            ],
            "active_skill" => $active_skill,
            "skills" => $skills
        ];
    }

    public static function get_hero_xp_needed(int $level): int
    {
        if ($level >= ARENA_HERO_MAX_LEVEL) return 0;
        return (int)round(ARENA_HERO_BASE_XP * pow(ARENA_HERO_XP_FACTOR, $level - 1));
    }

    public static function get_skill_xp_needed(int $level): int
    {
        if ($level >= ARENA_SKILL_MAX_LEVEL) return 0;

        return (int)round(ARENA_SKILL_BASE_XP * pow(ARENA_SKILL_XP_FACTOR, $level - 1));
    }

    public static function get_skill_rps_bonus(int $level): float
    {
        return (float)(ARENA_HERO_RPS_BASE * pow(ARENA_HERO_RPS_FACTOR, max(0, $level - 1)));
    }

    public static function calculate_battle_xp(int $elo_change, string $outcome = "win", int $fights_today = 0): int
    {
        $base = match ($outcome) {
            "win" => ARENA_HERO_XP_WIN,
            "draw" => ARENA_HERO_XP_DRAW,
            "loss" => ARENA_HERO_XP_LOSS,
            default => 0
        };

        if ($base <= 0) {
            return 0;
        }

        $elo_scaling = max(0, $elo_change) * 1.5;
        $raw_xp = ($base + $elo_scaling);

        $diminishing_factor = match ($fights_today) {
            0 => 1.0,
            1 => 0.7,
            2 => 0.45,
            default => 0.2
        };

        $variance = mt_rand(90, 115) / 100.0;
        return (int)round($raw_xp * $diminishing_factor * $variance);
    }

    public function add_hero_xp(int $xp_gain): array
    {
        $p = $this->get_player_data();
        $cur_lvl = (int)($p["hero_level"] ?? 1);
        $cur_xp = (int)($p["hero_xp"] ?? 0) + $xp_gain;
        $leveled_up = false;

        if ($cur_lvl >= ARENA_HERO_MAX_LEVEL) {
            return ["level" => ARENA_HERO_MAX_LEVEL, "xp" => 0, "leveled_up" => false];
        }

        while ($cur_lvl < ARENA_HERO_MAX_LEVEL) {
            $needed = self::get_hero_xp_needed($cur_lvl);
            if ($cur_xp >= $needed) {
                $cur_xp -= $needed;
                $cur_lvl++;
                $leveled_up = true;
            } else {
                break;
            }
        }

        if ($cur_lvl >= ARENA_HERO_MAX_LEVEL) {
            $cur_xp = 0;
        }

        $this->db->execute_query(
            "UPDATE arena_players SET hero_level = ?, hero_xp = ? WHERE user_id = ?",
            [$cur_lvl, $cur_xp, $this->user_id]
        );

        if ($leveled_up) {
            Achievement::check($this->user_id, AchievementTypes::ACHIEVEMENT_ARENA_HERO_LEVEL);
        }

        return ["level" => $cur_lvl, "xp" => $cur_xp, "leveled_up" => $leveled_up];
    }

    public function add_skill_xp(int $skill_id, int $xp_gain): array
    {
        $res = $this->db->execute_query(
            "SELECT level, xp FROM arena_hero_skills WHERE user_id = ? AND skill_id = ?",
            [$this->user_id, $skill_id]
        )->fetch_assoc();

        $cur_lvl = (int)($res["level"] ?? 1);
        $cur_xp = (int)($res["xp"] ?? 0) + $xp_gain;
        $leveled_up = false;

        if ($cur_lvl >= ARENA_SKILL_MAX_LEVEL) {
            return ["level" => ARENA_SKILL_MAX_LEVEL, "xp" => 0, "leveled_up" => false];
        }

        while ($cur_lvl < ARENA_SKILL_MAX_LEVEL) {
            $needed = self::get_skill_xp_needed($cur_lvl);

            if ($cur_xp >= $needed) {
                $cur_xp -= $needed;
                $cur_lvl++;
                $leveled_up = true;
            } else {
                break;
            }
        }

        if ($cur_lvl >= ARENA_SKILL_MAX_LEVEL) {
            $cur_xp = 0;
        }

        $this->db->execute_query("
            INSERT INTO arena_hero_skills (user_id, skill_id, level, xp)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE level = VALUES(level), xp = VALUES(xp)
        ", [$this->user_id, $skill_id, $cur_lvl, $cur_xp]);

        return ["level" => $cur_lvl, "xp" => $cur_xp, "leveled_up" => $leveled_up];
    }

    public function get_battle_history(int $limit = 10): array
    {
        $res = $this->db->execute_query("
            SELECT ab.*, 
                   u_atk.username AS attacker_name,
                   u_atk.guildid AS attacker_guild_id,
                   g_atk.tag AS attacker_guild_tag,
                   u_def.username AS defender_name,
                   u_def.guildid AS defender_guild_id,
                   g_def.tag AS defender_guild_tag
            FROM arena_battles ab
            JOIN users u_atk ON ab.attacker_id = u_atk.id
            LEFT JOIN guilds g_atk ON u_atk.guildid = g_atk.id
            JOIN users u_def ON ab.defender_id = u_def.id
            LEFT JOIN guilds g_def ON u_def.guildid = g_def.id
            WHERE ab.attacker_id = ? OR ab.defender_id = ?
            ORDER BY ab.id DESC
            LIMIT ?
        ", [$this->user_id, $this->user_id, $limit]);

        return $res->fetch_all(MYSQLI_ASSOC);
    }

    public function get_leaderboard_count(): int
    {
        return (int)$this->db->execute_query("
            SELECT COUNT(*) 
            FROM arena_players ap
            JOIN users u ON ap.user_id = u.id
            WHERE ap.defense_troops IS NOT NULL AND u.status = 1
        ")->fetch_column();
    }

    public function get_leaderboard(int $limit = 10, int $offset = 0): array
    {
        $res = $this->db->execute_query("
            SELECT ap.*, u.username, u.guildid, g.tag AS guild_tag
            FROM arena_players ap
            JOIN users u ON ap.user_id = u.id
            LEFT JOIN guilds g ON u.guildid = g.id
            WHERE ap.defense_troops IS NOT NULL AND u.status = 1
            ORDER BY ap.elo DESC, ap.wins DESC
            LIMIT ?, ?
        ", [$offset, $limit]);

        return $res->fetch_all(MYSQLI_ASSOC);
    }

    public function select_hero_skill(int $skill_id): bool
    {
        if (!isset(ARENA_HERO_SKILLS[$skill_id])) return false;

        $this->db->execute_query(
            "UPDATE arena_players SET active_hero_skill_id = ? WHERE user_id = ?",
            [$skill_id, $this->user_id]
        );
        return true;
    }
}