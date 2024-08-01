<?php
require_once("functions.php");
global $db_instance;

$stmt = $db_instance->prepare("SELECT id, maxwood, wood, woodperhour, maxfood, food, foodperhour, maxstone, stone, stoneperhour, maxgold, gold, goldperhour, maxvillager, villager, villagerperhour FROM kingdoms");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $resources = array("wood", "food", "stone", "gold", "villager");

    foreach ($resources as $resource) {
        $newResource = $row[$resource] + $row["{$resource}perhour"];
        $maxResource = $row["max$resource"];

        // Check if the new resource exceeds the maximum limit and adjust if necessary
        if ($newResource > $maxResource) {
            $newResource = $maxResource;
        }

        $row[$resource] = $newResource;
    }

    // Update the kingdom's resources with the adjusted values
    $stmt = $db_instance->prepare("UPDATE kingdoms SET wood = ?, food = ?, stone = ?, gold = ?, villager = ? WHERE id = ?");
    $stmt->bind_param("iiiiii", $row["wood"], $row["food"], $row["stone"], $row["gold"], $row["villager"], $row["id"]);
    $stmt->execute();
}

$stmt->close();


?>

<html>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE-edge">
    <meta name="viewport" content="width=device-width, initial-scale:1.0">
    <link rel="stylesheet" href="styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
            href="https://fonts.googleapis.com/css2?family=Londrina+Outline&family=Londrina+Solid&family=Roboto&family=Signika+Negative:wght@500&display=swap"
            rel="stylesheet">
    <title>Magic Empires</title>
</head>
<body>

<div class="content">
    <div class="content-box">
        <div class="middle-container">
            <div class="big-box-container">
                <div class="big-box-header">
                    Under construction!
                </div>

                <div id="construction">
                    <div class="preview-item">
                        <img class="mySlides" src="images/preview_one.png">
                    </div>
                    <div class="preview-item">
                        <img class="mySlides" src="images/preview_two.png">
                    </div>
                    <div class="preview-item">
                        <img class="mySlides" src="images/preview_three.png">
                    </div>
                    <div class="preview-item">
                        <img class="mySlides" src="images/preview_four.png">
                    </div>
                    <div class="preview-item">
                        <img class="mySlides" src="images/preview_five.png">
                    </div>
                    <div class="preview-item">
                        <img class="mySlides" src="images/preview_six.png">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let slideIndex = 1;
    showDivs(slideIndex);

    function plusDivs(n) {
        showDivs(slideIndex += n);
    }

    function showDivs(n) {
        var i;
        var x = document.getElementsByClassName("mySlides");
        if (n > x.length) {
            slideIndex = 1;
        }
        if (n < 1) {
            slideIndex = x.length;
        }
        for (i = 0; i < x.length; i++) {
            x[i].style.display = "none";
        }
        x[slideIndex - 1].style.display = "block";
    }
</script>

</body>
</html>