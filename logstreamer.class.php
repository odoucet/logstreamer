<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**
 * logStreamer class
 * @author Olivier Doucet <odoucet@php.net>
 */
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
    protected $_distantUrl;
    
    
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
        $this->_distantUrl = false;
        
        if ($urlinput !== false) $this->open('read',  $urlinput);
        if ($urloutput !== false) {
            $this->open('write', $urloutput);
            $this->_distantUrl = $urloutput;
        }
        
        // @todo check config
        // check read at least 4096 bytes w/ compression (or useless)

    }
    
    /**
     * Open distant stream
     * @return bool success or not
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
            if ($stream !== false) {
                $this->_stats['outputConnections']++;
                $this->_stream = $stream;
            }
        }
        if ($stream === false) return false;
        return true;
    }
    
    /**
     * Read data
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
            
            if ($this->_config['compression'] === false) {
                $this->_bufferLen += $len;
                $this->_buffer .= $str;
                
            } else {
                // More difficult ...
                $this->_uncompressedBufferLen += $len;
                $this->_uncompressedBuffer .= $str;
                
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
                        $compressData = gzdeflate(
                            substr($this->_uncompressedBuffer, 0, $pos), 
                            $this->_config['compressionLevel']
                        );
                        if ($compressData !== false) {
                            $this->_uncompressedBufferLen -= $pos;
                            $this->_uncompressedBuffer = substr(
                                $this->_uncompressedBuffer, 
                                $pos
                            );
                            $this->_buffer .= $compressData;
                            $this->_bufferLen += strlen($compressData);
                            
                        }
                    }
                }
            }
        }
        return $len;
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
    
    /**
     * @return false if error, else bytes written
     */
    public function write($force = false)
    {
        if ($this->_bufferLen === 0 && $this->_uncompressedBufferLen === 0) return 0; // nothing to write
        
        // Reconnect if distant stream not available
        if ($this->_stream === false) {
            if ($this->open('write', $this->_distantUrl) === false) return false;
        }

        // if force = true, then write all buffer
        if ($force === true) {
            // Transform unbuffered to buffer if compressed
            if ($this->_config['compression'] === true && 
                $this->_uncompressedBufferLen > 0) {
                
                $compressData = gzencode(
                    $this->_uncompressedBuffer, 
                    $this->_config['compressionLevel']
                );
                if ($compressData !== false) {
                    $this->_uncompressedBufferLen = 0;
                    $this->_uncompressedBuffer    = '';
                    $this->_buffer .= $compressData;
                    $this->_bufferLen += strlen($compressData);
                }
            }
            $pos = $this->_bufferLen;
            
        } elseif ($this->_config['binary']      === false && 
                $this->_config['compression'] === false) {
            
            // We get old lines so get position of near \n
            $pos = @strpos($this->_buffer, "\n", $this->_config['writeSize']);
            
            if ($pos === false) {
                // no return line. Write nothing
                return false;
            }
            
        } else {
            // Get X bytes
            $pos = $this->_config['writeSize'];
        }
        
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
        
        return $bytesWritten;    
    }
    
    /** 
     * Returns statistics array
     * @return array
     **/
    public function getStats()
    {
        $this->_stats['bufferSize'] = $this->_bufferLen;
        $this->_stats['inputFeof'] = $this->feof();
        $this->_stats['outputFeof'] = $this->feofOutput();
        return $this->_stats;
    }

}
