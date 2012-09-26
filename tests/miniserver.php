<?php
// Mini server
file_put_contents('server.pid', getmypid());

//$argv[1] = 'tcp://127.0.0.1:27010';
if (!isset($argv[1])) {
    exit('argv1 required (server socket) like "tcp://127.0.0.1:27010"');
}
chdir(dirname(__FILE__));

$socket = stream_socket_server($argv[1], $errno, $errstr);
if (!is_resource($socket)) exit('cannot create socket server on '.$argv[1]);
stream_set_blocking($socket, 0);

while (true) {
    $conn = @stream_socket_accept($socket, 0);
    if ($conn === false) {
        usleep(10000);
        continue;
    }
    $tmpData = '';
    
    // We need header
    while (!feof($conn)) {
        $tmpData .= fread($conn, 4096);
        
        // Analyse
        if (($pos = strpos($tmpData, "\r\n\r\n")) !== false) {
            // parse header
            $z = preg_match('@Content-Length: ([0-9]{1,})@', $tmpData);
            if (!$z) {
                fwrite($conn, 'HTTP/1.1 500 No-Content-Length'."\r\n");
                fclose($conn);
                break;
            }
            $contentLength = $pos[1];
            
            stream_set_blocking($conn, 0);
            
            $tmpData = substr($tmpData, $pos+4); // get content
            while (strlen($tmpData) < $contentLength) {
                $tmpData .= fread($conn, 4096);
                usleep(10000);
            }
            
            // write
            file_put_contents('resultfile.bin', $tmpData, FILE_APPEND);
            fwrite($conn, "HTTP/1.1 200 OK\r\n\r\nEverything fine\r\n\r\n");
            fclose($conn);
            break;
        }
        usleep(1000);
    }
    fclose($conn);
    usleep(10000);
}
fclose($socket);
