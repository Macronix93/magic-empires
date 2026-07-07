<?php
$current_page = basename($_SERVER["PHP_SELF"]);
$messages = new Messages($db_instance, $user);
$unread = $user->get_unread_messages();
$unread_news = get_unread_news_count($user, $db_instance);
?>
    <div class="box-container">
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
                <img src="images/icons/icon_overview.png" class="menu-icons" alt="Übersicht"/> Übersicht
            </div>
            <div class="box<?= $current_page === 'messages.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="messages.php">
                <img src="images/icons/icon_messages.png" class="menu-icons" alt="Nachrichten"/>
                <span>Nachrichten</span>
                <?php if ($unread > 0): ?>
                    <span class="msg-badge">
                <?= $messages->show_messages_indicator($unread) ?>
            </span>
                <?php endif; ?>
            </div>
            <div class="box<?= $current_page === 'guild.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="guild.php">
                <img src="images/icons/icon_guild.png" class="menu-icons" alt="Gilde"/> Gilde
            </div>
            <div class="box<?= $current_page === 'ranking.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="ranking.php">
                <img src="images/icons/icon_ranking.png" class="menu-icons" alt="Rangliste"/> Rangliste
            </div>
            <div class="box<?= $current_page === 'map.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="map.php">
                <img src="images/icons/icon_map.png" class="menu-icons" alt="Karte"/> Karte
            </div>
            <div class="box<?= $current_page === 'techtree.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="techtree.php">
                <img src="images/icons/icon_buildings.png" class="menu-icons" alt="Gebäude"/> Techtree
            </div>
            <div class="box<?= $current_page === 'warsim.php' ? ' active' : '' ?>" data-on-click="navigate"
                 data-url="warsim.php">
                <img src="images/icons/icon_warsim.png" class="menu-icons" alt=""/> War Simulator
            </div>
        </div>
    </div>
    <div class="box-container">
        <div class="box-header">Allgemeines</div>
        <div class="box-content">
            <?php
            if ($user->get_user_admin_level() > 0) {
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
            <div class="box<?= $current_page === 'userdetails.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="userdetails.php">
                <img src="images/icons/icon_userdetails.png" class="menu-icons" alt="Account-Info"/> Account-Info
            </div>
            <div class="box<?= $current_page === 'settings.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="settings.php">
                <img src="images/icons/icon_settings.png" class="menu-icons" alt="Einstellungen"/> Einstellungen
            </div>
            <div class="box<?= $current_page === 'donations.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="donations.php">
                <img src="images/icons/icon_donation.png" class="menu-icons" alt="Spenden"/> Spenden
            </div>
        </div>
    </div>
    <div class="box-container">
        <div class="box-header">Sonstiges</div>
        <div class="box-content">
            <div class="box" data-on-click="navigate" data-url="https://board.magic-empires.de">
                <img src="images/icons/icon_forum.png" class="menu-icons" alt="Forum"/> Forum
            </div>
            <div class="box<?= $current_page === 'statistics.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="overview.php">
                <img src="images/icons/icon_statistics.png" class="menu-icons" alt="Statistiken"/> Statistiken
            </div>
            <div class="box<?= $current_page === 'disclaimer.php' ? ' active' : '' ?>"
                 data-on-click="navigate" data-url="disclaimer.php">
                <img src="images/icons/icon_disclaimer.png" class="menu-icons" alt="Credits"/> Credits
            </div>
        </div>
    </div>
<?php include_once("copyright.php"); ?>