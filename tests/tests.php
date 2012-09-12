<?php

class logstreamerTest extends PHPUnit_Framework_TestCase
{
	protected $stream;
    protected static $config;
    protected static $plainSig;
    protected static $plainLen;
    protected static $binSig;
    protected static $binLen;
    
    public function tearDown()
    {
        // Reset default config
        self::$config = array (
            'target' => '127.0.0.1:27010',
            'maxMemory' => 4096,
            'binary' => false,
            'compression' => false,
            'compressionLevel' => 4,
            'readSize' => 4096*4,
            'writeSize' => 4096*8,
        );
    }
	public static function setUpBeforeClass()
    {
        // Default values
        self::tearDown();
        
        // Plain text
        if (!file_exists('testfile.txt')) {
            for ($i = 0; $i<10000; $i++) {
                $str .= 'test.com 10.0.0.1 - - [17/Jul/2012:01:59:28 +0200] "POST /index.php HTTP/1.1" 401 465 "-" "Wget/1.11.4"';
            }
            file_put_contents('testfile.txt', $str);
        } else {
            $str = file_get_contents('testfile.txt');
        }
        self::$plainSig = md5($str);
        self::$plainLen = strlen($str);
        unset($str);
        
        // Binary text
        if (!file_exists('testfile.bin')) {
            exec('dd if=/dev/urandom of=testfile.bin bs=16k count=64 2>&1');        
        }
        $str = file_get_contents('testfile.bin');
        self::$binSig = md5($str);
        self::$binLen = strlen($str);
        unset($str);
	}
    
    protected function _init($src)
    {
        require_once dirname(__FILE__).'/../logstreamer.class.php';
		$this->stream = new logStreamer(
            self::$config, 
            $src,
            'tcp://'.self::$config['target']
        );
    }
    
    public static function tearDownAfterClass()
    {
        if (file_exists('testfile.txt'))
            unlink('testfile.txt');
        if (file_exists('testfile.bin'))
            unlink('testfile.bin');
        if (file_exists('resultfile.bin'))
            unlink('resultfile.bin');
    }
	
	public function testInitBasic()
	{
        require_once dirname(__FILE__).'/../logstreamer.class.php';
		$test = new logStreamer(self::$config);
        $this->assertTrue($test instanceof logstreamer);
	}
    
    
    /**
     * @dataProvider logprovider
    */
    public function testLogStream($config, $src, $data)
    {
        // modify config if necessary
        foreach ($config as $name => $val) {
            self::$config[$name] = $val;
        }
        
        // Launch target if necessary
        if (isset($config['target'])) {
            // it's crap, we can make it better (one day)
            $port = substr($config['target'], strpos($config['target'], ':')+1);
            exec('nc -l '.$port.' > resultfile.bin &');
        }
        
        // Init logStreamer
        $this->_init($src);
        
        while (true) {
            $this->stream->read();
            $this->stream->write();
            if ($this->stream->feof() === true) break;
            
            // Retry ?
            if (isset($config['retryConnect'])) {
                if ($this->stream->feofOutput() === true) {
                    $this->stream->open('write', 'tcp://'.$config['target']);
                }
            }
            usleep(1000);
        }
        $this->stream->write(true);
        
        $stats = $this->stream->getStats();

        foreach ($data as $name => $val) {
            if (isset($stats[$name])) {
                $this->assertEquals($val, $stats[$name], $name);
            }
        }
    }
    
    public function logprovider()
    {
        self::setUpBeforeClass();
        return array (
            // plain data, no compression, no remote connection
            array (
                array(), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'inputDataDiscarded' => 0,
                    'bufferSize' => self::$plainLen,
                )
            ),
            
            // binary data, no compression, no remote connection
            array (
                array('binary' => true), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'readErrors'=> 0,
                    'inputDataDiscarded' => 0,
                    'bufferSize' => self::$binLen,
                )
            ),
            
            // plain data, no compression, no remote connection, small buffer
            array (
                array('maxMemory' => 1), 'testfile.txt', array(
                    'readBytes' => 4096*4, // first buffer
                    'readErrors'=> 0,
                    'inputDataDiscarded' => self::$plainLen-4096*4,
                    'bufferSize' => 4096*4,
                )
            ),
            
            // plain data, no compression, with remote connection
            array (
                array('target' => '127.0.0.1:27010'), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'inputDataDiscarded' => 0,
                    'writtenBytes' => self::$plainLen,
                    'bufferSize' => 0,
                )
            ),
        );   
    }
}
