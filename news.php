<?php
require_once("includes/core.php");

$is_admin = ($user->is_logged_in() && $user->is_admin());

if ($is_admin) {
    if (isset($_POST["edit_news"])) {
        $news_id = (int)$_POST["news_id"];
        $raw_title = trim($_POST["title"] ?? "");
        $raw_content = trim($_POST["content"] ?? "");

        if (empty($raw_title) || empty($raw_content)) {
            $view .= show_error_box("Bitte alle Felder ausfüllen!");
        } else if (mb_strlen($raw_title) > MAX_NEWS_TITLE_LENGTH) {
            $view .= show_error_box("Der Titel ist zu lang (max. " . MAX_NEWS_TITLE_LENGTH . " Zeichen)!");
        } else if (mb_strlen($raw_content) > MAX_NEWS_CONTENT_LENGTH) {
            $view .= show_error_box("Die Nachricht ist zu lang (max. " . MAX_NEWS_CONTENT_LENGTH . " Zeichen)!");
        } else {
            $title = e($raw_title);
            $content = nl2br(e($raw_content));

            $db_instance->execute_query(
                "UPDATE news SET title = ?, content = ? WHERE id = ?",
                [$title, $content, $news_id]
            );

            $db_instance->execute_query(
                "UPDATE news SET id = (SELECT m FROM (SELECT MAX(id) + 1 AS m FROM news) t) WHERE id = ?",
                [$news_id]
            );

            $view .= show_passed_box("News-Beitrag aktualisiert!");
        }
    }

    if (isset($_POST["post_news"])) {
        $raw_title = trim($_POST["title"] ?? "");
        $raw_content = trim($_POST["content"] ?? "");

        if (empty($raw_title) || empty($raw_content)) {
            $view .= show_error_box("Bitte alle Felder ausfüllen!");
        } else if (mb_strlen($raw_title) > MAX_NEWS_TITLE_LENGTH) {
            $view .= show_error_box("Der Titel ist zu lang (max. " . MAX_NEWS_TITLE_LENGTH . " Zeichen)!");
        } else if (mb_strlen($raw_content) > MAX_NEWS_CONTENT_LENGTH) {
            $view .= show_error_box("Die Nachricht ist zu lang (max. " . MAX_NEWS_CONTENT_LENGTH . " Zeichen)!");
        } else {
            $title = e($raw_title);
            $content = nl2br(e($raw_content));

            $db_instance->execute_query(
                "INSERT INTO news (userid, username, title, content, date) VALUES (?, ?, ?, ?, ?)",
                [$user->get_user_id(), $user->get_user_name(), $title, $content, time()]
            );
            $view .= show_passed_box("Neuigkeit veröffentlicht!");
        }
    }

    if (isset($_GET["delete"])) {
        $news_id = (int)$_GET["delete"];
        $db_instance->execute_query("DELETE FROM news WHERE id = ?", [$news_id]);

        $view .= show_passed_box("Eintrag gelöscht.");
    }
}

// Pagination Setup
$rows_per_page = 5;
$current_page = max(1, (int)($_GET["currentpage"] ?? 1));
$num_rows = $db_instance->execute_query("SELECT COUNT(*) FROM news")->fetch_row()[0];
$total_pages = ceil($num_rows / $rows_per_page);
$offset = ($current_page - 1) * $rows_per_page;

// Load News
$result = $db_instance->execute_query(
    "SELECT * FROM news ORDER BY id DESC LIMIT ?, ?",
    [$offset, $rows_per_page]
);

// Set Read Status
if ($user->is_logged_in()) {
    $latest_news_id = $db_instance->execute_query("SELECT MAX(id) FROM news")->fetch_row()[0] ?? 0;
    $db_instance->execute_query("UPDATE users SET last_news_read = ? WHERE id = ?", [$latest_news_id, $user->get_user_id()]);
}

// Build View
if ($is_admin) {
    $view .= "
    <div class='box-container allow-overflow' style='margin-bottom: 30px;'>
        <div class='box-header'>Neuigkeit verfassen</div>
        <div class='box-content box-content-bg' style='padding: 15px;'>
            <form method='POST'>
                <input type='text' maxlength='" . MAX_NEWS_TITLE_LENGTH . "' name='title' placeholder='Titel' style='width: 100%; margin-bottom: 10px;' required><br>
                <textarea id='message-input' name='content' maxlength='" . MAX_NEWS_CONTENT_LENGTH . "'  placeholder='Inhalt...' rows='3' style='width: 100%; margin-bottom: 10px;' required></textarea><br>
                <div style='display: flex; justify-content: center; align-items: center; gap: 10px;'>
                    <div class='emoji-picker-container'>
                        <div id='emoji-menu' class='emoji-menu bottom'>";
    foreach (get_chat_emojis() as $emoji) {
        $view .= "<span data-on-click='pickEmoji'>$emoji</span>";
    }
    $view .= "          </div>
                        <button type='button' class='emoji-trigger' data-on-click='toggleEmojis' title='Emoji einfügen'>🙂</button>
                    </div>
                    <input type='submit' name='post_news' value='Veröffentlichen'>
                </div>
            </form>
        </div>
    </div><hr><br>";
}

if ($result->num_rows > 0) {
    foreach ($result as $row) {
        $date = date("d.m.Y \u\m H:i:s", $row["date"]);
        $del_button = "";

        if ($is_admin) {
            $raw_content = str_replace(['<br>', '<br />'], '', $row["content"]);

            $del_button = "<div class='news-admin-tools' style='gap: 10px; display: flex;'>
                        <a href='#' 
                           data-on-click='editNewsInline' 
                           data-id='{$row["id"]}'
                           data-title='" . e($row["title"]) . "'
                           data-content='" . e($raw_content) . "'>
                            <img src='images/icons/icon_edit.png' class='ressource-icons' alt='Edit' title='Eintrag bearbeiten'>
                        </a>
                        <a href='#' 
                           data-on-click='confirmDeleteNews' 
                           data-id='{$row["id"]}'>
                            <img src='images/icons/icon_delete.png' class='ressource-icons' alt='Löschen' title='Eintrag löschen'>
                        </a>
                    </div>";
        }

        $view .= "
        <div class='box-container'>
            <div class='box-header news-flex-header'>
                    <div class='news-header-title' title='" . e($row["title"]) . "'>
                        " . e($row["title"]) . "
                    </div>
                    $del_button
                </div>
                <div class='box-content news-content box-content-bg' style='padding-bottom: 15px; padding-left: 15px; padding-right: 15px; text-align: center;'>
                    <p style='margin-top: 0; padding-top: 15px;'>" . $row["content"] . "</p>
                    <div style='font-size: 12px; margin-top: 25px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 5px; text-align: left;'>
                        Verfasst von: <b>" . e($row["username"]) . "</b> am $date
                    </div>
                </div>
        </div>";
    }

    if ($total_pages > 1) {
        $view .= "<div class='pagination-container'><div class='pagination-bar'>";

        if ($current_page > 1) {
            $view .= "<a href='news.php?currentpage=1' class='page-link' title='Erste Seite'>&laquo;</a>";
            $prev = $current_page - 1;
            $view .= "<a href='news.php?currentpage=$prev' class='page-link' title='Zurück'>&lsaquo;</a>";
        }

        $range = 2;
        for ($i = ($current_page - $range); $i <= ($current_page + $range); $i++) {
            if ($i > 0 && $i <= $total_pages) {
                if ($i == $current_page) {
                    $view .= "<span class='page-link active'>$i</span>";
                } else {
                    $view .= "<a href='news.php?currentpage=$i' class='page-link'>$i</a>";
                }
            }
        }

        if ($current_page < $total_pages) {
            $next = $current_page + 1;
            $view .= "<a href='news.php?currentpage=$next' class='page-link' title='Weiter'>&rsaquo;</a>";
            $view .= "<a href='news.php?currentpage=$total_pages' class='page-link' title='Letzte Seite'>&raquo;</a>";
        }

        $view .= "</div></div>";
    }
} else {
    $view .= "Es gibt noch keine Neuigkeiten.";
}

/*
 * HTML Section
 */
$title = "Neuigkeiten";
$header = "Neuigkeiten";
$script_files = ["adminpanel", "news"];

if ($user->is_logged_in()) {
    include("layout/base.php");
} else {
    include("layout/guest_base.php");
}