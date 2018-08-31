<?php
	// Бот который проходит по столам и закрывает зависшие игры

	ini_set('error_reporting', 1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);

    require_once ('/var/www/user/data/www/injobber.com/socket/server/mysql.php');
    require_once ('/var/www/user/data/www/injobber.com/socket/server/common.php');

    $db_host = 'localhost';
    $db_name = 'game';
    $db_login = 'injobber';
    $db_password = '2M5v6O2n';

    db_connect($db_host, $db_login, $db_password);
    db_select_db($db_name);

	$result = db_query("SELECT * FROM module_games WHERE start = 1 AND end = 0");
	if (db_num_rows($result) > 0) {
		while ($row = db_fetch_array($result)) {
			$timestamp1 = date("U", strtotime($row['modify_date']));
			$timestamp2 = time();

			$diff = $timestamp2 - $timestamp1;

			if ($diff > 20) {
				$sql = "UPDATE module_games SET start = 0, end = 1, players_count = 0 WHERE id = ".$row['id'];
		        db_query($sql);

		        $sql = "UPDATE module_tables SET players_count = 0, bank = 0, pot = 0 WHERE id = ".$row['table_id'];
		        db_query($sql);

		        $sql = "DELETE FROM module_places WHERE table_id = ".$row['table_id'];
		        db_query($sql);
			}
		}
	}
?>