<?php
require_once("../includes/core.php");
$user->check_user_login();
?>
<div id="modal-title-data" data-title="Arena – Anleitung & Belohnungen" data-width="650px" style="display: none;"></div>
<div style="padding: 10px; max-width: 600px; margin: 0 auto; text-align: left; line-height: 1.5;">
    <h3 class="title-border" style="margin-top: 0;">Die Arena von Magic Empires</h3>
    <p>
        In der Arena treten die besten Herrscher in taktischen Duellen gegeneinander an.
        Hier zählt einzig deine militärische Zusammenstellung und Taktik – <b>deine echten Truppen im Dorf werden dabei
            nicht angetastet</b> (alle Verluste sind rein fiktiv).
    </p>
    <div class="tech-info-box">
        <b style="color: var(--link-color);">1. Deine Armee:</b><br>
        Jeder Herrscher verteilt exakt <b><?= fnum(ARENA_ARMY_UNIT_LIMIT) ?> Truppen</b> und bis zu
        <b><?= ARENA_MAX_TECH_POINTS ?> Schmiede-Punkte</b>. Deine Aufstellung dient gleichzeitig als passive
        Verteidigung gegen Angreifer.
    </div>
    <div class="tech-info-box">
        <b style="color: var(--link-color);">2. Dein Champion & Spezialisierungen:</b><br>
        Ein mächtiger Held führt deine Armee an. Durch Kämpfe sammelt er Erfahrung und steigert seine
        Kampfkraft.<br>
        Zusätzlich wählst du eine Spezialisierung (Anti-Infanterie, Anti-Kavallerie, Anti-Fernkampf), die mit jedem
        Level einen steigenden Konter-Schaden gewährt.<br><br>
        <i style="opacity: 0.8;">Hinweis: Zu Beginn jeder neuen Saison starten alle Helden und Fähigkeiten wieder auf
            Stufe 1.</i>
    </div>
    <div class="tech-info-box">
        <b style="color: var(--link-color);">3. Arena-Münzen & Duelle:</b><br>
        Jeder Angriff kostet <b>1 Arena-Münze</b>. Münzen regenerieren sich automatisch alle
        <b><?= ARENA_COIN_REGEN_HOURS ?> Stunden</b> (bis maximal <?= ARENA_MAX_COINS ?>). Ein Sieg bringt dir wertvolle
        <b>ELO-Punkte</b>, eine Niederlage zieht Punkte ab.
    </div>
    <div class="tech-info-box">
        <b style="color: var(--link-color);">4. Saison-Rhythmus:</b><br>
        Eine Saison läuft immer über einen <b>Kalendermonat</b>:
        <ul style="margin: 5px 0 0 0; padding-left: 20px;">
            <li><b>Aktiv:</b> Vom 1. des Monats bis 2 Tage vor Monatsende.</li>
            <li><b>Auszahlung:</b> Genau 2 Tage vor Monatsende werden alle Belohnungen ausgeschüttet.</li>
            <li><b>Saisonpause:</b> Die letzten 2 Tage des Monats ruhen die Waffen.</li>
            <li><b>Neustart:</b> Am 1. Tag des neuen Monats startet die nächste Saison frisch!</li>
        </ul>
    </div>
    <div class="tech-info-box">
        <b style="color: var(--link-color);">Hinweis zum „Aktualisieren“-Button:</b><br>
        Der Button <b>„🔄 Aktualisieren“</b> oben rechts lädt lediglich die Seite neu. Er bringt deine ELO-Punkte,
        regenerierte Arena-Münzen und den Kampf-Log auf den aktuellen Stand (z.&nbsp;B. falls du in der Zwischenzeit
        angegriffen wurdest). Deine aktuellen Gegner bleiben dabei unverändert.
    </div>
    <?php
    $user_max_tc = Arena::get_player_max_tc($user->get_user_id());
    ?>
    <div class="title-border" style="margin-top: 20px;">Saison-Prämien</div>
    <table class="table" style="width: 100%; font-size: 13px;">
        <tr>
            <td class="td-gradient td-center"><b>Platzierung</b></td>
            <td class="td-gradient td-center"><b>Münzen</b></td>
            <td class="td-gradient td-center"><b>Rohstoffe (je Sorte)</b></td>
        </tr>
        <?php foreach (ARENA_SEASON_REWARDS as $max_rank => $rew):
            $rank_label = match ($max_rank) {
                1 => "🥇 1. Platz",
                2 => "🥈 2. Platz",
                3 => "🥉 3. Platz",
                10 => "Top 10",
                25 => "Top 25",
                50 => "Top 50",
                default => "Teilnahme"
            };
            $scaled_res = (int)round($rew["res"] * ($user_max_tc / MAX_BUILDING_LEVEL));
            ?>
            <tr>
                <td><b><?= $rank_label ?></b></td>
                <td class="td-center"><?= get_resource_icon(ResourceTypes::RESOURCE_TYPE_COINS) ?>
                    <b>+<?= $rew["coins"] ?></b></td>
                <td class="td-center"><?= fnum($scaled_res) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p style="font-size: 12px; opacity: 0.7; text-align: center; margin: 10px 0 0;">
        <i>Rohstoffmengen skalieren an deinem höchsten Dorfzentrum (aktuell: <b>Stufe <?= $user_max_tc ?></b>) und
            werden direkt in dein gewähltes Beute-Dorf geliefert.</i>
    </p>
</div>