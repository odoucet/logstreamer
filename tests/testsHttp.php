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
            'target' => 'tcp://127.0.0.1:27010/myurl.php',
            'maxMemory' => 4096,
            'binary' => false,
            'compression' => false,
            'compressionLevel' => 4,
            'readSize' => 4096*4,
            'writeSize' => 4096*8,
            'maxRetryWithoutTransfer' => 10,
        );
        
        if (file_exists('server.pid')) {
            exec('kill -15 $(cat server.pid) 2>&1 &');
            usleep(100000);
            unlink('server.pid');
        }
        
        if (file_exists('resultfile.bin'))
            unlink('resultfile.bin');
        
    }
    
	public static function setUpBeforeClass()
    {
        chdir(dirname(__FILE__));
        
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
		$this->stream = new logStreamerHttp(
            self::$config, 
            $src,
            self::$config['target']
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
        require_once dirname(__FILE__).'/../logstreamerhttp.class.php';
		$test = new logStreamerHttp(self::$config);
        $this->assertTrue($test instanceof logStreamerHttp);
	}
    
    // Test php mini server is working; mandatory for all tests
    public function testServer()
    {
        if (file_exists('resultfile.bin'))
            unlink('resultfile.bin');
        
        //exec('/bin/bash -c "php miniserver.php tcp://127.0.0.1:27010 & "');
        shell_exec('php miniserver.php tcp://127.0.0.1:27010 <&- >&- 2>&- &');
        usleep(100000); // we give some time to php to open the socket
        shell_exec('cat testheaders.txt |nc 127.0.0.1 27010');
        $this->assertEquals(
            'thisismycontent'."\r\n", 
            file_get_contents('resultfile.bin'), 
            'PHP server should work to test logstreamerHttp correctly'
        );
    }
    
    
    /**
     * @dataProvider logprovider
     * @depends    testServer
    */
    public function testLogStream($config, $src, $data)
    {
        
        
        // modify config if necessary
        foreach ($config as $name => $val) {
            self::$config[$name] = $val;
        }
        
        // Launch target if necessary
        if (isset($config['target'])) {
            $target = explode('/', $config['target']);
            $args = '';
            if (isset($config['targetProperties'])) {
                
                foreach ($config['targetProperties'] as $k => $v)
                    $args .= ' '.$k.'='.$v;
            }
            shell_exec('php miniserver.php tcp://'.$target[2].' '.$args.' <&- >&- 2>&- &');
        }
        
        // Init logStreamer
        $this->_init($src);
        //xdebug_start_trace('trace');
        
        $startTime = microtime(true);
        while (true) {
            $this->stream->read();
            $this->stream->write();
            if ($this->stream->feof() === true) break;
            usleep(10000);
        }
        
        $i = 0;
        do {
            $bytesWritten = $this->stream->write(true);
            if ($this->stream->bytesWrittenLast() == 0)
                $i++;
            
            usleep(10000);
        } while ($this->stream->dataLeft() > 0 && $i < 100);
        $this->stream->write(true, true);
        
        $execTime = microtime(true) - $startTime;
        
        //xdebug_stop_trace();
        
        $stats = $this->stream->getStats();
        var_dump($stats);
        if (isset($data['statsFunction'])) {
            $this->assertTrue($data['statsFunction']($stats), 'custom check function');
        }
        foreach ($data as $name => $val) {
            if (isset($stats[$name])) {
                $this->assertEquals($val, $stats[$name], $name);
            }
        }
        
        
        // Test signature
        if (isset($data['md5'])) {
            $this->assertEquals($data['filesize'], strlen(file_get_contents('resultfile.bin')), 'result file size');
            $this->assertEquals($data['md5'], md5(file_get_contents('resultfile.bin')), 'md5 signature');
        }
        
        // Test temps d'execution
        if (isset($data['execTime'])) {
            $this->assertLessThan($data['execTime'], $execTime, 'execution time');
        }
    }
    
    public function logprovider()
    {
        self::setUpBeforeClass();
        return array (
            
            
            // #0 plain data, no compression, no remote connection
            array (
                array(), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'dataDiscarded' => 0,
                    'uncompressedBufferSize' => 0,
                    'statsFunction' => function($stats) {
                        if ($stats['writeErrors'] != ($stats['outputConnections']-1))
                            return false;
                        if ($stats['bucketsCreated'] ==0)
                            return false;
                        if ($stats['writeBufferSize']+$stats['bufferSize'] != $stats['readBytes'])
                            return false;
                        return true;
                    },
                )
            ),
            
            // #1 binary data, no compression, no remote connection
            array (
                array('binary' => true), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'readErrors'=> 0,
                    'dataDiscarded' => 0,
                    'uncompressedBufferSize' => 0,
                    'statsFunction' => function($stats) {
                        if ($stats['writeErrors'] != ($stats['outputConnections']-1))
                            return false;
                        if ($stats['bucketsCreated'] ==0)
                            return false;
                        if ($stats['writeBufferSize']+$stats['bufferSize'] != $stats['readBytes'])
                            return false;
                        return true;
                    },
                )
            ),
            
            // #2 plain data, no compression, no remote connection, small buffer
            array (
                array('maxMemory' => 1), 'testfile.txt', array(
                    'readBytes' => self::$plainLen, // full buffer
                    'readErrors'=> 0,
                    'uncompressedBufferSize' => 0,
                    'statsFunction' => function($stats) {
                        if ($stats['writeErrors'] != ($stats['outputConnections']-1))
                            return false;
                        if ($stats['bucketsCreated'] ==0)
                            return false;
                        if ($stats['writeBufferSize']+$stats['bufferSize'] != 
                           ($stats['readBytes']-$stats['dataDiscarded']))
                            return false;
                        if ($stats['dataDiscarded'] < $stats['readBytes']-4096*4*8)
                            return false;
                        if ($stats['dataDiscarded'] > $stats['readBytes']-4096*4)
                            return false;
                        return true;
                    },
                )
            ),
            
            // #3 plain data, no compression, with remote connection
            array (
                array('target' => 'tcp://127.0.0.1:27009/3pdncwrc.php'), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'serverAnsweredNo200' => 0,
                    'writeErrors'=> 0,
                    'dataDiscarded' => 0,
                    'buckets' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'bufferSize' => 0,
                    'md5' => self::$plainSig,
                    'filesize' => self::$plainLen,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] < $stats['readBytes'])
                            return false;
                        if ($stats['bucketsCreated'] != ($stats['outputConnections']-1))
                            return false;
                        return true;
                    },
                )
            ),
            
            // #4 binary data, no compression, with remote connection
            array (
                array('target' => 'tcp://127.0.0.1:27009/4bdncwrc.php', 'binary'    => true,), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'readErrors'=> 0,
                    'writeErrors'=> 0,
                    'dataDiscarded' => 0,
                    'buckets' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'bufferSize' => 0,
                    'md5' => self::$binSig,
                    'filesize' => self::$binLen,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] < $stats['readBytes'])
                            return false;
                        if ($stats['bucketsCreated'] != ($stats['outputConnections']-1))
                            return false;
                        return true;
                    },
                )
            ),
            
            
            // #5 plain data, compression, with remote connection
            array (
                array('target' => 'tcp://127.0.0.1:27009/5pdcwrc.php', 'compression' => true,), 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'writeErrors'=> 0,
                    'dataDiscarded' => 0,
                    'buckets' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'bufferSize' => 0,
                    'filesize' => self::$plainLen,
                    'md5' => self::$plainSig,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] == 0)
                            return false;
                        if ($stats['bucketsCreated'] != ($stats['outputConnections']-1))
                            return false;
                        return true;
                    },
                )
            ),
            
            
            // #6 binary data, compression, with remote connection
            array (
                array('target' => 'tcp://127.0.0.1:27009/6bdcwrc.php', 'compression' => true,), 'testfile.bin', array(
                    'readBytes' => self::$binLen,
                    'binary'    => true,
                    'readErrors'=> 0,
                    'writeErrors'=> 0,
                    'dataDiscarded' => 0,
                    'buckets' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'bufferSize' => 0,
                    'filesize' => self::$binLen,
                    'md5' => self::$binSig,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] == 0)
                            return false;
                        if ($stats['bucketsCreated'] != ($stats['outputConnections']-1))
                            return false;
                        return true;
                    },
                )
            ),
            
            
            // #7 plain data, compression, remote connection and odd config
            array (
                array(
                    'target' => 'tcp://127.0.0.1:27009/7pdcrcaoc.php', 
                    'compression' => true,
                    'readSize' => 4096*1024,
                    'writeSize' => 4096*1024,
                    )
                    , 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'writeErrors'=> 0,
                    'dataDiscarded' => 0,
                    'buckets' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'bufferSize' => 0,
                    'filesize' => self::$plainLen,
                    'md5' => self::$plainSig,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] == 0)
                            return false;
                        if ($stats['bucketsCreated'] != ($stats['outputConnections']-1))
                            return false;
                        return true;
                    },
                )
            ),
            
            
            // #8 plain data, compression, remote connection VERY SLOW
            array (
                array(
                    'target' => 'tcp://127.0.0.1:27009/8pdcrcvs.php', 
                    'targetProperties' => array(
                            'readspeed' => 1000000, 
                        ),
                    )
                    , 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'dataDiscarded' => 0,
                    'buckets' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'bufferSize' => 0,
                    'currentMaxRetryWithoutTransfer' => 10,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] == 0)
                            return false;
                        if ($stats['bucketsCreated'] != ($stats['outputConnections']-1))
                            return false;
                        if ($stats['writeErrors'] != $stats['outputConnections']) {
                            // in this test, server did not returned in time (everytime)
                            return false;
                        }
                        return true;
                    },
                )
            ),
            
            // #9 plain data, compression, remote connection unexpectly closed
            array (
                array(
                    'target' => 'tcp://127.0.0.1:27009/9pdcrcuc.php', 
                    'targetProperties' => array(
                            'closeloop' => 2, //loop to close
                        ),
                    )
                    , 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'dataDiscarded' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] == 0)
                            return false;
                        
                        // if FEOF, we insert the data back in buffer, so we create many buckets
                        if ($stats['bucketsCreated'] <= $stats['outputConnections'])
                            return false;
                            
                        if ($stats['bufferSize'] == 0)
                            return false;
                        return true;
                    },
                )
            ),
            
            // #10 plain data / compression, remote connection sleeps for very long
            array (
                array(
                    'target' => 'tcp://127.0.0.1:27009/10pdcrcsfvl.php', 
                    'targetProperties' => array(
                            'sleeploop' => 2, //loop to close
                        ),
                    )
                    , 'testfile.txt', array(
                    'readBytes' => self::$plainLen,
                    'readErrors'=> 0,
                    'dataDiscarded' => 0,
                    'uncompressedBufferSize' => 0, 
                    'writeBufferSize' => 0,
                    'statsFunction' => function($stats) {
                        if ($stats['writtenBytes'] == 0)
                            return false;
                        
                        // if error, we insert the data back in buffer, so we create many buckets
                        if ($stats['bucketsCreated'] != $stats['outputConnections']-1)
                            return false;
                            
                        if ($stats['writeErrors'] != $stats['outputConnections'])
                            return false;
                        return true;
                    },
                    'execTime' => 5,
                )
            ),
            
            
        );   
    }
}
