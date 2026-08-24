<?php
require_once("includes/core.php");

check_user_login($user);

$world_event_manager = new WorldEvent($db_instance);
$active_event = $world_event_manager->get_active_event();

if (!$active_event) {
    $view = "<div class='info-box event-warning' style='justify-content: center;'>
                <span>Derzeit findet kein Welt-Event statt. Kehre bald zum Auge des Sturms zurück!</span>
             </div>";
} else {
    if (isset($_SESSION["game_success"])) {
        $view .= show_passed_box($_SESSION["game_success"]);

        unset($_SESSION["game_success"]);
    }

    $user_id = $user->get_user_id();

    // --- TROOP MOVEMENT ---
    $res_mv = $db_instance->execute_query("
        SELECT e.*, st.soldierid, st.soldiercount, sl.soldiername, sl.icon 
        FROM events e
        JOIN sent_troops st ON e.eventid = st.eventid
        JOIN soldier_list sl ON st.soldierid = sl.id
        WHERE e.userid = ? AND e.targetid = ?
        ORDER BY e.arrivaltime", [$user_id, WORLD_EVENT_ID]);

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

    $disabled = ($is_boss_dead && $event_type == "BOSS_HP") ||
    ($event_type == "DAMAGE" && $user_attempts >= WORLD_EVENT_MAX_ATTEMPTS) ? "disabled" : "";

    $num_slots = ($max_tc >= WORLD_EVENT_HP_SLOT_HIGH_TC) ? 3 : ($max_tc >= WORLD_EVENT_HP_SLOT_MID_TC ? 2 : WORLD_EVENT_HP_SLOT_LOW);
    $special_chance = WORLD_EVENT_HP_SPECIAL_CHANCE_BASE + ($max_tc * WORLD_EVENT_HP_SPECIAL_CHANCE_TC_MULT);

    $target_url = "sendtroops.php?x=50&y=50";
    $pool = $world_event_manager->get_monster_pool();
    $monster = $pool[$active_event["monster_index"]];

    $view .= "<div class='title-border'>" . e($monster["name"]) . "</div>";
    $view .= "<div style='display: flex; justify-content: center; align-items: center; gap: 15px; flex-direction: column; margin-bottom: 20px;'>
                <div style='display: flex; justify-content: space-between; width: 240px;'>
                    Verbleibende Zeit: <b><span class='js-countdown' data-seconds='$time_left'>$php_timer_display</span></b>
                </div>
                <button data-on-click='redirect' data-url='" . $target_url . "' $disabled>Boss angreifen</button>
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
            $view .= "<p style='margin-top: 50px;'>Die dunkle Präsenz wurde vertrieben.<br>Alle Teilnehmer erhalten nach Ablauf ihre Belohnung!</p>";
        } else {
            $view .= "<p style='margin-top: 50px;'>Schicke deine Truppen zum Zentrum der Karte, um den Boss gemeinsam zu besiegen!</p>";
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
                    <li>ca. <b class='passed'>" . fnum(WORLD_EVENT_HP_RES_BASE * $avg_lvl) . "</b> Einheiten pro Ressource</li>
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

        // Rewards Box
        $personal_gold_preview = (int)($user_damage / WORLD_EVENT_DMG_GOLD_RATIO);
        if ($personal_gold_preview > WORLD_EVENT_DMG_GOLD_MAX) $personal_gold_preview = WORLD_EVENT_DMG_GOLD_MAX;

        $view .= "
        <div class='box-container' style='max-width: 500px; margin: 20px auto;'>
            <div class='box-header'>Deine Statistik & Beute</div>
            <div class='box-content box-content-bg' style='padding: 15px; text-align: left;'>
                <div class='split-content'><span>Versuche genutzt:</span> <b>$user_attempts / " . WORLD_EVENT_MAX_ATTEMPTS . "</b></div>
                <div class='split-content'><span>Gesamt-Schaden:</span> <b class='passed'>" . fnum($user_damage, true) . "</b></div>
                <hr>
                <p style='margin-bottom: 5px;'>Deine aktuelle Belohnung am Ende:</p>
                <ul>
                    <li><span class='passed'>" . fnum($personal_gold_preview) . " " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_GOLD) . "</span> für dein Königreich</li>
                    <li>Münzen für deine Schatzkammer (basierend auf Schadens-Stufe)</li>
                </ul>
            </div>
        </div>";

        $h_inner = "background: rgba(255, 255, 255, 0.2); font-weight: bold;";

        $st6 = ($user_damage >= WORLD_EVENT_REWARD_TRESHOLD_5) ? $h_inner : "";
        $st5 = ($user_damage >= WORLD_EVENT_REWARD_TRESHOLD_4 && $user_damage < WORLD_EVENT_REWARD_TRESHOLD_5) ? $h_inner : "";
        $st4 = ($user_damage >= WORLD_EVENT_REWARD_TRESHOLD_3 && $user_damage < WORLD_EVENT_REWARD_TRESHOLD_4) ? $h_inner : "";
        $st3 = ($user_damage >= WORLD_EVENT_REWARD_TRESHOLD_2 && $user_damage < WORLD_EVENT_REWARD_TRESHOLD_3) ? $h_inner : "";
        $st2 = ($user_damage >= WORLD_EVENT_REWARD_TRESHOLD_1 && $user_damage < WORLD_EVENT_REWARD_TRESHOLD_2) ? $h_inner : "";
        $st1 = ($user_damage >= WORLD_EVENT_REWARD_MIN_TRESHOLD && $user_damage < WORLD_EVENT_REWARD_TRESHOLD_1) ? $h_inner : "";

        $view .= "
        <div class='box-container' style='max-width: 500px; margin: 20px auto;'>
            <div class='box-header'>Münz-Belohnungen</div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                    <tr>
                        <td style='padding: 5px; $st6'>Über " . fnum(WORLD_EVENT_REWARD_TRESHOLD_5, true) . " Schaden:</td>
                        <td class='passed' style='padding: 5px; text-align: right; $st6'>" . WORLD_EVENT_REWARD_COINS_5 . " Münzen</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px; $st5'>" . fnum(WORLD_EVENT_REWARD_TRESHOLD_4, true) . " bis " . fnum(WORLD_EVENT_REWARD_TRESHOLD_5 - 1, true) . ":</td>
                        <td class='passed' style='padding: 5px; text-align: right; $st5'>" . WORLD_EVENT_REWARD_COINS_4 . " Münzen</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px; $st4'>" . fnum(WORLD_EVENT_REWARD_TRESHOLD_3, true) . " bis " . fnum(WORLD_EVENT_REWARD_TRESHOLD_4 - 1, true) . ":</td>
                        <td class='passed' style='padding: 5px; text-align: right; $st4'>" . WORLD_EVENT_REWARD_COINS_3 . " Münzen</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px; $st3'>" . fnum(WORLD_EVENT_REWARD_TRESHOLD_2, true) . " bis " . fnum(WORLD_EVENT_REWARD_TRESHOLD_3 - 1, true) . ":</td>
                        <td class='passed' style='padding: 5px; text-align: right; $st3'>" . WORLD_EVENT_REWARD_COINS_2 . " Münzen</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px; $st2'>" . fnum(WORLD_EVENT_REWARD_TRESHOLD_1, true) . " bis " . fnum(WORLD_EVENT_REWARD_TRESHOLD_2 - 1, true) . ":</td>
                        <td class='passed' style='padding: 5px; text-align: right; $st2'>" . WORLD_EVENT_REWARD_COINS_1 . " Münzen</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px; $st1'>" . fnum(WORLD_EVENT_REWARD_MIN_TRESHOLD, true) . " bis " . fnum(WORLD_EVENT_REWARD_TRESHOLD_1 - 1, true) . ":</td>
                        <td class='passed' style='padding: 5px; text-align: right; $st1'>" . WORLD_EVENT_REWARD_COINS_MIN . " Münzen</td>
                    </tr>
                </table>
                    <p style='font-size: 12px; opacity: 0.6; margin-top: 10px; text-align: center;'>
                        <i>Zusätzlich erhältst du Gold für dein Königreich (1 pro " . WORLD_EVENT_DMG_GOLD_RATIO . " Schaden).</i>
                    </p>
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
                        <td class='td-center'>
                            <div class='image-and-user'>
                                <img class='user-image' src='$avatar' alt=''>
                                $sender_link
                            </div>
                        </td>
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