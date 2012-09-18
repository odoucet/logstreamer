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
        exec('killall nc 2>&1');
        
        if (file_exists('resultfile.bin'))
            unlink('resultfile.bin');
            
    }
	public static function setUpBeforeClass()
    {
        // Default values
        self::tearDown();
        
        // Plain text
        if (!file_exists('testfile.txt')) {
            $str = '';
            for ($i = 0; $i<10000; $i++) {
                $str .= 'test.com 10.0.0.1 - - [17/Jul/2012:01:59:28 +0200] "POST /index.php HTTP/1.1" 401 '.$i.' "-" "Wget/1.11.4"'."\n";
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
        
    }
	
	public function testInitBasic()
	{
        require_once dirname(__FILE__).'/../logstreamer.class.php';
		$test = new logStreamer(self::$config);
        $this->assertTrue($test instanceof logstreamer);
	}
    
    // Test netcat is functioning correctly
    public function testNetcat()
    {
        if (file_exists('resultfile.bin'))
            unlink('resultfile.bin');
            
        exec('nc -l 27010 > resultfile.bin &');
        exec('echo "test" |nc 127.0.0.1 27010');
        
        $this->assertEquals(
            'test'."\n", 
            file_get_contents('resultfile.bin'), 
            'Netcat should work to test logstreamer correctly'
        );
    }
    
    
    /**
     * @dataProvider logprovider
     * @depends    testNetcat
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
            exec('nc -l '.$port.' > resultfile.bin 2>&1 &');
        }
        
        // Init logStreamer
        $this->_init($src);
        
        //echo exec('ps fauxw |fgrep "nc -l" |fgrep -v fgrep');
        //echo exec('netstat -lptn');
        //var_dump($this->stream->getStats());
        
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
        do {
            $bytesWritten = $this->stream->write(true);
        } while ($bytesWritten > 0);
        
        $stats = $this->stream->getStats();

        foreach ($data as $name => $val) {
            if (isset($stats[$name])) {
                $this->assertEquals($val, $stats[$name], $name);
            }
        }
        
        // Test signature
        if (isset($data['md5'])) {
            if (isset($config['compression']) && $config['compression'] === true) {
                /*$gzf = gzopen('resultfile.bin', "rb");
                $gzdatadecode='';
                while(!feof($gzf)) {
                    $gzdatadecode .= fread($gzf, 16384);
                }
                gzclose($gzf);
                
                $gzdatadecode = gzinflate(file_get_contents('resultfile.bin'));
                file_put_contents('test.bin', $gzdatadecode);
                $this->assertEquals($data['filesize'], strlen($gzdatadecode), 'result file size');
                $this->assertEquals($data['md5'], md5($gzdatadecode), 'md5 signature');
                */
            } else {
                $this->assertEquals($data['filesize'], strlen(file_get_contents('resultfile.bin')), 'result file size');
                $this->assertEquals($data['md5'], md5(file_get_contents('resultfile.bin')), 'md5 signature');
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
                    'outputConnections' => 0,
                    'inputDataDiscarded' => 0,
                    'bufferSize' => self::$plainLen,
                )
            ),
            
            // binary data, no compression, no remote connection
            array (
                array('binary' => true), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'readErrors'=> 0,
                    'outputConnections' => 0,
                    'inputDataDiscarded' => 0,
                    'bufferSize' => self::$binLen,
                )
            ),
            
            // plain data, no compression, no remote connection, small buffer
            array (
                array('maxMemory' => 1), 'testfile.txt', array(
                    'readBytes' => 4096*4, // first buffer
                    'readErrors'=> 0,
                    'outputConnections' => 0,
                    'inputDataDiscarded' => self::$plainLen-4096*4,
                    'bufferSize' => 4096*4,
                )
            ),
            
            // plain data, no compression, with remote connection
            array (
                array('target' => '127.0.0.1:27009'), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'outputConnections' => 1,
                    'inputDataDiscarded' => 0,
                    'writtenBytes' => self::$plainLen,
                    'bufferSize' => 0,
                    'md5' => self::$plainSig,
                    'filesize' => self::$plainLen,
                )
            ),
            
            // binary data, no compression, with remote connection
            array (
                array('target' => '127.0.0.1:27009'), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'readErrors'=> 0,
                    'binary'    => true,
                    'outputConnections' => 1,
                    'inputDataDiscarded' => 0,
                    'writtenBytes' => self::$binLen,
                    'bufferSize' => 0,
                    'md5' => self::$binSig,
                    'filesize' => self::$binLen,
                )
            ),
            
            /*
            // plain data, compression, with remote connection
            
            array (
                array('target' => '127.0.0.1:27009', 'compression' => true,), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'outputConnections' => 1,
                    'inputDataDiscarded' => 0,
                    'filesize' => self::$plainLen,
                    'bufferSize' => 0,
                    'md5' => self::$plainSig,
                )
            ),
            /*
            // binary data, compression, with remote connection
            array (
                array('target' => '127.0.0.1:27009', 'compression' => true,), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'readErrors'=> 0,
                    'binary'    => true,
                    'outputConnections' => 1,
                    'inputDataDiscarded' => 0,
                    'filesize' => self::$binLen,
                    'bufferSize' => 0,
                    'md5' => self::$binSig,
                )
            ),
            */
        );   
    }
}

if (!function_exists('gzdecode')) {
    function gzdecode($string) { // no support for 2nd argument
        return file_get_contents('compress.zlib://data:who/cares;base64,'. base64_encode($string));
    }
}
