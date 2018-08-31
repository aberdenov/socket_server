<?php
	// авторизация
	function auth($email, $password) {
		$response_text = '';
		$email = trim($email);
		$password = trim($password);
			
		$result = db_query("SELECT * FROM module_users WHERE email = '".$email."' AND password = MD5('".$password."') AND active = 1 LIMIT 1");
		if (db_num_rows($result) > 0) {
			$row = db_fetch_assoc($result);
			
			$md5_hash = md5($email.":".$password.":".time());
			
			$sql = "UPDATE module_users SET session_id = '".$md5_hash."' WHERE id = ".$row['id'];
			db_query($sql);

			$response_text = array(
                    'sid' => $md5_hash,
                    'email' => $email,
                    'password' => $password,
                    'balance' => $row['balance']
                );
		} else {
			$response_text = array(
                    'error' => 'Неверный логин или пароль'
                );
		}

		return $response_text;
	}

	// регистрация
	function reg($email, $password, $name) {
		$response_text = '';
		$email = trim($email);
		$password = trim($password);
		$name = trim($name);

		$count = db_table_count('module_users', "email = '".$email."'");
		if ($count == 0) {
			$sql = "INSERT INTO module_users SET email = '".$email."', password = MD5('".$password."'), name = '".$name."', balance = '1000', active = 1";				
			db_query($sql);
					
			$user_id = db_insert_id();
			
			$response_text = array(
                    'user_id' => $user_id
                );
		} else {
			$response_text = array(
                    'error' => 'Пользователь с таким e-mail адресом уже зарегистрирован'
                );
		}

		return $response_text;
	}

	// рассадка за стол
	function place($sid, $cash) {		
		$user_id = db_get_data("SELECT id FROM module_users WHERE session_id = '".$sid."' LIMIT 1", "id");

		$result = db_query("SELECT * FROM module_tables WHERE cash = '".$cash."' AND players_count < 6 LIMIT 1"); 
		if (db_num_rows($result) > 0) {
			$row = db_fetch_assoc($result);
				
			$table_id = $row['id'];
			$count = $row['players_count'];
		} else {
			$sql = "INSERT INTO module_tables SET date = NOW(), cash = '".$cash."', players_count = 0";
			db_query($sql);
				
			$table_id = db_insert_id();
			$count = 0;
		}

		$result = db_query("SELECT * FROM module_places WHERE table_id = '".$table_id."' AND user_id = '".$user_id."' LIMIT 1"); 
		if (db_num_rows($result) > 0) {
			$row = db_fetch_assoc($result);
				
			$row_id = $row['id'];
			$place_id = $row['place_id'];
		} else {
			$new_count = $count + 1;
				
			$sql = "UPDATE module_tables SET players_count = '".$new_count."' WHERE id = ".$table_id;
			db_query($sql);
				
			$sql = "INSERT INTO module_places SET table_id = '".$table_id."', place_id = '".$new_count."', user_id = '".$user_id."'";
			db_query($sql);
				
			$row_id = db_insert_id();
			$place_id = $new_count;
		}

		$response_text = array(
                    'table_id' => $table_id,
                    'players_count' => $count,
                    'place_row_id' => $row_id,
                    'place_id' => $place_id
                );

		return $response_text;
	}

	function getPlaceCount($table_id) {
		$result = db_get_data("SELECT players_count FROM module_tables WHERE id = ".$table_id, "players_count");

		return $result;
	}

	// создаем игру за столом
	function createGame($table_id, $bet) {
		$result = db_query("SELECT * FROM module_games WHERE table_id = '".$table_id."' AND end = 0 AND players_count < 6 LIMIT 1"); 
		if (db_num_rows($result) > 0) {
			$row = db_fetch_assoc($result);

			$id = $row['id'];
		} else {
			$sql = "INSERT INTO module_games SET date = NOW(), modify_date = NOW(), table_id = ".$table_id;
			db_query($sql);

			$id = db_insert_id();

			$sql = "INSERT INTO module_bets SET date = NOW(), game_id = ".$id.", bet = ".$bet;
			db_query($sql);
		}

		return $id;
	}

	// генерируем колоду для игры
	function generateColoda($game_id) {
		$mast_not_use = rand(1, 4);
		$count_coloda = db_table_count("module_coloda", "game_id = ".$game_id);

		$result = db_query("SELECT * FROM module_cards WHERE type != ".$mast_not_use);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				if ($count_coloda == 0) {
					$sql = "INSERT INTO module_coloda SET game_id = ".$game_id.", card_id = ".$row['id'].", used = 0, main = 0";
					db_query($sql);
				}
			}
		}

		// отмечаем козырь
		$card_id = db_get_data("SELECT id FROM module_coloda WHERE game_id = ".$game_id." ORDER BY rand() LIMIT 1", "id");

		if ($card_id > 0) {
			$sql = "UPDATE module_coloda SET main = 1 WHERE id = ".$card_id;
			db_query($sql);
		}
	}

	// выдаем колоду
	function getColoda($game_id) {
		$sql = "SELECT t1.id, t1.game_id, t1.card_id, t1.used, t1.main, t2.name FROM module_coloda AS t1 LEFT JOIN module_cards AS t2 ON t1.card_id = t2.id WHERE game_id = ".$game_id." AND used = 0 ORDER BY rand()";
		$result = db_query($sql);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				$response_text[] = $row;
			}
		} else {
			$response_text = array(
                    'error' => 'Колода не создана'
                );
		}

		return $response_text;
	}

	// выдаем карты
	function getCards($game_id, $table_id, $sid) {
		$user_id = db_get_data("SELECT id FROM module_users WHERE session_id = '".$sid."' LIMIT 1", "id");
		
		$i = 0;
		$total = 6;
		$main_card = getMainCard($game_id);

		$result = db_query("SELECT * FROM module_places WHERE table_id = ".$table_id." LIMIT ".$total);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				$i++;

				if ($row['user_id'] == $user_id) {
					$user = db_get_data("SELECT * FROM module_users WHERE id = ".$user_id);

					// $response_text['p'.$i.'IsPlay'] = 1;
					// $response_text['p'.$i.'Balance'] = $user['balance'];
					// $response_text['p'.$i.'Name'] = $user['name'];
					// $response_text['p'.$i.'Avatar'] = $user['avatar'];
					// $response_text['p'.$i.'Place'] = $row['place_id'];
					$response_text['p'.$i.'Card1'] = getCard($game_id, 'p'.$row['place_id'].'Card1', $i);
					$response_text['p'.$i.'Card2'] = getCard($game_id, 'p'.$row['place_id'].'Card2', $i);
					$response_text['p'.$i.'Card3'] = getCard($game_id, 'p'.$row['place_id'].'Card3', $i);
				} else {
					$user = db_get_data("SELECT * FROM module_users WHERE id = ".$row['user_id']);

					// $response_text['p'.$i.'IsPlay'] = 1;
					// $response_text['p'.$i.'Balance'] = $user['balance'];
					// $response_text['p'.$i.'Name'] = $user['name'];
					// $response_text['p'.$i.'Avatar'] = $user['avatar'];
					// $response_text['p'.$i.'Place'] = $row['place_id'];
					$response_text['p'.$i.'Card1'] = 0;
					$response_text['p'.$i.'Card2'] = 0;
					$response_text['p'.$i.'Card3'] = 0;
				}
			}

			if ($i != $total) {
				while ($i != $total) {
					$i++;

					//$response_text['p'.$i.'IsPlay'] = 0;
					//$response_text['p'.$i.'Balance'] = 0;
					//$response_text['p'.$i.'Name'] = '';
					//$response_text['p'.$i.'Avatar'] = '';
					//$response_text['p'.$i.'Place'] = 0;
					$response_text['p'.$i.'Card1'] = 0;
					$response_text['p'.$i.'Card2'] = 0;
					$response_text['p'.$i.'Card3'] = 0;
				}
			}
		} else {
			$response_text = array(
                    'error' => 'В игре нет игроков'
                );
		}

		return $response_text;
	}

	function getCard($game_id, $user, $user_index) {
		$main_card = getMainCard($game_id);

		$sql = "SELECT t1.id, t1.game_id, t1.card_id, t1.used, t1.main, t2.name FROM module_coloda AS t1 LEFT JOIN module_cards AS t2 ON t1.card_id = t2.id WHERE t1.game_id = ".$game_id." AND t1.used = 0 AND t2.name != '".$main_card."' ORDER BY rand() LIMIT 1";
		$result = db_query($sql);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				$value = $row['name'];

				db_query("UPDATE module_coloda SET used = 1, user = '".$user."', user_index = '".$user_index."' WHERE id = ".$row['id']);
			}
		} else {
			$value = "";
		}

		return $value;
	}

	// выводим массив игроков за столом
	function getGameUsers($table_id, $game_id, $start_time) {
		$i = 0;
		$total = 6;
		$players_count = 0;

		$result = db_query("SELECT * FROM module_places WHERE table_id = ".$table_id." LIMIT ".$total);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				$i++;
				$players_count++;

				$user = db_get_data("SELECT * FROM module_users WHERE id = ".$row['user_id']);

				$response_text['p'.$i.'IsPlay'] = 1;
				$response_text['p'.$i.'Balance'] = $user['balance'];
				$response_text['p'.$i.'Name'] = $user['name'];
				$response_text['p'.$i.'Avatar'] = $user['avatar'];
				$response_text['p'.$i.'Place'] = $row['place_id'];
			}

			if ($i != $total) {
				while ($i != $total) {
					$i++;

					$response_text['p'.$i.'IsPlay'] = 0;
					$response_text['p'.$i.'Balance'] = 0;
					$response_text['p'.$i.'Name'] = '';
					$response_text['p'.$i.'Avatar'] = '';
					$response_text['p'.$i.'Place'] = 0;
				}
			}
		} else {
			$response_text = array(
                    'error' => 'В игре нет игроков'
                );
		}

		// записываем общее число фактических игроков
		$sql = "UPDATE module_games SET players_count = ".$players_count.", modify_date = NOW() WHERE id = ".$game_id;
		db_query($sql);

		return $response_text;
	}

	// открываем свою карту
	function sendCard($game_id, $card_name, $turn, $play_users) {
		$card_id = db_get_data("SELECT id FROM module_cards WHERE name = '".$card_name."'", "id");
		$user = db_get_data("SELECT user, user_index FROM module_coloda WHERE game_id = ".$game_id." AND card_id = ".$card_id);
		$game = db_get_data("SELECT players_count, turn FROM module_games WHERE id = ".$game_id);
		
		$next_user = nextUser($user['user_index'], $game['players_count']);
		$next_turn = $game['turn'] + 1;

		db_query("UPDATE module_games SET turn = ".$next_turn.", modify_date = NOW() WHERE id = ".$game_id);
		db_query("UPDATE module_coloda SET card_send_date = NOW(), open = 1 WHERE game_id = ".$game_id." AND card_id = ".$card_id);

		if ($turn != $play_users) {
			$response_text = array(
                    $user['user'] => $card_name,
                    "next_user" => $next_user
                );
		} else {
			$response_text = array(
                    $user['user'] => $card_name,
                    "next_user" => 0
                );

		}		

		return $response_text;
	}

	// следующий игрок
	function nextUser($user_index, $players_count) {
		//echo "user_index: ".$user_index."<br>";
		//echo "players_count: ".$players_count."<br>";

		if ($user_index < $players_count) {
			$next_user = $user_index + 1;
		} else {
			$next_user = 1;
		}

		return $next_user;
	}

	// предыдущий игрок
	function prevUser($user_index, $players_count) {
		//echo "user_index: ".$user_index."<br>";
		//echo "players_count: ".$players_count."<br>";

		if ($user_index > 1) {
			$prev_user = $user_index - 1;
		} else {
			$prev_user = $players_count;
		}

		return $prev_user;
	}

	// получаем козырь
	function getMainCard($game_id) {
		$main_card_id = db_get_data("SELECT card_id FROM module_coloda WHERE main = 1 AND game_id = ".$game_id, "card_id");
		$main_card_name = db_get_data("SELECT name FROM module_cards WHERE id = ".$main_card_id, "name");

		return $main_card_name;
	}

	// определение карты, которую можно скинуть
	function variantCard($user, $game_id, $limit) {
		$i = 0;
		$j = 0;
		$k = 0;
		//$type = 0;

		// получаем козырь
		$main_card = getMainCard($game_id);
		//$main_card = '7-H';
		$main_val = explode("-", $main_card); 
		// echo "Козырь<br>";
		// echo $main_card."<hr>";

		// получаем карты пользователя 
		$data = db_get_array("SELECT t1.card_id, t2.name FROM module_coloda AS t1 LEFT JOIN module_cards AS t2 ON t1.card_id = t2.id WHERE t1.game_id = ".$game_id." AND (t1.user = 'p".$user."Card1' OR t1.user = 'p".$user."Card2' OR t1.user = 'p".$user."Card3') AND t1.open = 0 ORDER BY t1.user", "name");
		//$data = array("12-H", "9-D", "11-S");
		// echo "карты на руках<br>";
		// print_r($data);
		// echo "<hr>";

		// получаем список скинутых карт, сортируем по очередности выкидывания
		$data_db = db_get_array("SELECT t1.card_id, t2.name FROM module_coloda AS t1 LEFT JOIN module_cards AS t2 ON t1.card_id = t2.id WHERE t1.game_id = ".$game_id." AND open = 1 AND round_use = 0 ORDER BY t1.card_send_date DESC LIMIT ".$limit, "name");
		//krsort($data_db);
		
		// echo "карты на столе из базы с сортировкой по дате в обратном порядке<br>";
		// print_r($data_db);
		// echo "<hr>";

		foreach ($data_db as $key => $value) {
			$table_data[$j] = $value;

			$j++;
		}
		
		$table_count = count($table_data);

		// нашли карты по масти
		foreach ($data as $key => $value) {
			$val = explode("-", $value);

			foreach ($table_data as $t_key => $t_value) {
				$t_val = explode("-", $t_value);

				if ($table_count > 0 && $val[1] == $t_val[1]) {
					$count_array[$i] = $value; 
				}
			}

			$i++;
		}
		
		// если карт по масти нет, ищем козыря
		if (count($count_array) == 0) {
			foreach ($data as $key => $value) {
				$val = explode("-", $value);

				if ($table_count > 0 && $val[1] == $main_val[1]) {
					$count_array[$i] = $value; 
				}

				$i++;
			}
		}

		// если нет карт по масти и нет козырей
		if (count($count_array) == 0) {
			foreach ($data as $key => $value) {
				$count_array[$i] = $value; 

				$i++;
			}
		}

		
		foreach ($data as $key => $value) {
			$k++;

			if (in_array($value, $count_array)) {
				$response_text['card'.$k.'Possible'] = $value;
			}
		}

		return $response_text;
	}

	// определяем кто выиграл игру и забрал взятку
	function getGameWinner($game_id, $table_id, $limit) {
		$main_card_exist = 0;
		$i = 0;
		$j = 0;

		// получаем козырь
		$main_card = getMainCard($game_id);
		$main_val = explode("-", $main_card);
		
		// усрщ "Козырь<br>";
		// echo $main_card."<hr>";

		// получаем список скинутых карт, сортируем по очередности выкидывания
		$data_db = db_get_array("SELECT t1.card_id, t2.name FROM module_coloda AS t1 LEFT JOIN module_cards AS t2 ON t1.card_id = t2.id WHERE t1.game_id = ".$game_id." AND open = 1 ORDER BY t1.card_send_date DESC LIMIT ".$limit, "name");
		
		krsort($data_db);
		
		// echo "карты на столе из базы с сортировкой по дате в обратном порядке<br>";
		// print_r($data_db);
		// echo "<hr>";

		foreach ($data_db as $key => $value) {
			$data[$j] = $value;

			$j++;
		}

		// echo "карты на столе<br>";
		// print_r($data);
		// echo "<hr>";

		// логика игры:
		// 1. определеяем есть ли на столе карта с козырем
		// 2. если карта с козырем есть, то смотрим чтобы он был старшим. Кому старший козырь принадлежит, тот и забрал взятку
		// 3. если козыря в игре нет, то смотрим масть карты и ищем старшую карту по масти. 
		// 	  Если есть карта старшей масти, то кому она принадлежит забирает взятку
		// 4. если старшей карты по масти нет, то взятку забирает игрок начавший игру

		foreach ($data as $key => $value) {
			$val = explode("-", $value);

			if ($val[1] == $main_val[1]) {
				$main_card_exist = 1;
				break;
			} 
		}

		//$data = array("6-S", "1-D", "2-D", "6-D", "11-S", "11-D", "10-D");
		//echo "тестовые карты на столе<br>";
		//print_r($data);
		// echo "<hr>";

		// $main_card_exist = 0;
		if ($main_card_exist == 1) {
			foreach ($data as $key => $value) {
				$val = explode("-", $value);

				if ($val[1] == $main_val[1]) {
					$count_array[$i] = $val[0]; 
				}

				$i++;
			}

			$max_value = max($count_array);
			$max_index = array_search($max_value, $count_array);
			
			$winner_card = $data[$max_index];
		} else {
			$main_card = explode("-", $data[0]);

			foreach ($data as $key => $value) {
				$val = explode("-", $value);

				if ($val[1] == $main_card[1]) {
					$count_array[$i] = $val[0]; 
				}

				$i++;
			}

			$max_value = max($count_array);
			$max_index = array_search($max_value, $count_array);
			
			$winner_card = $data[$max_index];
		}

		$card_index = db_get_data("SELECT id FROM module_cards WHERE name = '".$winner_card."'", "id");
		$user_index = db_get_data("SELECT user_index FROM module_coloda WHERE card_id = '".$card_index."' AND game_id = ".$game_id, "user_index");
		
		$win = db_get_data("SELECT MAX(win) FROM module_places WHERE table_id = ".$table_id, "MAX(win)");
		if ($win <= 2) {
			$sql = "UPDATE module_places SET win = win + 1 WHERE place_id = ".$user_index." AND table_id = ".$table_id;
			db_query($sql);
		} else {
			$sql = "UPDATE module_places SET win = 0 WHERE table_id = ".$table_id;
			db_query($sql);
		}

		$sql = "UPDATE module_games SET end = 1, modify_date = NOW() WHERE table_id = ".$table_id;
		db_query($sql);

		db_query("UPDATE module_games SET turn = 1, modify_date = NOW() WHERE id = ".$game_id);

		// отмечаем карты используемы во взятках
		foreach ($data as $key => $value) {
			$card_id = db_get_data("SELECT id FROM module_cards WHERE name = '".$value."'", "id");

			$sql = "UPDATE module_coloda SET round_use = 1 WHERE card_id = ".$card_id." AND game_id = ".$game_id;
			db_query($sql);
		}	

		// записываем общий баланс игры за столом
		$total_bet = 0;
		$result = db_query("SELECT bet FROM module_bets WHERE place_id != 0 AND game_id = ".$game_id);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				$total_bet = $total_bet + $row['bet'];
			}
		}

		$sql = "UPDATE module_tables SET bank = ".$total_bet." WHERE id = ".$table_id;
		db_query($sql);

		$response_text = array(
                    "card_winner" => $winner_card,
                    "round_winner" => $user_index,
                    "next_user" => $user_index
                );

		return $response_text;
	}

	// определяем победителя
	function getWinner($table_id) {
		$win = db_get_data("SELECT * FROM module_places WHERE table_id = ".$table_id." AND win >= 2");
		
		$balance = db_get_data("SELECT balance FROM module_users WHERE id = '".$win['user_id']."' LIMIT 1", "balance");
        $bank = db_get_data("SELECT bank FROM module_tables WHERE id = ".$table_id, "bank");
        
        $new_balance = intval($balance + $bank);

		$response_text = array(
                    "winner" => $win['place_id']
                );

		return $response_text;
	}

	// делаем ставку
	function makeBet($bet, $game_id, $place_id, $sid, $table_id) {
		// echo $bet.' = '.$game_id.' = '.$place_id.' = '.$sid.' = '.$table_id."<br>";
		$balance = db_get_data("SELECT balance FROM module_users WHERE session_id = '".$sid."' LIMIT 1", "balance");
		$players_count = getPlaceCount($table_id);

		$next_user = nextUser($place_id, $players_count);

		if ($balance >= $bet) {
			// определяем начальную ставку, так как текущая ставка не может быть меньше предыдущей
			$start_bet = db_get_data("SELECT MAX(bet) FROM module_bets WHERE place_id != 0 AND game_id = ".$game_id, "MAX(bet)");

			if ($bet >= $start_bet) {
				if ($bet == $start_bet) {  
					// если текущая ставка равна предыдущей, игрок хочет заровнять ставку				
					$new_balance = $balance - $bet; 
						
					$sql = "UPDATE module_users SET balance = ".$new_balance." WHERE session_id = '".$sid."'";
					db_query($sql);

					$sql = "INSERT INTO module_bets SET date = NOW(), game_id = ".$game_id.", place_id = ".$place_id.", bet = ".$bet;
					db_query($sql);

					$pot = db_get_data("SELECT SUM(bet) FROM module_bets WHERE place_id != 0 AND game_id = ".$game_id, "SUM(bet)");

					$new_start_bet = db_get_data("SELECT MAX(bet) FROM module_bets WHERE game_id = ".$game_id, "MAX(bet)");

					$table_pot = tablePot($pot, $table_id);

					$response_text = array(
		                    	"currentBet" => $new_start_bet,
		                    	"p".$place_id."Bet" => $bet,
		                    	"pot" => $pot,
		                    	"next_bet_user" => $next_user,
		                    	"p".$place_id."Balance" => $new_balance
		                	);
				} else {
					$allow_bet = $start_bet * 2;

					if ($bet >= $allow_bet) {
						$new_balance = $balance - $bet;
						
						$sql = "UPDATE module_users SET balance = ".$new_balance." WHERE session_id = '".$sid."'";
						db_query($sql);

						$sql = "INSERT INTO module_bets SET date = NOW(), game_id = ".$game_id.", place_id = ".$place_id.", bet = ".$bet;
						db_query($sql);	

						$new_start_bet = db_get_data("SELECT MAX(bet) FROM module_bets WHERE game_id = ".$game_id, "MAX(bet)");

						$pot = db_get_data("SELECT SUM(bet) FROM module_bets WHERE place_id != 0 AND game_id = ".$game_id, "SUM(bet)");

						$table_pot = tablePot($pot, $table_id);

						$response_text = array(
		                    	"currentBet" => $new_start_bet,
		                    	"p".$place_id."Bet" => $bet,
		                    	"pot" => $pot,
		                    	"next_bet_user" => $next_user,
		                    	"p".$place_id."Balance" => $new_balance
		                	);
					} else {
						$response_text = array(
		                    	'error' => 'При повышении ставки, ставка должно быть минимум в два раза больше текущей'
		                	);
					}
				}
			} else {
				$response_text = array(
	                    'error' => 'Ставка не может быть меньше предыдущей'
	                );
			}
		} else {
			$response_text = array(
	                'error' => 'Не достаточно средств'
	            );
		}

		return $response_text;
	}

	// заканчиваем игру
	function endGame($game_id, $table_id) {
		$sql = "UPDATE module_games SET start = 0, end = 1, modify_date = NOW() WHERE id = ".$game_id;
        db_query($sql);

        $sql = "UPDATE module_places SET win = 0 WHERE table_id = ".$table_id;
        db_query($sql);

        $response_text = array(
	            'game_id' => $game_id,
	            'table_id' => $table_id
	        );

        return $response_text;
	}

	// проверяем все ли ставки сделаны
	function endBet($game_id, $table_id, $user_index, $bet) {
		$players_count = getPlaceCount($table_id);

		$next_user = nextUser($user_index, $players_count);
		$prev_user = prevUser($user_index, $players_count);

		$max_bet_place_id = db_get_data("SELECT place_id FROM module_bets WHERE place_id != 0 AND def_bet = 0 AND game_id = ".$game_id." ORDER BY date ASC LIMIT 1", "place_id");
		$max_bet = db_get_data("SELECT bet FROM module_bets WHERE place_id != 0 AND def_bet = 0 AND game_id = ".$game_id." ORDER BY bet DESC LIMIT 1", "bet");
		
		if ($bet > $max_bet) {
			$max_bet_place_id = $user_index;
		} 

		$prev_user_bet = db_get_data("SELECT bet FROM module_bets WHERE place_id = ".$prev_user." AND def_bet = 0 AND game_id = ".$game_id." ORDER BY date DESC LIMIT 1", "bet");

		// echo "==========\n";
		// echo $next_user." == ".$max_bet_place_id." && ".$prev_user_bet." == ".$max_bet."\n";
		// echo "==========\n";

		if ($next_user == $max_bet_place_id && $prev_user_bet == $max_bet) {
			$response_text = 1;
		} else {
			$response_text = 0;
		}

		return $response_text;
	}

	function isAzi($table_id) {
		$count = db_get_data("SELECT SUM(win) FROM module_places WHERE table_id = 1 LIMIT 1", "SUM(win)");
	 	$win_count = db_get_data("SELECT MAX(win) FROM module_places WHERE table_id = ".$table_id." LIMIT 1", "MAX(win)");

	 	// echo "==========\n";
		// echo $count." == 3 && ".$win_count." != 2\n";
		// echo "==========\n";

		if ($count == 3 && $win_count != 2) {
 			$response_text = 1;
		} else {
			$response_text = 0;
		}

		return $response_text;
	}

	function tablePot($pot, $table_id) {
		$table_pot = db_get_data("SELECT bank FROM module_tables WHERE id = ".$table_id, "bank");
		$new_pot = $table_pot + $pot;

		$sql = "UPDATE module_tables SET pot = ".$new_pot." WHERE id = ".$table_id;
		db_query($sql);

		$response_text = $new_pot;

		return $response_text;
	}
?>