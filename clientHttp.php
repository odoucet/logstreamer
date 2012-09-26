#/usr/bin/env php
<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**********************
 * Log Streamer PHP
 * @version 0.0.1
 * @author Olivier Doucet <odoucet@php.net>
 *
 */

define('DEBUG', 1);

// @todo Config file ?
$config = array (
    'target' => 'tcp://195.60.189.9:80/test.php', // target host
    'maxMemory' => 4096,    // max buffer size for both input/output 
                            // (in kilobytes)
                            // Note that memory used can be twice this size
                            // (for input and output) + internal php usage
    'binary' => false, // if plain log files on input, set to false.
                       // If binary = false, lines will be sent fully
    'compression' => false,  // will compress output with gzip
    'compressionLevel' => 4, // GZIP Level. Impact on CPU
    'readSize' => 4096*4,    // in bytes
    'writeSize' => 4096*8,
);

if (!class_exists('logStreamerHttp'))
    require 'logstreamerhttp.class.php';

$logStreamer = new logStreamerHttp(
    $config, 
    'php://stdin', 
    $config['target']
);

while (true) {

    $logStreamer->read();
    $logStreamer->write();
    
    // if no more data on input, we can stop
    if ($logStreamer->feof() === true) break;
    
    // @todo intercept signals to write Statistics somewhere
    
    usleep(1000);
}
// final write
$i = 0;
echo "Finished ! Flushing buffers ! \n";
do {
    $bytesWritten = $logStreamer->write(true);
    if ($bytesWritten == 0)
        $i++;
    echo '.';
    usleep(10000);
} while ($logStreamer->dataLeft() > 0 && $i < 100);

// @todo : if distant host not available, 
//         we should retry, but how many times ?
echo "\n";
if (DEBUG) var_dump($logStreamer->getStats());
