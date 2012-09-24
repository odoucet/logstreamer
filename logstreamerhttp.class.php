<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**
 * logStreamer on HTTP class
 * @author Olivier Doucet <odoucet@php.net>
 */
class logStreamerHttp
{
    const VERSION = '1.0 (2012-09-24)';
    protected $_input;
    protected $_stream;
    protected $_errno;
    protected $_errstr;
    protected $_uncompressedBuffer;
    protected $_uncompressedBufferLen;
    
    /**
     * @var array "buckets" to send. Already compressed
     */
    protected $_buffer;
    
    /** 
     * @var Size of all buffers aggregated
     */
    protected $_bufferLen;
    
    
    protected $_config;
    protected $_stats;
    protected $_distantUrl;
    
    
    public function __construct($config, $urlinput = false, $urloutput = false)
    {
        $this->_stream = false;
        $this->_config = $config;
        $this->_bufferLen = 0;
        $this->_buffer = array();
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
        $this->_distantUrl = false;
        
        if ($urlinput !== false) $this->open($urlinput);
        if ($urloutput !== false) {
            $this->_distantUrl = $urloutput;
        }
        
        // @todo check config
        // check read at least 4096 bytes w/ compression (or useless)

    }
    
    /**
     * Open input stream
     * @return bool success or not
     */
    public function open($url)
    {
        $stream = fopen($url, 'r');

        if (is_resource($stream))
            stream_set_blocking($stream, 0);
        
        $this->_input = $stream;

        if ($stream === false) return false;
        return true;
    }
    
    /**
     * Read data from input
     * @return int|bool  false if any error, else bytes read
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
            $this->_stats['readBytes'] += $len;
            
            $this->_uncompressedBufferLen += $len;
            $this->_uncompressedBuffer .= $str;
            
            // Create a bucket ?
            if ($this->_uncompressedBufferLen > $this->_config['writeSize']) {
            
                if ($this->_config['binary'] === true) {
                    $pos = $this->_uncompressedBufferLen;
                } else {
                    $pos = @strrpos(
                        $this->_uncompressedBuffer, 
                        "\n"
                    );
                }
                if ($pos === 0) return $len;
                
                if ($this->_config['compression'] === false) {
                    $this->_buffer[] = substr($this->_uncompressedBuffer, 0, $pos);
                    $this->_bufferLen += $pos;
                    
                } else {
                    $tmp = gzencode(
                        substr($this->_uncompressedBuffer, 0, $pos),
                        $this->_config['compressionLevel']
                    );
                    $this->_buffer[] = $tmp;
                    $this->_bufferLen += strlen($tmp);
                }
                
                // clean first buffer
                $this->_uncompressedBuffer = substr($this->_uncompressedBuffer, $pos);
                $this->_uncompressedBufferLen -= $pos;
            }
        }
        return $len;
    }
    
    public function feof()
    {
        return feof($this->_input);
    }
    
    /**
     * @return false if error, else bytes written
     */
    public function write($force = false)
    {
        if ($this->_checkAnswers() === false) return 0;

        // if force = true, then write all buffer
        if ($force === true) {
            // Transform buffer if compressed
            if ($this->_uncompressedBufferLen > 0) {
                if ($this->_config['compression'] === true) {
                    $data = gzencode($this->_uncompressedBuffer,
                        $this->_config['compressionLevel']
                    );
                } else {
                    $data = $this->_uncompressedBuffer;
                }
            
                if ($data !== false) {
                    $this->_uncompressedBufferLen = 0;
                    $this->_uncompressedBuffer    = '';
                    $this->_buffer[] = $data;
                    $this->_bufferLen += strlen($data);
                }
            }
            $pos = $this->_bufferLen;
        }
        
        
        if ($this->_bufferLen === 0) return 0; // nothing to write

        // try to write 'buckets'
        $buf = array_shift($this->_buffer);
        $bytesWritten = strlen($buf);
        
        $opts = array(
            'http' => array(
                'method' => 'POST',
                'header' => "User-Agent: logstreamerHttp ".self::VERSION."\r\n".
                        "Content-Type: text/plain\r\n" .
                        "X-Content-Encoding: gzip\r\n" . // forced to use X- header as this 
                                                         // is not a standard in POST requests
                        "Connection: Close\r\n",
                'content' => $buf,
            )
        );
        $context = stream_context_create($opts);

        
        if ($this->_stream === false) {
            $this->_stream = stream_socket_client(
                $this->_distantUrl, 
                $errno, 
                $errstr, 
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            
            if ($this->_stream !== false) {
                $this->_stats['outputConnections']++;
                stream_set_blocking($this->_stream, 0);
                
                // @todo handle writing to server with no lag
                // @todo handle URL
                fwrite(
                    $this->_stream, 
                    "POST / HTTP/1.1\r\n".
                    "User-Agent: logStreamerHttp v".self::VERSION."\r\n".
                    "Content-Type: text/plain\r\n" .
                    "X-Content-Encoding: gzip\r\n" . // forced to use X- header as this 
                                                         // is not a standard in POST requests
                    "Connection: Close\r\n\r\n"
                );
                fwrite(
                    $this->_stream,
                    $buf                
                );
            } else {
                // reinsert buf into buffer (at the beginning)
                array_unshift($this->_buffer, $buf);
            }
        } else {
            // stream != false ? not normal behaviour here as we do not handle keepalive
            // and we have connection: close !
            trigger_error('Stream should not be false', E_USER_WARNING);
        }
        
        
        /******
        $bytesWritten = false;
        
        if ($this->_stream !== false)
            $bytesWritten = @fwrite($this->_stream, $this->_buffer, $pos);
            
        //echo "[force=".((int)$force)."] Writing ".$bytesWritten."/".$pos.
        // " bytes (second buffer: ".$this->_uncompressedBufferLen." bytes)\n";
        
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
        *****/
        return $bytesWritten;    
    }
    
    /**
     * Update _stream state and get answers
     * @return bool false if we should not send more data.
     */
    protected function _checkAnswers()
    {
        if ($this->_stream === false)
            return true;
        
        if (feof($this->_stream)) {
            fclose($this->_stream);
            $this->_stream = false;
            return true;
        }
        
        $returnCode = fread($this->_stream, 4096);
        
        if ($returnCode == '') return false; // we should get data back
        
        var_dump($returnCode);
    
    }
    
    /** 
     * Returns statistics array
     * @return array
     **/
    public function getStats()
    {
        $this->_stats['bufferSize'] = $this->_bufferLen;
        $this->_stats['inputFeof'] = $this->feof();
        return $this->_stats;
    }

}
