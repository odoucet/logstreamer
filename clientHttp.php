#!/usr/bin/env php
<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**********************
 * Log Streamer PHP
 * @version 0.0.2
 * @author Olivier Doucet <odoucet@php.net>
 * @author Gabriel Barazer <gabriel@oxeva.fr>
 */

define('DEBUG', 1);

if (version_compare(PHP_VERSION, '5.3.0') < 0) {
    declare(ticks = 1);
}

// @todo Config file ?
$config = array (
    // target host
    'remoteUrl'        => 'http://127.0.0.1/test.php',  
    
    // max buffer size for both input/output
    // Note that memory used can be twice this size
    // (for input and output) + internal php usage
    'maxMemory'        => '32M',                         
    
    // if plain log files on input, set to false.
    // If binary = false, lines will be sent fully
    'binary'           => false,                        
    
    // will compress output with gzip    
    'compression'      => true,    

    // GZIP Level. Impact on CPU
    'compressionLevel' => 6,
    
    // Buckets will be this size long (before compression)
    'writeSize'        => '128K',
    
    // time to wait in seconds when remote server reports a failure with non-200 HTTP code
    'throttleTimeOnFail'   => 5,

    // Time in seconds before sending data even if the current bucket is not full
    'bufferLifetimeBeforeFlush' => 60,
    
    // Maximum time in seconds to wait before making a reconnection 
    // (if server is really too slow)
    'maxWriteTimeout' => 120,
);

if (!class_exists('logStreamerHttp')) require 'logstreamerhttp.class.php';

$logStreamer = new logStreamerHttp($config);
$bytesRead   = 0;
pcntl_signal(SIGUSR1, array(&$logStreamer, 'printStatus'));

$lastPrint = time();
while (true) {

    if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
        pcntl_signal_dispatch();
    }

    if (($bytesRead = $logStreamer->read()) === false) break;
    $logStreamer->write();

    if (DEBUG && time() != $lastPrint) {
        $lastPrint = time();
        $infos = $logStreamer->getStats();
        printf(
            "Read: %6.1f MB  Write: %6.1f MB  Discarded: %6.1f MB  ".
            "BufferSize: %5.0f + %4.0f KB (bucketsLen+writeBufferLen) ".
            "Memory: %4.1f MB (peak: %4.1f MB)\r", 
            $infos['readBytes']/1024/1024, 
            $infos['writtenBytes']/1024/1024,
            $infos['dataDiscarded']/1024/1024,
            $infos['bucketsLen']/1024,
            $infos['writeBufferLen']/1024/1024,
            memory_get_usage(true)/1024/1024, 
            memory_get_peak_usage(true)/1024/1024
        );
    }
    if ($bytesRead === 0)
        usleep(10000); // wait a little more if no data read
    else
        usleep(1000);
}

//No more data on input. Grab infos and print them with trigger_error
$logStreamer->printStatus();

/*
 * logStreamer->flush() is synchronous and only exits when all bytes are written 
 * to the output stream. It may be modified to asynchronous if we want to follow
 * the flush progress, but that is unlikely.
 */
$logStreamer->flush();


if (DEBUG) {
    echo "\n";
    var_dump($logStreamer->getStats());
}
