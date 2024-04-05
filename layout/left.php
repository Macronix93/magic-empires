<div class="box-container">
    <div class="box-header">
        <?php
        global $user, $db_instance;

        $currentPage = basename($_SERVER["PHP_SELF"]);

        // Count unread messages for the user
        $stmt = $db_instance->prepare("SELECT COUNT(*) AS unread_count FROM messages WHERE receiver = ? AND hasread = 0");
        $stmt->bind_param("s", $_SESSION["username"]);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $num_unread_messages = $row["unread_count"];
        $stmt->close();

        echo "<div style='width: 80%; display: flex; justify-content: space-between; align-items: center;' id='usernameContainer'>
        <div style='overflow: hidden; white-space: nowrap;' id='username'>";
        echo $user->getUserName();
        echo "</div>
        <a href='login.php?logout'><img src='images/icons/icon_logout.png' class='ressource-icons' alt=''/></a>
      </div>";

        // Calculate and update the styling of the username dynamically
        echo "<script>adjustUsernameDisplay()</script>";
        ?>
    </div>
    <div class="box-content">
        <div class="box<?= $currentPage === 'index.php' ? ' active' : '' ?>" onclick="navigateTo('index.php', this)">
            <img src="images/icons/icon_overview.png" class="menu-icons" alt="Übersicht"/> Übersicht
        </div>
        <div class="box<?= $currentPage === 'messages.php' ? ' active' : '' ?>"
             onclick="navigateTo('messages.php', this)">
            <img src="images/icons/icon_messages.png" class="menu-icons" alt="Nachrichten"/>
            Nachrichten&nbsp;<?php showNewMessagesIndicator($num_unread_messages); ?>
        </div>
        <div class="box<?= $currentPage === 'guild.php' ? ' active' : '' ?>" onclick="navigateTo('guild.php', this)">
            <img src="images/icons/icon_guild.png" class="menu-icons" alt="Gilde"/> Gilde
        </div>
        <div class="box<?= $currentPage === 'ranking.php' ? ' active' : '' ?>"
             onclick="navigateTo('ranking.php', this)">
            <img src="images/icons/icon_ranking.png" class="menu-icons" alt="Rangliste"/> Rangliste
        </div>
        <div class="box<?= $currentPage === 'map.php' ? ' active' : '' ?>" onclick="navigateTo('map.php', this)">
            <img src="images/icons/icon_map.png" class="menu-icons" alt="Karte"/> Karte
        </div>
        <div class="box<?= $currentPage === 'buildinglist.php' ? ' active' : '' ?>"
             onclick="navigateTo('buildinglist.php', this)">
            <img src="images/icons/icon_buildings.png" class="menu-icons" alt="Gebäude"/> Gebäude
        </div>
        <div class="box<?= $currentPage === 'warsim.php' ? ' active' : '' ?>" onclick="navigateTo('warsim.php', this)">
            <img src="images/icons/icon_warsim.png" class="menu-icons" alt=""/> War Simulator
        </div>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Allgemeines</div>
    <div class="box-content">
        <div class="box<?= $currentPage === 'settings.php' ? ' active' : '' ?>"
             onclick="navigateTo('settings.php', this)">
            <img src="images/icons/icon_settings.png" class="menu-icons" alt="Einstellungen"/> Einstellungen
        </div>
        <div class="box<?= $currentPage === 'donations.php' ? ' active' : '' ?>"
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
        <div class="box<?= $currentPage === 'statistics.php' ? ' active' : '' ?>"
             onclick="navigateTo('index.php', this)">
            <img src="images/icons/icon_statistics.png" class="menu-icons" alt="Statistiken"/> Statistiken
        </div>
        <div class="box<?= $currentPage === 'disclaimer.php' ? ' active' : '' ?>"
             onclick="navigateTo('disclaimer.php', this)">
            <img src="images/icons/icon_disclaimer.png" class="menu-icons" alt="Credits"/> Credits
        </div>
    </div>
</div>