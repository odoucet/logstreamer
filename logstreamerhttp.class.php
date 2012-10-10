<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**
 * logStreamer on HTTP class
 * @author Olivier Doucet <odoucet@php.net>
 */
class logStreamerHttp
{
    const VERSION = '1.0 (2012-09-24)';
    const DEBUG   = 0;
    protected $_input;
    protected $_stream;
    protected $_errno;
    protected $_errstr;
    protected $_buffer;
    protected $_bufferLen;
    protected $_writeAnswerRequired;
    protected $_bytesWrittenLast;
    
    /**
     * @var array "buckets" to send. Already compressed
     */
    protected $_buckets;
    
    /**
     * @var string write buffer
     */
    protected $_writeBuffer;
    
    /** 
     * @var int Size of all buffers aggregated
     */
    protected $_bucketsLen;
    
    /**
     * @var array Config options
     */
    protected $_config;
    
    /**
     * @var array Stats array
     */
    protected $_stats;
    
    /**
     * @var string distant URL
     */
    protected $_remoteUrl;
    
    /**
     * @see $config['maxRetryWithoutTransfer']
     */
    protected $_currentMaxRetryWithoutTransfer;
    
    
    public function __construct($config, $remoteUrl)
    {
        $this->_stream = false;
        $this->_config = $config;
        $this->_bucketsLen = 0;
        $this->_buckets = array();
        $this->_buffer = '';
        $this->_bufferLen = 0;
        $this->_currentMaxRetryWithoutTransfer = 0;
        $this->_stats = array (
            'dataDiscarded' => 0, // bytes of data discarded due to memory limit
            'readErrors'         => 0, // errors reading data
            'writeErrors'        => 0, // errors when writing data to distant host
            'outputConnections'  => 0, // total connections to output
            'readBytes'          => 0, // bytes read from input
            'writtenBytes'       => 0, // bytes written to server
            'bucketsCreated'     => 0, // total number of buckets created
            'serverAnsweredNo200'=> 0, // how many times distant server answered != 200
        );

        $this->_remoteUrl = $remoteUrl;

        if (!defined('STDIN')) {
            $this->_input = fopen('php://stdin', 'rb');
        } else {
            $this->_input = STDIN;
        }

        stream_set_blocking($this->_input, 0);
        
        // @todo check config
        // check read at least 4096 bytes w/ compression (or useless)
        
        if (!isset($this->_config['maxRetryWithoutTransfer'])) {
            $this->_config['maxRetryWithoutTransfer'] = 10;
            /**
            Each loop in client, we do one try to write() (+ connect if necessary).
            Connect always returned true because it is async. That's why we do not know
            immediately if connection succeeded. Then, we need to decide when we
            consider the connection as "failed".
            After 'maxRetryWithoutTransfer' pass at 0 writes, we consider a failure.
            **/
        }

    }

    /**
     * Check if we have to drop old buckets when we hit the memory limit.
     * @return int number of buckets dropped, should not be > 1
     */

    public function checkBucketLimit()
    {
        $dropCount = 0;
        while ($this->_bucketsLen > $this->_config['maxMemory']*1024) {
            $dropBucket = array_shift($this->_buckets);
            $dropBucketLen = strlen($dropBucket);
            $this->_bucketsLen -= $dropBucketLen;
            $this->_stats['dataDiscarded'] += $dropBucketLen;
            unset($dropBucket, $dropBucketLen);
            $dropCount++;
        }
        return $dropCount;
    }
    
    /**
     * Read data from input
     * @return int|bool  false if EOF, else bytes read
     */
    public function read()
    {
        if (!is_resource($this->_input)) return false;

        if (feof($this->_input)) {
            fclose($this->_input);
            return false;
        }

        if (($str = @fread($this->_input, $this->_config['readSize'])) === false) {
            // WTF happened ?
            $this->_stats['readErrors']++;
            return false;
        }

        if (($len = strlen($str)) === 0) return 0;

        $this->_buffer .= $str;
        $this->_bufferLen += $len;
        $this->_stats['readBytes'] += $len;

        // Try to store data in a bucket after each read. May be optimized to not try after every single read call.
        $this->store();

        return $len;
    }

    /**
     * Store content into buckets
     * @return int number of buckets created
     */
    public function store()
    {
        $bucketCount = 0;

        while ($this->_bufferLen >= $this->_config['writeSize']) {
            if ($this->_config['binary'] === true) {
                $size = $this->_bufferLen;
            } else {
                $size = @strrpos($this->_buffer, "\n");

                // Checking maximum line size
                if ($size === false) {
                    $size = $this->_bufferLen;
                } else {
                    // Don't forget to include the \n character in the line !!!
                    $size++;
                }
            }

            if ($this->_config['compression'] === true) {
                $bucket = gzencode(substr($this->_buffer, 0, $size), $this->_config['compressionLevel']);
                $this->_buckets[] = $bucket;
                $this->_bucketsLen += strlen($bucket);
            } else {
                $this->_buckets[] = substr($this->_buffer, 0, $size);
                $this->_bucketsLen += $size;
            }

            $this->_buffer = substr($this->_buffer, $size);
            $this->_bufferLen -= $size;

            $this->_stats['bucketsCreated']++;
            $bucketCount++;
        }

        return $bucketCount;
    }
    
    /**
     * @return int bytes not written yet
     */
    public function dataLeft()
    {
        $cpt = $this->_bucketsLen + $this->_bufferLen + strlen($this->_writeBuffer);
        
        /* if an answer is required, we should know that we are still waiting for something
         * this is really crappy code and I need to rewrite it, because it breaks
         * the purpose of dataLeft.
         * There is NO data left, just that we cannot end normally without an answer ...
         */
        if ($this->_writeAnswerRequired === true) 
            $cpt++;
            
        return $cpt;
        
    }
    
    /**
     * @var bool Force creation of bucket with remaining data
     * @var bool Force flush of write buffer
     * @return bool|int false if error, else bytes written into writeBuffer
     */
    public function write($force = false, $forceAnswer = false)
    {
        // if force = true, then write all buffer
        if ($force === true) {
            // Transform buffer if compressed
            if ($this->_bufferLen > 0) {
                if ($this->_config['compression'] === true) {
                    $data = gzencode($this->_buffer,
                        $this->_config['compressionLevel']
                    );
                } else {
                    $data = $this->_buffer;
                }
            
                if ($data !== false) {
                    $this->_bufferLen = 0;
                    $this->_buffer    = '';
                    $this->_buckets[] = $data;
                    $this->_bucketsLen += strlen($data);
                }
            }
        }
        
        // actual write to distant server
        // return true if we can go on and prepare another HTTP request
        if ($this->_checkAnswers($forceAnswer) === false) return 0;
        
        // nothing to write
        if ($this->_bucketsLen === 0 || count($this->_buckets) === 0) return 0;

        // try to write 'buckets'
        $buf = array_shift($this->_buckets);
        $bytesWritten = strlen($buf);

        $context = stream_context_create($opts);

        if ($this->_stream === false) {
            // pos == 7 to skip tcp://
            // @todo use parse_url
            $url = substr($this->_remoteUrl, 0, strpos($this->_remoteUrl, '/', 7));
            if (self::DEBUG) echo "\nConnection to $url to send ".strlen($buf)." bytes\n";
            $this->_stream = @stream_socket_client(
                $url,
                $errno, 
                $errstr, 
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            $this->_currentMaxRetryWithoutTransfer = 0;
            
            if ($this->_stream !== false) {
                $this->_stats['outputConnections']++;
                stream_set_blocking($this->_stream, 0);
                
                // @todo handle writing to server with no lag
                // @todo handle URL
                $uri  = parse_url($this->_remoteUrl, PHP_URL_PATH);
                $host = parse_url($this->_remoteUrl, PHP_URL_HOST);
                $this->_writeBuffer =
                    "POST ".$uri." HTTP/1.1\r\n".
                    "Host: ".$host."\r\n".
                    "User-Agent: logStreamerHttp ".self::VERSION."\r\n".
                        // Why not use a standard MIME type for log files ? something more like text/* ?
                    "Content-type: application/x-www-form-urlencoded\r\n";
                    
                if ($this->_config['compression'] === true) {
                    // forced to use X- header as this 
                    // is not a standard in POST requests
                    // ??? RFC 2616 14.11 does not prohibit the use of Content-Encoding in HTTP requests
                    $this->_writeBuffer .= "X-Content-Encoding: gzip\r\n";
                }
                
                $this->_writeBuffer .= "Content-Length: " . strlen($buf) . "\r\n".
                    "Connection: Close\r\n\r\n".
                    $buf;
                    
                $this->_bucketsLen -= strlen($buf);
                $this->_checkAnswers($forceAnswer);
                
            } else {
                // reinsert buf into buffer (at the beginning)
                array_unshift($this->_buckets, $buf);
                $this->_bucketsLen += strlen($buf);
                $this->_stats['bucketsCreated']++;
                return 0;
            }
        }
        return $bytesWritten;
    }
    
    /**
     * Update write stream state and get answers
     * @return bool false if we should not send more data.
     */
    protected function _checkAnswers($force = false)
    {
        if ($this->_stream === false)
            return true;
        if (self::DEBUG) echo "_checkAnswers() : need to write ".strlen($this->_writeBuffer)." bytes. Stream=".($this->_stream);
        
        //write ? 
        if ($this->_writeBuffer != '') {
            //if (self::DEBUG) echo " feof=".((int) feof($this->_stream))." answerRequired=".((int) $this->_writeAnswerRequired)."\n";
            
            if (!feof($this->_stream)) {
                $writtenBytes = @fwrite($this->_stream, $this->_writeBuffer, $this->_config['writeSize']);
            } else {
                $writtenBytes = 0; // feof, so no writes ...
                fclose($this->_stream);
                $this->_stream = false;
                $this->_stats['writeErrors']++;
                if (self::DEBUG) echo "FEOF DETECTED, RETRY=MAX\n";
                $this->_currentMaxRetryWithoutTransfer = $this->_config['maxRetryWithoutTransfer'];
            }
            $this->_bytesWrittenLast = $writtenBytes;
            
            if (self::DEBUG) echo "  BUFSIZE=".strlen($this->_writeBuffer)." WRITTEN ".$writtenBytes." bytes. retry=".$this->_currentMaxRetryWithoutTransfer." stream=".($this->_stream)."\n";
            
            if ($writtenBytes === false || $writtenBytes === 0) {
                $this->_currentMaxRetryWithoutTransfer++;
                
                if ($force === true || $this->_currentMaxRetryWithoutTransfer >= 
                    $this->_config['maxRetryWithoutTransfer']) {
                    
                    // reset packet 
                    // if strpos === false, cast to int => position 0
                    $pos = strpos($this->_writeBuffer, "\r\n\r\n");
                    if ($pos === false) $pos = 0;
                    else $pos += 4;
                    $tmp = substr($this->_writeBuffer, $pos);
                    if ($tmp != '') {
                        array_unshift(
                            $this->_buckets,
                            $tmp
                        );
                        $this->_bucketsLen += strlen($tmp);
                        $this->_stats['bucketsCreated']++;
                    }
                    unset($pos, $tmp);
                    $this->_writeBuffer = '';
                    $this->_currentMaxRetryWithoutTransfer = 0;
                    
                    if ($this->_stream !== false) {
                        fclose($this->_stream);
                        $this->_stream = false;
                    }
                    return true;
                }
                return false;
            
            }
            
            $this->_writeBuffer = substr($this->_writeBuffer, $writtenBytes);
            $this->_stats['writtenBytes'] += $writtenBytes;
            
            if ($this->_writeBuffer == '') {
                // we have written all data, now wait for an answer
                $this->_writeAnswerRequired = true;
            }
        }
        
        if ($this->_writeAnswerRequired === true) {
        
            // Code investigator
            if ($this->_writeBuffer != '') {
                trigger_error('writeAnswerRequired=true but there is still data in writeBuffer', E_USER_WARNING);
            }
            
            $returnCode = fread($this->_stream, 4096);
            if (self::DEBUG) echo "  _writeAnswerRequired! Return=".strlen($returnCode)." bytes  errors=".$this->_currentMaxRetryWithoutTransfer."\n";
            
            if ($returnCode == '')  {
                $this->_currentMaxRetryWithoutTransfer++;
                
                if ($this->_currentMaxRetryWithoutTransfer >= 
                    $this->_config['maxRetryWithoutTransfer']) {
                    if (self::DEBUG) echo '   MAXRETRY REACHED'."\n";
                    $this->_stats['writeErrors']++;
                    $this->_writeAnswerRequired = false;
                    fclose($this->_stream);
                    $this->_stream = false;
                    return true;
                }
                
                return false; // we should get data back
            } else {
                // if not a 200, increment error counter
                if (strpos($returnCode, 'HTTP/1.1 200') === false) {
                    $this->_stats['serverAnsweredNo200']++;
                    if (self::DEBUG) echo "Server answered != 200: ".$returnCode."\n\n";
                }
                $this->_writeAnswerRequired = false;
                fclose($this->_stream);
                $this->_stream = false;
                return true;
            }
        
        }
        return false;
    }
    
    /** 
     * Returns statistics array
     * @return array
     **/
    public function getStats()
    {
        $this->_stats['uncompressedBufferSize'] = $this->_bufferLen;
        $this->_stats['bufferSize'] = $this->_bucketsLen;
        $this->_stats['writeBufferSize'] = strlen(
            substr($this->_writeBuffer, strpos($this->_writeBuffer, "\r\n\r\n")+4)
        );
        $this->_stats['inputFeof']  = feof($this->_input);
        $this->_stats['buckets'] = count($this->_buckets);
        $this->_stats['currentMaxRetryWithoutTransfer'] = $this->_currentMaxRetryWithoutTransfer;
        return $this->_stats;
    }
    
    /**
     * Bytes written on last pass
     */
    public function bytesWrittenLast() {
        return $this->_bytesWrittenLast;
    }

}
