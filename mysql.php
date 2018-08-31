<?php
	// Устанавливает соединение с базой. В случае ошибки выводится уведомление.
	function db_connect($db_host, $db_login, $db_password) {
		if (!mysql_connect($db_host, $db_login, $db_password)) {
			echo "<br>
					<center style='font-family: Verdana; font-size: 12px;'>
						Error in connecting to database server.<br>
						Auto reload in&nbsp;<span id='reloadTime'></span>&nbsp;seconds<br>
						<a href='' onClick='window.location.reload(); return false;' style='color: black;'>Reload Now</a><br>
						
					</center>
					<script language='JavaScript'>
						var secondsRemain = 60;
						function deferedReload() {
							if (secondsRemain == 0)
								window.location.reload();
							else {
								window.document.all['reloadTime'].innerText = secondsRemain;
								secondsRemain--;
							}
						}
						deferedReload();
						window.setInterval('deferedReload()', 1000);
					</script>
				<br>";
			exit();
//			echo "<br><b>Error #: </b>" . db_errno() . "<br>";
//			echo "<br><b>Error message: </b>" . db_error() . "<br>";
		} else {
			$query = 'SET NAMES utf8';
			db_query($query);
			date_default_timezone_set("Asia/Almaty");
		}
	}

	function db_select_db($db_name) {
		if (!mysql_select_db($db_name)) {
			echo "<br><b>Error in selecting database</b><br>";
			echo "<br><b>Error #: </b>" . db_errno() . "<br>";
			echo "<br><b>Error message: </b>" . db_error() . "<br>";
			exit;
		}
	}

	function db_query($query, $hide_errors = false) {
		echo $query."\n";
		global $_debug;
		
		if (!$result = mysql_query($query)) {
			if (!$hide_errors) {
				echo '<small><br><b>'.$query.'</b><br>';
				echo '<br><b>Error in executing query</b><br>';
				echo '<br><b>Error #: </b>'.db_errno().'<br>';
				echo '<br><b>Error message: </b>'.db_error().'<br></small>';
			}
			
			return false;
		} else {
			if (isset($_debug)) $_debug->sql_log($query);
			return $result;
		}
	}

	function db_table_count($table, $where) {
		if (!empty($where)) $sql = "SELECT COUNT(*) FROM ".$table." WHERE ".$where; 
			else $sql = "SELECT COUNT(*) FROM ".$table;
		
		if (!$result = mysql_query($sql)) {
			return -1;
		} else {
			$count = mysql_fetch_array($result);
			return $count[0];
		}
	}

	function db_errno() {
		return mysql_errno();
	}

	function db_error() {
		return mysql_error();
	}

	function db_num_rows($result) {
		return mysql_num_rows($result);
	}

	function db_num_fields($result) {
		return mysql_num_fields($result);
	}

	function db_fetch_object($result) {
		return mysql_fetch_object($result);
	}

	function db_fetch_array($result) {
		return mysql_fetch_array($result);
	}
	
	function db_fetch_assoc($result) {
		return mysql_fetch_assoc($result);
	}

	function db_insert_id() {
		return mysql_insert_id();
	}

	function db_list_tables() {
		return mysql_list_tables(DB_NAME);
	}

	function db_list_fields($tableName) {
		return mysql_list_fields(DB_NAME, $tableName);
	}

	function db_field_name($result, $i) {
		return mysql_field_name($result, $i);
	}

	function db_field_type($result, $i) {
		return mysql_field_type($result, $i);
	}

	function db_tablename($result, $i) {
		return mysql_tablename($result, $i);
	}

	function db_affected_rows() {
		return mysql_affected_rows();
	}

	function db_free_result($result){
		return mysql_free_result($result);
	}

	function db_table_exists($tablename) {
		$result = mysql_list_tables(DB_NAME);
		if (db_num_rows($result) > 0) {
			for ($i = 0; $i < mysql_num_rows($result); $i++) {
				if (mysql_tablename($result, $i) == $tablename) {
					mysql_free_result($result);
					return true;
				}
			}
			
			mysql_free_result($result);
		}
		
		return false;
	}

	function db_field_exists($tableName, $fieldName) {
		$result = mysql_query("DESCRIBE ".$tableName." ".$fieldName);
		if (mysql_num_rows($result) > 0) 
			return 1;
		 else 
		 	return 0;
	}

	function db_get_data($sql, $field = '') {
		$result = db_query($sql);
		if (db_num_rows($result) > 0) {
			$row = db_fetch_array($result);
			db_free_result($result);
			if ($field == '') return $row; else	return $row[$field];
		}
		return false;
	}

	function db_get_array($sql, $field1, $field2 = '') {
		$records = array();
		$result = db_query($sql);
		if (db_num_rows($result) > 0) {
			while ($row = db_fetch_array($result)) {
				if ($field1 && $field2 && isset($row[$field1]) && isset($row[$field2])) $records[$row[$field1]] = $row[$field2];
					else $records[] = $row[$field1];
			}
			
			db_free_result($result);
		}
		return $records;
	}

	function db_sql_where($param_array, $operand) {
		$condition_array = array();
		foreach ($param_array as $param) {
			if ($param != '') $condition_array[] = $param;
		}
		
		$sql = implode(" ".$operand." ", $condition_array);
		// if ($sql != '') $sql = ' WHERE '.$sql;
		
		return $sql;
	}

	function db_check_connection($db_host, $db_login, $db_password, $db_name) {
		if (!$result = mysql_connect($db_host, $db_login, $db_password)) {
			return -1;
		} else {
			if (!mysql_select_db($db_name)) return -2;
			mysql_close($result);
		}
		
		return 0;
	}
	
	function db_query_ex($query) {
		global $SQL_LOG;
		
		if (!$result = mysql_query($query)) {
			return false;
		} else {
			if (CONFIG_SQL_LOGGING) {
				$SQL_LOG[] = array("time" => date("H:i:s"), "query" => $query);
			}
			
			return $result;
		}
	}
?>