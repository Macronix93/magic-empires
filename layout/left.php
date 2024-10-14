<?php
$current_page = basename($_SERVER["PHP_SELF"]);
?>
<div class="box-container">
    <div class="box-header">
        <?php
        echo "<div style='width: 80%; display: flex; justify-content: space-between; align-items: center;' id='usernameContainer'>
        <div style='overflow: hidden; white-space: nowrap;' id='username'>";
        echo $user->get_user_name();
        echo "</div><a href='login.php?logout'><img src='images/icons/icon_logout.png' class='ressource-icons' alt='Logout' title='Logout'/></a></div>";
        ?>
    </div>
    <div class="box-content">
        <div class="box<?= $current_page === 'index.php' ? ' active' : '' ?>" onclick="navigateTo('index.php', this)">
            <img src="images/icons/icon_overview.png" class="menu-icons" alt="Übersicht"/> Übersicht
        </div>
        <div class="box<?= $current_page === 'messages.php' ? ' active' : '' ?>"
             onclick="navigateTo('messages.php', this)">
            <img src="images/icons/icon_messages.png" class="menu-icons" alt="Nachrichten"/>
            Nachrichten&nbsp;<?php echo show_messages_indicator($user->get_unread_messages()); ?>
        </div>
        <div class="box<?= $current_page === 'guild.php' ? ' active' : '' ?>" onclick="navigateTo('guild.php', this)">
            <img src="images/icons/icon_guild.png" class="menu-icons" alt="Gilde"/> Gilde
        </div>
        <div class="box<?= $current_page === 'ranking.php' ? ' active' : '' ?>"
             onclick="navigateTo('ranking.php', this)">
            <img src="images/icons/icon_ranking.png" class="menu-icons" alt="Rangliste"/> Rangliste
        </div>
        <div class="box<?= $current_page === 'map.php' ? ' active' : '' ?>" onclick="navigateTo('map.php', this)">
            <img src="images/icons/icon_map.png" class="menu-icons" alt="Karte"/> Karte
        </div>
        <div class="box<?= $current_page === 'buildinglist.php' ? ' active' : '' ?>"
             onclick="navigateTo('buildinglist.php', this)">
            <img src="images/icons/icon_buildings.png" class="menu-icons" alt="Gebäude"/> Gebäude
        </div>
        <div class="box<?= $current_page === 'warsim.php' ? ' active' : '' ?>" onclick="navigateTo('warsim.php', this)">
            <img src="images/icons/icon_warsim.png" class="menu-icons" alt=""/> War Simulator
        </div>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Allgemeines</div>
    <div class="box-content">
        <?php
        if ($user->get_user_admin_level() > 0) {
            echo '<div class="box' . ($current_page === "adminpanel.php" ? ' active' : '') . '" 
                onclick="navigateTo(\'adminpanel.php\', this)">
                <img src="images/icons/icon_adminpanel.png" class="menu-icons" alt="Admin-Bereich"/> Admin-Bereich
              </div>';
        }
        ?>
        <div class="box<?= $current_page === 'settings.php' ? ' active' : '' ?>"
             onclick="navigateTo('settings.php', this)">
            <img src="images/icons/icon_settings.png" class="menu-icons" alt="Einstellungen"/> Einstellungen
        </div>
        <div class="box<?= $current_page === 'donations.php' ? ' active' : '' ?>"
             onclick="navigateTo('donations.php', this)">
            <img src="images/icons/icon_donation.png" class="menu-icons" alt="Spenden"/> Spenden
        </div>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Sonstiges</div>
    <div class="box-content">
        <div class="box" onclick="navigateTo('https://board.magic-empires.de', this)">
            <img src="images/icons/icon_forum.png" class="menu-icons" alt="Forum"/> Forum
        </div>
        <div class="box<?= $current_page === 'statistics.php' ? ' active' : '' ?>"
             onclick="navigateTo('index.php', this)">
            <img src="images/icons/icon_statistics.png" class="menu-icons" alt="Statistiken"/> Statistiken
        </div>
        <div class="box<?= $current_page === 'disclaimer.php' ? ' active' : '' ?>"
             onclick="navigateTo('disclaimer.php', this)">
            <img src="images/icons/icon_disclaimer.png" class="menu-icons" alt="Credits"/> Credits
        </div>
    </div>
</div>