<div class="stats-container">
    <div class="stats-bar">
        <?php
        global $kingdom, $user;

        echo "<img src='images/icons/icon_score.png' class='ressource-icons' alt='Punkte'> " . ($user->getUserScore() ==
            0 ? "0" : $user->getUserScore()) . "
        <img src='images/icons/icon_meat.png' class='ressource-icons' alt='Nahrung'> {$kingdom->getKingdomFood()}
        ({$kingdom->getKingdomFoodPerHour()} / h)
        <img src='images/icons/icon_wood.png' class='ressource-icons' alt='Holz'> {$kingdom->getKingdomWood()}
        ({$kingdom->getKingdomWoodPerHour()} / h)
        <img src='images/icons/icon_stone.png' class='ressource-icons' alt='Stein'> {$kingdom->getKingdomStone()}
        ({$kingdom->getKingdomStonePerHour()} / h)
        <img src='images/icons/icon_gold.png' class='ressource-icons' alt='Gold'> {$kingdom->getKingdomGold()}
        ({$kingdom->getKingdomGoldPerHour()} / h)
        <img src='images/icons/icon_villager.png' class='ressource-icons' alt='Dorfbewohner'>
        {$kingdom->getKingdomVillager()} ({$kingdom->getKingdomVillagerPerHour()} / h)";
        ?>
    </div>
</div>
<footer>© Magic Empires - 2023</footer>