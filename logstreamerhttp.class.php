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

    /**
     * @var array "buckets" to send. Already compressed
     */
    protected $_buckets;

    /**
     * @var string buffer containing the bucket being written
     */
    protected $_writeBuffer;

    /**
     * @var string buffer containing the server response after writing a bucket
     */
    protected $_responseBuffer;

    /**
     * @var int offset of the write buffer
     */
    protected $_writePos;
    
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
     * @var string remote stream descriptor
     */
    protected $_remoteStream;

    /**
     * @var string remote host name
     */
    protected $_remoteHost;

    /**
     * @var string remote URI
     */
    protected $_remoteUri;
    
    /**
     * @see $config['maxRetryWithoutTransfer']
     */
    protected $_currentMaxRetryWithoutTransfer;
    
    
    public function __construct($config)
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

        $this->_config['maxMemory'] = self::humanToBytes($config['maxMemory']);
        $this->_config['readSize'] = self::humanToBytes($config['readSize']);
        $this->_config['writeSize'] = self::humanToBytes($config['writeSize']);

        // @todo handle HTTP authentication ?
        $remote = parse_url($config['remoteUrl']);
        $protocol = (array_key_exists('scheme', $remote) && $remote['scheme'] === 'https') ? 'ssl' : 'tcp';
        $this->_remoteHost = $remote['host'];
        $this->_remoteUri = (array_key_exists('path', $remote)) ? $remote['path'] : '/';
        $this->_remoteUri .= (array_key_exists('query', $remote)) ? '?' . $remote['query'] : '';
        if (array_key_exists('port', $remote)) {
            $port = (int) $remote['port'];
        } else {
            $port = ($protocol === 'ssl') ? 443 : 80;
        }

        $this->_remoteStream = $protocol . '://' . $this->_remoteHost . ':' . $port;

        if (!defined('STDIN')) {
            $this->_input = fopen('php://stdin', 'rb');
        } else {
            $this->_input = STDIN;
        }

        stream_set_blocking($this->_input, 0);
    }

    /**
     * Check if we have to drop old buckets when we hit the memory limit.
     * @return int number of buckets dropped, should not be > 1
     */

    public function checkBucketLimit()
    {
        $dropCount = 0;
        while ($this->_bucketsLen > $this->_config['maxMemory']) {
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

        if (($str = fread($this->_input, $this->_config['readSize'])) === false) {
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
     * Store content into HTTP POST requests in a bucket list
     * @return int number of buckets created
     */
    public function store()
    {
        $bucketCount = 0;

        while ($this->_bufferLen >= $this->_config['writeSize']) {
            if ($this->_config['binary'] === true) {
                $size = $this->_bufferLen;
            } else {
                $size = strrpos($this->_buffer, "\n");

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
                $bucketLen = strlen($bucket);
            } else {
                $bucket = substr($this->_buffer, 0, $size);
                $bucketLen = $size;
            }

            $this->_buffer = substr($this->_buffer, $size);
            $this->_bufferLen -= $size;

            $writeBuffer =
                'POST ' . $this->_remoteUri . ' HTTP/1.1' . "\r\n" .
                    'Host: ' . $this->_remoteHost . "\r\n" .
                    'User-Agent: logStreamerHttp ' . self::VERSION . "\r\n".
                    // XXX: Why not use a standard MIME type for log files ? something more like text/* ?
                    'Content-Type: text/x-log' . "\r\n";

            if ($this->_config['compression'] === true) {
                // RFC 2616 14.11 does not prohibit the use of Content-Encoding in HTTP requests
                $writeBuffer .= 'Content-Encoding: gzip' . "\r\n";
            }

            $writeBuffer .=
                'Content-Length: ' . $bucketLen . "\r\n".
                    'Connection: Close' . "\r\n" . // Disable Keep-Alive
                    "\r\n" . // End of HTTP headers
                    $bucket; // POST Data

            $this->_buckets[] = $writeBuffer;
            $this->_bucketsLen += strlen($writeBuffer);
            $this->_stats['bucketsCreated']++;
            $bucketCount++;
        }

        if ($bucketCount > 0) $this->checkBucketLimit();  // New buckets have been stored, now check if we don't have too much. If so, drop the older ones.

        return $bucketCount;
    }
    
    /**
     * @return bool|int false if error, else bytes written into writeBuffer
     */
    public function write()
    {
        // transform 'buckets' into HTTP post requests in the write queue

        if ($this->_writeBuffer === null) {
            $this->_writeBuffer = array_shift($this->_buckets);
            if ($this->_writeBuffer === null) return 0;
            $this->_writePos = 0;
            $this->_responseBuffer = null;
            $this->_bucketsLen -= strlen($this->_writeBuffer);
            if (self::DEBUG) echo "Inserting bucket in the write buffer, ".count($this->_buckets)." buckets remaining (".$this->_bucketsLen." bytes)\n";
        }

        if (feof($this->_stream)) {
            $this->_stats['writeErrors']++;
            fclose($this->_stream);
            $this->_stream = null;
        }

        if (!is_resource($this->_stream)) {

            // @see SSL over async sockets won't work because of bug #48182, see https://bugs.php.net/bug.php?id=48182

            if (self::DEBUG) echo "\nConnection to $this->_remoteStream\n";
            $this->_stream = stream_socket_client(
                $this->_remoteStream,
                $errno = null,
                $errstr = null,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            stream_set_blocking($this->_stream, 0);
            $this->_stats['outputConnections']++;
        }

        if ($this->_writePos < strlen($this->_writeBuffer) &&
            stream_select($r = array(), $w = array($this->_stream), $e = array(), 0) > 0) {

            $pos = fwrite($this->_stream, substr($this->_writeBuffer, $this->_writePos), $this->_config['writeSize']);
            if (self::DEBUG) echo "Wrote $pos bytes\n";

            if ($pos === 0) {
                $this->_stats['writeErrors']++;
                fclose($this->_stream);
                $this->_stream = null;
                return 0;
            }
            $this->_stats['writtenBytes'] += $pos;
            $this->_writePos += $pos;
        }

        if ($this->_writePos === strlen($this->_writeBuffer)) {
            if (self::DEBUG) echo "Write complete, now wait for server ACK before processing the next bucket...\n";

            if (stream_select($r = array($this->_stream), $w = array(), $e = array(), 0) > 0) {
                $this->_responseBuffer .= fread($this->_stream, 8192);
            }
            if ($responsePos = strpos($this->_responseBuffer, "\r\n\r\n")) {
                // HTTP response header found, ignoring the body
                $response = explode("\r\n", substr($this->_responseBuffer, $responsePos));
                if (preg_match('/^HTTP\/[0-9]\.[0-9] 200 .*/', $response[0])) {
                    // We got our server positive ACK, moving on to the next bucket
                    if (self::DEBUG) echo "Got server ACK, processing next bucket...\n";
                    $this->_writeBuffer = null;
                    return $this->_writePos;
                } else {
                    // Server responded with an error, trying again the same bucket
                    $this->_writePos = 0;
                    $this->_responseBuffer = null;
                    return 0;
                }
            }
        }
    }

    /**
     * Return synchronously when the buckets list and the write queue are flushed
     * Note: we could also set the socket to blocking mode now to save a few CPU cycles.
     */
    public function flush()
    {
        while ($this->_bucketsLen > 0 || count($this->_buckets) > 0 || $this->_writeBuffer !== null) {
            $this->write();
        }
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
     * Convert Human-readable units into bytes
     * Note: we use the PHP's integer so values over 2^31 can be unexpected on 32-bit platforms
     *
     * @param string value Human-readable value
     *
     * @return int Bytes
     */
    public static function humanToBytes($value)
    {
        $bytes = (int) 0;
        $decimal = 1;
        $pos = 0;

        $units = array(
            'b' => (int) 1,
            'k' => (int) 1024,
            'm' => (int) 1024 * 1024,
            'g' => (int) 1024 * 1024 * 1024
        );
        $value = strtolower($value);

        for ($i = 0; $i < strlen($value); $i++) {
            $digit = $value{$i};

            if (array_key_exists($digit, $units)) {
                $bytes *= $units[$digit];
                break;
            } elseif ($digit === '.') {
                $decimal = -1;
                $pos = 0;
            } else {
                if ($decimal > 0) {
                    $bytes *= pow(10, $pos * $decimal);
                    $bytes += (int) $digit;
                } else {
                    $bytes += $digit * pow(10, $pos * $decimal);
                }
            }

            $pos++;
        }

        return (int) round($bytes, 0);
    }
}
