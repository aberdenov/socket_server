<?php
	ini_set('error_reporting', E_ALL);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 1);

	require_once ('mysql.php');
    require_once ('common.php');

    $db_host = 'localhost';
    $db_name = 'game';
    $db_login = 'injobber';
    $db_password = '2M5v6O2n';

    db_connect($db_host, $db_login, $db_password);
    db_select_db($db_name);

    db_query("TRUNCATE TABLE module_coloda");
    db_query("TRUNCATE TABLE module_games");
    db_query("TRUNCATE TABLE module_places");
    db_query("TRUNCATE TABLE module_tables");
    db_query("TRUNCATE TABLE module_bets");
    
    echo "clean ok";
?>