<?php
require_once("includes/core.php");

$user->check_user_login();
$uid = $user->get_user_id();

// Set arena logs as read
$db_instance->execute_query("UPDATE arena_players SET last_viewed_logs = ? WHERE user_id = ?", [time(), $uid]);

$arena = new Arena($uid);
$is_season_active = $arena->is_season_active();

if (!$is_season_active) {
    $season_info = Arena::get_season_time_info();

    $view = show_warning_box("
        <b>Die Arena befindet sich aktuell in der Saisonpause!</b><br>
        Die Belohnungen der vergangenen Saison wurden bereits ausgeschüttet.<br>
        Die neue Saison beginnt in: <b><span class='js-countdown' data-seconds='{$season_info["seconds_until_next_season"]}'>-</span></b>
    ");
} else {
    $is_participating = $arena->is_participating();
    $player_data = $arena->get_player_data();

    $allowed_tabs = ["battle", "defense", "ranking", "logs"];
    $active_tab = $_GET["tab"] ?? ($is_participating ? "battle" : "defense");
    if (!in_array($active_tab, $allowed_tabs)) {
        $active_tab = "battle";
    }

    if (!$is_participating) {
        $active_tab = "defense";
    }

    // Load arena data
    $arena_rank_page = max(1, (int)($_GET["page"] ?? 1));
    $rank_per_page = 10;

    $total_rank_entries = $arena->get_leaderboard_count();
    $total_rank_pages = max(1, (int)ceil($total_rank_entries / $rank_per_page));
    if ($arena_rank_page > $total_rank_pages) $arena_rank_page = $total_rank_pages;
    $rank_offset = ($arena_rank_page - 1) * $rank_per_page;

    $opponents = ($is_participating) ? $arena->get_or_roll_opponents() : [];
    $leaderboard = $arena->get_leaderboard($rank_per_page, $rank_offset);
    $history = $arena->get_battle_history(15);

    $seconds_next_coin = ($is_participating) ? $arena->get_seconds_until_next_coin() : 0;

    $res_sol = $db_instance->execute_query("
        SELECT id, soldiername, category, attack, defense, icon 
        FROM soldier_list 
        WHERE id IN (" . implode(',', ARENA_ALLOWED_SOLDIER_IDS) . ") 
        ORDER BY category, id
    ");
    $allowed_soldiers = $res_sol->fetch_all(MYSQLI_ASSOC);

    $res_tech = $db_instance->execute_query("
        SELECT id, techname, description 
        FROM tech_list 
        WHERE id IN (" . implode(',', ARENA_ALLOWED_TECH_IDS) . ") 
        ORDER BY id
    ");
    $allowed_techs = $res_tech->fetch_all(MYSQLI_ASSOC);

    if (!$is_participating) {
        $view .= show_warning_box("
            <b>Willkommen in der Arena!</b><br>
            Bevor du andere Herrscher herausfordern kannst, musst du zuerst deine eigene Verteidigungsmacht aufstellen.
            <br>
            <div style='margin-top: 8px;'>
                <button data-on-click='openOverlay' data-url='ajax/arena_info.php' data-title='Arena-Erklärung'>Wie funktioniert die Arena?</button>
            </div>
        ");
    } else {
        // Arena-Header: Elo, Coins & Ticket-Timer
        $coins_display = (int)$player_data["coins"];
        $elo_display = fnum($player_data["elo"], true);
        $coin_timer_text = ($coins_display < ARENA_MAX_COINS)
            ? "Nächster Coin in: <span class='js-countdown' 
                                        data-seconds='$seconds_next_coin' 
                                        data-keep-params='true' 
                                        data-zero-text='00:00'>" . format_time_for_js($seconds_next_coin) . "</span>"
            : "";

        $season_info = Arena::get_season_time_info();
        $season_time_left = $season_info["seconds_until_pause"];

        $view .= "
        <div class='box-container' style='max-width: 650px; margin: 0 auto 20px auto;'>
            <div class='box-header' style='display: flex; justify-content: space-between; align-items: center; padding: 0 15px;'>
                <span>Arena-Status</span>
                <div style='display: flex; justify-content: center; align-items: center; gap: 15px;'>
                    <button data-on-click='openOverlay' data-url='ajax/arena_info.php' data-title='Arena-Erklärung' class='arena-info-button'>Info</button>
                    <button type='button' data-on-click='refreshArena' title='Arena-Werte aktualisieren' class='arena-info-button'>
                        " . Messages::wrap_emojis("🔄 Aktualisieren") . "
                    </button>
                </div>
            </div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <div style='display: flex; justify-content: space-around; align-items: center; flex-wrap: wrap; gap: 15px;'>
                    <div style='text-align: center;'>
                        <span style='font-size: 13px; opacity: 0.8;'>Deine Arena-Wertung</span><br>
                        <b style='font-size: 26px; color: var(--link-color);'>$elo_display ELO</b>
                    </div>
                    <div style='text-align: center;'>
                        <span style='font-size: 13px; opacity: 0.8;'>Arena-Münzen</span><br>
                        <span style='font-size: 22px; display: inline-flex; align-items: center; gap: 6px;'>
                            " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ARENA_COINS) . " 
                            <b><span id='arena-coins-counter'>$coins_display</span> / " . ARENA_MAX_COINS . "</b>
                        </span><br>
                        <small style='opacity: 0.7;'>$coin_timer_text</small>
                    </div>
                    <div style='text-align: center;'>
                        <span style='font-size: 13px; opacity: 0.8;'>Bilanz (S / N / U)</span><br>
                        <b>
                            <span class='passed'>{$player_data["wins"]}</span> / 
                            <span class='error'>{$player_data["losses"]}</span> / 
                            <span>{$player_data["draws"]}</span>
                        </b><br>
                        <small style='opacity: 0.7;'>{$player_data["season_battles"]} Kämpfe</small>
                    </div>
                    <div style='text-align: center;'>
                        <span style='font-size: 13px; opacity: 0.8;'>Saison endet in</span><br>
                        <b style='font-size: 18px; color: var(--link-color);'>
                            <span class='js-countdown' 
                                  data-seconds='" . $season_time_left . "' 
                                  data-keep-params='true' 
                                  data-zero-text='00:00' 
                                  style='display: inline-block; min-width: 125px;'>
                                " . format_time_for_js($season_time_left) . "
                            </span>
                        </b>
                    </div>
                </div>
            </div>
        </div>";
    }

    // Tab menu
    $view .= "
    <div class='tab' id='arena-tabs' style='margin: 0 auto 15px auto; max-width: 650px;'>
        <div class='tablinks " . ($active_tab === "battle" ? "active" : "") . " " . (!$is_participating ? "tab-disabled" : "") . "' data-on-click='switchArenaTab' data-tab='battle'>Kämpfen</div>
        <div class='tablinks " . ($active_tab === "defense" ? "active" : "") . "' data-on-click='switchArenaTab' data-tab='defense'>Verteidigung</div>
        <div class='tablinks " . ($active_tab === "ranking" ? "active" : "") . "' data-on-click='switchArenaTab' data-tab='ranking'>Rangliste</div>
        <div class='tablinks " . ($active_tab === "logs" ? "active" : "") . " " . (!$is_participating ? "tab-disabled" : "") . "' data-on-click='switchArenaTab' data-tab='logs'>Kampf-Log</div>
    </div>";

    // TAB 1: BATTLE
    $rerolls_left = $arena->get_rerolls_left();
    $reroll_disabled = ($rerolls_left <= 0) ? "disabled" : "";

    $view .= "<div id='tab_battle' class='js-arena-tab' style='display: " . ($active_tab === "battle" ? "block" : "none") . "; max-width: 650px; margin: 0 auto;'>";

    if ($is_participating) {
        $view .= "
            <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;'>
                <span class='arena-choose-enemy-text'>Wähle einen Kontrahenten:</span>
                <div>
                    <button type='button' data-on-click='rerollArenaOpponents' $reroll_disabled style='padding: 4px 10px;'>
                        Neue Gegner suchen (<span id='arena-rerolls-counter'>$rerolls_left / " . ARENA_MAX_REROLLS_PER_DAY . "</span>)
                    </button>
                </div>
            </div>
            <div id='arena-opponents-container' style='display: flex; flex-direction: column; gap: 7px;'>
                " . Arena::render_opponents_html($opponents, $player_data, $uid) . "
            </div>";
    }
    $view .= "</div>";

    // TAB 2: DEFENSE
    $current_def_troops = $player_data["defense_troops"] ?? [];
    $current_def_techs = $player_data["defense_techs"] ?? [];
    $hero_participates = (int)($player_data["defense_hero_participates"] ?? 1);

    $init_troops_sum = 0;
    $init_techs_sum = 0;
    foreach ($current_def_troops as $sid => $cnt) {
        $init_troops_sum += (int)$cnt;
    }
    foreach ($current_def_techs as $tid => $lvl) {
        $init_techs_sum += (int)$lvl;
    }

    $tech_bonuses = [0 => ["atk" => 0, "def" => 0], 1 => ["atk" => 0, "def" => 0], 2 => ["atk" => 0, "def" => 0]];
    $tech_map = [
        TechTypes::TECH_TYPE_BLADES => [0, "atk", SMITHY_INF_ATK_BONUS],
        TechTypes::TECH_TYPE_SHIELDWALL => [0, "def", SMITHY_INF_DEF_BONUS],
        TechTypes::TECH_TYPE_LANCE_RIDING => [1, "atk", SMITHY_CAV_ATK_BONUS],
        TechTypes::TECH_TYPE_CUIRASS => [1, "def", SMITHY_CAV_DEF_BONUS],
        TechTypes::TECH_TYPE_ARROWHEADS => [2, "atk", SMITHY_ARC_ATK_BONUS],
        TechTypes::TECH_TYPE_DOUBLET => [2, "def", SMITHY_ARC_DEF_BONUS]
    ];
    foreach ($tech_map as $t_id => $t_info) {
        $lvl = (int)($current_def_techs[$t_id] ?? 0);
        $tech_bonuses[$t_info[0]][$t_info[1]] += $lvl * $t_info[2];
    }

    $init_total_atk = 0;
    $init_total_def = 0;
    foreach ($allowed_soldiers as $s) {
        $sid = (int)$s["id"];
        $cnt = (int)($current_def_troops[$sid] ?? 0);

        if ($cnt > 0) {
            $cat = (int)$s["category"];
            $unit_atk = (int)$s["attack"] + ($tech_bonuses[$cat]["atk"] ?? 0);
            $unit_def = (int)$s["defense"] + ($tech_bonuses[$cat]["def"] ?? 0);
            $init_total_atk += $cnt * $unit_atk;
            $init_total_def += $cnt * $unit_def;
        }
    }

    $init_troops_class = ($init_troops_sum === ARENA_ARMY_UNIT_LIMIT) ? "passed" : "error";
    $init_techs_class = ($init_techs_sum <= ARENA_MAX_TECH_POINTS) ? "passed" : "error";

    $hero_info = $arena->get_hero_full_data();
    $h_data = $hero_info["hero"];
    $active_skill = $hero_info["active_skill"];
    $all_skills = $hero_info["skills"];
    $is_h_max = ($h_data["level"] >= ARENA_HERO_MAX_LEVEL);
    $h_xp_percent = $is_h_max ? 100 : (($h_data["xp_needed"] > 0) ? min(100, round(($h_data["xp"] / $h_data["xp_needed"]) * 100, 1)) : 100);
    $h_xp_text = $is_h_max ? "<span class='passed'>MAX</span>" : "{$h_data["xp"]} / {$h_data["xp_needed"]} XP";

    $hero_atk = $h_data["atk"];
    $hero_def = $h_data["def"];
    $init_total_atk += $hero_atk;
    $init_total_def += $hero_def;

    $view .= "<div id='tab_defense' class='js-arena-tab' style='display: " . ($active_tab === "defense" ? "block" : "none") . "; max-width: 650px; margin: 0 auto;'>";
    $view .= "
        <div class='box-container'>
            <div class='box-header'>Verteidigungsaufstellung</div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <div style='display: flex; justify-content: center; gap: 20px; padding: 10px; margin-bottom: 15px; background: rgba(0,0,0,0.35); border: 1px solid var(--border-gold); border-radius: 5px;'>
                    <div style='display: flex; align-items: center; gap: 6px;'>
                        <img src='images/icons/icon_sword.png' class='ressource-icons' alt='ATK'>
                        <span class='js-arena-total-atk'>" . fnum($init_total_atk, true) . "</span>
                    </div>
                    <div style='display: flex; align-items: center; gap: 6px;'>
                        <img src='images/icons/icon_shield.png' class='ressource-icons' alt='DEF'>
                        <span class='js-arena-total-def'>" . fnum($init_total_def, true) . "</span>
                    </div>
                </div>
                <form id='arena-defense-form' data-on-submit='saveArenaDefense'>
                <div class='title-border' style='margin-top: 15px;'>Arena-Held</div>
                    <div style='margin-bottom: 20px;'>
                        <div>
                            <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;'>
                                <span style='font-weight: bold;'>Dein Champion</span>
                                <div style='display: flex; justify-content: center; gap: 12px; font-size: 13px;'>
                                <div style='display: flex; align-items: center; gap: 6px;'>
                                    <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_ATTACK) . "</span>
                                    <b>" . fnum($hero_atk, true) . "</b>
                                </div>
                                <div style='display: flex; align-items: center; gap: 6px;'>
                                    <span>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_DEFENSE) . "</span>
                                    <b>" . fnum($hero_def, true) . "</b>
                                </div>
                            </div>
                        </div>

                        <div style='display: flex; gap: 15px; align-items: center; margin-bottom: 15px; background: rgba(0,0,0,0.25); padding: 10px; border-radius: 4px; cursor: pointer'
                        data-on-click='openHeroProgressionModal'>
                            <img src='images/icons/icon_hero.png' style='width: 44px; height: 44px;' alt=''>
                            <div style='flex: 1;'>
                                <div style='display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 4px;'>
                                    <b>Helden-Stufe</b>
                                    <span>{$h_data["level"]} / " . ARENA_HERO_MAX_LEVEL . "</span>
                                </div>
                                <!-- Lila XP-Bar Held -->
                                <div class='arena-xp-bar'>
                                    <div style='width: $h_xp_percent%; height: 100%; background: linear-gradient(90deg, #8e44ad, #ba55d3);'></div>
                                    <span class='arena-xp-bar-text'>
                                        $h_xp_text
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div style='font-size: 13px; opacity: 0.85; margin-bottom: 8px; text-align: left;'>
                            Aktive Kampf-Spezialisierung:
                        </div>
                        <div style='display: flex; flex-direction: column; gap: 8px;'>";

    foreach ($all_skills as $sid => $s) {
        $is_active = $s["is_active"];
        $is_s_max = ($s["level"] >= ARENA_SKILL_MAX_LEVEL);
        $s_xp_percent = $is_s_max ? 100 : (($s["xp_needed"] > 0) ? min(100, round(($s["xp"] / $s["xp_needed"]) * 100, 1)) : 100);
        $s_xp_text = $is_s_max ? "<span class='passed'>MAX</span>" : "{$s["xp"]} / {$s["xp_needed"]} XP";
        $rps_perc = fdec(round($s["rps_bonus"] * 100, 1));
        $active_border = $is_active ? "border: 2px solid var(--link-color); background: rgba(212, 175, 55, 0.15);" : "border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.2);";

        $view .= "
        <div style='padding: 8px 12px; border-radius: 4px; $active_border display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer;' 
                    data-on-click='openSkillProgressionModal' 
                    data-id='{$s["id"]}' 
                    data-name='" . e($s["name"]) . "' >
            <div style='flex: 1; text-align: left;'>
                <div style='display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px;'>
                    <span class='arena-skills-text'>
                        <b>{$s["name"]}</b> ({$s["level"]})
                    </span>
                    <span class='passed arena-skills-perc'><b>+$rps_perc%</b> vs. {$s["target_name"]}</span>
                </div>
                <div class='arena-xp-bar'>
                    <div style='width: $s_xp_percent%; height: 100%; background: linear-gradient(90deg, #8e44ad, #ba55d3);'></div>
                    <span class='arena-xp-bar-text'>
                        $s_xp_text
                    </span>
                </div>
            </div>
            <div style='min-width: 60px;'>
                " . ($is_active ? "<b class='passed' style='font-size: 12px;'>Aktiv</b>" : "<button type='button' data-on-click='selectHeroSkill' data-id='{$s["id"]}' style='padding: 3px 8px; font-size: 11px;'>Wählen</button>") . "
            </div>
        </div>";
    }

    $view .= "
                        </div>
                    </div>
                </div>
                <div class='title-border' style='margin-top: 20px;'>Truppenkontingent</div>
                <div id='hero-data-node' data-atk='{$h_data["atk"]}' data-def='{$h_data["def"]}' style='display: none;'></div>
                    <div class='arena-counter-bar' style='display: flex; justify-content: space-between; margin-bottom: 15px;'>
                        <span>Einheiten vergeben:</span>
                        <b id='defense-troops-counter' class='$init_troops_class'>" . fnum($init_troops_sum) . " / " . fnum(ARENA_ARMY_UNIT_LIMIT) . "</b>
                    </div>";

    foreach ($allowed_soldiers as $s) {
        $sid = (int)$s["id"];
        $val = (int)($current_def_troops[$sid] ?? 0);
        $view .= "
                <div class='arena-slider-row'>
                    <div class='arena-unit-label'>
                        <img src='images/icons/{$s["icon"]}.png' class='ressource-icons' alt=''>
                        <span class='arena-soldier-name'>{$s["soldiername"]}</span>
                    </div>
                    <input type='range' class='js-arena-slider' data-target='def_sol_$sid' min='0' max='" . ARENA_ARMY_UNIT_LIMIT . "' step='1' value='$val'>
                    <input type='text' inputmode='numeric' pattern='[0-9]*' id='def_sol_$sid' name='troops[$sid]' 
                           class='js-arena-input js-numeric-input' 
                           data-atk='{$s["attack"]}' data-def='{$s["defense"]}' data-cat='{$s["category"]}'
                           value='$val' style='width: 60px; text-align: center;'>
                </div>";
    }

    $view .= "
                <div class='title-border' style='margin-top: 25px;'>Schmiede-Attribute</div>
                <div class='arena-counter-bar' style='display: flex; justify-content: space-between; margin-bottom: 15px;'>
                    <span>Schmiedepunkte vergeben:</span>
                    <b id='defense-techs-counter' class='$init_techs_class'>$init_techs_sum / " . ARENA_MAX_TECH_POINTS . "</b>
                </div>";

    foreach ($allowed_techs as $t) {
        $tid = (int)$t["id"];
        $t_val = (int)($current_def_techs[$tid] ?? 0);
        $view .= "
                <div class='arena-slider-row'>
                    <div class='arena-unit-label'>
                        <img src='images/icons/icon_tech$tid.png' class='ressource-icons' alt=''>
                        <span class='arena-tech-name' title='" . e($t["description"]) . "'>{$t["techname"]}</span>
                    </div>
                    <input type='range' class='js-arena-tech-slider' data-target='def_tech_$tid' min='0' max='" . ARENA_MAX_TECH_LEVEL . "' step='1' value='$t_val'>
                    <input type='text' inputmode='numeric' pattern='[0-9]*' id='def_tech_$tid' name='techs[$tid]' 
                           class='js-arena-tech-input js-numeric-input' 
                           data-tech-id='$tid'
                           value='$t_val' style='width: 60px; text-align: center;'>
                </div>";
    }

    $user_kingdoms = $db_instance->execute_query("
        SELECT id, kingdomname, mapx, mapy FROM kingdoms WHERE userid = ? ORDER BY created_at
    ", [$uid])->fetch_all(MYSQLI_ASSOC);

    $current_reward_kid = (int)($player_data["reward_kingdom_id"] ?? 0);

    $view .= "
    <div class='title-border' style='margin-top: 25px;'>Beute-Ziel für Belohnungen</div>
    <p style='font-size: 13px; opacity: 0.8;'>Wähle das Königreich, in welches deine Rohstoff-Prämien nach der Saison geliefert werden sollen:</p>
    <div style='text-align: center; margin-bottom: 20px;'>
        <select name='reward_kingdom_id' style='max-width: 300px;'>
            <option value='0'>Haupt-Königreich (Automatisch)</option>";

    foreach ($user_kingdoms as $uk) {
        $sel = ($uk["id"] == $current_reward_kid) ? "selected" : "";
        $view .= "<option value='{$uk["id"]}' $sel>" . e($uk["kingdomname"]) . " ({$uk["mapx"]}:{$uk["mapy"]})</option>";
    }

    $view .= "
        </select>
    </div>";

    $view .= "
                    <div style='display: flex; justify-content: center; gap: 10px; margin-top: 25px; flex-wrap: wrap;'>
                        <input type='submit' id='btn-save-defense' value='Verteidigung speichern' disabled>
                        <button type='button' data-on-click='resetArenaDefense'>Zurücksetzen</button>
                    </div>
                </form>
            </div>
        </div>
    </div>";

    // TAB 3: RANKING
    $view .= "
    <div id='tab_ranking' class='js-arena-tab' style='display: " . ($active_tab === "ranking" ? "block" : "none") . "; max-width: 650px; margin: 0 auto;'>
        <table class='table' style='width: 100%;'>
            <colgroup>
                <col style='width: 15%;'>
                <col style='width: 55%;'>
                <col style='width: 30%;'>
            </colgroup>
            <tr>
                <td class='td-center td-gradient'><b>#</b></td>
                <td class='td-gradient'><b>Herrscher</b></td>
                <td class='td-center td-gradient'><b>Punkte</b></td>
            </tr>";

    $pos = $rank_offset + 1;
    foreach ($leaderboard as $entry) {
        $is_me = ((int)$entry["user_id"] === $uid);
        $row_style = $is_me ? "style='background: rgba(212, 175, 55, 0.2);'" : "";
        $u_obj = new User((int)$entry["user_id"], $entry["username"]);

        $rank_class = match ($pos) {
            1 => "rank-gold",
            2 => "rank-silver",
            3 => "rank-bronze",
            default => ""
        };

        $arena_user_link = "<a href='#' data-on-click='openArenaUserModal' 
                                        data-userid='{$entry["user_id"]}' 
                                        data-username='" . e($entry["username"]) . "' 
                                        class='user-name-link'>" . e($entry["username"]) . "</a>";

        $view .= "
            <tr $row_style>
                <td class='td-center $rank_class'>$pos</td>
                <td>" . $u_obj->render_user(
                content: $arena_user_link,
                show_guild: true,
                guild_tag: $entry["guild_tag"] ?? null,
                guild_id: (int)($entry["guildid"] ?? 0)
            ) . "</td>
                <td class='td-center $rank_class'><b>" . fnum($entry["elo"]) . " ELO</b></td>
            </tr>";

        $pos++;
    }

    if (empty($leaderboard)) {
        $view .= "<tr><td colspan='3' class='td-center'>Noch keine Spieler registriert.</td></tr>";
    }
    $view .= "</table>";

    if ($total_rank_pages > 1) {
        $view .= '<div class="pagination-container"><div class="pagination-bar">';

        if ($arena_rank_page > 1) {
            $view .= "<a href='arena.php?tab=ranking&page=1' class='page-link'>&laquo;</a>";
            $prev = $arena_rank_page - 1;
            $view .= "<a href='arena.php?tab=ranking&page=$prev' class='page-link'>&lsaquo;</a>";
        }

        for ($x = max(1, $arena_rank_page - 2); $x <= min($total_rank_pages, $arena_rank_page + 2); $x++) {
            $active = ($x == $arena_rank_page) ? "active" : "";
            $view .= ($x == $arena_rank_page) ? "<span class='page-link active'>$x</span>" : "<a href='arena.php?tab=ranking&page=$x' class='page-link'>$x</a>";
        }

        if ($arena_rank_page < $total_rank_pages) {
            $next = $arena_rank_page + 1;
            $view .= "<a href='arena.php?tab=ranking&page=$next' class='page-link'>&rsaquo;</a>";
            $view .= "<a href='arena.php?tab=ranking&page=$total_rank_pages' class='page-link'>&raquo;</a>";
        }

        $view .= '</div></div>';
    }

    $user_max_tc = Arena::get_player_max_tc($uid);

    $view .= "<button type='button' data-on-click='openOverlay' data-url='ajax/arena_season_history.php' data-title='Arena Saison-Archiv'
                style='margin-top: 10px;'>
                " . Messages::wrap_emojis("🏆 Saison-Archiv") . "
            </button>";
    $view .= "
    <div class='box-container' style='margin: 25px auto; max-width: 500px;'>
        <div class='box-header'>Saison-Belohnungen</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <p style='margin-top: 0; font-size: 13px; opacity: 0.85;'>
                Am Ende der Saison erhalten alle aktiven Teilnehmer folgende Belohnungen (skaliert an deinem höchsten Dorfzentrum: <b>Stufe $user_max_tc</b>):
            </p>
            <table class='table' style='width: 100%; font-size: 13px;'>
                <tr>
                    <td class='td-gradient td-center'><b>Rang</b></td>
                    <td class='td-gradient td-center'><b>Münzen</b></td>
                    <td class='td-gradient td-center'><b>Rohstoffe (je Sorte)</b></td>
                </tr>";

    foreach (ARENA_SEASON_REWARDS as $max_rank => $rew) {
        $label = match ($max_rank) {
            1 => "<span class='rank-gold'>1. Platz</span>",
            2 => "<span class='rank-silver'>2. Platz</span>",
            3 => "<span class='rank-bronze'>3. Platz</span>",
            10 => "Top 10",
            25 => "Top 25",
            50 => "Top 50",
            default => "Teilnahme<br>(ab 1 Duell)"
        };

        $scaled_res = (int)round($rew["res"] / MAX_BUILDING_LEVEL * $user_max_tc);

        $view .= "<tr>
            <td><b>$label</b></td>
            <td class='td-center'>" . get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) . " <b>+{$rew["coins"]}</b></td>
            <td class='td-center'>" . fnum($scaled_res) . "</td>
        </tr>";
    }
    $view .= "</table></div></div></div>";

    // TAB 4: BATTLE LOG
    $view .= "
    <div id='tab_logs' class='js-arena-tab' style='display: " . ($active_tab === "logs" ? "block" : "none") . "; max-width: 650px; margin: 0 auto;'>
        <table class='table' style='width: 100%; font-size: 14px;'>
            <colgroup>
                <col style='width: 25%;'>
                <col style='width: 45%;'>
                <col style='width: 30%;'>
            </colgroup>
            <tr>
                <td class='td-center td-gradient'><b>Art</b></td>
                <td class='td-gradient'><b>Gegner</b></td>
                <td class='td-center td-gradient'><b>Ergebnis</b></td>
            </tr>";

    foreach ($history as $h) {
        $is_atk = ((int)$h["attacker_id"] === $uid);

        $opp_id = $is_atk ? (int)$h["defender_id"] : (int)$h["attacker_id"];
        $opp_name = $is_atk ? e($h["defender_name"]) : e($h["attacker_name"]);
        $opp_user = new User($opp_id, $opp_name);

        $opp_guild_tag = $is_atk ? ($h["defender_guild_tag"] ?? null) : ($h["attacker_guild_tag"] ?? null);
        $opp_guild_id = $is_atk ? (int)($h["defender_guild_id"] ?? 0) : (int)($h["attacker_guild_id"] ?? 0);

        $elo_change = $is_atk ? (int)$h["attacker_elo_change"] : (int)$h["defender_elo_change"];
        $change_str = $elo_change === 0 ? "" : ($elo_change > 0 ? "(<small class='passed'>+$elo_change</small>)" : "(<small class='error'>$elo_change</small>)");

        $won = ((int)$h["winner_id"] === $uid);
        $draw = ((int)$h["winner_id"] === 0);
        $result_badge = $draw ? "<i>Unentschieden</i>" : ($won ? "<span class='passed'>Sieg</span>" : "<span class='error'>Niederlage</span>");

        $mode_badge = $is_atk ? "<span style='color: #ea5151;'>Angriff</span>" : "<span style='color: #389cff;'>Verteidigung</span>";
        $date_str = "<small style='opacity: 0.6;'>" . date("d.m. H:i:s", $h["created_at"]) . "</small>";

        $view .= "
            <tr class='tr-hover td-cursor' data-on-click='openOverlay' data-url='ajax/arena_battle_log.php?id={$h["id"]}' data-title='Kampfbericht'>
                <td class='td-center'>$mode_badge<br>$date_str</td>
                <td>" . $opp_user->render_user(
                popup_id: "arena_log_av_" . $h["id"],
                show_guild: true,
                guild_tag: $opp_guild_tag,
                guild_id: $opp_guild_id
            ) . "</td>
                <td class='td-center'>$result_badge $change_str</td>
            </tr>";
    }

    if (empty($history)) {
        $view .= "<tr><td colspan='3' class='td-center'>Bisher keine Kämpfe absolviert.</td></tr>";
    }
    $view .= "</table></div>";

    // JSON Meta data
    $view .= "<div id='arena-config' 
                 data-max-units='" . ARENA_ARMY_UNIT_LIMIT . "' 
                 data-max-techs='" . ARENA_MAX_TECH_POINTS . "' 
                 data-defense-troops='" . e(json_encode($current_def_troops)) . "' 
                 data-defense-techs='" . e(json_encode($current_def_techs)) . "'
                 data-reward-kingdom-id='$current_reward_kid'
                 style='display:none;'></div>";
}

if ($is_season_active) {
    $view = "<div id='arena-main-view'>$view</div><div id='arena-attack-inline-container' style='display: none;'></div>";
}

/*
 * HTML Section
 */
$title = "Arena";
$header = "Arena";
$script_files = ["timer", "arena", "userinfo", "guild"];

include("layout/base.php");