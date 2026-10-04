<?php
require_once("../includes/core.php");

$user->check_user_login();

$uid = $user->get_user_id();
$sug_id = (int)($_GET["id"] ?? 0);
if ($sug_id <= 0) die("Ungültige ID");

$sug = $db_instance->execute_query("SELECT id, title FROM suggestions WHERE id = ?", [$sug_id])->fetch_assoc();
if (!$sug) {
    echo show_error_box("Dieser Vorschlag existiert nicht mehr.");
    exit;
}

$error = "";

if (isset($_GET["delete_comment"])) {
    $cid = (int)$_GET["delete_comment"];
    $c_row = $db_instance->execute_query("SELECT user_id FROM suggestion_comments WHERE id = ?", [$cid])->fetch_assoc();

    if ($c_row && ((int)$c_row["user_id"] === $uid || $user->is_admin())) {
        $db_instance->execute_query("DELETE FROM suggestion_comments WHERE id = ?", [$cid]);
    } else {
        $error = "Du hast keine Berechtigung, diesen Kommentar zu löschen!";
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_comment"])) {
    $raw = trim($_POST["comment"] ?? "");

    $clean = preg_replace([
            '/[^\P{Cc}\r\n]+|[\p{Cf}\p{Mn}]+/u',
            '/[ \t]+/u',
            '/^[ \t]+/m',
            '/[ \t]+$/m'
    ], ['', ' ', '', ''], $raw);
    $clean = trim($clean);

    $len = mb_strlen($clean, "UTF-8");
    $line_breaks = substr_count($clean, "\n");

    $res_last = $db_instance->execute_query(
            "SELECT created_at FROM suggestion_comments WHERE user_id = ? ORDER BY id DESC LIMIT 1",
            [$uid]
    );
    $last_time = (int)($res_last->fetch_column() ?? 0);
    $wait = ($last_time + SUGGESTION_COMMENT_COOLDOWN_SECONDS) - time();

    if ($wait > 0 && !$user->is_admin()) {
        $error = "Bitte warte noch " . $wait . "s, bevor du erneut kommentierst.";
    } else if ($len < SUGGESTION_COMMENT_MIN_LENGTH) {
        $error = "Dein Kommentar muss mindestens " . SUGGESTION_COMMENT_MIN_LENGTH . " Zeichen lang sein.";
    } else if ($len > SUGGESTION_COMMENT_MAX_LENGTH) {
        $error = "Dein Kommentar darf maximal " . SUGGESTION_COMMENT_MAX_LENGTH . " Zeichen lang sein.";
    } else if ($line_breaks > MAX_LINE_BREAK_COUNT) {
        $error = "Dein Text darf maximal " . MAX_LINE_BREAK_COUNT . " Zeilenumbrüche beinhalten.";
    } else {
        $filtered = Messages::filter_chat_message(nl2br(e($clean)));
        $db_instance->execute_query(
                "INSERT INTO suggestion_comments (suggestion_id, user_id, username, comment, created_at) VALUES (?, ?, ?, ?, ?)",
                [$sug_id, $uid, $user->get_user_name(), $filtered, time()]
        );

        $total_comments = (int)$db_instance->execute_query("SELECT COUNT(*) FROM suggestion_comments WHERE suggestion_id = ?", [$sug_id])->fetch_column();
        $target_page = max(1, (int)ceil($total_comments / 6));
        header("Location: suggestion_comments.php?id=$sug_id&cpage=$target_page");
        exit;
    }
}

// --- PAGINATION ---
$per_page = MAX_SUGGESTION_COMMENTS_PER_PAGE;
$total_comments = (int)$db_instance->execute_query("SELECT COUNT(*) FROM suggestion_comments WHERE suggestion_id = ?", [$sug_id])->fetch_column();
$total_pages = max(1, (int)ceil($total_comments / $per_page));
$current_page = max(1, min($total_pages, (int)($_GET["cpage"] ?? 1)));
$offset = ($current_page - 1) * $per_page;

$comments = $db_instance->execute_query(
        "SELECT c.*, u.username FROM suggestion_comments c JOIN users u ON c.user_id = u.id WHERE c.suggestion_id = ? ORDER BY c.created_at LIMIT ?, ?",
        [$sug_id, $offset, $per_page]
);
?>
<div style="text-align: left; padding: 10px; max-width: 650px; margin: 0 auto; box-sizing: border-box;"
     data-total-comments="<?= $total_comments ?>">
    <h3 style="margin-top: 0; color: var(--link-color); border-bottom: 1px solid var(--border-gold); padding-bottom: 5px; word-break: break-word;">
        <?= e($sug["title"]) ?>
    </h3>

    <?php if (!empty($error)): ?>
        <?= show_error_box($error) ?>
    <?php endif; ?>

    <div id="comments-list"
         style="min-height: 120px; max-height: 450px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; padding-right: 4px;">
        <?php if ($total_comments === 0): ?>
            <p id="no-comments-msg" style="text-align: center; opacity: 0.6; padding: 20px 0;">Bisher keine Kommentare.
                Schreibe den ersten!</p>
        <?php else: ?>
            <?php while ($c = $comments->fetch_assoc()):
                $is_me = ((int)$c["user_id"] === $uid);
                $can_delete = ($is_me || $user->is_admin());
                $sender = new User((int)$c["user_id"], $c["username"]);
                ?>
                <div class="server-bubble"
                     style="background-color: rgba(0,0,0,0.3); padding: 8px 12px; margin-bottom: 0;">
                    <div class="message-border" style="margin-bottom: 4px; padding-bottom: 3px;">
                        <span class="msg-header-left">
                            <img class="user-image" src="<?= e($sender->get_avatar()) ?>" alt="">
                            <span><b><?= e($c["username"]) ?></b> <small
                                        class="msg-date"><?= date(DATE_FORMAT_CHAT, $c["created_at"]) ?></small></span>
                        </span>
                        <?php if ($can_delete): ?>
                            <img src="images/icons/icon_delete.png" class="ressource-icons"
                                 style="cursor: pointer;"
                                 data-on-click="deleteSuggestionComment" data-sugid="<?= $sug_id ?>"
                                 data-id="<?= $c["id"] ?>" data-page="<?= $current_page ?>" title="Kommentar löschen"
                                 alt="">
                        <?php endif; ?>
                    </div>
                    <div style="word-break: break-word; line-height: 1.4;"><?= Messages::wrap_emojis($c["comment"]) ?></div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination-container" style="margin: 5px 0 15px 0; text-align: center;">
            <div class="pagination-bar" style="padding: 4px;">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $current_page): ?>
                        <span class="page-link active" style="padding: 3px 8px; font-size: 12px;"><?= $i ?></span>
                    <?php else: ?>
                        <a href="#" class="page-link" style="padding: 3px 8px; font-size: 12px;"
                           data-on-click="paginateSuggestionComments" data-sugid="<?= $sug_id ?>"
                           data-page="<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        </div>
    <?php endif; ?>

    <form id="form-suggestion-comment" method="POST" data-on-submit="submitSuggestionComment" style="margin-top: 10px;">
        <input type="hidden" name="suggestion_id" value="<?= $sug_id ?>">
        <input type="hidden" name="cpage" value="<?= $current_page ?>">

        <div style="display: flex; gap: 6px; align-items: stretch; position: relative;">
            <label for="comment-input" style="display: none;">Dein Kommentar</label>
            <textarea id="comment-input" name="comment" rows="3"
                      maxlength="<?= SUGGESTION_COMMENT_MAX_LENGTH ?>"
                      placeholder="Dein Kommentar..."
                      required></textarea>

            <div class="emoji-picker-container" style="position: relative; display: flex; align-items: stretch;">
                <div id="emoji-menu" class="emoji-menu" style="bottom: calc(100% + 6px); right: 0;">
                    <?php foreach (Messages::get_chat_emojis() as $emoji): ?>
                        <span data-on-click="pickEmoji"><?= $emoji ?></span>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="emoji-trigger" data-on-click="toggleEmojis" title="Emoji einfügen"
                        style="display: flex; align-items: center; justify-content: center;">
                    🙂
                </button>
            </div>

            <input type="submit" value="Absenden" style="max-width: 90px;">
        </div>
    </form>
</div>
<br>
<div style="text-align: center;">
    <button data-on-click="closeOverlay">Schließen</button>
</div>