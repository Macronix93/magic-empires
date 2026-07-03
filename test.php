<?php
// Mock-Konstanten für Icons
function getIcon($name)
{
    $icons = [
        'Milizsoldat' => 'icon_militia',
        'Schwertkämpfer' => 'icon_swordsman',
        'Hellebardier' => 'icon_halberdier',
        'Ritter' => 'icon_knight',
        'Eroberer' => 'icon_conqueror',
        'Späher' => 'icon_scout',
        'Räuber' => 'icon_robber',
        'food' => 'icon_meat',
        'wood' => 'icon_wood',
        'stone' => 'icon_stone',
        'gold' => 'icon_gold',
        'wall' => 'icon_health',
        'score' => 'icon_score'
    ];
    $icon = $icons[$name] ?? 'icon_error';
    return "<img src='images/icons/$icon.png' style='width:24px;height:24px;vertical-align:middle;' alt=''>";
}

function renderBattleUnit($name, $initial, $losses)
{
    $survivors = $initial - $losses;
    $lossText = ($losses > 0) ? "<span class='loss-red'>(-$losses)</span>" : "";
    $survivorClass = ($survivors > 0) ? "survivor-green" : "loss-red";

    return "
    <div class='battle-unit-card'>
        " . getIcon($name) . "
        <div class='battle-unit-info'>
            <span class='battle-unit-name'>$name</span>
            <span class='battle-unit-count $survivorClass'>$survivors <small style='color:#ccc;'> von $initial</small> $lossText</span>
        </div>
    </div>";
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta content="IE-edge" http-equiv="X-UA-Compatible">
    <meta content="width=device-width, initial-scale:1.0" name="viewport">
    <link href="images/favicon.ico" id="icon" rel="icon" type="image/x-icon">
    <link href="styles.css" rel="stylesheet" type="text/css">
    <meta charset="UTF-8">
    <title>Battle Report Test - Full Scenarios</title>
    <style>
        :root {
            --link-color: rgb(212, 175, 55);
            --border-gold: rgb(165, 124, 0);
            --box-header: rgb(35, 32, 28);
        }

        .battle-report {
            display: flex;
            flex-direction: column;
            gap: 10px;
            border-radius: 8px;
        }

        .battle-vs-wrapper {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            position: relative;
        }

        .battle-column {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
            background: rgba(255, 255, 255, 0.05);
            padding: 5px;
            border-radius: 5px;
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .battle-vs-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: var(--link-color);
            font-size: 24px;
            padding-top: 80px;
        }

        .battle-unit-card {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(0, 0, 0, 0.4);
            padding: 5px 10px;
            border-radius: 4px;
            border-left: 3px solid var(--link-color);
            min-height: 40px;
        }

        .battle-unit-info {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .battle-unit-name {
            font-size: 18px;
            color: var(--link-color);
        }

        .battle-unit-count {
            font-size: 18px;
        }

        .loss-red {
            color: #ff4d4d;
        }

        .report-section-title {
            border-bottom: 1px solid var(--border-gold);
            color: var(--link-color);
            margin-bottom: 10px;
            text-align: left;
            font-variant: small-caps;
            font-size: 18px;
        }

        @media (max-width: 600px) {
            .battle-report {
                padding: 5px;
            }

            .battle-unit-card {
                padding: 5px 2px;
            }

            .battle-vs-divider {
                display: none;
            }

            .battle-unit-name {
                font-size: 13px;
            }

            .battle-column {
                gap: 2px;
            }

            .report-section-title,
            .battle-unit-count {
                font-size: 15px;
            }
        }

        @media screen and (max-width: 1392px) {
            .middle-container {
                width: 95%;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>

<!-- SZENARIO 1: GROSSER SIEG (ANGREIFER) -->
<div class="middle-container" style="margin: 0 auto;">
    <div class="big-box-container">
        <div class="big-box-header">
            Servernachrichten
        </div>
        <div class="big-box-content">
            <div id="messages-section">
                <div class="server-bubble">
                    <div class="message-border">Am 19.06.2026 18:26:26 (X)</div>
                    <div class="title-border">Kampfbericht</div>
                    <div class="battle-report">
                        <span style="text-align: center;">Es hat ein Kampf stattgefunden mit SPIELER_XYZ (KÖNIGREICH ABC, KOORDINATEN X/Y)!</span>
                        <div class="battle-vs-wrapper">
                            <div class="battle-column">
                                <div class="report-section-title">Deine Truppen</div>
                                <?= renderBattleUnit('Schwertkämpfer', 99999, 10000) ?>
                                <?= renderBattleUnit('Ritter', 50, 2) ?>
                                <?= renderBattleUnit('Eroberer', 1, 0) ?>
                            </div>
                            <div class="battle-vs-divider">VS</div>
                            <div class="battle-column">
                                <div class="report-section-title">Gegnerische Truppen</div>
                                <?= renderBattleUnit('Milizsoldat', 400, 400) ?>
                                <?= renderBattleUnit('Hellebardier', 20, 20) ?>
                            </div>
                        </div>
                        <div class="battle-column"
                             style="background: rgba(46, 204, 113, 0.2); border-color: #2ecc71;">
                            <div class="report-section-title" style="color: #2ecc71; border-color: #2ecc71;">
                                Kampfausgang
                            </div>
                            Der Sieg ist unser! Deine Truppen haben die Verteidigung durchbrochen.<br>
                            Mauerbeschädigung:
                            <div><?= getIcon('wall') ?> 2.700 &rarr; 2.150</div>
                            <div><span class="passed">Beute gemacht:</span> <?= getIcon('wood') ?>
                                12.450 <?= getIcon('gold') ?> 1.120
                            </div>
                        </div>
                    </div>
                </div>
                <!-- SZENARIO 2: NIEDERLAGE (VERTEIDIGER) -->
                <div class="server-bubble">
                    <h2 style="color:var(--link-color); text-align:center;">2. Szenario: Niederlage bei
                        Verteidigung</h2>
                    <div class="battle-report">
                        <div class="battle-vs-wrapper">
                            <div class="battle-column">
                                <div class="report-section-title">Deine Truppen</div>
                                <?= renderBattleUnit('Milizsoldat', 100, 100) ?>
                                <?= renderBattleUnit('Schwertkämpfer', 15, 15) ?>
                            </div>
                            <div class="battle-vs-divider">VS</div>
                            <div class="battle-column">
                                <div class="report-section-title">Angreifer</div>
                                <?= renderBattleUnit('Ritter', 500, 12) ?>
                                <?= renderBattleUnit('Eroberer', 5, 0) ?>
                            </div>
                        </div>
                        <div class="battle-column" style="background: rgba(231, 76, 60, 0.2); border-color: #e74c3c;">
                            <div class="report-section-title" style="color: #e74c3c; border-color: #e74c3c;">
                                Kampfausgang
                            </div>
                            <span class="error">Das Königreich wurde überrannt!</span><br>
                            Die Verteidiger wurden bis auf den letzten Mann aufgerieben.<br>
                            Mauer: <?= getIcon('wall') ?> 500 &rarr; 0 (Zerstört)<br>
                        </div>
                    </div>
                </div>
                <!-- SZENARIO 3: SPIONAGEBERICHT -->
                <div class="server-bubble">
                    <h2 style="color:var(--link-color); text-align:center;">3. Szenario: Spionage (Tier 3 Erfolg)</h2>
                    <div class="battle-report">
                        <div class="battle-column">
                            <div class="report-section-title">Spionagebericht: Königreich Rabenfels</div>
                            <div style="display: flex; justify-content: space-around; background: rgba(0,0,0,0.4); padding: 10px; border-radius: 5px; border: 1px solid #555;">
                                <div><?= getIcon('food') ?> 45.000</div>
                                <div><?= getIcon('wood') ?> 12.500</div>
                                <div><?= getIcon('stone') ?> 8.200</div>
                                <div><?= getIcon('gold') ?> 2.100</div>
                            </div>
                            <div class="report-section-title" style="margin-top: 10px;">Identifizierte Gebäude</div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px;">
                                <div>• Dorfzentrum (Stufe 10)</div>
                                <div>• Kaserne (Stufe 8)</div>
                                <div>• Mauer (Stufe 10)</div>
                                <div>• Lager (Stufe 10)</div>
                                <div>• Schmiede (Stufe 5)</div>
                                <div>• Universität (Stufe 7)</div>
                            </div>
                            <div class="report-section-title" style="margin-top: 10px;">Gegnerische Garnison</div>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <div style="flex: 1 1 200px;"><?= renderBattleUnit('Schwertkämpfer', 1250, 0) ?></div>
                                <div style="flex: 1 1 200px;"><?= renderBattleUnit('Späher', 25, 0) ?></div>
                            </div>
                        </div>
                        <div class="battle-unit-card"
                             style="border-left: 3px solid #3498db; background: rgba(52, 152, 219, 0.2);">
                            <?= getIcon('Späher') ?>
                            <div class="battle-unit-info">
                                <span class="battle-unit-name" style="color:#3498db;">Eigene Späher</span>
                                <span class="battle-unit-count">12 <small>/ 15</small> <span
                                            class="loss-red">(-3)</span></span>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- SZENARIO 4: RAUBZUG -->
                <div class="server-bubble">
                    <h2 style="color:var(--link-color); text-align:center;">4. Szenario: Raubzug auf Ressourcenfeld</h2>
                    <div class="battle-report">
                        <div class="battle-column">
                            <div class="report-section-title">Erfolgreiche Plünderung</div>
                            Unsere Räuber haben ein verlassenes Vorratslager (X:12 | Y:88) geleert.<br><br>
                            <div style="display: flex; gap: 20px; justify-content: center;">
                                <div class="passed"><?= getIcon('wood') ?> +2.850</div>
                                <div class="passed"><?= getIcon('stone') ?> +1.400</div>
                            </div>
                        </div>
                        <div style="max-width: 300px; margin: auto; width: 100%;">
                            <?= renderBattleUnit('Räuber', 20, 0) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


</body>
</html>