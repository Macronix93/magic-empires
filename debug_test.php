<?php
require_once("includes/core.php");
$test_word = "Penisreich";
if (contains_bad_words($test_word)) {
    echo "'$test_word' wurde BLOCKIERT (Richtig)";
} else {
    echo "'$test_word' wurde ERLAUBT (Falsch!)";
    echo "<br>Geladene Wörter: " . count(get_bad_names());
}