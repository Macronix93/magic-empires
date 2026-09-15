<?php
$current_page = basename($_SERVER["PHP_SELF"]);
$messages = new Messages($user);

$unreads = $user->get_unread_counts();

$unread_total = $unreads["total"];
$unread_news = $unreads["news"];
$unread_world = $unreads["world"];
$unread_guild = $unreads["guild"];

$inbox_only_unread = $unreads["pms"] + $unreads["server"] + $unreads["support"];
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
                <img src="images/icons/icon_buildings.png" class="menu-icons" alt="Übersicht"/> Übersicht
            </div>
            <div class="box<?= ($current_page === 'messages.php' && !isset($_GET["worldchat"])) ? " active" : '' ?>"
                 data-on-click="navigate" data-url="messages.php">
                <img src="images/icons/icon_messages.png" class="menu-icons" alt="Nachrichten"/>
                <span>Nachrichten</span>
                <?php
                if ($inbox_only_unread > 0): ?>
                    <span class="msg-badge" id="badge-priv-messages">
                <?= $messages->show_messages_indicator($inbox_only_unread) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="box<?= isset($_GET["worldchat"]) ? " active" : '' ?>"
                 data-on-click="navigate" data-url="messages.php?worldchat">
                <img src="images/icons/icon_worldchat.png" class="menu-icons" alt="Welt-Chat"/>
                <span>Welt-Chat</span>
                <?php if ($unread_world > 0): ?>
                    <span class="msg-badge" id="badge-world-chat">
                        <?= $messages->show_messages_indicator($unread_world) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="box<?= $current_page === 'guild.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="guild.php?tab=chat">
                <img src="images/icons/icon_guild.png" class="menu-icons" alt="Gilde"/>
                <span style="flex: 1;">Gilde</span>
                <?php
                $gid = $user->get_user_guild_id();
                $guild_status_icon = "";

                if ($gid > 0) {
                    $res_research = $db_instance->execute_query(
                            "SELECT 1 FROM events WHERE guild_id = ? AND actionid = ? LIMIT 1",
                            [$gid, ActionTypes::ACTION_RESEARCH_TECH]
                    );

                    if ($res_research->num_rows > 0) {
                        $guild_status_icon = '<img src="images/icons/icon_time.png" class="ressource-icons" title="Gildenforschung läuft..." alt="Forschung">';
                    } else {
                        $res_project = $db_instance->execute_query(
                                "SELECT gp.tech_id, gtl.name 
                                 FROM guild_projects gp 
                                 JOIN guild_tech_list gtl ON gp.tech_id = gtl.id 
                                 WHERE gp.guild_id = ? LIMIT 1",
                                [$gid]
                        );

                        if ($p_row = $res_project->fetch_assoc()) {
                            $guild_status_icon = '<img src="images/icons/icon_hammer.png" class="ressource-icons" title="Projekt aktiv: ' . e($p_row["name"]) . ' (Ressourcen werden gesammelt)" alt="Projekt">';
                        }
                    }
                }

                if (!empty($guild_status_icon) || $unread_guild > 0): ?>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <?= $guild_status_icon ?>
                        <?php if ($unread_guild > 0): ?>
                            <span class="msg-badge" id="badge-guild-chat" style="margin-left: 0;">
                                <?= $messages->show_messages_indicator($unread_guild) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="box<?= $current_page === 'ranking.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="ranking.php">
                <img src="images/icons/icon_ranking.png" class="menu-icons" alt="Rangliste"/> Rangliste
            </div>
            <div class="box<?= $current_page === 'map.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="map.php">
                <img src="images/icons/icon_tech6.png" class="menu-icons" alt="Karte"/> Karte
            </div>
            <div class="box<?= $current_page === 'techtree.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="techtree.php">
                <img src="images/icons/icon_techtree.png" class="menu-icons" alt="Gebäude"/> Techtree
            </div>
            <div class="box<?= $current_page === 'warsim.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="warsim.php">
                <img src="images/icons/icon_warsim.png" class="menu-icons" alt=""/> War Simulator
            </div>
            <div class="box<?= $current_page === 'halloffame.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="halloffame.php">
                <img src="images/icons/icon_score.png" class="menu-icons" alt="Hall of Fame"/> Ruhmeshalle
            </div>
            <?php
            $we = new WorldEvent();
            $active = $we->get_active_event();

            if ($active) {
                echo "<div class='box' data-on-click='navigate' data-url='events.php' style='background-color: rgba(73,72,68,0.95);'>
                        <img src='images/icons/icon_lich.png' class='menu-icons'  alt='Event'/> Event 
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
                <img src="images/icons/icon_adminpanel.png" class="menu-icons" alt="Admin-Bereich"/> Admin-Bereich
              </div>';
            }
            ?>
            <div class="box<?= $current_page === 'news.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="news.php">
                <img src="images/icons/icon_news.png" class="menu-icons" alt="Neuigkeiten"/>
                <span>Neuigkeiten</span>
                <?php if ($unread_news > 0): ?>
                    <span class="msg-badge"><?= $unread_news ?></span>
                <?php endif; ?>
            </div>
            <div class="box<?= $current_page === 'settings.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="settings.php">
                <img src="images/icons/icon_settings.png" class="menu-icons" alt="Einstellungen"/> Einstellungen
            </div>
            <div class="box<?= $current_page === 'rules.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="rules.php">
                <img src="images/icons/icon_rules.png" class="menu-icons" alt="Regeln"/> Regeln
            </div>
            <div class="box<?= $current_page === 'faq.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="faq.php">
                <img src="images/icons/icon_faq.png" class="menu-icons" alt="FAQ"/> FAQ
            </div>
            <div class="box<?= $current_page === 'donations.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="donations.php">
                <img src="images/icons/icon_guildtech1.png" class="menu-icons" alt="Spenden"/> Spenden
            </div>
        </div>
    </div>
    <div class="box-container left-right-container" style="margin-bottom: 0;">
        <div class="box-header">Sonstiges</div>
        <div class="box-content">
            <div class="box box-disabled" data-on-click="navigate" data-url="https://board.magic-empires.de">
                <img src="images/icons/icon_forum.png" class="menu-icons" alt="Forum"/> Forum
            </div>
            <div class="box<?= $current_page === 'stats.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="stats.php">
                <img src="images/icons/icon_statistics.png" class="menu-icons" alt="Statistiken"/> Statistiken
            </div>
            <div class="box<?= $current_page === 'disclaimer.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="disclaimer.php">
                <img src="images/icons/icon_disclaimer.png" class="menu-icons" alt="Credits"/> Credits
            </div>
            <div class="box<?= $current_page === 'imprint.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="imprint.php">
                <img src="images/icons/icon_imprint.png" class="menu-icons" alt="Impressum"/> Impressum
            </div>
            <div class="box<?= $current_page === 'privacy.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="privacy.php">
                <img src="images/icons/icon_privacy.png" class="menu-icons" alt="Datenschutz"/> Datenschutz
            </div>
        </div>
    </div>
<?php include_once("copyright.php"); ?>