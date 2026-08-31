<?php
require_once("includes/core.php");

check_user_login($user);

$user_data = $db_instance->execute_query("SELECT guildid, ranking_points FROM users WHERE id = ?", [$user->get_user_id()])->fetch_assoc();
$my_guild_id = (int)$user_data["guildid"];
$guild_logic = new Guild($db_instance, $user, $my_guild_id);

if (isset($_POST["create_guild"]) && $my_guild_id === -1) {
    $min_score = (int)($_POST["g_min_score"] ?? 0);
    $res = $guild_logic->create_guild($_POST["g_name"] ?? "", $_POST["g_tag"] ?? "", $_POST["g_motto"] ?? "", $min_score);

    if ($res === null) {
        $_SESSION["guild_success"] = "Gilde erfolgreich gegründet!";

        change_location("guild.php");
        exit;
    } else {
        $error = $res;
    }
}

if (isset($_POST["save_guild_settings"]) && $my_guild_id !== -1) {
    $my_perms = $guild_logic->get_user_permissions($user->get_user_id());

    if ($my_perms["can_edit_settings"]) {
        $error = null;

        if (!empty($_FILES["guild_avatar"]["name"])) {
            $error = $guild_logic->update_avatar($_FILES["guild_avatar"]);
        }

        if ($error === null) {
            $res = $guild_logic->update_settings(
                $_POST["g_name"] ?? "",
                $_POST["g_tag"] ?? "",
                $_POST["g_motto"] ?? "",
                (int)($_POST["g_min_score"] ?? 0)
            );

            if ($res === null) {
                $_SESSION["guild_success"] = "Gilden-Einstellungen erfolgreich gespeichert.";

                change_location("guild.php");
                exit;
            } else {
                $error = $res;
            }
        }
    }
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
    $view .= "<table class='table'>
                <colgroup>
                    <col style='width: 30%'>
                    <col>
                    <col style='width: 20%'>
                    <col style='width: 20%'>
                    <col style='width: 5%'>
                </colgroup>
                <tr>
                    <td class='td-gradient td-center'><b>Name</b></td>
                    <td class='td-gradient td-center'><b>Gründer</b></td>
                    <td class='td-gradient td-center'><b>Mitglieder</b></td>
                    <td class='td-gradient td-center'><b>Score</b></td>
                    <td class='td-gradient td-center'></td>
                </tr>";

    if ($guilds->num_rows > 0) {
        while ($g = $guilds->fetch_assoc()) {
            $user_score = $user_data["ranking_points"];
            $has_score = ($user_score >= $g["min_score"]);
            $has_space = ($g["member_count"] < $g["max_members"]);

            $btn_title = "";
            if (!$has_score) $btn_title = "Mindestpunktzahl von " . fnum($g["min_score"], true) . " benötigt.";
            else if (!$has_space) $btn_title = "Gilde ist voll.";
            else $btn_title = "Gilde beitreten";

            $score_display = $g["min_score"] > 0 ? fnum($g["min_score"], true) : "-";

            $action_icon = "";
            if ($has_score && $has_space) {
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
                    <td>" . e($g["founder_name"]) . "</td>
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
    $k = new Kingdom($db_instance, $user->get_current_kingdom());

    if ($k->get_kingdom_building_level(BuildingTypes::BUILDING_EMBASSY) > 0) {
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
                        <td>Mindestpunktzahl für Beitritt:</td>
                        <td><input type='text' name='g_min_score' id='g_min_score' 
                            value='" . e($_POST["g_min_score"] ?? "0") . "' 
                            inputmode='numeric' pattern='[0-9]*' class='js-numeric-input'></td>
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
    $my_perms = $guild_logic->get_user_permissions($user->get_user_id());
    $ranks_res = $guild_logic->get_ranks();
    $ranks = $ranks_res->fetch_all(MYSQLI_ASSOC);

    $has_actions = ($my_perms["can_kick"] || $my_perms["can_edit_settings"]);
    $col_count = $has_actions ? 4 : 3;
    $cooldown_time = convert_sec_to_str(GUILD_JOIN_COOLDOWN);

    $view .= "<div class='msg-back-button-container'>
                <button class='btn-delete' data-on-click='confirmLeaveGuild' data-cooldown='$cooldown_time'>Gilde verlassen</button>
            </div>";
    $view .= "<img src='" . $guild_logic->get_avatar() . "' class='guild-avatar' alt='Wappen'>";
    $view .= "<h2 style='margin-top: 0;'>[" . e($guild_info["tag"]) . "] " . e($guild_info["name"]) . "</h2>";

    if ($guild_info["motto"]) {
        $view .= "<p style='margin-bottom: 25px; color: rgb(208,208,208); opacity: 0.7;'><i>&bdquo;" . e($guild_info["motto"]) . "&ldquo;</i></p>";
    }

    $view .= "<div class='title-border'>Mitgliederliste</div>";
    $view .= "<table class='table' style='max-width: 650px;'>
            <colgroup>
                <col>                               <!-- Name: -->
                <col style='width: 15%'>                   <!-- Score -->
                <col style='width: 20%'>                   <!-- Rang -->
                " . ($has_actions ? "<col style='width: 140px;'>" : "") . " <!-- Aktion -->
            </colgroup>
            <tr>
                <td class='td-gradient td-center'><b>Name</b></td>
                <td class='td-gradient td-center'><b>Score</b></td>
                <td class='td-gradient td-center'><b>Rang</b></td>
                " . ($has_actions ? "<td class='td-gradient td-center'><b>Aktion</b></td>" : "") . "
            </tr>";

    $members = $guild_logic->get_members_detailed($my_guild_id);

    while ($m = $members->fetch_assoc()) {
        $is_me = ($m["id"] == $user->get_user_id());
        $row_style = $is_me ? "style='background: rgba(212, 175, 55, 0.1);'" : "";

        $rank_display = "<b style='color: {$m["rank_color"]}'>" . e($m["rank_name"]) . "</b>";
        $action_content = "";

        if ($has_actions && !$is_me) {
            if ($my_perms["can_edit_settings"]) {
                if (!$m["is_founder"] || $my_perms["is_founder"]) {
                    $action_content .= "<select data-on-change='changeMemberRank' data-userid='{$m["id"]}' style='font-size: 11px; margin-right: 10px; vertical-align: middle; width: 85px;'>";
                    foreach ($ranks as $r) {
                        if ($r["id"] == GuildRanks::GUILD_FOUNDER && !$my_perms["is_founder"]) continue;

                        $is_selected = ($r["id"] == $m["rank_id"]);
                        $disabled = $is_selected ? "disabled" : "";
                        $sel = $is_selected ? "selected" : "";

                        $action_content .= "<option value='{$r["id"]}' $sel $disabled>" . e($r["rank_name"]) . "</option>";
                    }
                    $action_content .= "</select>";
                }
            }

            if ($my_perms["can_kick"] && !$m["is_founder"]) {
                $action_content .= "<img src='images/icons/icon_logout.png' class='ressource-icons' 
                                  style='cursor:pointer; vertical-align: middle;' 
                                  data-on-click='confirmKickMember' 
                                  data-userid='{$m["id"]}' 
                                  data-username='" . e($m["username"]) . "'
                                  title='Mitglied entfernen'>";
            }
        }

        $guild_user = new User($m["id"], $m["username"]);

        $view .= "<tr $row_style>
                    <td>
                        <div class='image-and-user'>
                            <img class='user-image' src='{$guild_user->get_avatar()}' alt=''>
                            <a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid={$m["id"]}' data-title='Spieler-Info'>" . e($m["username"]) . "</a>
                        </div>
                    </td>
                    <td class='td-center'>" . fnum($m["ranking_points"], true) . "</td>";

        $view .= "<td class='td-center'>$rank_display</td>";

        if ($has_actions) {
            $view .= "<td class='td-center' style='white-space: nowrap;'>$action_content</td>";
        }

        $view .= "</tr>";
    }
    $view .= "</table>";

    $my_rank = $user->get_guild_rank_id();

    if ($my_rank <= GuildRanks::GUILD_OFFICER) {
        $pending = $guild_logic->get_pending_invites($my_guild_id);

        if ($pending->num_rows > 0) {
            $view .= "<div class='title-border' style='margin-top: 35px;'>Offene Einladungen</div>";
            $view .= "<table class='table' style='max-width: 650px;'>
                    <colgroup>
                        <col>
                        <col style='width: 30%'>
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

                $view .= "<tr>
                        <td>
                            <div class='image-and-user'>
                                <img class='user-image' src='{$invited_user->get_avatar()}' alt=''>
                                <a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid={$p["invited_user_id"]}' data-title='Spieler-Info'>" . e($p["username"]) . "</a>
                            </div>
                        </td>
                        <td class='td-center'>" . e($p["inviter_name"]) . "</td>
                        <td class='td-center'>" . convert_sec_to_str($time_left, true) . "</td>
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

    $messages = new Messages($db_instance, $user);
    $view .= "<br><hr><div class='title-border'>Gilden-Chat</div>";
    $view .= $messages->show_guild_chat();

    if ($my_perms["can_edit_settings"]) {
        $view .= "<br><hr><div class='box-container' style='max-width: 600px; margin: 20px auto 0 auto;'>
            <div class='box-header'>Gilden-Verwaltung</div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <form method='POST' enctype='multipart/form-data'>
                    <div style='text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1);'>
                        <p style='margin-top: 0;'>Gilden-Wappen:</p>
                        <img src='" . $guild_logic->get_avatar() . "' 
                             style='width: 64px; height: 64px; border: 2px solid var(--border-gold); border-radius: 5px; background: rgba(0,0,0,0.3);' alt='Wappen'>
                        <br><br>
                        <input type='file' name='guild_avatar'>
                        <p style='font-size: 10px; opacity: 0.6;'>Max. " . MAX_UPLOAD_FILE_SIZE . " KB | JPG, PNG, GIF</p>
                    </div>
                    <table class='table'>
                        <tr>
                            <td>Gilden-Name:</td>
                            <td><input type='text' name='g_name' value='" . e($guild_info["name"]) . "' maxlength='" . GUILD_NAME_MAX . "' required></td>
                        </tr>
                        <tr>
                            <td>Gilden-Tag:</td>
                            <td><input type='text' name='g_tag' value='" . e($guild_info["tag"]) . "' maxlength='" . GUILD_TAG_MAX . "' required></td>
                        </tr>
                        <tr>
                            <td>Motto:</td>
                            <td><input type='text' name='g_motto' value='" . e($guild_info["motto"]) . "' maxlength='" . GUILD_MOTTO_MAX . "'></td>
                        </tr>
                        <tr>
                            <td>Beitritts-Limit (Score):</td>
                            <td><input type='text' name='g_min_score' value='{$guild_info["min_score"]}' class='js-numeric-input'></td>
                        </tr>
                    </table><br>
                    <input type='submit' name='save_guild_settings' value='Alle Änderungen speichern'>
                </form>
            </div>
        </div>";
    }
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