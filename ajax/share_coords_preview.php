<?php
require_once("../includes/ajax_core.php");

$map = new Map($user);
[$x, $y] = $map->require_valid_coordinates();
?>
<div id="modal-title-data" data-title="Koordinaten <?= $x ?>:<?= $y ?>" data-width="380px" style="display: none;"></div>

<div class="coord-modal-wrap">
    <div class="minimap-wrapper">
        <?= $map->render_minimap($x, $y) ?>
    </div>
    <div style="display: flex; gap: 10px; justify-content: center; width: 100%; margin-top: 5px;">
        <button type="button" data-on-click="mapJump" data-x="<?= $x ?>" data-y="<?= $y ?>"
                style="padding: 7px 25px; font-weight: bold;">
            🗺️ Zur Karte
        </button>
    </div>
</div>