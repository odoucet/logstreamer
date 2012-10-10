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
    'target' => 'tcp://127.0.0.1:80/test.php', // target host
    'maxMemory' => 4096,    // max buffer size for both input/output 
                            // (in kilobytes)
                            // Note that memory used can be twice this size
                            // (for input and output) + internal php usage
    'binary' => true,       // if plain log files on input, set to false.
                            // If binary = false, lines will be sent fully
    'compression' => true, // will compress output with gzip
    'compressionLevel' => 6,// GZIP Level. Impact on CPU
    'readSize' => 4096*4,   // in bytes
    'writeSize' => 4096*32,
);

if (!class_exists('logStreamerHttp')) require 'logstreamerhttp.class.php';

$logStreamer = new logStreamerHttp(
    $config,
    $config['target']
);

$lastPrint = time();
while (true) {

    $logStreamer->read();
    $logStreamer->write();
    
    // if no more data on input, we can stop
    if ($logStreamer->feof() === true) break;
    
    // @todo intercept signals to write Statistics somewhere
    if (time() != $lastPrint) {
        $lastPrint = time();
        $infos = $logStreamer->getStats();
        printf("Read: %6.1f MB Write: %6.1f MB  Discarded: %6.1f MB  BufferSize: %5.0f + %4.0f KB  Memory: %4.1f MB (peak: %4.1f MB)\r", 
            $infos['readBytes']/1024/1024, 
            $infos['writtenBytes']/1024/1024,
            $infos['dataDiscarded']/1024/1024,
            $infos['bufferSize']/1024,
            $infos['writeBufferSize']/1024/1024,
            memory_get_usage(true)/1024/1024, 
            memory_get_peak_usage(true)/1024/1024
        );
    }
    
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
// end
$logStreamer->write(true, true);

// @todo : if distant host not available, 
//         we should retry, but how many times ?
echo "\n";
if (DEBUG) var_dump($logStreamer->getStats());
