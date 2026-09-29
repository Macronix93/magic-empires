<?php
require_once("includes/core.php");

check_user_login($user);

$uid = $user->get_user_id();
$is_admin = $user->is_admin();

$latest_sug_id = (int)($db_instance->execute_query("SELECT MAX(id) FROM suggestions")->fetch_row()[0] ?? 0);
if ($latest_sug_id > 0) {
    $db_instance->execute_query("UPDATE users SET last_suggestion_read = ? WHERE id = ?", [$latest_sug_id, $uid]);
}

$post_title = e($_POST["title"] ?? "");
$post_content = e($_POST["content"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST["submit_suggestion"])) {
        $title = sanitize_input($_POST["title"] ?? "");
        $content = sanitize_input($_POST["content"] ?? "");

        if (mb_strlen($title) < SUGGESTION_TITLE_MIN_LENGTH || mb_strlen($title) > SUGGESTION_TITLE_MAX_LENGTH) {
            $error = "Der Titel muss zwischen " . SUGGESTION_TITLE_MIN_LENGTH . " und " . SUGGESTION_TITLE_MAX_LENGTH . " Zeichen lang sein.";
        } else if (mb_strlen($content) < SUGGESTION_DESC_MIN_LENGTH || mb_strlen($content) > SUGGESTION_DESC_MAX_LENGTH) {
            $error = "Die Beschreibung muss zwischen " . SUGGESTION_DESC_MIN_LENGTH . " und " . SUGGESTION_DESC_MAX_LENGTH . " Zeichen lang sein.";
        } else {
            $res_last = $db_instance->execute_query(
                "SELECT created_at FROM suggestions WHERE user_id = ? ORDER BY id DESC LIMIT 1",
                [$uid]
            );
            $last_created = (int)($res_last->fetch_column() ?? 0);
            $wait = ($last_created + SUGGESTION_COOLDOWN_SECONDS) - time();

            if ($wait > 0 && !$is_admin) {
                $error = "Bitte warte noch " . convert_sec_to_str($wait) . ", bevor du einen neuen Vorschlag einreichst.";
            } else {
                $clean_content = filter_chat_message(nl2br(e($content)));
                $clean_title = e($title);

                $db_instance->execute_query(
                    "INSERT INTO suggestions (user_id, username, title, content, created_at) VALUES (?, ?, ?, ?, ?)",
                    [$uid, $user->get_user_name(), $clean_title, $clean_content, time()]
                );

                $post_title = "";
                $post_content = "";

                $_SESSION["game_success"] = "Vorschlag erfolgreich eingereicht! Andere Herrscher können nun dafür abstimmen.";
                change_location("suggestions.php");
                exit;
            }
        }
    }

    if (isset($_POST["edit_suggestion"])) {
        $sug_id = (int)$_POST["suggestion_id"];
        $title = sanitize_input($_POST["title"] ?? "");
        $content = sanitize_input($_POST["content"] ?? "");

        $res_check = $db_instance->execute_query(
            "SELECT user_id, status FROM suggestions WHERE id = ?",
            [$sug_id]
        )->fetch_assoc();

        if (!$res_check) {
            $error = "Dieser Vorschlag existiert nicht mehr.";
        } elseif (!$is_admin && ((int)$res_check["user_id"] !== $uid || (int)$res_check["status"] !== 0)) {
            $error = "Dieser Vorschlag kann nicht mehr bearbeitet werden, da die Abstimmungs-/Prüfungsphase bereits abgeschlossen ist.";
        } elseif (mb_strlen($title) < SUGGESTION_TITLE_MIN_LENGTH || mb_strlen($title) > SUGGESTION_TITLE_MAX_LENGTH) {
            $error = "Der Titel muss zwischen " . SUGGESTION_TITLE_MIN_LENGTH . " und " . SUGGESTION_TITLE_MAX_LENGTH . " Zeichen lang sein.";
        } elseif (mb_strlen($content) < SUGGESTION_DESC_MIN_LENGTH || mb_strlen($content) > SUGGESTION_DESC_MAX_LENGTH) {
            $error = "Die Beschreibung muss zwischen " . SUGGESTION_DESC_MIN_LENGTH . " und " . SUGGESTION_DESC_MAX_LENGTH . " Zeichen lang sein.";
        } else {
            $clean_content = filter_chat_message(nl2br(e($content)));
            $clean_title = e($title);

            $db_instance->execute_query(
                "UPDATE suggestions SET title = ?, content = ? WHERE id = ?",
                [$clean_title, $clean_content, $sug_id]
            );

            $_SESSION["game_success"] = "Dein Vorschlag wurde erfolgreich aktualisiert.";
            change_location("suggestions.php");
            exit;
        }
    }
}

if ($is_admin) {
    if (isset($_POST["admin_update_status"])) {
        $sug_id = (int)$_POST["suggestion_id"];
        $status = (int)$_POST["status"];
        $comment = trim($_POST["admin_comment"] ?? "");

        $db_instance->execute_query(
            "UPDATE suggestions SET status = ?, admin_comment = ? WHERE id = ?",
            [$status, e($comment), $sug_id]
        );
        $_SESSION["game_success"] = "Status des Vorschlags aktualisiert.";
        change_location("suggestions.php");
        exit;
    }

    if (isset($_GET["delete"])) {
        $sug_id = (int)$_GET["delete"];
        $db_instance->execute_query("DELETE FROM suggestions WHERE id = ?", [$sug_id]);
        $db_instance->execute_query("DELETE FROM suggestion_votes WHERE suggestion_id = ?", [$sug_id]);
        $_SESSION["game_success"] = "Vorschlag wurde gelöscht.";
        change_location("suggestions.php");
        exit;
    }
}

$filter = $_GET["tab"] ?? "all";
$order_sql = match ($filter) {
    "popular" => "score DESC, s.created_at DESC",
    default => "s.created_at DESC"
};

$where_sql = match ($filter) {
    "open" => "WHERE s.status = 0",
    "approved" => "WHERE s.status = 1",
    "done" => "WHERE s.status = 2",
    default => ""
};

$status_meta = [
    0 => [
        "label" => "In Prüfung",
        "color" => "var(--link-color)",
        "border" => "rgba(212, 175, 55, 0.4)",
        "bg" => "rgba(212, 175, 55, 0.2)"
    ],
    1 => [
        "label" => "Geplant",
        "color" => "#3498db",
        "border" => "rgba(52, 152, 219, 0.4)",
        "bg" => "rgba(52, 152, 219, 0.25)"
    ],
    2 => [
        "label" => "Umgesetzt",
        "color" => "#2ecc71",
        "border" => "rgba(46, 204, 113, 0.4)",
        "bg" => "rgba(46, 204, 113, 0.25)"
    ],
    3 => [
        "label" => "Abgelehnt",
        "color" => "#e74c3c",
        "border" => "rgba(231, 76, 60, 0.4)",
        "bg" => "rgba(231, 76, 60, 0.25)"
    ]
];

/*
 * HTML Content Part
 */
$view .= "<p style='margin-top: 0;'>Hier kannst du deine Ideen für <b>Magic Empires</b> einreichen und über Vorschläge anderer Herrscher abstimmen.</p>";

$res_last_cd = $db_instance->execute_query(
    "SELECT created_at FROM suggestions WHERE user_id = ? ORDER BY id DESC LIMIT 1",
    [$uid]
);
$last_created_cd = (int)($res_last_cd->fetch_column() ?? 0);
$cooldown_wait = max(0, ($last_created_cd + SUGGESTION_COOLDOWN_SECONDS) - time());
$is_on_cooldown = ($cooldown_wait > 0 && !$is_admin);

$cooldown_notice = "";
if ($is_on_cooldown) {
    $cooldown_notice = "<p class='error' id='sug-cooldown-notice' style='font-size: 13px; margin: 10px 0 0 0;'>
        Wartezeit: Neuer Vorschlag möglich in <b><span class='js-countdown' id='sug-cooldown-timer' data-seconds='$cooldown_wait' data-no-reload='true'>-</span></b>
    </p>";
}

$disabled_attr = ($is_on_cooldown || mb_strlen($post_title) < SUGGESTION_TITLE_MIN_LENGTH || mb_strlen($post_content) < SUGGESTION_DESC_MIN_LENGTH) ? "disabled" : "";

$view .= "
<div class='box-container' style='max-width: 650px; margin: 0 auto 25px auto;'>
    <div class='box-header'>Neuen Vorschlag einreichen</div>
    <form method='POST' id='new-suggestion-form' data-cooldown='" . ($is_on_cooldown ? "true" : "false") . "' class='box-content box-content-bg' style='padding: 15px;'>
        <input type='text' name='title' id='new-sug-title' placeholder='Kurzer, aussagekräftiger Titel...' value='$post_title' 
                minlength='" . SUGGESTION_TITLE_MIN_LENGTH . "' maxlength='" . SUGGESTION_TITLE_MAX_LENGTH . "' style='width: 100%; margin-bottom: 10px;' required>
        <textarea name='content' id='new-sug-content' rows='5' placeholder='Beschreibe deine Idee möglichst genau...' 
                minlength='" . SUGGESTION_DESC_MIN_LENGTH . "' maxlength='" . SUGGESTION_DESC_MAX_LENGTH . "' style='width: 100%; margin-bottom: 10px;' required>$post_content</textarea>
        <div style='text-align: center;'>
            <input type='submit' name='submit_suggestion' id='btn-submit-suggestion' value='Einreichen' style='padding: 6px 25px;' $disabled_attr>
            $cooldown_notice
        </div>
    </form>
</div>";

$tabs = [
    "all" => "Alle",
    "popular" => "Beliebteste",
    "open" => "In Prüfung",
    "approved" => "Geplant",
    "done" => "Umgesetzt"
];

$view .= "<div class='tab' style='max-width: 650px; margin: 0 auto 20px auto;'>";
foreach ($tabs as $k => $label) {
    $active = ($k === "all") ? "active" : "";
    $view .= "<div class='tablinks $active' data-on-click='switchSuggestionTab' data-tab='$k'>$label</div>";
}
$view .= "</div>";

$query = "
    SELECT 
        s.*,
        COALESCE(SUM(IF(v.vote = 1, 1, 0)), 0) AS upvotes,
        COALESCE(SUM(IF(v.vote = -1, 1, 0)), 0) AS downvotes,
        COALESCE(SUM(v.vote), 0) AS score,
        COALESCE(MAX(CASE WHEN v.user_id = ? THEN v.vote END), 0) AS my_vote
    FROM suggestions s
    LEFT JOIN suggestion_votes v ON s.id = v.suggestion_id
    GROUP BY s.id, s.created_at
    ORDER BY s.created_at DESC
";
$suggestions = $db_instance->execute_query($query, [$uid]);

$view .= "<div id='suggestions-list-container' style='display: flex; flex-direction: column; gap: 15px; max-width: 650px; margin: 0 auto;'>";

while ($row = $suggestions->fetch_assoc()) {
    $sug_id = (int)$row["id"];
    $status_data = $status_meta[$row["status"]] ?? $status_meta[0];
    $my_vote = (int)$row["my_vote"];
    $author = new User($row["user_id"], $row["username"]);
    $author_link = "<a href='#' data-on-click='openOverlay' data-url='userinfo.php?userid={$row["user_id"]}' data-title='Spieler-Info'><b>" . e($row["username"]) . "</b></a>";
    $date_str = date("d.m.Y \u\m H:i:s", $row["created_at"]);
    $score = (int)$row["score"];

    $btn_up_active = ($my_vote === 1) ? " active-vote" : "";
    $btn_down_active = ($my_vote === -1) ? " active-vote" : "";

    $admin_controls = "";
    if ($is_admin) {
        $admin_controls = "
            <hr style='margin: 12px 0 8px 0; border: 0; border-top: 1px dashed rgba(255,255,255,0.1);'>
            <div style='display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;'>
                <form method='POST' style='display: flex; gap: 6px; align-items: center; margin: 0; flex: 1;'>
                    <input type='hidden' name='suggestion_id' value='$sug_id'>
                    <input type='hidden' name='admin_update_status' value='1'>
                    <select name='status' style='font-size: 12px; padding: 2px 20px 2px 4px;'>
                        <option value='0' " . ($row["status"] == 0 ? "selected" : "") . ">In Prüfung</option>
                        <option value='1' " . ($row["status"] == 1 ? "selected" : "") . ">Angenommen</option>
                        <option value='2' " . ($row["status"] == 2 ? "selected" : "") . ">Umgesetzt</option>
                        <option value='3' " . ($row["status"] == 3 ? "selected" : "") . ">Abgelehnt</option>
                    </select>
                    <input type='text' name='admin_comment' placeholder='Admin-Notiz...' value='" . e($row["admin_comment"] ?? '') . "' style='font-size: 12px; padding: 3px; flex: 1; max-width: 250px;'>
                    <input type='submit' value='Speichern' style='font-size: 11px; padding: 3px 8px;'>
                </form>
                <button type='button' class='btn-delete' data-on-click='confirmDeleteSuggestion' data-id='$sug_id' title='Vorschlag löschen' style='font-size: 11px; padding: 3px 8px;'>Löschen</button>
            </div>";
    }

    $comment_box = "";
    if (!empty($row["admin_comment"])) {
        $comment_box = "
            <div style='margin-top: 10px; background: rgba(0,0,0,0.3); border-left: 3px solid var(--link-color); padding: 8px 12px; border-radius: 3px; text-align: left;'>
                <small style='color: var(--link-color); font-weight: bold;'>Antwort der Spielleitung:</small><br>
                <span style='font-size: 13px;'>" . e($row["admin_comment"]) . "</span>
            </div>";
    }

    $can_edit = ($is_admin || ((int)$row["user_id"] === $uid && (int)$row["status"] === 0));

    $raw_content = str_replace(['<br>', '<br />'], '', $row["content"]);

    $edit_btn = "";
    if ($can_edit) {
        $edit_btn = "<img src='images/icons/icon_edit.png' 
                             class='ressource-icons' 
                             style='cursor: pointer; width: 18px; height: 18px;' 
                             data-on-click='editSuggestionInline' 
                             data-id='$sug_id' 
                             data-title='" . e($row["title"]) . "' 
                             data-content='" . e($raw_content) . "' 
                             title='Vorschlag bearbeiten' alt='Bearbeiten'>";
    }

    $view .= "
        <div class='box-container' data-id='$sug_id' data-status='{$row["status"]}' data-score='$score' style='margin-bottom: 0;'>
            <div class='box-header' style='justify-content: space-between; padding: 0 15px; height: auto; min-height: 40px;'>
                <span style='font-weight: bold; font-size: 17px; word-break: break-word; text-align: left; padding: 8px 0;'>" . e($row["title"]) . "</span>
                <div style='display: flex; align-items: center; gap: 10px;'>
                    $edit_btn
                    <span style='background: {$status_data["bg"]}; color: {$status_data["color"]}; font-size: 12px; font-weight: bold; border: 1px solid {$status_data["border"]}; padding: 3px 8px; border-radius: 4px; white-space: nowrap;'>
                        {$status_data["label"]}
                    </span>
                </div>
            </div>
            <div class='box-content box-content-bg' style='padding: 15px;'>
                <p style='margin-top: 0; text-align: left; font-size: 15px; line-height: 1.5; word-break: break-word;'>
                    " . wrap_emojis($row["content"]) . "
                </p>
                $comment_box
                <div style='display: flex; justify-content: space-between; align-items: center; margin-top: 15px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 10px;'>
                    <small style='opacity: 0.7;'>Von $author_link am $date_str</small>

                    <div class='suggestion-vote-bar' data-id='$sug_id' style='display: flex; gap: 8px; align-items: center;'>
                        <button type='button' class='btn-vote-up$btn_up_active' data-on-click='voteSuggestion' data-id='$sug_id' data-type='up' title='Dafür stimmen' style='font-size: 13px; padding: 3px 10px;'>
                            " . wrap_emojis("👍") . " <b class='count-up'>{$row["upvotes"]}</b>
                        </button>
                        <button type='button' class='btn-vote-down$btn_down_active' data-on-click='voteSuggestion' data-id='$sug_id' data-type='down' title='Dagegen stimmen' style='font-size: 13px; padding: 3px 10px;'>
                            " . wrap_emojis("👎") . " <b class='count-down'>{$row["downvotes"]}</b>
                        </button>
                    </div>
                </div>
                $admin_controls
            </div>
        </div>";
}

$view .= "</div>";
$view .= "<div id='suggestions-empty-box' class='info-box' style='display: none; justify-content: center; max-width: 500px;'><span>Keine Vorschläge in dieser Kategorie vorhanden.</span></div>";

/*
 * HTML Section
 */
$title = "Vorschläge";
$header = "Vorschläge & Wünsche";
$script_files = ["suggestions", "userinfo"];

if (!empty($error)) {
    $view = show_error_box($error) . $view;
}

include("layout/base.php");