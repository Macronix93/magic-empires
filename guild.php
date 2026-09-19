<?php
require_once("includes/core.php");

check_user_login($user);

$user_data = $db_instance->execute_query("SELECT guildid, ranking_points FROM users WHERE id = ?", [$user->get_user_id()])->fetch_assoc();
$my_guild_id = (int)$user_data["guildid"];
$guild_logic = new Guild($user, $my_guild_id);
$my_perms = $guild_logic->get_user_permissions($user->get_user_id());
$k = new Kingdom($user->get_current_kingdom());

$allowed_tabs = ["chat", "general", "settings", "storage", "research"];
$active_tab = $_GET["tab"] ?? ($_COOKIE["me_guild_tab"] ?? "chat");
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$is_external_nav = empty($referer) || !str_contains($referer, 'guild.php');

if (isset($_GET["tab"]) && in_array($_GET["tab"], $allowed_tabs)) {
    $active_tab = $_GET["tab"];

    setcookie("me_guild_tab", $active_tab, time() + 31536000, "/", "", false, false);
} else if ($is_external_nav) {
    $active_tab = "chat";
} else {
    $active_tab = $_COOKIE["me_guild_tab"] ?? "chat";

    if (!in_array($active_tab, $allowed_tabs)) {
        $active_tab = "chat";
    }
}

$is_invite_only = isset($_POST["g_invite_only"]) && $_POST["g_invite_only"] == "1";

if (isset($_POST["create_guild"]) && $my_guild_id === -1) {
    if ($is_invite_only) {
        $min_score = -1;
    } else {
        $min_score = (int)($_POST["g_min_score"] ?? 0);
        if ($min_score < 0) {
            $error = "Die Mindestpunktzahl darf nicht negativ sein!";
        }
    }

    if (empty($error)) {
        $res = $guild_logic->create_guild($_POST["g_name"] ?? "", $_POST["g_tag"] ?? "", $_POST["g_motto"] ?? "", $min_score);

        if ($res === null) {
            $_SESSION["guild_success"] = "Gilde erfolgreich gegründet!";

            change_location("guild.php");
            exit;
        } else {
            $error = $res;
        }
    }
}

if ((isset($_POST["save_avatar"]) || isset($_POST["save_identity"]) || isset($_POST["save_profile"])) && $my_guild_id !== -1) {
    if ($my_perms["can_edit_settings"]) {
        $error = null;

        if (isset($_POST["save_avatar"])) {
            if (!empty($_FILES["guild_avatar"]["name"])) {
                $error = $guild_logic->update_avatar($_FILES["guild_avatar"]);
            } else {
                $error = "Bitte wähle ein Bild aus.";
            }
        }

        if (isset($_POST["save_identity"])) {
            $error = $guild_logic->update_settings(
                $_POST["g_name"] ?? "",
                $_POST["g_tag"] ?? "",
                $guild_logic->get_motto(),
                $guild_logic->get_min_score()
            );
        }

        if (isset($_POST["save_profile"])) {
            if ($is_invite_only) {
                $min_score = -1;
            } else {
                $min_score = (int)($_POST["g_min_score"] ?? 0);
                if ($min_score < 0) {
                    $error = "Die Mindestpunktzahl darf nicht negativ sein!";
                }
            }

            if (empty($error)) {
                $error = $guild_logic->update_settings(
                    $guild_logic->get_name(),
                    $guild_logic->get_tag(),
                    $_POST["g_motto"] ?? "",
                    $min_score
                );
            }
        }

        if ($error === null) {
            $_SESSION["guild_success"] = "Änderungen erfolgreich gespeichert.";

            change_location("guild.php?tab=general");
            exit;
        }
    } else {
        $error = "Du hast keine Berechtigung, die Einstellungen zu ändern.";
    }
}

if (isset($_GET["mark_project"]) && $my_guild_id !== -1) {
    if ($my_perms["can_edit_settings"]) {
        if ($guild_logic->is_researching()) {
            $error = "Es läuft bereits eine Forschung. Erst nach Abschluss kann ein neues Projekt markiert werden.";
        } else {
            $tid = (int)$_GET["mark_project"];
            $existing_project = $guild_logic->get_active_project();

            if ($existing_project) {
                if ($existing_project["tech_id"] != $tid) {
                    $_SESSION["guild_error"] = "Es ist bereits ein Projekt aktiv (" . e($existing_project["name"]) . "). Bitte brich dieses erst ab.";
                }

                change_location("guild.php?tab=research");
                exit;
            }

            $t_res = $db_instance->execute_query("SELECT * FROM guild_tech_list WHERE id = ?", [$tid]);
            $tech_raw = $t_res->fetch_assoc();
            $cur_lvl = $guild_logic->get_tech_level($tid);
            $costs = $guild_logic->calculate_tech_costs($tech_raw, $cur_lvl);

            $special_res_keys = [
                "coal" => "Kohle",
                "iron" => "Eisen",
                "sapphire" => "Saphir",
                "diamond" => "Diamant"
            ];
            $missing = false;

            foreach ($special_res_keys as $key => $label) {
                $req = $costs[$key] ?? 0;
                $cur = $guild_logic->get_storage_amount($key);

                if ($req > $cur) {
                    $missing = true;
                }
            }

            if ($missing) {
                $_SESSION["guild_error"] = "Nicht genügend Spezialressourcen in der Schatzkammer!";

                change_location("guild.php?tab=research");
                exit;
            }

            $db_instance->begin_transaction();
            try {
                foreach ($special_res_keys as $key => $label) {
                    if (($costs[$key] ?? 0) > 0) {
                        $guild_logic->modify_storage_resource($key, -$costs[$key]);
                    }
                }

                $guild_logic->set_active_project($tid);
                $db_instance->commit();

                $guild_logic->notify_guild("Neues Gilden-Projekt",
                    "Ein neues Ziel wurde ausgerufen: <b>" . e($tech_raw["name"]) . "</b>.<br>Alle Mitglieder sind nun aufgerufen, Rohstoffe beizusteuern!",
                    "Veranlasst durch: " . $user->get_user_name());

                $_SESSION["guild_success"] = "Neues Gilden-Projekt wurde markiert!";
            } catch (Exception $e) {
                $db_instance->rollback();

                $_SESSION["guild_error"] = "Fehler beim Starten des Projekts.";
            }
        }
    }

    change_location("guild.php?tab=research");
    exit;
}

if (isset($_POST["contribute_project"]) && $my_guild_id !== -1) {
    $db_instance->begin_transaction();

    $project_res = $db_instance->execute_query("
        SELECT gp.*, gtl.name, gtl.icon, gtl.multiplicator, 
               gtl.food_cost, gtl.wood_cost, gtl.stone_cost, gtl.gold_cost,
               gtl.coal_cost, gtl.iron_cost, gtl.sapphire_cost, gtl.diamond_cost,
               gtl.base_time
        FROM guild_projects gp
        JOIN guild_tech_list gtl ON gp.tech_id = gtl.id
        WHERE gp.guild_id = ? FOR UPDATE",
        [$my_guild_id]
    );

    $project = $project_res->fetch_assoc();

    if (!$project) {
        $error = "Es ist kein Projekt markiert oder es wurde schon fertiggestellt.";
        $db_instance->rollback();
    } else {
        $input_amounts = [
            max(0, (int)($_POST["am"][0] ?? 0)), // Food
            max(0, (int)($_POST["am"][1] ?? 0)), // Wood
            max(0, (int)($_POST["am"][2] ?? 0)), // Stone
            max(0, (int)($_POST["am"][3] ?? 0))  // Gold
        ];

        if (array_sum($input_amounts) <= 0) {
            $error = "Bitte gib eine Menge an.";
            $db_instance->rollback();
        } else {
            $cur_lvl = $guild_logic->get_tech_level($project["tech_id"]);
            $costs = $guild_logic->calculate_tech_costs($project, $cur_lvl);

            $final_amounts = [
                min($input_amounts[0], max(0, $costs["food"] - $project["current_food"])),
                min($input_amounts[1], max(0, $costs["wood"] - $project["current_wood"])),
                min($input_amounts[2], max(0, $costs["stone"] - $project["current_stone"])),
                min($input_amounts[3], max(0, $costs["gold"] - $project["current_gold"]))
            ];

            $actual_total_to_take = array_sum($final_amounts);

            if ($actual_total_to_take <= 0) {
                $error = "Diese Ressourcen werden für das aktuelle Projekt nicht mehr benötigt.";
                $db_instance->rollback();
            } else if ($final_amounts[0] > $k->get_kingdom_food() ||
                $final_amounts[1] > $k->get_kingdom_wood() ||
                $final_amounts[2] > $k->get_kingdom_stone() ||
                $final_amounts[3] > $k->get_kingdom_gold()) {
                $error = "Du hast nicht genügend Ressourcen für diesen Beitrag!";
                $db_instance->rollback();
            } else {
                $k->give_kingdom_food(-$final_amounts[0]);
                $k->give_kingdom_wood(-$final_amounts[1]);
                $k->give_kingdom_stone(-$final_amounts[2]);
                $k->give_kingdom_gold(-$final_amounts[3]);

                $guild_logic->add_contribution($user->get_user_id(), $final_amounts);

                $p_check = $db_instance->execute_query("SELECT * FROM guild_projects WHERE guild_id = ?", [$my_guild_id])->fetch_assoc();

                if ($p_check["current_food"] >= $costs["food"] && $p_check["current_wood"] >= $costs["wood"] &&
                    $p_check["current_stone"] >= $costs["stone"] && $p_check["current_gold"] >= $costs["gold"]) {

                    $finish = time() + $costs["time"];

                    $db_instance->execute_query(
                        "INSERT INTO events (actionid, userid, guild_id, buildingid, buildingname, buildingtime, buildinglevel) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [ActionTypes::ACTION_RESEARCH_TECH, $user->get_user_id(), $my_guild_id, $p_check["tech_id"], $project["name"], $finish, $cur_lvl]
                    );

                    $db_instance->execute_query("DELETE FROM guild_projects WHERE guild_id = ?", [$my_guild_id]);

                    $target_level = $cur_lvl + 1;

                    $guild_logic->notify_guild("Gildenforschung gestartet",
                        "Die Ressourcen für <b>" . e($project["name"]) . " (Stufe $target_level)</b> wurden vollständig gesammelt. Die Forschung hat begonnen!",
                        "Finaler Beitrag durch: " . $user->get_user_name(), "success", [$user->get_user_id()]);

                    $_SESSION["guild_success"] = "Projekt abgeschlossen! Die Forschung wurde gestartet.";
                } else {
                    if (array_sum($input_amounts) > $actual_total_to_take) {
                        $_SESSION["guild_success"] = "Beitrag eingezahlt! Es wurde nur ein Teil deiner Ressourcen benötigt.";
                    } else {
                        $_SESSION["guild_success"] = "Dein Beitrag wurde erfolgreich eingezahlt!";
                    }
                }

                $db_instance->commit();

                change_location("guild.php?tab=research");
                exit;
            }
        }
    }
}

if (isset($_GET["cancel_project"]) && $my_guild_id !== -1) {
    $my_perms = $guild_logic->get_user_permissions($user->get_user_id());

    $project = $guild_logic->get_active_project();

    if ($project) {
        $total_donated = (int)$project["current_food"] + (int)$project["current_wood"] + (int)$project["current_stone"] + (int)$project["current_gold"];
        $can_cancel = false;

        if ($total_donated === 0) {
            if ($my_perms["can_edit_settings"]) {
                $can_cancel = true;
            } else {
                $error = "Du hast keine Berechtigung, dieses Projekt abzubrechen.";
            }
        } else {
            if ($my_perms["is_founder"]) {
                $can_cancel = true;
            } else {
                $error = "Da bereits Ressourcen gespendet wurden, kann das Projekt nur noch vom Gilden-Leader abgebrochen werden!";
            }
        }

        if ($can_cancel) {
            $db_instance->begin_transaction();

            try {
                $guild_logic->cancel_active_project();

                $db_instance->commit();

                $_SESSION["guild_success"] = "Das Gilden-Projekt wurde erfolgreich abgebrochen." . ($total_donated > 0 ? " Es erfolgte kein Refund der Spenden." : "");

                $guild_logic->notify_guild("Projekt abgebrochen",
                    "Das aktuelle Projekt wurde von <b>" . $user->get_user_name() . "</b> abgebrochen.",
                    "", "error");

            } catch (Exception $e) {
                $db_instance->rollback();

                $error = "Fehler beim Abbrechen: " . $e->getMessage();
            }
        }
    }

    change_location("guild.php?tab=research");
    exit;
}


/*
 * HTML Content Part
 */
if ($my_guild_id === -1) {
    $header = "Gilden-Zentrum";
    $view .= "<div class='title-border'>Gilde beitreten</div>";

    $invites = $db_instance->execute_query("
        SELECT i.id AS invite_id, g.name, g.tag 
        FROM guild_invites i 
        JOIN guilds g ON i.guild_id = g.id 
        WHERE i.user_id = ? AND i.expires_at > ?",
        [$user->get_user_id(), time()]
    );

    if ($invites->num_rows > 0) {
        while ($inv = $invites->fetch_assoc()) {
            $view .= "<div class='info-box event-warning'>
                        <img src='images/icons/icon_guild.png' class='ressource-icons' alt='Gilde'>
                        <span style='flex: 1;'>Die Gilde <b>[{$inv["tag"]}] {$inv["name"]}</b> möchte dich rekrutieren!</span>
                        <div style='display: flex; gap: 5px;'>
                            <button data-on-click='acceptGuildInvite' data-id='{$inv["invite_id"]}'>Annehmen</button>
                            <button data-on-click='declineGuildInvite' data-id='{$inv["invite_id"]}'>Ablehnen</button>
                        </div>
                    </div>";
        }
    }

    $guilds = $guild_logic->get_guild_list();
    $view .= "<table class='table guild-list-table' style='hyphens: auto;'>
                <colgroup>
                    <col class='guild-list-name'>
                    <col class='guild-list-leader'>
                    <col class='guild-list-members'>
                    <col class='guild-list-join-type'>
                    <col class='guild-list-join'>
                </colgroup>
                <tr>
                    <td class='td-gradient td-center'><b>Name</b></td>
                    <td class='td-gradient td-center'><b>Leader</b></td>
                    <td class='td-gradient td-center'><b>Mitgl.</b></td>
                    <td class='td-gradient td-center' colspan='2'><b>Beitritt</b></td>
                </tr>";

    if ($guilds->num_rows > 0) {
        while ($g = $guilds->fetch_assoc()) {
            $user_score = $user_data["ranking_points"];
            $is_invite_only = ($g["min_score"] == -1);

            $has_score = ($user_score >= $g["min_score"]);
            $has_space = ($g["member_count"] < $g["max_members"]);

            $btn_title = "";
            if ($is_invite_only) $btn_title = "Dieser Gilde kann nur per Einladung beigetreten werden.";
            else if (!$has_score) $btn_title = "Mindestpunktzahl von " . fnum($g["min_score"], true) . " benötigt.";
            else if (!$has_space) $btn_title = "Gilde ist voll.";
            else $btn_title = "Gilde beitreten";

            $score_display = $is_invite_only ? "Einladung" : ($g["min_score"] > 0 ? fnum($g["min_score"]) . " Punkte" : "Jeder");

            $action_icon = "";
            if (!$is_invite_only && $has_score && $has_space) {
                $action_icon = "<img src='images/icons/icon_join_guild.png' 
                                     class='ressource-icons' 
                                     style='cursor: pointer' 
                                     data-on-click='joinGuild' 
                                     data-id='{$g["id"]}' 
                                     data-name='" . e($g["name"]) . "' 
                                     title='$btn_title'>";
            } else {
                $action_icon = "<img src='images/icons/icon_join_guild.png' 
                                     class='ressource-icons' 
                                     style='opacity: 0.3; filter: grayscale(1); cursor: not-allowed;' 
                                     title='$btn_title' alt='Gilde beitreten'>";
            }

            $view .= "<tr>
                    <td>
                        " . $guild_logic->render_badge($g["id"], $g["tag"], $g["name"]) . "
                    </td>
                    <td class='td-center'>" . e($g["founder_name"]) . "</td>
                    <td class='td-center'>{$g["member_count"]} / {$g["max_members"]}</td>
                    <td class='td-center' " . (!$has_score ? "class='error'" : "") . ">$score_display</td>
                    <td class='td-center'>$action_icon</td>
                  </tr>";
        }
    } else {
        $view .= "<tr><td colspan='4' class='td-center'>Bisher keine Gilden gegründet.</td></tr>";
    }
    $view .= "</table>";

    // Founding section
    if ($k->get_kingdom_building_level(BuildingTypes::BUILDING_EMBASSY) > 0) {
        $is_invite_checked = (!isset($_POST["create_guild"])) || !empty($_POST["g_invite_only"]);
        $checked_create = $is_invite_checked ? "checked" : "";
        $disabled_create = $is_invite_checked ? "disabled" : "";
        $post_min_score = e($_POST["g_min_score"] ?? "0");

        $view .= "<br><hr><br><div class='box-container' style='max-width: 650px; margin: 0 auto;'>
            <div class='box-header'>Eigene Gilde gründen</div>
            <form method='POST' class='box-content box-content-bg' style='padding: 15px;'>
                <table class='table'>
                    <tr>
                        <td>Gilden-Name:</td>
                        <td><input type='text' name='g_name' maxlength='" . GUILD_NAME_MAX . "' 
                            value='" . e($_POST["g_name"] ?? "") . "' required></td>
                    </tr>
                    <tr>
                        <td>Gilden-Tag:</td>
                        <td><input type='text' name='g_tag' maxlength='" . GUILD_TAG_MAX . "' 
                            value='" . e($_POST["g_tag"] ?? "") . "' required></td>
                    </tr>
                    <tr>
                        <td>Gilden-Motto (optional):</td>
                        <td><input type='text' name='g_motto' maxlength='" . GUILD_MOTTO_MAX . "' 
                            value='" . e($_POST["g_motto"] ?? "") . "'></td>
                    </tr>
                    <tr>
                        <td>Beitritt:</td>
                        <td>
                            <label style='display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 5px;'>
                                <input type='checkbox' name='g_invite_only' id='g_invite_only_create' value='1' data-on-change='toggleCreateInviteOnly' style='width: auto;' $checked_create>
                                <span>Nur per Einladung</span>
                            </label>
                            <input type='text' name='g_min_score' id='g_min_score_create' maxlength='7'
                                value='$post_min_score' 
                                inputmode='numeric' pattern='[0-9]*' class='js-numeric-input' style='width: 120px;' $disabled_create>
                        </td>
                    </tr>
                </table>
                <input type='submit' name='create_guild' value='Gilde gründen' style='margin-top: 15px;'>
            </form>
        </div>";
    } else {
        $view .= "<div class='title-border' style='margin-top: 20px;'>Gilde gründen</div>";
        $view .= show_warning_box(
            "Du benötigst eine <b><a href='#' data-on-click='openOverlay' data-url='techinfo.php?bid=" . BuildingTypes::BUILDING_EMBASSY . "' data-title='Gebäude-Info'>Botschaft</a></b>, 
                        um eine Gilde zu gründen.");
    }
} else {
    $header = "Gilden-Halle";

    $guild_info = $guild_logic->get_guild_info($my_guild_id);
    $ranks_res = $guild_logic->get_ranks();
    $ranks = $ranks_res->fetch_all(MYSQLI_ASSOC);

    $has_actions = ($my_perms["can_kick"] || $my_perms["can_edit_settings"]);
    $cooldown_time = convert_sec_to_str(GUILD_JOIN_COOLDOWN);

    $view .= "<div class='msg-back-button-container'>
                <button class='btn-delete' data-on-click='confirmLeaveGuild' data-cooldown='$cooldown_time'>Gilde verlassen</button>
            </div>";

    $view .= "<div class='tab'>
        <div class='tablinks " . ($active_tab == "chat" ? "active" : '') . "' data-on-click='switchGuildTab' data-tab='chat'>Chat</div>
        <div class='tablinks " . ($active_tab == "general" ? "active" : '') . "' data-on-click='switchGuildTab' data-tab='general'>Allgemein</div>
        <div class='tablinks " . ($active_tab == "settings" ? "active" : '') . "' data-on-click='switchGuildTab' data-tab='settings'>Einstellungen</div>
        <div class='tablinks " . ($active_tab == "storage" ? "active" : '') . "' data-on-click='switchGuildTab' data-tab='storage'>Lager</div>
        <div class='tablinks " . ($active_tab == "research" ? "active" : '') . "' data-on-click='switchGuildTab' data-tab='research'>Forschung</div>
    </div>";
    $view .= "<div id='guild_tab_chat' class='js-guild-tab' style='display: " . ($active_tab == "chat" ? "block" : "none") . ";'>";
    $messages = new Messages($user);
    $view .= "<div class='title-border' style='margin-top: 20px;'>Gilden-Chat</div>";
    $view .= $messages->show_guild_chat();
    $view .= "</div>";

    $view .= "<div id='guild_tab_general' class='js-guild-tab' style='display: " . ($active_tab == "general" ? "block" : "none") . ";'>";
    $view .= "<img src='" . $guild_logic->get_avatar() . "' class='guild-avatar' alt='Wappen'>";
    $view .= "<h2 style='margin-top: 0;'>[" . e($guild_info["tag"]) . "] " . e($guild_info["name"]) . "</h2>";

    if ($guild_info["motto"]) {
        $view .= "<p class='guild-motto' style='margin-bottom: 25px; color: rgb(208,208,208); opacity: 0.7;'><i>&bdquo;" . e($guild_info["motto"]) . "&ldquo;</i></p>";
    }

    $members = $guild_logic->get_members_detailed($my_guild_id);

    $cur_members_count = $members->num_rows;
    $max_m = $guild_logic->get_max_members();

    $view .= "<div class='title-border'>Mitgliederliste ($cur_members_count / $max_m)</div>";
    $view .= "<table class='table guild-members-table'>
            <colgroup>
                <col>                                               <!-- Name -->
                <col class='col-g-score' style='width: 16%;'>       <!-- Score -->
                <col class='col-g-rank' style='width: 20%;'>        <!-- Rang -->
                " . ($has_actions ? "<col class='col-g-action' style='width: 125px;'>" : "") . " <!-- Aktion -->
            </colgroup>
            <tr>
                <td class='td-gradient td-center'><b>Name</b></td>
                <td class='td-gradient td-center'><b>Punkte</b></td>
                <td class='td-gradient td-center'><b>Rang</b></td>
                " . ($has_actions ? "<td class='td-gradient td-center'><b>Aktion</b></td>" : "") . "
            </tr>";

    while ($m = $members->fetch_assoc()) {
        $is_me = ($m["id"] == $user->get_user_id());
        $row_style = $is_me ? "style='background: rgba(212, 175, 55, 0.1);'" : "";

        $rank_display = "<b style='color: {$m["rank_color"]}'>" . e($m["rank_name"]) . "</b>";
        $action_content = "";

        if ($has_actions && !$is_me) {
            $my_rank = $user->get_guild_rank_id();

            $can_manage_target = $my_perms["is_founder"] || ($m["rank_id"] > $my_rank);

            if ($my_perms["can_edit_settings"] && $can_manage_target) {
                $action_content .= "<select data-on-change='changeMemberRank' data-userid='{$m["id"]}' class='guild-rank-select'>";

                foreach ($ranks as $r) {
                    if ($r["id"] == GuildRanks::GUILD_LEADER && !$my_perms["is_founder"]) continue;

                    if (!$my_perms["is_founder"] && $r["id"] <= $my_rank) continue;

                    $is_selected = ($r["id"] == $m["rank_id"]);
                    $disabled = $is_selected ? "disabled" : "";
                    $sel = $is_selected ? "selected" : "";

                    $action_content .= "<option value='{$r["id"]}' $sel $disabled>" . e($r["rank_name"]) . "</option>";
                }

                $action_content .= "</select>";
            }

            if ($my_perms["can_kick"] && !$m["is_founder"] && $can_manage_target) {
                $action_content .= "<img src='images/icons/icon_logout.png' class='ressource-icons kick-icon' 
                                  style='cursor:pointer; vertical-align: middle;' 
                                  data-on-click='confirmKickMember' 
                                  data-userid='{$m["id"]}' 
                                  data-username='" . e($m["username"]) . "'
                                  title='Mitglied entfernen'>";
            }
        }

        $guild_user = new User($m["id"], $m["username"]);

        $view .= "<tr $row_style>
                    <td>" . $guild_user->render_user() . "</td>
                    <td class='td-center'>" . fnum($m["ranking_points"], true) . "</td>
                    <td class='td-center'>$rank_display</td>";

        if ($has_actions) {
            $view .= "<td class='td-center guild-action-cell' style='white-space: nowrap;'>$action_content</td>";
        }

        $view .= "</tr>";
    }
    $view .= "</table>";

    $my_rank = $user->get_guild_rank_id();

    if ($my_rank <= GuildRanks::GUILD_OFFICER) {
        $pending = $guild_logic->get_pending_invites($my_guild_id);

        if ($pending->num_rows > 0) {
            $view .= "<div class='title-border' style='margin-top: 35px;'>Offene Einladungen</div>";
            $view .= "<table class='table'>
                    <colgroup>
                        <col>
                        <col style='width: 35%'>
                        <col style='width: 20%'>
                        <col style='width: 50px;'>
                    </colgroup>
                    <tr>
                        <td class='td-gradient td-center'><b>Name</b></td>
                        <td class='td-gradient td-center'><b>Eingeladen von</b></td>
                        <td class='td-gradient td-center'><b>Ablauf</b></td>
                        <td class='td-gradient'></td>
                    </tr>";

            while ($p = $pending->fetch_assoc()) {
                $time_left = $p["expires_at"] - time();

                $invited_user = new User($p["invited_user_id"], $p["username"]);
                $inviter = new User($p["inviter_id"], $p["inviter_name"]);

                $view .= "<tr>
                        <td>" . $invited_user->render_user() . "</td>
                        <td>" . $inviter->render_user() . "</td>
                        <td class='td-center'>" . convert_sec_to_str($time_left) . "</td>
                        <td class='td-center'>
                            <img src='images/icons/icon_error.png' class='ressource-icons' 
                                 style='cursor:pointer' 
                                 data-on-click='confirmCancelInvite' 
                                 data-id='{$p["id"]}' 
                                 data-name='" . e($p["username"]) . "'
                                 title='Einladung zurückziehen' alt=''>
                        </td>
                      </tr>";
            }
            $view .= "</table>";
        }
    }
    $view .= "</div>";

    $view .= "<div id='guild_tab_settings' class='js-guild-tab' style='display: " . ($active_tab == "settings" ? "block" : "none") . ";'>";
    $view .= "<div class='title-border' style='margin-top: 20px;'>Gilden-Verwaltung</div>";

    $can_edit = $my_perms["can_edit_settings"];
    $now = time();
    $last_change = $guild_logic->get_last_settings_change();
    $wait_time = $last_change + (GUILD_SETTINGS_CHANGE_COOLDOWN_DAYS * 86400) - $now;
    $cooldown_active = $wait_time > 0;

    // Avatar
    $view .= "
    <div class='box-container' style='max-width: 600px; margin: 0 auto 10px auto;'>
        <div class='box-header'>Gilden-Wappen</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            " . ($can_edit ? "<form method='POST' enctype='multipart/form-data'>" : "") . "
                <div style='text-align: center;'>
                    <img src='" . $guild_logic->get_avatar() . "' 
                         style='width: 64px; height: 64px; border: 2px solid var(--border-gold); border-radius: 5px; background: rgba(0,0,0,0.3);' alt='Wappen'>
                    " . ($can_edit ? "
                        <br><br>
                        <input type='file' name='guild_avatar' required>
                        <p style='font-size: 12px; opacity: 0.6;'>Max. " . MAX_UPLOAD_FILE_SIZE . " KB | JPG, PNG, GIF</p>
                        <input type='submit' name='save_avatar' value='Wappen hochladen'>
                    " : "") . "
                </div>
            " . ($can_edit ? "</form>" : "") . "
        </div>
    </div>";

    // Identity
    $view .= "
    <div class='box-container' style='max-width: 600px; margin: 0 auto 10px auto;'>
        <div class='box-header'>Gilden-Identität</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            " . ($can_edit ? "<form method='POST'>" : "") . "
                <table class='table'>
                    <tr>
                        <td>Gilden-Name:</td>
                        <td>" . ($can_edit ? "
                            <input type='text' name='g_name' value='" . e($guild_logic->get_name()) . "' 
                                   maxlength='" . GUILD_NAME_MAX . "' required " . ($cooldown_active ? "disabled" : "") . ">
                        " : "<b>" . e($guild_logic->get_name()) . "</b>") . "</td>
                    </tr>
                    <tr>
                        <td>Gilden-Tag:</td>
                        <td>" . ($can_edit ? "
                            <input type='text' name='g_tag' value='" . e($guild_logic->get_tag()) . "' 
                                   maxlength='" . GUILD_TAG_MAX . "' required " . ($cooldown_active ? "disabled" : "") . ">
                        " : "<b>[" . e($guild_logic->get_tag()) . "]</b>") . "</td>
                    </tr>
                </table>";

    if ($can_edit && $cooldown_active) {
        $view .= "<div style='margin-top: 10px; margin-bottom: -5px;'>
                    <small class='error'>Änderung möglich in <b><span class='js-countdown'
                       id='counter_guild'
                       data-seconds='$wait_time'>" . format_time_for_js($wait_time) . "</span></b></small>
                </div>";
    }

    if ($can_edit) {
        $view .= "<br><input type='submit' name='save_identity' value='Identität speichern' " . ($cooldown_active ? "disabled" : "") . "></form>";
    }
    $view .= "</div></div>";

    // Public Profile
    $is_invite_only = ($guild_logic->get_min_score() == -1);
    $checked_settings = $is_invite_only ? "checked" : "";
    $disabled_settings = $is_invite_only ? "disabled" : "";
    $score_settings_val = $is_invite_only ? 0 : $guild_logic->get_min_score();

    $td_styling = $can_edit ? "" : "style='width: 60%;'";

    $view .= "
    <div class='box-container' style='max-width: 600px; margin: 0 auto 0 auto;'>
        <div class='box-header'>Öffentliches Profil</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            " . ($can_edit ? "<form method='POST'>" : "") . "
                <table class='table'>
                    <tr>
                        <td>Gilden-Motto:</td>
                        <td $td_styling>" . ($can_edit ? "
                            <input type='text' name='g_motto' value='" . e($guild_logic->get_motto()) . "' 
                                   maxlength='" . GUILD_MOTTO_MAX . "'>
                        " : (!empty($guild_logic->get_motto())
            ? '<i>&bdquo;' . e($guild_logic->get_motto()) . '&ldquo;</i>'
            : 'Keins')) . "</td>
                    </tr>
                    <tr>
                        <td>Beitritts-Modus:</td>
                        <td>" . ($can_edit ? "
                            <label style='display: flex; align-items: center; gap: 8px; cursor: pointer; margin-bottom: 5px;'>
                                <input type='checkbox' name='g_invite_only' id='g_invite_only_settings' value='1' $checked_settings data-on-change='toggleSettingsInviteOnly' style='width: auto;'>
                                <span>Nur per Einladung</span>
                            </label>
                            <input type='text' name='g_min_score' id='g_min_score_settings' value='$score_settings_val' 
                                   class='js-numeric-input' style='width: 120px;' $disabled_settings>
                        " : "<b>" . ($is_invite_only ? "Nur per Einladung" : fnum($guild_logic->get_min_score(), true) . " Punkte") . "</b>") . "</td>
                    </tr>
                </table>";

    if ($can_edit) {
        $view .= "<br><input type='submit' name='save_profile' value='Profil speichern'></form>
                  <p style='font-size: 12px; opacity: 0.6; margin-top: 10px;'>Hinweis: Diese Felder können jederzeit angepasst werden.</p>";
    }
    $view .= "</div></div></div>";

    // Guild Storage
    $res_map = [
        "coal" => ResourceTypes::RESOURCE_TYPE_COAL,
        "iron" => ResourceTypes::RESOURCE_TYPE_IRON,
        "sapphire" => ResourceTypes::RESOURCE_TYPE_SAPPHIRE,
        "diamond" => ResourceTypes::RESOURCE_TYPE_DIAMOND
    ];

    $view .= "<div id='guild_tab_storage' class='js-guild-tab' style='display: " . ($active_tab == "storage" ? "block" : "none") . ";'>";
    $view .= "<div class='title-border' style='margin-top: 20px;'>Gilden-Schatzkammer</div>";
    $view .= "<div style='display: flex; flex-wrap: wrap; justify-content: center; gap: 15px;'>";
    $view .= "<div class='storage-listing'>";

    foreach ($res_map as $key => $type) {
        $cur = $guild_logic->get_storage_amount($key);
        $max = $guild_logic->get_storage_limit($key);
        $view .= "<div class='split-content' style='gap: 15px;'>
                    <div>" . get_resource_icon($type) . " " . fnum($cur) . "</div>von " . fnum($max) . "
                  </div>";
    }
    $view .= "</div></div>";

    // Active Guild Miners Listing
    $view .= "<div class='title-border' style='margin-top: 30px;'>Aktive Minen-Schürfer</div>";

    $active_mines_res = $db_instance->execute_query("
        SELECT 
            mn.id AS mine_id, mn.mapx, mn.mapy, mn.level, mn.work_done, mn.work_total,
            SUM(mst.soldiercount * mst.unit_atk) AS total_mine_atk
        FROM mine_stationed_troops mst
        JOIN users u ON mst.user_id = u.id
        JOIN mines mn ON mst.mine_id = mn.id
        WHERE u.guildid = ?
        GROUP BY mn.id, mn.mapx, mn.mapy, mn.level, mn.work_done, mn.work_total
        ORDER BY mn.level DESC, mn.mapx
    ", [$my_guild_id])->fetch_all(MYSQLI_ASSOC);

    if (!empty($active_mines_res)) {
        $view .= "<table class='table guild-mining-table' style='margin: 0 auto;'>
                    <colgroup>
                        <col class='guild-mining-table-mine'>
                        <col class='guild-mining-table-members'>
                        <col class='guild-mining-table-troops'>
                        <col class='guild-mining-table-progress'>
                    </colgroup>
                    <tr>
                        <td class='td-gradient td-center'><b>Mine</b></td>
                        <td class='td-gradient td-center'><b>Mitglieder</b></td>
                        <td class='td-gradient td-center'><b>Truppen</b></td>
                        <td class='td-gradient td-center'><b>Fortschritt</b></td>
                    </tr>";

        foreach ($active_mines_res as $am) {
            $mine_id = (int)$am["mine_id"];
            $mx = (int)$am["mapx"];
            $my = (int)$am["mapy"];
            $mine_lvl = (int)$am["level"];

            $w_done = (int)$am["work_done"];
            $w_total = max(1, (int)$am["work_total"]);
            $percent_val = ($w_done / $w_total) * 100;
            $percent_display = fdec($percent_val);

            $rate = (float)$am["total_mine_atk"] * MINE_WORK_RATE_FACTOR;

            $c_link = "<a href='map.php?startx=$mx&starty=$my' data-on-click='mapJump' data-x='$mx' data-y='$my'>$mx:$my</a>";

            $users_res = $db_instance->execute_query("
                SELECT DISTINCT u.id, u.username 
                FROM mine_stationed_troops mst
                JOIN users u ON mst.user_id = u.id
                WHERE mst.mine_id = ? AND u.guildid = ?
                ORDER BY u.username
            ", [$mine_id, $my_guild_id]);

            $members_html = "<div style='display: flex; flex-direction: column; gap: 4px; align-items: center;'>";
            while ($u_row = $users_res->fetch_assoc()) {
                $m_user = new User((int)$u_row["id"], $u_row["username"]);
                $members_html .= $m_user->render_user();
            }
            $members_html .= "</div>";

            $t_res = $db_instance->execute_query("
                SELECT SUM(mst.soldiercount) as soldiercount, sl.soldiername, sl.icon 
                FROM mine_stationed_troops mst
                JOIN users u ON mst.user_id = u.id
                JOIN soldier_list sl ON mst.soldier_id = sl.id
                WHERE mst.mine_id = ? AND u.guildid = ?
                GROUP BY mst.soldier_id, sl.soldiername, sl.icon
                ORDER BY mst.soldier_id
            ", [$mine_id, $my_guild_id]);

            $troop_badges = "<div style='display: flex; flex-wrap: wrap; gap: 3px; justify-content: center;'>";
            while ($tr = $t_res->fetch_assoc()) {
                $troop_badges .= "
                    <div class='unit-badge' title='{$tr["soldiername"]}' style='padding: 2px 5px;'>
                        <img src='images/icons/{$tr["icon"]}.png' class='ressource-icons' alt=''>
                        <b>{$tr["soldiercount"]}</b>
                    </div>";
            }
            $troop_badges .= "</div>";

            $view .= "<tr>
                        <td class='td-center'>
                            <b>Stufe $mine_lvl</b><br>
                            <small>($c_link)</small>
                        </td>
                        <td class='td-center'>$members_html</td>
                        <td class='td-center'>$troop_badges</td>
                        <td class='td-center'>
                            <span><b class='js-mine-progress' data-work-done='$w_done' data-work-total='$w_total' data-rate='$rate'>$percent_display %</b></span>
                            <div class='tick-progress-bg mining-progress'>
                                <div class='tick-progress-fill js-mine-progress-bar' style='width: " . min(100, $percent_val) . "%;'></div>
                            </div>
                        </td>
                      </tr>";
        }
        $view .= "</table>";
    } else {
        $view .= "<p style='text-align: center; opacity: 0.6;'>Aktuell bauen keine Gildenmitglieder in Minen ab.</p>";
    }

    $view .= "</div>";

    // Guild Techs
    $view .= "<div id='guild_tab_research' class='js-guild-tab' style='display: " . ($active_tab == "research" ? "block" : "none") . ";'>";
    $project = $guild_logic->get_active_project();
    $is_researching = $guild_logic->is_researching();

    if ($is_researching) {
        $current_ev = $db_instance->execute_query("SELECT * FROM events WHERE guild_id = ? AND actionid = ? LIMIT 1", [$my_guild_id, ActionTypes::ACTION_RESEARCH_TECH])->fetch_assoc();
        $rem = $current_ev["buildingtime"] - time();

        $view .= "<div class='info-box event-passed' style='flex-direction: column; padding: 20px;'>
                <span style='font-size: 20px;'>Laufende Forschung: <b>{$current_ev["buildingname"]}</b></span>
                <span>Fertigstellung in:<br><b class='js-countdown' data-seconds='$rem'>" . format_time_for_js($rem) . "</b></span>
              </div>";
    } else if ($project) {
        $lvl = $guild_logic->get_tech_level($project["tech_id"]);
        $costs = $guild_logic->calculate_tech_costs($project, $lvl);

        $view .= "<div class='box-container active-guild-project'>
                <div class='box-header'>{$project["name"]} (Stufe " . ($lvl + 1) . ")</div>
                <div class='box-content box-content-bg' style='padding: 20px;'>
                    <div style='display: flex; gap: 20px; align-items: center; justify-content: center; flex-wrap: wrap;'>
                        <img src='images/icons/{$project["icon"]}.png' style='width: 64px; height: 64px;' alt=''>
                        <div style='flex: 1; min-width: 250px;'>";

        $res_map = [
            "food" => [ResourceTypes::RESOURCE_TYPE_FOOD, "Nahrung"],
            "wood" => [ResourceTypes::RESOURCE_TYPE_WOOD, "Holz"],
            "stone" => [ResourceTypes::RESOURCE_TYPE_STONE, "Stein"],
            "gold" => [ResourceTypes::RESOURCE_TYPE_GOLD, "Gold"]
        ];
        foreach ($res_map as $key => $info) {
            $cur = $project["current_$key"];
            $req = $costs[$key];

            $perc_raw = $req > 0 ? ($cur / $req) * 100 : 100;
            $is_finished = ($cur >= $req);

            if (!$is_finished && $perc_raw > 99.9) {
                $perc_display = "99,9";
            } else {
                $perc_display = fdec($perc_raw);
            }

            $color = $is_finished ? "color: #0BDA51;" : "";
            $bar_width = min(100, $perc_raw);

            $view .= "<div style='margin-bottom: 12px;'>
                <div class='split-content' style='margin-bottom: 4px;'>
                    <span>" . get_resource_icon($info[0]) . " $info[1]</span>
                    <span>" . fnum($cur) . " / " . fnum($req) . " <span style='$color'>(" . $perc_display . "%)</span></span>
                </div>
                <div class='tick-progress-bg' style='height: 10px;'>
                    <div class='project-progress-fill' style='width: $bar_width%;'></div>
                </div>
              </div>";
        }

        $special_res_map = [
            "coal" => [ResourceTypes::RESOURCE_TYPE_COAL, "Kohle"],
            "iron" => [ResourceTypes::RESOURCE_TYPE_IRON, "Eisen"],
            "sapphire" => [ResourceTypes::RESOURCE_TYPE_SAPPHIRE, "Saphir"],
            "diamond" => [ResourceTypes::RESOURCE_TYPE_DIAMOND, "Diamant"]
        ];

        foreach ($special_res_map as $key => $info) {
            $req = $costs[$key] ?? 0;

            if ($req > 0) {
                $view .= "<div style='margin-bottom: 12px;'>
                    <div class='split-content' style='margin-bottom: 4px;'>
                        <span>" . get_resource_icon($info[0]) . " $info[1]</span>
                        <span>" . fnum($req) . " / " . fnum($req) . " <span style='color: #0BDA51;'>(100%)</span></span>
                    </div>
                    <div class='tick-progress-bg' style='height: 10px;'>
                        <div class='project-progress-fill' style='width: 100%;'></div>
                    </div>
                  </div>";
            }
        }

        $rem_food = max(0, $costs["food"] - $project["current_food"]);
        $rem_wood = max(0, $costs["wood"] - $project["current_wood"]);
        $rem_stone = max(0, $costs["stone"] - $project["current_stone"]);
        $rem_gold = max(0, $costs["gold"] - $project["current_gold"]);

        $k_stock = [
            0 => $k->get_kingdom_food(),
            1 => $k->get_kingdom_wood(),
            2 => $k->get_kingdom_stone(),
            3 => $k->get_kingdom_gold()
        ];
        $needed_map = [
            0 => [ResourceTypes::RESOURCE_TYPE_FOOD, $rem_food, "Nahrung"],
            1 => [ResourceTypes::RESOURCE_TYPE_WOOD, $rem_wood, "Holz"],
            2 => [ResourceTypes::RESOURCE_TYPE_STONE, $rem_stone, "Stein"],
            3 => [ResourceTypes::RESOURCE_TYPE_GOLD, $rem_gold, "Gold"]
        ];
        $donate_html = "";

        foreach ($needed_map as $idx => $n_info) {
            $res_type = $n_info[0];
            $rem = $n_info[1];
            $stock = $k_stock[$idx];
            $max_possible = max(0, min($rem, $stock));
            $is_disabled = ($rem <= 0 || $stock <= 0);

            $donate_html .= "<div style='display: flex; align-items: center; gap: 4px;'>
                " . get_resource_icon($res_type) . "
                <input type='text'
                       name='am[$idx]'
                       id='proj_am_$idx'
                       class='js-project-res-input'
                       placeholder='0'
                       style='width: 100%;'
                       inputmode='numeric'
                       pattern='[0-9]*'
                       data-needed='$rem'
                       data-stock='$stock'
                    " . ($rem <= 0 ? "disabled" : "") . ">
                <input type='button'
                       value='Max.'
                       data-on-click='fillProjectMax'
                       data-target='proj_am_$idx'
                       data-max='$max_possible'
                    " . ($is_disabled ? "disabled" : "") . "
                       style='font-size: 11px;'>
            </div>";
        }


        $view .= "      </div>
                </div>
                <hr>
                <h4 style='margin: 15px 0 10px 0;'>Projekt unterstützen</h4>
                <form method='POST'>
                    <div style='display: grid; grid-template-columns: 1fr 1fr; gap: 10px; max-width: 300px; margin: 0 auto;'>
                        $donate_html
                    </div>
                    <input type='submit' name='contribute_project' value='Ressourcen einzahlen' style='margin-top: 15px;'>
                </form>
            </div>
          </div>";
    } else {
        $view .= show_warning_box("Aktuell gibt es kein aktives Projekt.");
    }

    $top = $guild_logic->get_project_contributors();

    $view .= "<div class='title-border' style='margin-top: 25px;'>Projekt-Unterstützer</div>";
    if ($top->num_rows > 0) {
        $view .= "<table class='table' style='max-width: 450px; margin-bottom: 20px;'>
                    <tr>
                        <td class='td-gradient'><b>Name</b></td>
                        <td class='td-gradient td-center'><b>Ressourcen</b></td>
                    </tr>";

        while ($r = $top->fetch_assoc()) {
            $contributor_user = new User($r["id"], $r["username"]);

            $view .= "<tr>
                            <td>" . $contributor_user->render_user() . "</td>
                            <td class='td-center'>" . fnum($r["val"]) . "</td>
                          </tr>";
        }

        $view .= "</table>";
    } else {
        if ($project) {
            $view .= "<p style='text-align: center; opacity: 0.6;'>Bisher hat noch niemand zu diesem Projekt beigetragen.</p>";
        } else {
            $view .= "<p style='text-align: center; opacity: 0.6;'>Es gibt derzeit kein aktives Projekt.</p>";
        }
    }

    // Active Boni
    $guild_boni_view = "";

    $storage_lvl = $guild_logic->get_tech_level(GuildTechTypes::GUILD_TECH_TYPE_STORAGE);
    if ($storage_lvl > 0) {
        $guild_boni_view .= "<tr><td>Gilden-Schatzkammer:</td><td class='passed'>Stufe $storage_lvl (Erweiterte Kapazität)</td></tr>";
    }

    $event_gold_lvl = $guild_logic->get_tech_level(GuildTechTypes::GUILD_TECH_EVENT_GOLD);
    if ($event_gold_lvl > 0) {
        $val = fdec($event_gold_lvl * GUILD_BONUS_EVENT_GOLD_PER_LVL * 100);
        $guild_boni_view .= "<tr><td>Kriegsbeute-Kult:</td><td class='passed'>+$val% Gold aus Welt-Events</td></tr>";
    }

    $trade_speed_lvl = $guild_logic->get_tech_level(GuildTechTypes::GUILD_TECH_ALLY_TRADE_SPEED);
    if ($trade_speed_lvl > 0) {
        $val = fdec($trade_speed_lvl * GUILD_BONUS_ALLY_TRADE_SPEED_PER_LVL * 100);
        $guild_boni_view .= "<tr><td>Allianz-Handel:</td><td class='passed'>-$val% Marschzeit zu Verbündeten</td></tr>";
    }

    $support_cap_lvl = $guild_logic->get_tech_level(GuildTechTypes::GUILD_TECH_SUPPORT_CAPACITY);
    if ($support_cap_lvl > 0) {
        $val = fnum($support_cap_lvl * GUILD_BONUS_SUPPORT_CAP_PER_LVL);
        $guild_boni_view .= "<tr><td>Feldlager-Logistik:</td><td class='passed'>+$val stationierbare Unterstützungstruppen</td></tr>";
    }

    $support_speed_lvl = $guild_logic->get_tech_level(GuildTechTypes::GUILD_TECH_SUPPORT_SPEED);
    if ($support_speed_lvl > 0) {
        $val = fdec($support_speed_lvl * GUILD_BONUS_SUPPORT_SPEED_PER_LVL * 100);
        $guild_boni_view .= "<tr><td>Eilige Verstärkung:</td><td class='passed'>-$val% Marschzeit für Unterstützung</td></tr>";
    }

    $member_limit_lvl = $guild_logic->get_tech_level(GuildTechTypes::GUILD_TECH_MEMBER_LIMIT);
    if ($member_limit_lvl > 0) {
        $val = fnum($member_limit_lvl * GUILD_BONUS_MEMBER_LIMIT_PER_LVL);
        $total_max = GUILD_BASE_MEMBER_LIMIT + ($member_limit_lvl * GUILD_BONUS_MEMBER_LIMIT_PER_LVL);
        $guild_boni_view .= "<tr><td>Große Ratsversammlung:</td><td class='passed'>+$val Mitgliederplätze (Gesamt: $total_max)</td></tr>";
    }

    if (!empty($guild_boni_view)) {
        $view .= "<div class='title-border'>Aktive Gilden-Boni</div>";
        $view .= "<table class='table' style='max-width: 550px; margin-bottom: 25px;'>$guild_boni_view</table>";
    }

    // Tech List
    $view .= "<div class='title-border'>Verfügbare Forschungen</div>";
    $view .= "<table class='table'>";
    $view .= '<colgroup>
                <col class="col-guild-description">
                <col class="col-guild-action">
            </colgroup>';

    $all_techs = $guild_logic->get_all_techs();
    foreach ($all_techs as $t) {
        $level = (int)$t["current_level"];
        $max_lvl = (int)$t["max_level"];
        $costs = $guild_logic->calculate_tech_costs($t, $level);

        $res_html = "";
        $special_res_html = "";

        if ($level < $max_lvl) {
            $base_res_types = [
                "food" => ResourceTypes::RESOURCE_TYPE_FOOD,
                "wood" => ResourceTypes::RESOURCE_TYPE_WOOD,
                "stone" => ResourceTypes::RESOURCE_TYPE_STONE,
                "gold" => ResourceTypes::RESOURCE_TYPE_GOLD
            ];

            $special_res_types = [
                "coal" => ResourceTypes::RESOURCE_TYPE_COAL,
                "iron" => ResourceTypes::RESOURCE_TYPE_IRON,
                "sapphire" => ResourceTypes::RESOURCE_TYPE_SAPPHIRE,
                "diamond" => ResourceTypes::RESOURCE_TYPE_DIAMOND
            ];

            foreach ($base_res_types as $key => $icon_id) {
                if (($costs[$key] ?? 0) > 0) {
                    $res_html .= "<div class='legend-item' style='margin-right: 8px;'>"
                        . get_resource_icon($icon_id) . " " . fnum($costs[$key])
                        . "</div>";
                }
            }

            foreach ($special_res_types as $key => $icon_id) {
                $cost = ($costs[$key] ?? 0);

                if ($cost > 0) {
                    $current_stock = $guild_logic->get_storage_amount($key);
                    $cost_display = get_resource_text($cost, $current_stock);

                    $special_res_html .= "<div class='legend-item' style='margin-right: 8px;'>"
                        . get_resource_icon($icon_id) . " " . $cost_display
                        . "</div>";
                }
            }
        }

        $action_btn = "";
        if ($level >= $max_lvl) {
            $action_btn = "<b class='passed'>MAX</b>";
        } else if ($is_researching) {
            $action_btn = "<small style='opacity: 0.7;'>Forschung läuft</small>";
        } else if ($project && $project["tech_id"] == $t["id"]) {
            $action_btn = "<b class='passed'>AKTIV</b>";

            $total_donated = (int)$project["current_food"] + (int)$project["current_wood"] + (int)$project["current_stone"] + (int)$project["current_gold"];
            $show_cancel_btn = false;

            if ($total_donated === 0 && $my_perms["can_edit_settings"]) {
                $show_cancel_btn = true;
            } else if ($total_donated > 0 && $my_perms["is_founder"]) {
                $show_cancel_btn = true;
            }

            if ($show_cancel_btn) {
                $action_btn .= "<br><div style='margin-top: 5px;'>
                    <button data-on-click='confirmCancelProject' data-donated='$total_donated'>
                       Abbrechen
                    </button>
                </div>";
            }
        } else if ($my_perms["can_edit_settings"]) {
            $disabled = $project ? " disabled" : "";

            $action_btn = "<a href='guild.php?tab=research&mark_project={$t["id"]}'>
                        <button type='button'$disabled>Markieren</button>
                      </a>";
        } else {
            $action_btn = "-";
        }

        $view .= "<tr>
            <td>
                <div class='map-legend' style='justify-content: left;'>
                    <div class='legend-item'>
                        <img src='images/icons/{$t["icon"]}.png' class='buildable-icons' alt=''>
                    </div>
                    <div class='legend-item'>
                        <b class='popup' id='gt_{$t["id"]}'>{$t["name"]} ($level / $max_lvl)
                            <div id='gt_{$t["id"]}_box' class='popupbox'>{$t['description']}</div>
                        </b>
                    </div>
                </div>";

        if ($level < $max_lvl) {
            if (!empty($res_html)) {
                $view .= "<div class='map-legend' style='justify-content: left; gap: 5px;'>$res_html</div>";
            }
            if (!empty($special_res_html)) {
                $view .= "<div class='map-legend' style='justify-content: left; gap: 5px; margin-top: 4px;'>$special_res_html</div>";
            }
            $view .= "<div style='opacity: 0.8; margin-top: 5px;'>
                " . get_resource_icon(ResourceTypes::RESOURCE_TYPE_RECRUIT_TIME) . " " . convert_sec_to_str($costs["time"]) . "
            </div>";
        }

        $view .= "
        </td>
        <td class='td-center'>$action_btn</td>
    </tr>";
    }
    $view .= "</table></div>";
}

/*
 * HTML Section
 */
$title = "Gilde";
$header = "Gilde";
$script_files = ["userinfo", "guild", "chat", "timer"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");