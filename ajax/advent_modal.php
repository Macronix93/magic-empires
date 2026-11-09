<?php
require_once("../includes/ajax_core.php");

if (AdventCalendar::should_show_intro($user->get_user_id())) {
    echo AdventCalendar::render_intro_modal();
}