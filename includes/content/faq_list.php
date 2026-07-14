<div class="box-container" style="margin-bottom: 20px;">
    <div class="box-header">Allgemeine Fragen</div>
    <div class="box-content box-content-bg">
        <table class="table" style="width: 100%; border: none;">
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Was ist Magic Empires?</b></td>
                <td>Magic Empires ist ein klassisches Aufbau-Strategiespiel im Browser. Du schlüpfst in die Rolle eines
                    Herrschers, errichtest Gebäude, erforschst Technologien und misst dich mit anderen Spielern auf
                    einer riesigen Weltkarte.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Kostet das Spiel etwas?</b></td>
                <td>Nein. Magic Empires ist grundlegend kostenlos spielbar. Das Projekt wird durch
                    freiwillige Spenden getragen.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Werden die Spielstände zurückgesetzt?</b></td>
                <td>Das Spiel ist auf Langzeit ausgelegt. Sollte es dennoch zu einem Reset kommen (z.B. nach einer
                    Beta-Phase), wird dies rechtzeitig in den News bekannt gegeben.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie versende ich eine Nachricht?</b></td>
                <td>Du kannst Spielern direkt über die Rangliste oder das Info-Overlay auf der Karte eine Nachricht
                    schicken. Alternativ wählst du im Menü "Nachrichten" und suchst den Namen aus der Spielerliste aus.
                </td>
            </tr>
        </table>
    </div>
</div>
<div class="box-container" style="margin-bottom: 20px;">
    <div class="box-header">Wirtschaft & Gebäude</div>
    <div class="box-content box-content-bg">
        <table class="table" style="width: 100%; border: none;">
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Wie steigere ich meine Rohstoff-Erträge?</b></td>
                <td>Deine Erträge hängen von der Stufe deiner Produktionsgebäude (Mühle, Sägewerk, Steinbruch, Goldmine)
                    ab. Zudem kannst du in der Universität Forschungen betreiben, die deine Produktion dauerhaft
                    erhöhen.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie betreibe ich Handel?</b></td>
                <td>Du benötigst einen <b>Marktplatz</b>. Dort kannst du eigene Angebote einstellen oder die anderer
                    Spieler annehmen. Deine Karawanen transportieren die Waren dann automatisch zum Ziel.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wozu dient das Dorfzentrum?</b></td>
                <td>Das Dorfzentrum ist das Herz deines Reiches. Die Stufe deines Dorfzentrums limitiert die maximale
                    Stufe aller anderen Gebäude. Möchtest du also ein Gebäude auf Stufe 5 ausbauen, muss dein
                    Dorfzentrum ebenfalls mindestens Stufe 5 sein. Außerdem schaltet es unter anderem weitere Gebäude
                    frei.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wieviele Stufen können ausgebaut werden?</b></td>
                <td>Gebäude können derzeit bis <b>Stufe <?= MAX_BUILDING_LEVEL ?></b> ausgebaut werden. Forschungen in
                    der Universität oder der
                    Schmiede haben unterschiedliche Maximalstufen, welche du im Techtree einsehen kannst.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Warum habe ich keine Dorfbewohner mehr?</b></td>
                <td>Jede Einheit benötigt Arbeitskraft. Baue dein <b>Anwesen</b> aus, um das
                    Bevölkerungslimit zu erhöhen und die Geburtenrate zu steigern. Ansonsten warte bis der nächste
                    Ressourcen-Zuwachs (jede Stunde) kommt.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Mein Lager ist voll, was nun?</b></td>
                <td>Überschüssige Rohstoffe gehen verloren. Baue dein <b>Lager</b> aus, um die Kapazität zu erhöhen,
                    oder investiere Rohstoffe in Truppen und Forschung. Das Lager schützt zudem einen Teil deiner
                    Vorräte vor Plünderungen.
                </td>
            </tr>
        </table>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Militär & Expansion</div>
    <div class="box-content box-content-bg">
        <table class="table" style="width: 100%; border: none;">
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Was ist der Noob-Schutz?</b></td>
                <td>Um faire Bedingungen zu schaffen, können Spieler mit sehr hohem Punktestand keine Anfänger
                    angreifen. Das Gleiche gilt auch andersrum: Spieler mit sehr niedrigem Punktestand können Spieler
                    mit
                    höherem Punktestand nicht angreifen. Die Punkte-Differenz darf einen gewissen Faktor nicht
                    überschreiten (derzeit <b><?= NOOB_PROTECTION_MULT * 100 ?>%</b>).
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie gründe ich ein neues Königreich?</b></td>
                <td>Du benötigst einen <b>Siedlungskarren</b> (aus der Kaserne) und musst diesen zu einem leeren Feld
                    auf der Karte schicken. Beachte, dass die Gründung fehlschlagen kann – je mehr Siedlungskarren du
                    schickst, desto höher ist die Erfolgschance.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie erobere ich ein Königreich?</b></td>
                <td>Um ein anderes Königreich zu übernehmen, musst du einen <b>Eroberer</b> mitschicken. Du musst
                    den Kampf gewinnen und die Verteidigung des Gegners zerschlagen. Bei Erfolg opfert sich der Eroberer
                    und das Dorf gehört dir. Je mehr Eroberer du mitschickst, desto höher die Erfolgschance.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Was bewirkt die Mauer?</b></td>
                <td>Die Mauer gibt deinen stationierten Truppen einen massiven Verteidigungsbonus. Eine beschädigte
                    Mauer kann im Mauer-Menü mit Stein repariert werden. Sinkt die Haltbarkeit auf 0, entfällt der
                    Bonus.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie funktionieren Kämpfe?</b></td>
                <td>Kämpfe basieren auf einem Schere-Stein-Papier-Prinzip zwischen Infanterie, Kavallerie und
                    Bogenschützen. Nutze den <b>War Simulator</b> im Menü, um verschiedene Szenarien durchzurechnen,
                    bevor du deine Truppen in die Schlacht schickst!
                </td>
            </tr>
        </table>
    </div>
</div>