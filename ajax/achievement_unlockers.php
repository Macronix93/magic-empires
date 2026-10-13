<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $user->check_user_login();
    $uid = $user->get_user_id();

    // Mode 1: All achievements of the selected user
    if (isset($_GET["userid"])) {
        $target_user_id = (int)$_GET["userid"];
        if ($target_user_id <= 0) {
            echo show_error_box("Ungültige Spieler-ID.");
            exit;
        }

        $res_u = $db_instance->execute_query("SELECT id, username, gender FROM users WHERE id = ?", [$target_user_id]);
        $target_user = $res_u->fetch_assoc();

        if (!$target_user) {
            echo show_error_box("Spieler nicht gefunden.");
            exit;
        }

        $query_user_achs = "
            SELECT a.id, a.name, a.description, a.title, a.title_f, a.is_secret, ua.unlocked_at,
                   (SELECT 1 FROM user_achievements my_ua WHERE my_ua.user_id = ? AND my_ua.achievement_id = a.id LIMIT 1) AS viewer_unlocked
            FROM user_achievements ua
            JOIN achievements a ON ua.achievement_id = a.id
            WHERE ua.user_id = ?
            ORDER BY ua.unlocked_at DESC
        ";
        $res_user_achs = $db_instance->execute_query($query_user_achs, [$uid, $target_user_id])->fetch_all(MYSQLI_ASSOC);
        $total_user_achs = count($res_user_achs);
        $total_possible = (int)$db_instance->query("SELECT COUNT(*) FROM achievements")->fetch_column();
        $target_username = e($target_user["username"]);
        ?>

        <div id="modal-title-data" data-title="Errungenschaften: <?= $target_username ?>" data-width="650px"
             style="display: none;"></div>
        <div style="padding: 10px; margin: 0 auto; text-align: left;">
            <h3 class="title-border" style="margin-top: 0;">Errungenschaften von <?= $target_username ?></h3>

            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; font-size: 14px;">
                <span style="opacity: 0.8;">Freigeschaltet:</span>
                <b><?= $total_user_achs ?> / <?= $total_possible ?> <small
                            style="opacity: 0.7;">(<?= round(($total_user_achs / max(1, $total_possible)) * 100, 1) ?>
                        %)</small></b>
            </div>

            <?php if ($total_user_achs > 0): ?>
                <div class="userinfo-kingdoms-scroll" style="max-height: 380px;">
                    <table class="table" style="width: 100%; margin: 0;">
                        <colgroup>
                            <col style="width: 65%;">
                            <col style="width: 35%;">
                        </colgroup>
                        <tr>
                            <td class="td-gradient"><b>Errungenschaft & Titel</b></td>
                            <td class="td-gradient td-center"><b>Freigeschaltet am</b></td>
                        </tr>
                        <?php foreach ($res_user_achs as $row):
                            $is_secret = ((int)$row["is_secret"] === 1 && empty($row["viewer_unlocked"]));
                            $ach_name = $is_secret ? "???" : e($row["name"]);
                            $ach_desc = $is_secret ? "<i>Diese Errungenschaft ist geheim.</i>" : e($row["description"]);
                            $target_gender = $target_user["gender"] ?? 'm';
                            $ach_title = ($target_gender === 'f' && !empty($row["title_f"])) ? $row["title_f"] : $row["title"];
                            $title_display = $is_secret ? "<small style='opacity: 0.6;'>Titel: ???</small>" : "<small class='passed' style='font-weight: bold;'>Titel: &bdquo;" . e($ach_title) . "&ldquo;</small>";
                            ?>
                            <tr>
                                <td style="padding: 8px 10px;">
                                    <div style="margin-bottom: 3px;"><b><?= $ach_name ?></b></div>
                                    <div style="opacity: 0.85; margin-bottom: 3px;"><?= $ach_desc ?></div>
                                    <?= $title_display ?>
                                </td>
                                <td class="td-center" style="vertical-align: middle;">
                                    <?= date("d.m.Y H:i:s", $row["unlocked_at"]) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php else: ?>
                <div style="opacity: 0.6; text-align: center; margin-top: 20px;">Dieser Herrscher hat bisher noch keine
                    Errungenschaften freigeschaltet.
                </div>
            <?php endif; ?>
        </div>
        <?php
        exit;
    }

    // MODE 2: List of all players that unlocked that achievement
    $ach_id = (int)($_GET["id"] ?? 0);
    if ($ach_id <= 0) {
        echo show_error_box("Ungültige Anfrage.");
        exit;
    }

    $res_ach = $db_instance->execute_query(
            "SELECT id, name, description, title, title_f, is_secret FROM achievements WHERE id = ?",
            [$ach_id]
    );
    $ach = $res_ach->fetch_assoc();

    if (!$ach) {
        echo show_error_box("Errungenschaft nicht gefunden.");
        exit;
    }

    $is_secret = ((int)$ach["is_secret"] === 1);

    $res_unlocked = $db_instance->execute_query(
            "SELECT 1 FROM user_achievements WHERE user_id = ? AND achievement_id = ? LIMIT 1",
            [$uid, $ach_id]
    );
    $user_has_unlocked = ($res_unlocked->num_rows > 0);

    if ($is_secret && !$user_has_unlocked) {
        echo show_error_box("Diese Errungenschaft ist geheim und wurde von dir noch nicht freigeschaltet.");
        exit;
    }

    $user_gender = $user->get_gender();
    $earned_title = ($user_gender === 'f' && !empty($ach["title_f"])) ? $ach["title_f"] : $ach["title"];
    $name = e($ach["name"]);
    $desc = e($ach["description"]);

    $query = "
        SELECT u.id, u.username, ua.unlocked_at 
        FROM user_achievements ua
        JOIN users u ON ua.user_id = u.id
        WHERE ua.achievement_id = ?
        ORDER BY ua.unlocked_at DESC
    ";
    $res_users = $db_instance->execute_query($query, [$ach_id])->fetch_all(MYSQLI_ASSOC);
    $total_count = count($res_users);
    ?>

    <div id="modal-title-data" data-title="Errungenschaft: <?= $name ?>" data-width="600px"
         style="display: none;"></div>
    <div style="padding: 10px; margin: 0 auto; text-align: left;">
        <h3 class="title-border" style="margin-top: 0;"><?= $name ?></h3>

        <div style="background: rgba(0,0,0,0.25); border: 1px solid var(--border-gold); border-radius: 4px; padding: 10px 14px; margin-bottom: 15px;">
            <p style="margin: 0 0 8px 0;"><?= $desc ?></p>
            <?php if (!empty($earned_title)): ?>
                <div class="passed">
                    Titel: <b>&bdquo;<?= e($earned_title) ?>&ldquo;</b>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_count > 0): ?>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; font-size: 13px;">
            <span style="opacity: 0.8;">Freigeschaltet von:</span>
            <b><?= $total_count ?> <?= ($total_count === 1 ? "Herrscher" : "Herrschern") ?></b>
        </div>

        <div class="userinfo-kingdoms-scroll" style="max-height: 280px;">
            <table class="table" style="width: 100%; margin: 0;">
                <colgroup>
                    <col style="width: 45%;">
                    <col style="width: 40%;">
                </colgroup>
                <tr>
                    <td class="td-gradient"><b>Herrscher</b></td>
                    <td class="td-gradient td-center"><b>Datum</b></td>
                </tr>

                <?php foreach ($res_users as $idx => $row):
                    $u = new User((int)$row["id"], $row["username"]);
                    ?>
                    <tr>
                        <td><?= $u->render_user() ?></td>
                        <td class="td-center"><?= date("d.m.Y H:i:s", $row["unlocked_at"]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
                <div style="opacity: 0.6; text-align: center; margin-top: 10px;">Bisher hat noch niemand
                    diese Errungenschaft freigeschaltet.
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
} else {
    change_location("achievements.php");
}