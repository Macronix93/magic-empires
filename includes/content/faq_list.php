<div class="box-container" style="margin-bottom: 20px;">
    <div class="box-header">Allgemeine Fragen</div>
    <div class="box-content box-content-bg">
        <table class="table faq-table" style="width: 100%; border: none;">
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
        <table class="table faq-table" style="width: 100%; border: none;">
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
                    Dorfzentrum ebenfalls mindestens Stufe 5 sein (ausgenommen von dieser Regel ist das Lager - dieses
                    kann immer eine Stufe höher als das aktuelle Dorfzentrum ausgebaut werden). Außerdem schaltet es
                    unter anderem weitere Gebäude
                    frei (siehe <a href="techtree.php">Techtree</a>).
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
            <tr>
                <td class="td-gradient"><b>Gibt es noch andere Möglichkeiten, um an Ressourcen zu kommen?</b></td>
                <td><b>Ja!</b> Auf der Karte sind bestimmte Kacheln mit einem Diamanten-Symbol versehen. Hier befinden
                    sich plünderbare Lager. Mit <b>Räubern</b> können diese Lager geplündert werden, wobei jedes Lager
                    unterschiedliche Ressourcen besitzt. Aber Achtung: Während eines Raubzugs kann es vorkommen, dass
                    deine
                    Räuber durch lauernde Diebe in den Hinterhalt geraten...
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Was fange ich mit Münzen an?</b></td>
                <td>Münzen sind dafür da, bei den Ressourcen-Gebäuden (Mühle, Sägewerk, Steinmine, Goldmine) die
                    Ressourcen-Erträge
                    pro Stunde zu erhöhen. Außerdem sind sie Bezahlwerkzeug im Marktplatz (bei Angebotsannahme). Münzen
                    bekommt man pro Stunde automatisch, können aber auch durch Monstercamps erhalten werden.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie erfahre ich die Erträge der Feldtypen auf der Karte?</b></td>
                <td>Du kannst auf der Weltkarte in der oberen Legende einfach mit der Maus über die jeweiligen Feldtypen
                    (<b>Hochland</b>, <b>Wald</b>, <b>Wüste</b>, <b>Küste</b> oder <b>Gebirge</b>) fahren oder sie
                    auf dem Smartphone antippen. Es öffnet sich ein Info-Fenster, das dir den genauen Marschzeit-Faktor
                    sowie die Erträge für Nahrung, Holz, Stein und Gold pro Stunde anzeigt.
                </td>
            </tr>
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Was sind Erzminen?</b></td>
                <td>Erzminen sind spezielle Ressourcen-Kacheln auf der Weltkarte, in denen du seltene Spezial-Erze
                    (Kohle, Eisen, Saphir, Diamant) für deine Gilden-Schatzkammer abbauen kannst.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie berechnet sich die Abbau-Geschwindigkeit?</b></td>
                <td>Die Geschwindigkeit basiert auf dem Gesamt-Angriffswert aller in der Mine stationierten
                    Truppen. Jede Einheit erzeugt Arbeitspunkte (zu sehen im Techtree). Je stärker und größer die
                    Armee, desto schneller ist die Mine leergeräumt!
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Welche Arbeitspunkte werden pro Minen-Stufe benötigt?</b></td>
                <td>
                    <?php
                    for ($i = 1; $i < count(MINE_WORK_BY_LEVEL) + 1; $i++) {
                        echo "• <b>Stufe " . ($i) . ":</b> " . fnum(MINE_WORK_BY_LEVEL[$i], true) . " Arbeitspunkte<br>";
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Was passiert, wenn eine Mine von einer anderen Gilde übernommen wird?</b>
                </td>
                <td>Wenn eine feindliche Gilde eine stärkere Armee in eine von dir besetzte Mine schickt, verlierst du
                    zwar <b>keine Truppen</b>, aber die feindliche Gilde übernimmt die Mine und führt den Abbau ab
                    diesem Moment für sich fort. Deine Truppen treten dann automatisch den Heimweg an.
                </td>
            </tr>
        </table>
    </div>
</div>
<div class="box-container">
    <div class="box-header">Militär & Expansion</div>
    <div class="box-content box-content-bg">
        <table class="table faq-table" style="width: 100%; border: none;">
            <tr>
                <td class="td-gradient" style="width: 40%; vertical-align: top;"><b>Wie funktioniert das Kampfsystem im
                        Detail?</b></td>
                <td>
                    Kämpfe finden in Echtzeit exakt in der Sekunde des Eintreffens am Zielort statt. Die Berechnung
                    erfolgt nach folgenden Regeln:
                    <br><br>
                    <b>1. Vorbereitung & Modifikatoren:</b>
                    <ul style="margin: 5px 0 10px 0; padding-left: 20px;">
                        <li>Jede Einheit besitzt Basis-Angriffs- und Verteidigungswerte.</li>
                        <li><b>Schmiede-Upgrades:</b> Verbessern die Basiswerte jeder Gattung dauerhaft.
                        </li>
                        <li><b>Schrein der Ahnen (Kriegsgott):</b> Erhöht den gesamten Angriffswert der Armee um einen
                            prozentualen Bonus.
                        </li>
                    </ul>
                    <b>2. Das Schere-Stein-Papier-Prinzip (<?= (RPS_BONUS * 100) ?>% Bonus):</b>
                    <ul style="margin: 5px 0 10px 0; padding-left: 20px;">
                        <li><b>Infanterie</b> schlägt <b>Kavallerie</b></li>
                        <li><b>Kavallerie</b> schlägt <b>Fernkampf</b></li>
                        <li><b>Fernkampf</b> schlägt <b>Infanterie</b></li>
                        <li><i>Wichtig:</i> Der Bonus wird dynamisch anhand des prozentualen Anteils der jeweiligen
                            Einheit in der gegnerischen Armee verrechnet. Eine gemischte Armee schützt vor Kontern!
                        </li>
                    </ul>
                    <b>3. Stadtmauer, Gegenwehr & Rammböcke:</b>
                    <ul style="margin: 5px 0 10px 0; padding-left: 20px;">
                        <li><b>Defensiv-Bonus:</b> Eine intakte Mauer spendiert zusätzliche Verteidigung.
                        </li>
                        <li><b>Schadensabsorption:</b> Die Mauer schluckt einen festen Betrag des ankommenden Schadens,
                            bevor die Verteidiger getroffen werden.
                        </li>
                        <li><b>Gegenwehr:</b> <?= (WALL_COUNTER_DAMAGE_FACTOR * 100) ?>% des Mauer-Verteidigungswertes
                            werden als Gegenschlag direkt auf die
                            Angreifer zurückgeworfen.
                        </li>
                        <li><b>Mauerschaden:</b> Normale Truppen können
                            maximal <?= (WALL_MAX_NORMAL_DAMAGE_PERCENT * 100) ?>% Mauerschaden pro Angriff anrichten.
                            Für echte Zerstörung werden <b>Rammböcke</b> benötigt.
                        </li>
                    </ul>
                    <b>4. Verlustberechnung:</b>
                    <ul style="margin: 5px 0 10px 0; padding-left: 20px;">
                        <li>Beide Seiten schlagen simultan zu. Die Verluste berechnen sich aus dem Verhältnis des
                            gegnerischen Angriffspools zum eigenen Verteidigungspool.
                        </li>
                        <li>Im <b>PvP</b> beträgt der Tödlichkeitsfaktor <?= LETHALITY_PVP ?> (Truppen halten mehr
                            Schaden aus als ihren reinen DEF-Wert).
                        </li>
                        <li>Im <b>PvE</b> beträgt der Faktor <?= LETHALITY_PVE ?>. Zusätzlich greift eine
                            Dämpfungskurve, damit bei großer Übermacht gegen Monster die eigenen Verluste minimal
                            bleiben.
                        </li>
                    </ul>
                    <b>5. Gilden-Verstärkung:</b>
                    <ul style="margin: 5px 0 10px 0; padding-left: 20px;">
                        <li>Unterstützungstruppen von Gildenmitgliedern kämpfen gleichberechtigt in der
                            Verteidigungslinie. Verluste werden prozentual gleichmäßig auf den Besitzer und alle
                            anwesenden Unterstützer aufgeteilt.
                        </li>
                    </ul>
                    <b>6. Nach dem Kampf (Beute & Eroberung):</b>
                    <ul style="margin: 5px 0 10px 0; padding-left: 20px;">
                        <li><b>Diebe:</b> Haben die Angreifer gewonnen und Diebe überleben, stehlen sie ungeschützte
                            Ressourcen.
                        </li>
                        <li><b>Eroberer:</b> Besiegt die Angriffsarmee alle Verteidiger restlos und führt einen <i>Eroberer</i>
                            mit, besteht eine Chance, das Königreich
                            einzunehmen. Bei Erfolg opfert sich ein Eroberer.
                        </li>
                        <li><b>Späher:</b> Überleben Späher im Gefecht, bringen sie unabhängig vom Kampfausgang
                            Spionageberichte über das gegnerische Dorf mit nach Hause.
                        </li>
                    </ul>
                </td>
            </tr>
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
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Kann ich bestehende Truppen aufwerten?</b></td>
                <td>Ja! In der Kaserne kannst du Einheiten in stärkere Ränge derselben Kategorie umwandeln (z.B. Miliz
                    zu Schwertkämpfer). Wähle dazu einfach die Ziel-Einheit im Dropdown-Menü bei der entsprechenden
                    Truppe aus.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Was bringt mir ein Truppen-Upgrade?</b></td>
                <td>Ein Upgrade kostet dich nur die <b>Differenz</b> der Ressourcenkosten. Es ist der effizienteste Weg,
                    deine Armee zu modernisieren, ohne dein Truppenlimit mit schwachen Einheiten zu belasten. Zudem
                    behältst du so deine militärische Schlagkraft bei minimalem Ressourcenaufwand.
                </td>
            </tr>
        </table>
    </div>
</div>
<div class="box-container" style="margin-bottom: 20px;">
    <div class="box-header">Gilden & Bündnisse</div>
    <div class="box-content box-content-bg">
        <table class="table faq-table" style="width: 100%; border: none;">
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Wie gründe ich eine Gilde?</b></td>
                <td>Du benötigst in deinem Haupt-Königreich eine <b>Botschaft</b>. Anschließend kannst du im
                    Menü unter „Gilde“ einen Namen, ein Kürzel (Tag) und ein optionales Motto festlegen.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Welche Vorteile bietet eine Gilde?</b></td>
                <td>Als Gildenmitglied erhältst du Zugriff auf den internen <b>Gilden-Chat</b>, gemeinsame <b>Gildenforschungen</b>
                    (die globale Boni für alle Mitglieder freischalten), eine gemeinsame <b>Schatzkammer</b> sowie die
                    Möglichkeit, Verbündeten <b>militärische Unterstützung</b> ins Dorf zu stellen.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie funktionieren Gilden-Forschungen?</b></td>
                <td>Die Gildenführung kann ein Forschungsprojekt ausrufen. Alle Mitglieder können Rohstoffe spenden, um
                    den Bau voranzutreiben. Sobald das Ziel erreicht ist, startet die Forschung und die Boni (z. B. mehr
                    Lagerplatz, schnellere Truppenmärsche, mehr Event-Gold) gelten dauerhaft für die gesamte Gilde.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Was ist die Gilden-Schatzkammer?</b></td>
                <td>In der Schatzkammer werden seltene Spezial-Erze (Kohle, Eisen, Saphir und Diamant) gelagert, die
                    deine Truppen beim Schürfen in <b>Erzminen</b> erbeuten. Diese Erze werden benötigt, um höhere
                    Gilden-Technologien freizuschalten.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wie kann ich Verbündete unterstützen?</b></td>
                <td>Wähle auf der Karte das Königreich eines Gildenmitglieds an und sende Truppen mit dem Befehl <b>„Unterstützen“</b>.
                    Deine Einheiten verteidigen fortan das befreundete Dorf gegen Angreifer und können jederzeit über
                    deine eigene Kaserne zurückgerufen werden.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Was passiert, wenn ich eine Gilde verlasse?</b></td>
                <td>Deine stationierten Unterstützungstruppen bei Gildenmitgliedern treten sofort den Rückmarsch in
                    deine
                    Kaserne an. Nach dem Verlassen gilt eine <b>Sperrfrist von <?= (GUILD_JOIN_COOLDOWN / 3600) ?>
                        Stunden</b>, bevor du einer neuen
                    Gilde beitreten oder eine gründen kannst.
                </td>
            </tr>
        </table>
    </div>
</div>
<div class="box-container" style="margin-bottom: 20px;">
    <div class="box-header">Welt-Events</div>
    <div class="box-content box-content-bg">
        <table class="table faq-table" style="width: 100%; border: none;">
            <tr>
                <td class="td-gradient" style="width: 40%;"><b>Wann finden Welt-Events statt?</b></td>
                <td>Jeden <b>Dienstag</b> und <b>Freitag</b> um <b>16:00 Uhr</b> öffnen sich die Siegel im Zentrum der
                    Karte [50:50]. Ein Event dauert in der Regel 24 Stunden.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Muss ich Angst um meine Truppen haben?</b></td>
                <td><b>Nein!</b> Im Auge des Sturms herrscht ein besonderer Schutzzauber. Alle Truppen, die zum Event
                    entsandt werden, kehren nach dem Kampf garantiert und ohne Verluste in dein Königreich zurück.
                </td>
            </tr>
            <tr>
                <td class="td-gradient"><b>Wonach richten sich die Belohnungen?</b></td>
                <td>Die Beute ist fair und skaliert mit deinem Fortschritt. Je höher der Durchschnitt deiner
                    Gebäude-Stufen und dein Dorfzentrum sind, desto massiver fallen die Ressourcen- und Truppenpakete
                    aus.
                </td>
            </tr>
        </table>
    </div>
</div>