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
    'target' => '127.0.0.1:81', // target host
    'maxMemory' => 4096,    // max buffer size for both input/output 
                            // (in kilobytes)
                            // Note that memory used can be twice this size
                            // (for input and output) + internal php usage
    'binary' => false, // if plain log files on input, set to false.
                       // If binary = false, lines will be sent fully
    'compression' => true, // will compress output with gzip
    'compressionLevel' => 4, // GZIP Level. Impact on CPU
    'readSize' => 4096*4, // in bytes
    'writeSize' => 4096*8,
);

$logStreamer = new logStreamer(
    $config, 
    'php://stdin', 
    'tcp://'.$config['target']
);

while (true) {

    $logStreamer->read();
    $logStreamer->write();
    
    // test connection
    if ($logStreamer->feofOutput() === true) {
        $logStreamer->open('write', 'tcp://'.$config['target']);
    }
    
    // if no more data on input, we can stop
    if ($logStreamer->feof() === true) break;
    
    // @todo intercept signals to write Statistics somewhere
    
    usleep(1000);
}
// final write
$logStreamer->write(true);
// @todo : if distant host not available, 
//         we should retry, but how many times ?

if (DEBUG) var_dump($logStreamer->getStats());



class logStreamer
{
    protected $_input;
    protected $_stream;
    protected $_errno;
    protected $_errstr;
    protected $_uncompressedBuffer;
    protected $_uncompressedBufferLen;
    protected $_buffer;
    protected $_bufferLen;
    protected $_config;
    protected $_stats;
    
    
    public function __construct($config, $urlinput = false, $urloutput = false)
    {
        $this->_stream = false;
        $this->_config = $config;
        $this->_bufferLen = 0;
        $this->_buffer = '';
        $this->_uncompressedBuffer = '';
        $this->_uncompressedBufferLen = 0;
        $this->_stats = array (
            'inputDataDiscarded' => 0,
            'readErrors'         => 0,
            'writeErrors'        => 0,
            'outputConnections'  => 0,
            'readBytes'          => 0,
            'writtenBytes'       => 0,
        );
        
        if ($urlinput !== false) $this->open('read',  $urlinput);
        if ($urloutput !== false) $this->open('write', $urloutput);
        
        // @todo check config
        // check read at least 4096 bytes w/ compression (or useless)

    }
    
    /**
     * Open distant stream
     */
    public function open($action, $url)
    {
        if ($action != 'read' && $action != 'write') {
            // big error
            return false;
        }
        
        if ($action == 'read') {
            $stream = fopen($url, 'r');
        } else {
            $stream = @stream_socket_client(
                $url, 
                $this->_errno, 
                $this->_errstr,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
        }
        if (is_resource($stream))
            stream_set_blocking($stream, 0);
        
        if ($action == 'read') {
            $this->_input = $stream;
        } else {
            $this->_stats['outputConnections']++;
            $this->_stream = $stream;
        }
    }
    
    /**
     * Read data
     * @return bool  false if any error, true if 0 or more bytes read
     */
    public function read()
    {        
        if (feof($this->_input)) return false;
        $str = @fread($this->_input, $this->_config['readSize']);

        if ($str === false) {
            // read error
            $this->_stats['readErrors']++;
            return false;
        }
        
        $len = strlen($str);
        
        // Add to buffer ?
        if ($this->_config['maxMemory']*1024 < $this->_bufferLen) {
            // Discard data
            $this->_stats['inputDataDiscarded']+= $len;
            return false;
        }
        
        if ($len > 0) {
            if ($this->_config['compression'] === false) {
                $this->_bufferLen += $len;
                $this->_buffer .= $str;
                $this->_stats['readBytes'] += $len;
                
            } else {
                // More difficult ...
                $this->_uncompressedBufferLen += $len;
                $this->_uncompressedBuffer .= $str;
                $this->_stats['readBytes'] += $len;
                
                // Try to compress data
                if ($this->_uncompressedBufferLen > $this->_config['readSize']) {
                
                    if ($this->_config['binary'] === true) {
                        $pos = $this->_config['readSize'];
                    } else {
                        $pos = @strpos(
                            $this->_uncompressedBuffer, 
                            "\n", 
                            $this->_config['readSize']
                        );
                    }
                    if ($pos > 0) {
                        // note: gzencode adds a header compatible with Gzip.
                        // overrhead is ~ 0.5% when compressing 64KB of data
                        $compressData = gzencode(
                            substr($this->_uncompressedBuffer, 0, $pos), 
                            $this->_config['compressionLevel']
                        );
                        if ($compressData !== false) {
                            $this->_uncompressedBufferLen -= $pos;
                            $this->_uncompressedBufferLen = substr(
                                $this->_uncompressedBufferLen, 
                                $pos
                            );
                            $this->_buffer .= $compressData;
                            $this->_bufferLen += strlen($compressData);
                            
                        }
                    }
                }
            }
        }
        return true;
    }
    
    public function feof()
    {
        return feof($this->_input);
    }
    public function feofOutput()
    {
        if ($this->_stream === false) return false;
        return feof($this->_stream);
    }
    
    public function write($force = false)
    {
        if ($this->_bufferLen === 0) return true; // nothing to write
        
        if ($this->_config['binary']      === false && 
            $this->_config['compression'] === false) {
            
            // We get old lines so get position of near \n
            $pos = @strpos($this->_buffer, "\n", $this->_config['writeSize']);
            
            if ($pos === false && $force === false) {
                // no return line. Write nothing
                return false;
            }
            
        } else {
            // Get X bytes
            $pos = $this->_config['writeSize'];
            
        }
        
        // @todo Handle compression
        $bytesWritten = @fwrite($this->_stream, $this->_buffer, $pos);
        
        if ($bytesWritten === false) {
            // write error
            $this->_stats['writeErrors']++;
            return false;
        }
        
        if ($bytesWritten > 0) {
            $this->_bufferLen -= $bytesWritten;
            $this->_stats['writtenBytes'] += $bytesWritten;
            
            // @todo maybe next line could be optimized ?
            $this->_buffer = substr($this->_buffer, $bytesWritten);
        }
        
        return true;    
    }
    
    /** 
     * Returns statistics array
     * @return array
     **/
    public function getStats()
    {
        $this->_stats['bufferSize'] = $this->_bufferLen;
        return $this->_stats;
    }

}
