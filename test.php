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

    //generateColoda(11);
    //$main_card = getMainCard(39);
    //$response_text = getCards(2, 1, 'ad7e0a04778e093410961b1688a7a8e2');
    //$response_text = getGameUsers(1);
    // $response_text = variantCard(2, 1, 2);
    // $response_text = array(
    //         'trump' => $main_card,
    //     );
    //$response_text = getGameWinner(1, 1, 2);
    //$response_text = getWinner(1);
    // db_query("UPDATE module_users SET balance = '24243' WHERE session_id = '83431468e93706a6fe2ddef3b5850efe'");
    // db_query("UPDATE module_users SET balance = '1000' WHERE session_id = 'b343a494f691e92eebec815ee636278f'");

    // print_r($response_text);
    echo "<hr>";
    // echo json_encode($response_text);

    $count = db_get_data("SELECT SUM(win) FROM module_places WHERE table_id = 1 LIMIT 1", "SUM(win)");
    $win_count = db_get_data("SELECT MAX(win) FROM module_places WHERE table_id = 1 LIMIT 1", "MAX(win)");

    echo "<hr>";
    echo $count." == 3 && ".$win_count." != 2";
?>