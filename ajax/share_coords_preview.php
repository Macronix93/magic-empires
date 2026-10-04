<?php
require_once("../includes/core.php");

if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    $user->check_user_login();

    $x = (int)($_GET["x"] ?? 0);
    $y = (int)($_GET["y"] ?? 0);

    if ($x < 1 || $x > MAX_X || $y < 1 || $y > MAX_Y) {
        echo show_error_box("Ungültige Koordinaten.");
        exit;
    }

    $map = new Map($user);

    // TODO: Styles in CSS Datei packen (oder vielleicht custom styles übergeben für render mini map?)
    ?>
    <div id="modal-title-data" data-title="Koordinaten <?= $x ?>:<?= $y ?>" data-width="380px"
         style="display: none;"></div>

    <style>
        .coord-modal-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            width: 100%;
        }

        .coord-modal-wrap .minimap-wrapper {
            display: flex;
            justify-content: center;
            width: 100%;
        }

        .coord-modal-wrap .minimap-container {
            display: grid !important;
            width: fit-content !important;
            max-width: none !important;
            grid-template-columns: 24px repeat(13, 24px) !important;
            grid-auto-rows: 24px !important;
            gap: 1px !important;
            box-sizing: content-box !important;
            margin: 0 auto;
        }

        .coord-modal-wrap .minimap-tile {
            width: 100% !important;
            height: 100% !important;
            font-size: 14px !important;
        }

        .coord-modal-wrap .minimap-label-y {
            height: 100% !important;
            font-size: 11px !important;
        }

        .coord-modal-wrap .minimap-label-x,
        .coord-modal-wrap .minimap-origin {
            height: 20px !important;
            font-size: 10px !important;
        }

        @media screen and (max-width: 600px) {
            .coord-modal-wrap {
                gap: 10px;
            }

            .coord-modal-wrap .minimap-container {
                grid-template-columns: 22px repeat(13, 20px) !important;
                grid-auto-rows: 20px !important;
            }

            .coord-modal-wrap .minimap-tile {
                width: 100% !important;
                height: 100% !important;
                font-size: 12px !important;
            }

            .coord-modal-wrap .minimap-label-y {
                height: 100% !important;
                font-size: 10px !important;
            }

            .coord-modal-wrap .minimap-label-x,
            .coord-modal-wrap .minimap-origin {
                height: 18px !important;
                font-size: 9px !important;
            }
        }
    </style>

    <div class="coord-modal-wrap">
        <div class="minimap-wrapper">
            <?= $map->render_minimap($x, $y) ?>
        </div>

        <div style="display: flex; gap: 10px; justify-content: center; width: 100%; margin-top: 5px;">
            <button type="button" data-on-click="mapJump" data-x="<?= $x ?>" data-y="<?= $y ?>"
                    style="padding: 7px 25px; font-weight: bold;">
                🗺️ Zur Karte
            </button>
            <button type="button" data-on-click="closeOverlay" style="padding: 7px 18px;">
                Schließen
            </button>
        </div>
    </div>
    <?php
} else {
    change_location("map.php");
}