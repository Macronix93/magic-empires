<?php
require_once("includes/core.php");

check_user_login($user);

/*
 * HTML Section
 */
$title = "Spenden";
$header = "Spenden";
$view = "<div style='max-width: 600px; margin: 0 auto; line-height: 1.6;'>
            <p>Seid gegrüßt, Eure Hoheit!</p>
            <p>Die Welt von <b>Magic Empires</b> ist ein Reich, das aus Leidenschaft und in unzähligen Stunden harter Arbeit erschaffen wurde. 
            Um die Magie aufrechtzuerhalten, die Server zu bewachen und die Grenzen unseres gemeinsamen Reiches zu erweitern, bedarf es einiger Ressourcen.</p>
            <p>Wenn Ihr das Projekt unterstützen und zur Stärkung der königlichen Schatzkammer beitragen möchtet, könnt Ihr hier einen freiwilligen Obolus entrichten. 
            Jede Münze hilft dabei, die Feuer in den Schmieden am Brennen zu halten!</p>
            <div style='display: flex; justify-content: center;'>
                <iframe srcdoc=\"
                    <html>
                    <head>
                        <style>body { margin: 0; display: flex; justify-content: center; align-items: center; background: transparent; }</style>
                    </head>
                    <body>
                        <script src='https://storage.ko-fi.com/cdn/widget/Widget_2.js'></script>
                        <script>
                            kofiwidget2.init('Support me on Ko-fi', '#72a4f2', 'L4U025JD0P');
                            kofiwidget2.draw();
                        </script>
                    </body>
                    </html>\" 
                    style='border: none; width: 100%; height: 40px;'>
                </iframe>
            </div>
            <p style='font-size: 13px; opacity: 0.7; margin-top: 15px;'>
                <i>(Sämtliche Spenden sind absolut freiwillig!)</i>
            </p>
        </div>";

include("layout/base.php");