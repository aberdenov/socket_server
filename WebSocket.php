<?php
    namespace Socket;

    class WebSocket {
        private $host;
        private $port;
        private $clients;
        private $timeout;
        private $events;
        private $log_file = 'log.txt';

        public function __construct($host, $port) {
            $this->host = $host;
            $this->port = $port;
        }

        public function setTimeout($seconds) {
            $this->timeout = $seconds;
        }

        public function on($event, $callback, $res = '') {
            $this->events[$event] = $callback;
        }

        public function run() {
            $null = NULL;
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_bind($socket, $this->host, $this->port);
            socket_listen($socket);
            socket_set_nonblock($socket);

            $this->clients = array($socket);

            // бесконечный цикл
            while (true) {
                $changed = $this->clients;
                socket_select($changed, $null, $null, 0, 10);

                // проверка на новый сокет
                if (in_array($socket, $changed)) {
                    if (($socket_new = socket_accept($socket)) !== false) {
                        $this->clients[] = $socket_new;

                        $header = socket_read($socket_new, 1024);

                        if ($this->handshake($header, $socket_new) === false) {
                            continue;
                        }

                        socket_getpeername($socket_new, $ip);

                        if (isset($this->events['open'])) {
                            $this->events['open']($this, $ip, $socket);
                        }

                        $found_socket = array_search($socket, $changed);
                        unset($changed[$found_socket]);
                    }
                }

                foreach ($changed as $changed_socket) {
                    // проверяем на входящие данные
                    $buf = socket_read($changed_socket, 1024);
                    $buf = $this->unmask($buf);

                    if (strlen($buf) == 6) {   
                        $payload = str_split(sprintf('%016b', "1000"), 8);
                        $payload[0] = chr(bindec($payload[0]));
                        $payload[1] = chr(bindec($payload[1]));
                        $payload = implode('', $payload);

                        $response = $this->hybi10Encode($payload, "close", false);

                        $this->save_log($response);

                        socket_write($changed_socket, $response, strlen($response));

                        $found_socket = array_search($changed_socket, $this->clients);
                        
                        socket_getpeername($changed_socket, $ip);
                        socket_close($changed_socket);
                        unset($this->clients[$found_socket]);                        
                        
                        if (isset($this->events['close'])) {
                           $this->events['close']($this, $ip, $socket);                            
                        }
                    } else {
                        $data = json_decode($buf, true);

                        if (isset($this->events['message'])) {
                            $this->events['message']($this, $data, $changed_socket);
                        }
                    }
                }

                if ($this->timeout) {
                    sleep($this->timeout);
                }
            }

            socket_close($socket);
        }

        public function send($message) {
            $response = $this->mask(json_encode($message));

            foreach($this->clients as $changed_socket) {
                socket_write($changed_socket, $response, strlen($response));
            }

            return true;
        }

        public function sendCurrent($message, $found_socket) {
            $response = $this->mask(json_encode($message));
            
            socket_write($found_socket, $response, strlen($response));

            return true;
        }

        public function save_log($message) {
            $fd = fopen($this->log_file, 'a+');

            fwrite($fd, $message);
            fclose($fd);
        }

        private function unmask($text) {
            $length = ord($text[1]) & 127;

            if ($length == 126) {
                $masks = substr($text, 4, 4);
                $data = substr($text, 8);
            } elseif ($length == 127) {
                $masks = substr($text, 10, 4);
                $data = substr($text, 14);
            } else {
                $masks = substr($text, 2, 4);
                $data = substr($text, 6);
            }

            $text = '';

            for ($i = 0; $i < strlen($data); ++$i) {
                $text .= $data[$i] ^ $masks[$i%4];
            }

            return $text;
        }

        private function mask($text) {
            $b1 = 0x80 | (0x1 & 0x0f);
            $length = strlen($text);
            $header = '';

            if($length <= 125) {
                $header = pack('CC', $b1, $length);
            } elseif($length > 125 && $length < 65536) {
                $header = pack('CCn', $b1, 126, $length);
            } elseif($length >= 65536) {
                $header = pack('CCNN', $b1, 127, $length);
            }

            return $header . $text;
        }

        private function handshake($receivedHeader, $clientConn) {
            $headers = array();
            $lines = preg_split("/\r\n/", $receivedHeader);

            foreach($lines as $line) {
                $line = chop($line);

                if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                    $headers[$matches[1]] = $matches[2];
                }
            }

            if (isset($this->events['handshake'])) {
                if ($this->events['handshake']($this, $headers) === false) {
                    return false;
                }
            }

            $secKey = $headers['Sec-WebSocket-Key'];
            $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

            $upgrade =
                "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
                "Upgrade: websocket\r\n" .
                "Connection: Upgrade\r\n" .
                "WebSocket-Origin: $this->host\r\n" .
                "Sec-WebSocket-Accept: $secAccept\r\n\r\n";

            socket_write($clientConn, $upgrade, strlen($upgrade));
        }

        private function hybi10Decode($data) {
            $payloadLength = '';
            $mask = '';
            $unmaskedPayload = '';
            $decodedData = array();
            
            // estimate frame type:
            $firstByteBinary = sprintf('%08b', ord($data[0]));      
            $secondByteBinary = sprintf('%08b', ord($data[1]));
            $opcode = bindec(substr($firstByteBinary, 4, 4));
            $isMasked = ($secondByteBinary[0] == '1') ? true : false;
            $payloadLength = ord($data[1]) & 127;
            
            // close connection if unmasked frame is received:
            if ($isMasked === false) {
                $this->close(1002);
            }
            
            switch($opcode) {
                // text frame:
                case 1:
                    $decodedData['type'] = 'text';              
                break;
            
                case 2:
                    $decodedData['type'] = 'binary';
                break;
                
                // connection close frame:
                case 8:
                    $decodedData['type'] = 'close';
                break;
                
                // ping frame:
                case 9:
                    $decodedData['type'] = 'ping';              
                break;
                
                // pong frame:
                case 10:
                    $decodedData['type'] = 'pong';
                break;
                
                default:
                    // Close connection on unknown opcode:
                    $this->close(1003);
                break;
            }
            
            if ($payloadLength === 126) {
                $mask = substr($data, 4, 4);
                $payloadOffset = 8;
                $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
            } elseif($payloadLength === 127) {
                $mask = substr($data, 10, 4);
                $payloadOffset = 14;
                $tmp = '';
                
                for ($i = 0; $i < 8; $i++) {
                    $tmp .= sprintf('%08b', ord($data[$i+2]));
                }
                
                $dataLength = bindec($tmp) + $payloadOffset;
                
                unset($tmp);
            } else {
                $mask = substr($data, 2, 4);    
                $payloadOffset = 6;
                $dataLength = $payloadLength + $payloadOffset;
            }
            
            /**
             * We have to check for large frames here. socket_recv cuts at 1024 bytes
             * so if websocket-frame is > 1024 bytes we have to wait until whole
             * data is transferd. 
             */
            if (strlen($data) < $dataLength) {           
                return false;
            }
            
            if ($isMasked === true) {
                for ($i = $payloadOffset; $i < $dataLength; $i++) {
                    $j = $i - $payloadOffset;
                    
                    if (isset($data[$i])) {
                        $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                    }
                }
                
                $decodedData['payload'] = $unmaskedPayload;
            } else {
                $payloadOffset = $payloadOffset - 4;
                $decodedData['payload'] = substr($data, $payloadOffset);
            }
            
            return $decodedData;
        }

        private function hybi10Encode($payload, $type = 'text', $masked = true) {
            $frameHead = array();
            $frame = '';
            $payloadLength = strlen($payload);
            
            switch ($type) {       
                case 'text':
                    // first byte indicates FIN, Text-Frame (10000001):
                    $frameHead[0] = 129;                
                break;          
            
                case 'close':
                    // first byte indicates FIN, Close Frame(10001000):
                    $frameHead[0] = 136;
                break;
            
                case 'ping':
                    // first byte indicates FIN, Ping frame (10001001):
                    $frameHead[0] = 137;
                break;
            
                case 'pong':
                    // first byte indicates FIN, Pong frame (10001010):
                    $frameHead[0] = 138;
                break;
            }
            
            // set mask and payload length (using 1, 3 or 9 bytes) 
            if ($payloadLength > 65535) {
                $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
                $frameHead[1] = ($masked === true) ? 255 : 127;
                
                for ($i = 0; $i < 8; $i++) {
                    $frameHead[$i+2] = bindec($payloadLengthBin[$i]);
                }
                
                // most significant bit MUST be 0 (close connection if frame too big)
                if ($frameHead[2] > 127) {
                    $this->close(1004);
                    return false;
                }
            } elseif ($payloadLength > 125) {
                $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
                $frameHead[1] = ($masked === true) ? 254 : 126;
                $frameHead[2] = bindec($payloadLengthBin[0]);
                $frameHead[3] = bindec($payloadLengthBin[1]);
            } else {
                $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
            }

            // convert frame-head to string:
            foreach (array_keys($frameHead) as $i) {
                $frameHead[$i] = chr($frameHead[$i]);
            }
            
            if ($masked === true) {
                // generate a random mask:
                $mask = array();
                for ($i = 0; $i < 4; $i++) {
                    $mask[$i] = chr(rand(0, 255));
                }
                
                $frameHead = array_merge($frameHead, $mask);            
            }                       
            
            $frame = implode('', $frameHead);
            
            // append payload to frame:
            $framePayload = array();    
            for($i = 0; $i < $payloadLength; $i++) {       
                $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
            }

            return $frame;
        }
    }
?>
