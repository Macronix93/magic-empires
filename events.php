<?php
require_once("includes/core.php");

check_user_login($user);

$world_event_manager = new WorldEvent();
$active_event = $world_event_manager->get_active_event();

$user_id = $user->get_user_id();

if ($active_event && isset($_POST["attack_all_kingdoms"])) {
    $current_kid = $user->get_current_kingdom();
    $current_k_obj = new Kingdom($current_kid);

    $res_any_barracks = $db_instance->execute_query(
        "SELECT COUNT(*) FROM buildings b 
         JOIN kingdoms k ON b.kingdomid = k.id 
         WHERE k.userid = ? AND b.buildingid = ? AND b.buildinglevel > 0",
        [$user_id, BuildingTypes::BUILDING_BARRACKS]
    );
    $has_any_barracks = ((int)$res_any_barracks->fetch_column() > 0);

    if (!$has_any_barracks) {
        $_SESSION["game_error"] = "Befehl verweigert: Du besitzt in keinem deiner Königreiche eine Kaserne!";
    }

    // Check if user has attempts left for damage event
    if ($active_event["event_type"] === "DAMAGE") {
        $res_check = $db_instance->execute_query(
            "SELECT attempts_used FROM world_event_participants WHERE event_id = ? AND userid = ?",
            [$active_event["id"], $user_id]
        );
        $attempts = $res_check->fetch_assoc()["attempts_used"] ?? 0;

        if ($attempts >= WORLD_EVENT_MAX_ATTEMPTS) {
            $_SESSION["game_error"] = "Du hast bereits alle " . WORLD_EVENT_MAX_ATTEMPTS . " Versuche für dieses Event verbraucht!";
        }
    }

    // Is there still time left to send troops?
    $arrival_delay = $world_event_manager->get_current_duration();
    $time_left = $active_event["end_time"] - time();

    if ($time_left < $arrival_delay) {
        $_SESSION["game_error"] = "Befehl verweigert: Der Anmarsch dauert " . convert_sec_to_str($arrival_delay) . ", aber das Event endet bereits in " . convert_sec_to_str($time_left) . "!";
    }

    // Check, if troop marching limit was reached
    $current_tc_lvl = $current_k_obj->get_kingdom_building_level(BuildingTypes::BUILDING_TOWNCENTER);
    $max_commands = BASE_SEND_TROOPS_LIMIT + $current_tc_lvl;

    $res_commands = $db_instance->execute_query("
        SELECT 
            (SELECT COUNT(*) FROM events 
             WHERE kingdomid = ? AND actionid IN (?, ?)) AS active_events,
            (SELECT COUNT(DISTINCT mine_id) FROM mine_stationed_troops 
             WHERE kingdom_id = ?) AS active_mines
    ", [
        $current_kid,
        ActionTypes::ACTION_SEND_TROOPS,
        ActionTypes::ACTION_RETURN_TROOPS,
        $current_kid
    ]);
    $cmd_data = $res_commands->fetch_assoc();
    $total_occupied_commands = (int)($cmd_data["active_events"] ?? 0) + (int)($cmd_data["active_mines"] ?? 0);

    if ($total_occupied_commands >= $max_commands) {
        $_SESSION["game_error"] = "Befehlslimit erreicht: Deine Offiziere in <b>" . e($current_k_obj->get_kingdom_name()) . "</b> sind bereits voll ausgelastet ($total_occupied_commands/$max_commands Befehle)!";
    }

    if (empty($_SESSION["game_error"])) {
        // Get all available troops from every kingdom of the user and exclude specific ones
        $exclude_specials = isset($_POST["exclude_specials"]);
        setcookie("me_mass_exclude_specials", $exclude_specials ? "1" : "0", time() + 31536000, "/", "", false, false);
        $_COOKIE["me_mass_exclude_specials"] = $exclude_specials ? "1" : "0";

        $excluded_units = [
            Soldiers::SOLDIER_CONQUEROR,
            Soldiers::SOLDIER_SETTLER_WAGON
        ];

        if ($exclude_specials) {
            $excluded_units = array_merge($excluded_units, [
                Soldiers::SOLDIER_THIEF,
                Soldiers::SOLDIER_SCOUT,
                Soldiers::SOLDIER_RAIDER,
                Soldiers::SOLDIER_RAM
            ]);
        }
        $excluded_str = implode(',', $excluded_units);

        $query_troops = "SELECT kingdomid, soldierid, soldiercount FROM soldiers 
                         WHERE kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?) 
                         AND soldierid NOT IN ($excluded_str)
                         AND soldiercount > 0";
        $res_troops = $db_instance->execute_query($query_troops, [$user_id]);
        $troops = $res_troops->fetch_all(MYSQLI_ASSOC);

        if (empty($troops)) {
            $_SESSION["game_error"] = "Du hast aktuell in keinem deiner Königreiche Einheiten zur Verfügung (Spezial-Einheiten, außer Helden, ausgenommen).";
        } else {
            $db_instance->begin_transaction();

            try {
                $now = time();
                $arrival_delay = $world_event_manager->get_current_duration();
                $current_kid = $user->get_current_kingdom();

                $db_instance->execute_query(
                    "INSERT INTO events (actionid, userid, kingdomid, targetid, targetx, targety, arrivaltime, buildingtime) 
                     VALUES (?, ?, ?, ?, 50, 50, ?, ?)",
                    [ActionTypes::ACTION_SEND_TROOPS, $user_id, $current_kid, MapFieldTypes::MAP_FIELD_WORLD_EVENT, $now + $arrival_delay, $now]
                );
                $event_id = $db_instance->insert_id;

                $insert_values = [];
                foreach ($troops as $t) {
                    $insert_values[] = "($event_id, {$t["soldierid"]}, {$t["soldiercount"]}, {$t["soldiercount"]}, {$t["kingdomid"]})";
                }
                $db_instance->query("INSERT INTO sent_troops (eventid, soldierid, soldiercount, initial_count, source_kingdom_id) VALUES " . implode(',', $insert_values));

                $db_instance->execute_query(
                    "UPDATE soldiers SET soldiercount = 0 
                     WHERE kingdomid IN (SELECT id FROM kingdoms WHERE userid = ?) 
                       AND soldierid NOT IN ($excluded_str) 
                       AND soldiercount > 0",
                    [$user_id]
                );

                $db_instance->commit();

                $_SESSION["game_success"] = "Massenmobilisierung erfolgreich! Eine riesige Armee formiert sich.";

                change_location("events.php");
                exit;
            } catch (Exception $e) {
                $db_instance->rollback();

                $_SESSION["game_error"] = "Ein Fehler ist aufgetreten: " . $e->getMessage();
            }
        }
    }
}

if (!$active_event) {
    $view = "<div class='info-box event-warning' style='justify-content: center;'>
                <span>Derzeit findet kein Welt-Event statt. Kehre bald zum Auge des Sturms zurück!</span>
             </div>";
} else {
    // --- TROOP MOVEMENT ---
    $res_mv = $db_instance->execute_query("
        SELECT 
            e.eventid, e.actionid, e.arrivaltime, e.targetid, 
            st.soldierid, SUM(st.soldiercount) AS soldiercount, 
            sl.soldiername, sl.icon 
        FROM events e
        JOIN sent_troops st ON e.eventid = st.eventid
        JOIN soldier_list sl ON st.soldierid = sl.id
        WHERE e.userid = ? AND e.targetid = ?
        GROUP BY 
            e.eventid, st.soldierid, e.actionid, e.arrivaltime, e.targetid, 
            sl.soldiername, sl.icon
        ORDER BY e.arrivaltime", [$user_id, MapFieldTypes::MAP_FIELD_WORLD_EVENT]);

    if ($res_mv->num_rows > 0) {
        $view .= "<div class='title-border'>Deine Truppenbewegungen</div>";
        $view .= "<table class='table event-move-table' style='margin-bottom: 20px; table-layout: fixed; max-width: 600px;'>
                    <colgroup>
                        <col style='width: 120px;'>  <!-- Typ -->
                        <col style='width: auto;'>  <!-- Units -->
                        <col style='width: 100px;'> <!-- Timer -->
                    </colgroup>";

        $moves = [];
        foreach ($res_mv as $m) {
            if (!isset($moves[$m["eventid"]])) {
                $moves[$m["eventid"]] = $m;
                $moves[$m["eventid"]]["units"] = [];
            }

            $moves[$m["eventid"]]["units"][] = $m;
        }

        foreach ($moves as $eid => $data) {
            $diff = $data["arrivaltime"] - time();
            $php_timer_display = format_time_for_js($diff);
            $type = ($data["actionid"] == ActionTypes::ACTION_SEND_TROOPS) ? "Anmarsch" : "Rückkehr";

            $badge_count = 0;
            $units_html = "<div class='badge-container' style='display:flex; gap:5px; justify-content:center; flex-wrap:wrap;'>";

            foreach ($data["units"] as $u) {
                $badge_count++;
                $responsive_class = "";

                if ($badge_count > MAX_UNIT_BADGES_PER_ROW_MOBILE) {
                    $responsive_class .= " badge-hide-mobile";
                }
                if ($badge_count > MAX_UNIT_BADGES_PER_ROW_DESKTOP) {
                    $responsive_class .= " badge-hide-desktop";
                }

                $units_html .= "<div class='unit-badge $responsive_class' title='{$u["soldiername"]}'>
                                    <img src='images/icons/{$u["icon"]}.png' alt='{$u["soldiername"]}'>
                                    <b>{$u["soldiercount"]}</b>
                                </div>";
            }

            if ($badge_count > MAX_UNIT_BADGES_PER_ROW_MOBILE) {
                $btn_extra = ($badge_count <= MAX_UNIT_BADGES_PER_ROW_DESKTOP) ? " hide-toggle-desktop" : "";
                $units_html .= "<span data-on-click='toggleBadges' class='badge-toggle$btn_extra' style='cursor: pointer; font-weight: bold; padding: 5px;'> (...)</span>";
            }

            $units_html .= "</div>";

            $view .= "<tr>
                        <td class='td-center'>$type</td>
                        <td>$units_html</td>
                        <td class='td-center'><b><span class='js-countdown' data-seconds='$diff'>$php_timer_display</span></b></td>
                      </tr>";
        }

        $view .= "</table>";
    }

    $event_id = $active_event["id"];
    $event_type = $active_event["event_type"];
    $end_time = $active_event["end_time"];
    $time_left = $end_time - time();
    $php_timer_display = format_time_for_js($time_left);
    $current_hp = $active_event["current_hp"];
    $is_boss_dead = ($current_hp <= 0);

    $res_p = $db_instance->execute_query(
        "SELECT * FROM world_event_participants WHERE event_id = ? AND userid = ?",
        [$event_id, $user->get_user_id()]
    );
    $user_participation = $res_p->fetch_assoc();

    $user_damage = $user_participation["total_damage"] ?? 0;
    $user_attempts = $user_participation["attempts_used"] ?? 0;
    $top_kingdom_id = $user_participation["top_kingdom_id"] ?? 0;
    $avg_lvl = $world_event_manager->get_user_max_building_avg($user_id);
    $max_tc = $world_event_manager->get_max_tc_level($user_id);

    $num_slots = ($max_tc >= WORLD_EVENT_HP_SLOT_HIGH_TC) ? 3 : ($max_tc >= WORLD_EVENT_HP_SLOT_MID_TC ? 2 : WORLD_EVENT_HP_SLOT_LOW);
    $special_chance = WORLD_EVENT_HP_SPECIAL_CHANCE_BASE + ($max_tc * WORLD_EVENT_HP_SPECIAL_CHANCE_TC_MULT);

    $target_url = "sendtroops.php?x=50&y=50";
    $pool = $world_event_manager->get_monster_pool();
    $monster = $pool[$active_event["monster_index"]];

    $current_kid = $user->get_current_kingdom();
    $current_k_obj = new Kingdom($current_kid);
    $has_current_barracks = ($current_k_obj->get_kingdom_building_level(BuildingTypes::BUILDING_BARRACKS) > 0);

    $res_any_barracks = $db_instance->execute_query(
        "SELECT COUNT(*) FROM buildings b 
         JOIN kingdoms k ON b.kingdomid = k.id 
         WHERE k.userid = ? AND b.buildingid = ? AND b.buildinglevel > 0",
        [$user_id, BuildingTypes::BUILDING_BARRACKS]
    );
    $has_any_barracks = ((int)$res_any_barracks->fetch_column() > 0);

    $arrival_delay = $world_event_manager->get_current_duration();
    $is_time_too_short = ($time_left < $arrival_delay);

    $is_event_locked = ($is_boss_dead && $event_type == "BOSS_HP") ||
        ($event_type == "DAMAGE" && $user_attempts >= WORLD_EVENT_MAX_ATTEMPTS) ||
        $is_time_too_short;

    $single_disabled = ($is_event_locked || !$has_current_barracks) ? "disabled" : "";
    $single_title = "";
    if ($is_time_too_short) {
        $single_title = "title='Die verbleibende Event-Zeit reicht für den Anmarsch (" . convert_sec_to_str($arrival_delay) . ") nicht mehr aus!'";
    } else if (!$has_current_barracks) {
        $single_title = "title='Kaserne im aktuellen Königreich benötigt!'";
    }

    $mass_disabled = ($is_event_locked || !$has_any_barracks) ? "disabled" : "";
    $mass_title = "";
    if ($is_time_too_short) {
        $mass_title = "title='Die verbleibende Event-Zeit reicht für den Anmarsch (" . convert_sec_to_str($arrival_delay) . ") nicht mehr aus!'";
    } else if (!$has_any_barracks) {
        $mass_title = "title='Du besitzt keine Kaserne!'";
    } else {
        $mass_title = "title='Bündelt alle Truppen deiner Königreiche zu einem Angriff!'";
    }

    $exclude_specials_cookie = ($_COOKIE["me_mass_exclude_specials"] ?? "1") === "1";
    $checkbox_checked = $exclude_specials_cookie ? "checked" : "";

    $view .= "<div class='title-border'>" . e($monster["name"]) . "</div>";
    $view .= "<div style='display: flex; justify-content: center; align-items: center; gap: 15px; flex-direction: column; margin-bottom: 20px;'>
                <div style='display: flex; justify-content: space-between; width: 240px;'>
                    Verbleibende Zeit: <b><span class='js-countdown' data-seconds='$time_left'>$php_timer_display</span></b>
                </div>
                <div style='display: flex; flex-direction: column; align-items: center; width: 100%; max-width: 320px;'>
                    <button data-on-click='redirect' data-url='" . $target_url . "' $single_disabled $single_title style='width: 230px;'>
                        Aus Königreich auswählen
                    </button>
                    <div style='width: 100%; margin: 5px 0; border: 0; border-top: 1px solid rgba(212, 175, 55, 0.3);'></div>
                    <form method='POST' style='display: flex; flex-direction: column; align-items: center; gap: 8px; width: 100%;'>
                        <button type='submit' name='attack_all_kingdoms' $mass_disabled $mass_title style='width: 230px;'>
                            " . wrap_emojis("⚔️ Massenmobilisierung") . "
                        </button>
                        <label style='display: inline-flex; align-items: center; gap: 6px; cursor: pointer; user-select: none;'>
                            <input type='checkbox' name='exclude_specials' value='1' data-on-change='toggleMassExcludeSpecials' $checkbox_checked style='margin: 0; width: auto;'>
                            <span class='mass-troop-label'>Ohne Spezial-Einheiten (außer Helden)</span>
                        </label>
                    </form>
                </div>
              </div>";

    if ($event_type === "BOSS_HP") {
        // --- BOSS HP LOGIC ---
        $total_hp = $active_event["total_hp"];
        $hp_raw_percent = ($total_hp > 0) ? ($current_hp / $total_hp) * 100 : 0;

        if ($current_hp > 0 && $hp_raw_percent < 0.1) {
            $hp_display_percent = "< 0.1";
            $bar_width = 0.5;
        } else {
            $hp_display_percent = round($hp_raw_percent, 1);
            $bar_width = $hp_display_percent;
        }

        $view .= "<div style='display: flex; flex-direction: column; align-items: center; gap: 5px;'>";

        if ($is_boss_dead) {
            $view .= "<div style='filter: grayscale(1) sepia(1) hue-rotate(-50deg); opacity: 0.5;'>
                        <img src='images/icons/" . e($monster["icon"]) . ".png' alt='" . e($monster["name"]) . "' style='width: 94px; height: 94px;'>
                      </div>";
            $view .= "<p class='monster-desc'>" . e($monster["desc"]) . "</p>";
            $view .= "<h2 class='passed' style='margin: 0;'>BESIEGT!</h2>";
        } else {
            $view .= "<img src='images/icons/" . e($monster["icon"]) . ".png' alt='" . e($monster["name"]) . "' style='width: 94px; height: 94px;'>";
            $view .= "<p class='monster-desc'>" . e($monster["desc"]) . "</p>";
        }

        $view .= "</div>";

        // HP Bar
        if (!$is_boss_dead) {
            $view .= "<div style='margin: 20px auto; max-width: 500px;'>
                        <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;'>
                            <span style='display: inline-flex; align-items: center; gap: 5px;'>
                                <img src='images/icons/icon_health.png' class='ressource-icons' alt='Lebenspunkte' style='margin: 0;'> 
                                <span>" . fnum($current_hp) . " / " . fnum($total_hp) . "</span>
                            </span>
                            <span>$hp_display_percent%</span>
                        </div>
                        <div style='width: 100%; height: 25px; background: #333; border: 2px solid var(--border-gold); border-radius: 5px; overflow: hidden; position: relative;'>
                            <div style='width: $bar_width%; height: 100%; background: linear-gradient(90deg, #a62121, #ff4d4d); transition: width 0.5s ease;'></div>
                        </div>
                    </div>";
        }

        if ($is_boss_dead) {
            $view .= "<p style='margin-bottom: 30px;'>Die dunkle Präsenz wurde vertrieben.<br>Alle Teilnehmer erhalten nach Ablauf ihre Belohnung!</p>";
        } else {
            $view .= "<p style='margin-bottom: 30px;'>Schicke deine Truppen zum Zentrum der Karte, um den Boss gemeinsam zu besiegen!</p>";
        }

        // Rewards Box
        $view .= "
        <div class='box-container' style='max-width: 550px; margin: 20px auto;'>
            <div class='box-header'>Persönliche Beute-Vorschau</div>
            <div class='box-content box-content-bg' style='padding: 15px; text-align: left;'>
                <div class='split-content'>
                    <span>Königreich-Schnitt:</span>
                    <b class='passed'>Stufe " . fdec($avg_lvl) . "</b>
                </div>
                <div class='split-content'>
                    <span>Höchstes Dorfzentrum:</span>
                    <b class='passed'>Stufe $max_tc</b>
                </div>
                <hr>
                <p style='margin-bottom: 5px;'>Wird der Boss besiegt, erhältst du mindestens:</p>
                <ul style='margin-top: 0;'>
                    <li>ca. <b class='passed'>" . fnum((int)(WORLD_EVENT_HP_RES_BASE * $avg_lvl)) . "</b> Einheiten pro Ressource</li>
                    <li><b class='passed'>$num_slots Truppen-Paket(e)</b></li>
                    <li>Chance auf Spezial-Einheiten: <b class='passed'>$special_chance %</b></li>
                </ul>

                <div style='background: rgba(0,0,0,0.2); padding: 10px; border-radius: 5px; margin-top: 10px; font-size: 13px;'>
                    <div style='display: flex; gap: 15px; margin-top: 5px;'>
                        <div style='flex: 1;'>
                            <span class='passed'><b>Standard Pool:</b></span><br>
                            Miliz bis Elfenschützen. Die Menge skaliert an deinem Königreich-Schnitt.
                        </div>
                        <div style='flex: 1;'>
                            <span style='color: gold;'><b>Spezial Pool:</b></span><br>
                            Eroberer, Rammen, Räuber, Diebe oder Späher. Die Menge skaliert an deinem höchsten Dorfzentrum.
                        </div>
                    </div>
                </div>";

        if ($top_kingdom_id > 0) {
            $top_k_name = $db_instance->query("SELECT kingdomname FROM kingdoms WHERE id = $top_kingdom_id")->fetch_column();

            $view .= "<p style='font-size: 13px; border-top: 1px solid #555; padding-top: 8px; margin-top: 15px;'>
                        Beute-Ziel: <b>" . e($top_k_name) . "</b> (Am meisten Schaden verursacht).
                      </p>";
        } else {
            $view .= "<p style='font-size: 13px; border-top: 1px solid #555; padding-top: 8px; margin-top: 15px; opacity: 0.7;'>
                        <i>Nimm am Kampf teil, um ein Ziel-Königreich für den Loot festzulegen.</i>
                      </p>";
        }

        $view .= "</div></div>";
    } else {
        // --- DAMAGE EVENT LOGIC ---
        $view .= "<img src='images/icons/" . e($monster["icon"]) . ".png' alt='" . e($monster["name"]) . "'>";
        $view .= "<p class='monster-desc'>" . e($monster["desc"]) . "</p>";
        $view .= "<p>Verursache in maximal <b>" . WORLD_EVENT_MAX_ATTEMPTS . " Angriffen</b> so viel Schaden wie möglich!</p>";

        $g_gold_lvl = Guild::get_user_guild_tech_level($user_id, GuildTechTypes::GUILD_TECH_EVENT_GOLD);
        $guild_gold_mult = 1.0 + ($g_gold_lvl * GUILD_BONUS_EVENT_GOLD_PER_LVL);

        $total_gold_earned = 0;
        $total_coins_earned = 0;
        $highest_reached_threshold = 0;

        foreach (WORLD_EVENT_DAMAGE_TIERS as $threshold => $rewards) {
            if ($user_damage >= $threshold) {
                $total_gold_earned += (int)round($rewards["gold"] * $guild_gold_mult);
                $total_coins_earned += $rewards["coins"];
                $highest_reached_threshold = $threshold;
            }
        }

        $view .= "
        <div class='box-container' style='max-width: 520px; margin: 20px auto;'>
            <div class='box-header'>Deine Statistik & Auszahlungen</div>
            <div class='box-content box-content-bg' style='padding: 15px; text-align: left;'>
                <div class='split-content'><span>Versuche genutzt:</span> <b>$user_attempts / " . WORLD_EVENT_MAX_ATTEMPTS . "</b></div>
                <div class='split-content'><span>Gesamt-Schaden:</span> <b class='passed'>" . fnum($user_damage, true) . "</b></div>
                <div class='split-content'><span>Bisher erhaltene Belohnung:</span> 
                    <span>
                        " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <b>$total_coins_earned</b> 
                        " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " <b>" . fnum($total_gold_earned) . "</b>
                    </span>
                </div>
                <hr>
                <p style='font-size: 14px; opacity: 0.8; text-align: center; margin-bottom: 0;'>
                    <i>Hinweis: Münzen und Gold werden sofort nach Erreichen einer Stufe direkt auf dein Konto/Lager gutgeschrieben!</i>
                </p>
            </div>
        </div>";

        $next_target_threshold = null;
        foreach (WORLD_EVENT_DAMAGE_TIERS as $threshold => $rewards) {
            if ($user_damage < $threshold) {
                $next_target_threshold = $threshold;
                break;
            }
        }

        $view .= "
        <div class='box-container' style='max-width: 520px; margin: 20px auto;'>
            <div class='box-header'>Schadens-Stufen & Prämien</div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <table class='table' style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                    <tr style='font-weight: bold;'>
                        <td class='td-center td-gradient'>Gesamtschaden</td>
                        <td class='td-center td-gradient'>Prämie dieser Stufe</td>
                        <td class='td-center td-gradient'>Status</td>
                    </tr>";

        foreach (WORLD_EVENT_DAMAGE_TIERS as $threshold => $rewards) {
            $is_reached = ($user_damage >= $threshold);
            $is_next_target = ($threshold === $next_target_threshold);

            $tr_style = $is_next_target ? "style='background: rgba(255, 255, 255, 0.05); font-weight: bold;'" : "";

            if ($is_reached) {
                $cell_style = "style='background: rgba(0, 0, 0, 0.05); color: rgba(230, 220, 200, 0.6);'";
                $status_style = "class='td-center' style='background: rgba(0, 0, 0, 0.05);'";
                $status_html = "<span style='color: #2fa22f;'>✔</span";
            } else {
                $cell_style = "";
                $status_style = "class='td-center'";
                $status_html = "";
            }

            $base_gold = (int)$rewards['gold'];
            $boosted_gold = (int)round($base_gold * $guild_gold_mult);

            if ($g_gold_lvl > 0) {
                $gold_display = "<span title='Basis: " . fnum($base_gold) . " (+" . ($g_gold_lvl * GUILD_BONUS_EVENT_GOLD_PER_LVL * 100) . "% Gilden-Bonus)'>" . fnum($boosted_gold) . "</span>";
            } else {
                $gold_display = fnum($boosted_gold);
            }

            $view .= "<tr $tr_style>
                <td $cell_style>ab " . fnum($threshold, true) . "</td>
                <td $cell_style>
                    <div style='display: flex; justify-content: space-between; text-align: left;'>
                        <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " {$rewards['coins']}</span>
                        <span style='min-width: 100px;'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . " $gold_display</span>
                    </div>
                </td>
                <td $status_style>$status_html</td>
            </tr>";
        }

        $view .= "
                </table>
            </div>
        </div>";
    }

    // --- LAST 5 ATTACKS LOG ---
    $res_logs = $db_instance->execute_query("
        SELECT details, created_at 
        FROM game_logs 
        WHERE userid = ? 
          AND action = 'WORLD_EVENT_ATTACK' 
          AND JSON_EXTRACT(details, '$.event_id') = ?
        ORDER BY id DESC LIMIT 5", [$user_id, (int)$event_id]);

    if ($res_logs->num_rows > 0) {
        $view .= "<div class='title-border'>Deine letzten Treffer</div>";
        $view .= "<table class='table' style='margin-bottom: 20px; font-size: 14px; max-width: 600px;'>";
        $view .= "<colgroup>
                        <col style='width: 140px;'> <!-- Zeitpunkt -->
                        <col style='width: auto;'>  <!-- Truppen -->
                        <col style='width: 140px;'> <!-- Schaden -->
                    </colgroup>
                    <tr>
                        <td class='td-center td-gradient'><b>Zeitpunkt</b></td>
                        <td class='td-center td-gradient'><b>Truppen</b></td>
                        <td class='td-center td-gradient'><b>Schaden</b></td>
                    </tr>";

        foreach ($res_logs as $log) {
            $det = json_decode($log["details"], true);
            $dmg = $det["damage_caused"] ?? 0;
            $troops = $det["troops"] ?? [];

            $badge_html = "<div class='badge-container' style='display: flex; gap: 3px; justify-content: center; align-items: center; flex-wrap: wrap;'>";
            $b_count = 0;

            foreach ($troops as $t) {
                $b_count++;
                $resp_class = "";
                if ($b_count > MAX_UNIT_BADGES_PER_ROW_MOBILE) $resp_class .= " badge-hide-mobile";
                if ($b_count > MAX_UNIT_BADGES_PER_ROW_DESKTOP) $resp_class .= " badge-hide-desktop";

                $badge_html .= "<div class='unit-badge $resp_class' title='{$t["name"]}' style='padding: 2px 5px;'>
                                    <img src='images/icons/{$t["icon"]}.png' style='width: 18px; height: 18px;' alt='{$t["name"]}'>
                                    <b style='font-size: 11px;'>{$t["count"]}</b>
                                </div>";
            }

            if ($b_count > MAX_UNIT_BADGES_PER_ROW_MOBILE) {
                $btn_ex = ($b_count <= MAX_UNIT_BADGES_PER_ROW_DESKTOP) ? " hide-toggle-desktop" : "";
                $badge_html .= "<span data-on-click='toggleBadges' class='badge-toggle$btn_ex' style='cursor: pointer; font-weight: bold; font-size: 11px;'> (...)</span>";
            }
            $badge_html .= "</div>";

            $view .= "<tr>
                        <td style=''>" . date("H:i:s", $log["created_at"]) . " Uhr</td>
                        <td style='vertical-align:middle;'>$badge_html</td>
                        <td style='text-align: center; vertical-align: middle;'>
                            " . fnum($dmg, true) . "
                        </td>
                      </tr>";
        }

        $view .= "</table>";
    }

    // --- RANKING TABLE ---
    $view .= "<div class='title-border'>Top-Angreifer</div>";
    $view .= "<table class='table' style='max-width: 600px;'>
              <colgroup>
                <col style='width: 15%;'>
                <col style='width: 55%;'>
                <col style='width: 30%;'>
              </colgroup>
                <tr>
                    <td class='td-center td-gradient'><b>Rang</b></td>
                    <td class='td-gradient'><b>Spieler</b></td>
                    <td class='td-center td-gradient'><b>Schaden</b></td>
                </tr>";

    $res_rank = $db_instance->execute_query("
        SELECT u.id, u.username, p.total_damage 
        FROM world_event_participants p 
        JOIN users u ON p.userid = u.id 
        WHERE p.event_id = ? 
        ORDER BY p.total_damage DESC 
        LIMIT 20", [$event_id]);

    $rank_count = 1;

    if ($res_rank->num_rows > 0) {
        foreach ($res_rank as $r) {
            $is_me = ($r["id"] === $user->get_user_id());
            $style = $is_me ? "style='background: rgba(212, 175, 55, 0.2);'" : "";
            $player = new User($r["id"], $r["username"]);
            $avatar = $player->get_avatar() ?? "";

            $sender_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid=" . $r["id"] . "' data-title='Spieler-Info'>" . e($r["username"]) . "</a>";

            $view .= "<tr $style>
                        <td class='td-center'>$rank_count</td>
                        <td class='td-center'>" . $player->render_user() . "</td>
                        <td class='td-center'>" . fnum($r["total_damage"], true) . "</td>
                      </tr>";
            $rank_count++;
        }
    } else {
        $view .= "<tr><td colspan='3' class='td-center'>Noch keine Angriffe verzeichnet.</td></tr>";
    }
    $view .= "</table>";
}

/*
 * HTML Section
 */
$title = "Welt-Event";
$header = "Auge des Sturms";
$script_files = ["timer", "userinfo"];

include("layout/base.php");