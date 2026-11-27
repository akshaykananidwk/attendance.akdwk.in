<?php
// સેશન શરૂ કરો (જેથી સિસ્ટમને ખબર પડે કે કયું સેશન ચાલુ છે)
session_start();

// સેશનના બધા જ ડેટાને (જેમ કે user_id, name, role) ખાલી કરી દો
$_SESSION = array();

// સેશનને સંપૂર્ણપણે નષ્ટ (destroy/hard delete) કરી દો
session_destroy();

// યુઝરને પાછા login.php પેજ પર મોકલી દો (Redirect)
header("Location: login.php");
exit();
?>