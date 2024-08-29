<?php
if (isset($_SERVER["HTTP_X_REQUESTED_WITH"]) && $_SERVER["HTTP_X_REQUESTED_WITH"] === "XMLHttpRequest") {
    global $db_instance;
    require_once("functions.php");

    $map = new Map($db_instance);

    if ($_GET["clickedfield"] == -1) {
        $x = isset($_GET["x"]) && $_GET["x"] != -1 ? $_GET["x"] : 1;
        $y = isset($_GET["y"]) && $_GET["y"] != -1 ? $_GET["y"] : 1;

        $stmt = $db_instance->prepare("SELECT m.fieldtype, f.fieldname FROM map m
                                JOIN fieldtypes f ON m.fieldtype = f.fieldid
                                WHERE mapx = ? AND mapy = ?");
        $stmt->bind_param('ii', $x, $y);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        ?>
        <br>
        <div style="border-bottom: 2px solid rgba(0, 0, 0, 0.5); width: 50%; margin: auto; line-height: 40px">
            <?php
            echo $row["fieldname"];
            ?>
        </div>
        <table class="table"
               style="margin-top: 20px; min-width: 400px; text-align: left;">
            <tr>
                <td class="td-main"><b>Koordinaten</b></td>
                <?php
                echo "<td>" . $x . ":" . $y . "</td>";
                ?>
            </tr>
            <tr>
                <td colspan='2' class='td-main' style='text-align: center;'>
                    <button type='submit' style='margin: 10px;'>Erobern</button>
                </td>
            </tr>
        </table>
        <?php
    } else {
        $field = $_GET["clickedfield"] ?? -1;

        // Render the field info table HTML
        ob_start();
        $map->renderFieldInfo($field);
        $html = ob_get_clean();

        echo $html;
    }
} else {
    header("Location: map.php");
}