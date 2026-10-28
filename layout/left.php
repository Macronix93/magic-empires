<?php
$current_page = basename($_SERVER["PHP_SELF"]);
$messages = new Messages($user);

$unreads = $user->get_unread_counts();

$unread_total = $unreads["total"];
$unread_news = $unreads["news"];
$unread_world = $unreads["world"];
$unread_guild = $unreads["guild"];
$unread_suggestions = $unreads["suggestions"] ?? 0;
$unread_achievements = $unreads["achievements"] ?? 0;
$unread_arena = $unreads["arena"] ?? 0;

$inbox_only_unread = $unreads["pms"] + $unreads["server"] + $unreads["support"];

if ($current_page === 'messages.php' && ($_GET["action"] ?? '') === 'read' && !empty($_GET["s"])) {
    $partner_unread = (int)($db_instance->execute_query(
            "SELECT COUNT(*) FROM messages WHERE senderid = ? AND receiverid = ? AND hasread = 0 AND deleted = 0",
            [(int)$_GET["s"], $user->get_user_id()]
    )->fetch_column() ?? 0);
    
    $inbox_only_unread = max(0, $inbox_only_unread - $partner_unread);
}
if ($current_page === "messages.php" && isset($_GET["worldchat"])) {
    $unread_world = 0;
}
$guild_tab = $_GET["tab"] ?? ($_COOKIE["me_guild_tab"] ?? "chat");
if ($current_page === "guild.php" && $guild_tab === "chat") {
    $unread_guild = 0;
}

$arena_active = Arena::is_season_active();
$arena_has_coins = Arena::has_available_coins($user->get_user_id());
$arena_class = $arena_active ? ($current_page === "arena.php" ? " active" : '') : " box-disabled";
$arena_attr = $arena_active ? 'data-on-click="navigate" data-url="arena.php"' : 'title="Die Arena befindet sich aktuell in der Saisonpause."';
?>
    <div class="box-container left-right-container">
        <div class="box-header">
            <?php
            echo "<div style='width: 100%; padding: 0 12px; display: flex; justify-content: space-between; align-items: center;' id='usernameContainer'>
                <div style='overflow: hidden; white-space: nowrap;' id='username'>";
            echo e($user->get_user_name());
            echo "</div><a href='index.php?logout'><img src='images/icons/icon_logout.png' class='ressource-icons' alt='Logout' title='Logout'/></a></div>";
            ?>
        </div>
        <div class="box-content">
            <div class="box<?= $current_page === 'overview.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="index.php">
                <?= get_page_icon("overview.php") ?> Übersicht
                <span class="msg-badge" style="<?= $atk_count > 0 ? '' : 'display: none;' ?>">
                    <?= $messages->show_messages_indicator($atk_count) ?>
                </span>
                <span class="msg-badge badge-blue" style="<?= $sup_count > 0 ? '' : 'display: none;' ?>">
                    <?= $messages->show_messages_indicator($sup_count) ?>
                </span>
            </div>
            <div class="box<?= ($current_page === 'messages.php' && !isset($_GET["worldchat"])) ? " active" : '' ?>"
                 data-on-click="navigate" data-url="messages.php">
                <?= get_page_icon("messages.php") ?>
                <span>Nachrichten</span>
                <span class="msg-badge js-badge-priv" style="<?= $inbox_only_unread > 0 ? '' : 'display: none;' ?>">
                    <?= $messages->show_messages_indicator($inbox_only_unread) ?>
                </span>
            </div>
            <div class="box<?= isset($_GET["worldchat"]) ? " active" : '' ?>"
                 data-on-click="navigate" data-url="messages.php?worldchat">
                <?= get_page_icon("worldchat") ?>
                <span>Welt-Chat</span>
                <span class="msg-badge js-badge-world" style="<?= $unread_world > 0 ? '' : 'display: none;' ?>">
                    <?= $messages->show_messages_indicator($unread_world) ?>
                </span>
            </div>
            <div class="box<?= $current_page === 'guild.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="guild.php?tab=chat">
                <?= get_page_icon("guild.php") ?>
                <span style="flex: 1;">Gilde</span>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <?= $sidebar_data["guild_status"] ?>
                    <span class="msg-badge js-badge-guild"
                          style="margin-left: 0; <?= $unread_guild > 0 ? '' : 'display: none;' ?>">
                        <?= $messages->show_messages_indicator($unread_guild) ?>
                    </span>
                </div>
            </div>
            <div class="box<?= $current_page === 'ranking.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="ranking.php">
                <?= get_page_icon("ranking.php") ?> Rangliste
            </div>
            <div class="box<?= $current_page === 'map.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="map.php">
                <?= get_page_icon("map.php") ?> Karte
            </div>
            <div class="box<?= $current_page === 'techtree.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="techtree.php">
                <?= get_page_icon("techtree.php") ?> Techtree
            </div>
            <div class="box<?= $current_page === 'warsim.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="warsim.php">
                <?= get_page_icon("warsim.php") ?> War Simulator
            </div>
            <div class="box<?= $arena_class ?>" <?= $arena_attr ?>>
                <?= get_page_icon("arena.php") ?>
                <span style="flex: 1;">Arena</span>
                <span class="msg-badge js-badge-arena" style="<?= $unread_arena > 0 ? '' : 'display: none;' ?>">
                    <?= $messages->show_messages_indicator($unread_arena) ?>
                </span>
                <span class="msg-badge golden-badge js-badge-arena-coins"
                      title="Arena-Münzen verfügbar!"
                      style="<?= ($unread_arena <= 0 && $arena_has_coins) ? '' : 'display: none;' ?> font-size: 16px;">!</span>
            </div>
            <div class="box<?= $current_page === 'achievements.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="achievements.php">
                <?= get_page_icon("achievements.php") ?>
                <span>Errungenschaften</span>
                <?php if ($unread_achievements > 0): ?>
                    <span class="msg-badge"><?= $messages->show_messages_indicator($unread_achievements) ?></span>
                <?php endif; ?>
            </div>
            <div class="box<?= $current_page === 'halloffame.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="halloffame.php">
                <?= get_page_icon("halloffame.php") ?> Ruhmeshalle
            </div>
            <?php
            if ($sidebar_data["has_world_event"]) {
                echo "<div class='box' data-on-click='navigate' data-url='events.php' style='background-color: rgba(73,72,68,0.95);'>
                            " . get_page_icon("events.php") . " Event
                          </div>";
            }
            ?>
        </div>
    </div>
    <div class="box-container left-right-container">
        <div class="box-header">Allgemeines</div>
        <div class="box-content">
            <?php
            if ($user->is_admin()) {
                echo '<div class="box' . ($current_page === "adminpanel.php" ? " active" : '') . '" 
                data-on-click="navigate" data-url="adminpanel.php">
                ' . get_page_icon("adminpanel.php") . ' Admin-Bereich
              </div>';
            }
            ?>
            <div class="box<?= $current_page === 'news.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="news.php">
                <?= get_page_icon("news.php") ?>
                <span>Neuigkeiten</span>
                <?php if ($unread_news > 0): ?>
                    <span class="msg-badge"><?= $unread_news ?></span>
                <?php endif; ?>
            </div>
            <div class="box" data-on-click="navigate" data-url="forum.php">
                <?= get_page_icon("forum.php") ?> Forum
            </div>
            <div class="box<?= $current_page === 'settings.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="settings.php">
                <?= get_page_icon("settings.php") ?> Einstellungen
            </div>
            <div class="box<?= $current_page === 'rules.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="rules.php">
                <?= get_page_icon("rules.php") ?> Regeln
            </div>
            <div class="box<?= $current_page === 'faq.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="faq.php">
                <?= get_page_icon("faq.php") ?> FAQ
            </div>
            <div class="box<?= $current_page === 'donations.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="donations.php">
                <?= get_page_icon("donations.php") ?> Spenden
            </div>
            <?php if (SUGGESTIONS_ENABLED): ?>
                <div class="box<?= $current_page === 'suggestions.php' ? ' active' : '' ?>" data-on-click="navigate"
                     data-url="suggestions.php">
                    <?= get_page_icon("suggestions.php") ?> Vorschläge
                    <span class="msg-badge js-badge-suggestions"
                          style="<?= $unread_suggestions > 0 ? '' : 'display: none;' ?>">
                    <?= $messages->show_messages_indicator($unread_suggestions) ?>
                </span>
                </div>
            <?php endif ?>
        </div>
    </div>
    <div class="box-container left-right-container" style="margin-bottom: 0;">
        <div class="box-header">Sonstiges</div>
        <div class="box-content">
            <div class="box<?= $current_page === 'stats.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="stats.php">
                <?= get_page_icon("stats.php") ?> Statistiken
            </div>
            <div class="box<?= $current_page === 'disclaimer.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="disclaimer.php">
                <?= get_page_icon("disclaimer.php") ?> Credits
            </div>
            <div class="box<?= $current_page === 'imprint.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="imprint.php">
                <?= get_page_icon("imprint.php") ?> Impressum
            </div>
            <div class="box<?= $current_page === 'privacy.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="privacy.php">
                <?= get_page_icon("privacy.php") ?> Datenschutz
            </div>
        </div>
    </div>
<?php include_once("copyright.php"); ?>