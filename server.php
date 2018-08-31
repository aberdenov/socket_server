<?php
    ini_set('error_reporting', 1);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);

    require_once ('WebSocket.php');
    require_once ('mysql.php');
    require_once ('common.php');

    use Socket\WebSocket;

    $ip_addr = '78.40.108.38';
    $port = 11210;
    $db_host = 'localhost';
    $db_name = 'game';
    $db_login = 'injobber';
    $db_password = '2M5v6O2n';

    db_connect($db_host, $db_login, $db_password);
    db_select_db($db_name);

    $ws = new WebSocket($ip_addr, $port);

    $ws->on('handshake', function ($conn, $headers) {
        if (isset($headers['Server-message'])) {
            echo "Request from server\n";
            $conn->send(array('message' => 'Hello from server!', 'name' => 'server', 'color' => 'red', 'type' => 'usermsg'));
            return false;
        }

        return true;
    });

    $ws->on('open', function ($conn, $ip, $res) {

    });

    $ws->on('close', function ($conn, $ip) {

    }); 

    $ws->on('message', function ($conn, $data, $resource) {
        if ($data) {
            $action = $data['action'];

            // авторизация 
            if ($action == 'auth') {
                $response_text = auth($data['email'], $data['password']);

                $conn->sendCurrent($response_text, $resource);
            }

            // регистрация
            if ($action == 'reg') {
                $response_text = reg($data['email'], $data['password'], $data['name']);

                $conn->send($response_text);
            }

            // получаем resource_id сокета
            if ($action == 'getSocketId') {
                $response_text = array(
                        'socket_id' => " ".$resource
                    );

                $conn->send($response_text);
            }

            // рассадка за стол
            if ($action == 'place') {
                $table_info = place($data['sid'], $data['cash']);
                $game_id = createGame($table_info['table_id'], $data['cash']);

                if ($game_id > 0) {
                    // генерируем колоду
                    generateColoda($game_id);

                    $response_text = array(
                            'players_count' => getPlaceCount($table_info['table_id']),
                        );

                    $conn->send($response_text);

                    $response_text = array(
                            'table_id' => $table_info['table_id'],
                            'place_row_id' => $table_info['place_row_id'],
                            'place_id' => $table_info['place_id'],
                            'game_id' => $game_id
                        );
                } else {
                    $response_text = array(
                            'error' => 'Ошибка создания игры'
                        );
                }
                
                $conn->sendCurrent($response_text, $resource);
            }

            // выдаем колоду
            if ($action == 'coloda') {
                $response_text = getColoda($data['game_id']);

                $conn->send($response_text);
            }

            // выдаем карты
            if ($action == 'cards') {
                $response_text = getCards($data['game_id'], $data['table_id'], $data['sid']);

                $conn->sendCurrent($response_text, $resource);

                // передаем козырь
                $main_card = getMainCard($data['game_id']);

                $response_text = array(
                            'trump' => $main_card,
                        );

                $conn->send($response_text);

                $players_count = db_get_data("SELECT players_count FROM module_games WHERE id = '".$data['game_id']."'", "players_count");
                $next_user = nextUser(0, $players_count);

                $response_text = array(
                            'next_user' => $next_user,
                            'bet_state' => 1,
                            'next_def_bet_user' => 1
                        );

                $conn->send($response_text);
            }

            // выводим массив игроков за столом
            if ($action == 'getGameUsers') {               
                // получаем данные по триггерам (игра началась и игра идет)
                $game = db_get_data("SELECT date, start FROM module_games WHERE id = ".$data['game_id']);
                $players_count = db_table_count("module_places", "table_id = ".$data['table_id']);

                // проверяем количество игроков за столом, если больше 1
                if ($players_count > $data['players_limit'] && $game['start'] == 0) {
                    // делаем пометку что игра началась
                    $sql = "UPDATE module_games SET start = 1, modify_date = NOW() WHERE id = ".$data['game_id'];
                    db_query($sql);

                    $response_text = array(
                            'start_game' => '1',
                        );
 
                    $conn->send($response_text);                    
                } 

                $response_text = getGameUsers($data['table_id'], $data['game_id']);
                
                $conn->send($response_text);
            }

            // открываем свою карту
            if ($action == 'sendCard') {
                // определяем кто выиграл игру и забрал взятку
                $turn = db_get_data("SELECT turn FROM module_games WHERE id = ".$data['game_id'], "turn");
                $play_users = db_get_data("SELECT MAX(user_index) FROM module_coloda WHERE game_id = ".$data['game_id']." LIMIT 1", "MAX(user_index)");

                $response_text = sendCard($data['game_id'], $data['card_name'], $turn, $play_users);

                $conn->send($response_text);
                
                // проверяем прошел ли круг, для вызова определения победителя круга
                if ($turn >= $play_users) {
                    $response_text = getGameWinner($data['game_id'], $data['table_id'], $play_users);

                    $conn->send($response_text);
                }

                $win_count = db_get_data("SELECT MAX(win) FROM module_places WHERE table_id = ".$data['table_id']." LIMIT 1", "MAX(win)");
                if ($win_count >= 2) {
                    $sql = "UPDATE module_tables SET pot = 0 WHERE id = ".$data['table_id'];
                    db_query($sql);

                    $response_text = getWinner($data['table_id']);

                    $conn->send($response_text);

                    $response_text = array(
                            'start_game' => '0',
                        );

                    $conn->send($response_text); 

                    $response_text = endGame($data['game_id'], $data['table_id']);

                    $conn->send($response_text);                    
                } else {
                    // определяем ази не ази
                    $is_azi = isAzi($data['table_id']);

                    // echo "==========\n";
                    // echo $is_azi."\n";
                    // echo "==========\n";

                    if ($is_azi == 1) {
                        $response_text = array(
                            'winner' => '7',
                        );

                        // echo "==========\n";
                        // print_r($response_text);
                        // echo "==========\n";

                        $conn->send($response_text);

                        $bank = db_get_data("SELECT pot FROM module_tables WHERE id = ".$data['table_id'], "pot");

                        $response_text = array(
                            'start_game' => '0',
                            'bank' => $bank
                        );

                        $conn->send($response_text); 

                        $response_text = endGame($data['game_id'], $data['table_id']);

                        $conn->send($response_text); 
                    } 
                }
            }

            // проверяем карты, которые можно скинуть
            if ($action == 'variantCard') {
                $play_users = db_get_data("SELECT MAX(user_index) FROM module_coloda WHERE game_id = ".$data['game_id']." LIMIT 1", "MAX(user_index)");

                $response_text = variantCard($data['user'], $data['game_id'], $play_users);

                $conn->sendCurrent($response_text, $resource);

                $turn = db_get_data("SELECT turn FROM module_games WHERE id = ".$data['game_id'], "turn");
                $response_text = array(
                    "turn" => $turn
                );

                $conn->send($response_text);
            }

            // делаем ставку
            if ($action == 'makeBet') {
                $response_text = makeBet($data['bet'], $data['game_id'], $data['place_id'], $data['sid'], $data['table_id']);

                $conn->send($response_text);

                $status = endBet($data['game_id'], $data['table_id'], $data['place_id'], $data['bet']);

                if ($status == 1) {
                    $response_text = array(
                            'bet_state' => 0
                        );

                    $conn->send($response_text);
                }
            }

            if ($action == 'makeDefBet') {
                // делаем первичные ставки
                $balance = db_get_data("SELECT balance FROM module_users WHERE session_id = '".$data['sid']."' LIMIT 1", "balance");
                $players_count = getPlaceCount($data['table_id']);
                    
                $next_user = nextUser($data['place_id'], $players_count);

                if ($balance >= $data['cash']) {              
                    $new_balance = $balance - $data['cash']; 
                                
                    $sql = "UPDATE module_users SET balance = ".$new_balance." WHERE session_id = '".$data['sid']."'";
                    db_query($sql);          

                    $sql = "INSERT INTO module_bets SET date = NOW(), game_id = ".$data['game_id'].", place_id = ".$data['place_id'].", bet = ".$data['cash'].", def_bet = 1";
                    db_query($sql);

                    $pot = db_get_data("SELECT SUM(bet) FROM module_bets WHERE def_bet = 1 AND game_id = ".$data['game_id'], "SUM(bet)");
                    
                    $table_pot = tablePot($pot, $data['table_id']);

                    if ($pot == ($data['cash'] * $players_count)) {
                        $response_text = array(
                                "currentBet" => $data['cash'],
                                "pot" => $pot,
                                "next_bet_user" => 1,
                                "p".$data['place_id']."Balance" => $new_balance
                            ); 
                    } else {
                        $response_text = array(
                                "currentBet" => $data['cash'],
                                "pot" => $pot,
                                "next_def_bet_user" => $next_user,
                                "p".$data['place_id']."Balance" => $new_balance
                            ); 
                    }     
                } else {
                    $response_text = array(
                            'error' => 'Не достаточно средств'
                        );
                }
                    
                $conn->send($response_text);
            }

            if ($action == 'test') {
                $user_name = $data['name'];
                $user_message = $data['message'];

                $response_text = variantCard(3, 42);

                // $response_text = array(
                //     'type' => 'usermsg',
                //     'name' => $user_name,
                //     'message' => $user_name." ".$user_message." ".$resource
                // );

                $conn->send($response_text);
                //$conn->sendCurrent($response_text, trim($resource));
            }            
        }
    });

    $ws->setTimeout(1);
    $ws->run();
?>