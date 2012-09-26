<?php
/* vim: set ts=8 sw=4 tw=0 syntax=on: */

/**
 * logStreamer Server class
 * @author Olivier Doucet <odoucet@php.net>
 */
class logStreamerServer
{
    /**
     * @var resource Server socket
     */
    protected $_socket;
    
    /**
     * @var array of resource client connections
     */
    protected $_incoming;
    
    /**
     * @var array of input buffers
     */
    protected $_buffers;
    
    /**
     * @var array buffers size
     */
    protected $_buffersSize;
    
    /**
     * @var array config
     */
    protected $_config;
    
    /**
     * Create socket
     */
    public function construct(int $port, $ip = '0.0.0.0', $parameters = array())
    {
        $errno = $errstr = '';
        $this->_socket = @stream_socket_server('tcp://'.$ip.':'.$port, $errno, $errstr);
        
        if ($this->_socket === false) {
            throw new Exception($errstr.' ('.$errno.')');
        }
        stream_set_blocking($this->_socket, 0);
        // @todo : stream_set_timeout ?
        
        foreach ($parameters as $key => $name) {
            //@todo check each parameter
            $this->_config[$key] = $name;
        }
        
        if (!isset($this->_config['readBuffer']))
            $this->_config['readBuffer'] = 16384;
    }
    
    /**
     * Accept new connections
     */
    public function accept()
    {
        do {
            $tmp = stream_socket_accept($this->_socket, 0);
            
            if ($tmp !== false) {
                stream_set_blocking($tmp, 0);
                $this->_incoming[] = $tmp;
            }
        } while ($tmp !== false);
    }
    
    /**
     * Read data from incoming connections
     */
    public function read()
    {
        foreach ($this->_incoming as $id => $conn) {
            if (feof($conn)) {
                //end ? remove resource, but do not trash buffer
                $this->_incoming[$id] = null;
            }
            
            // no connection ? Go on
            if ($conn === false) continue;
            
            // now read data
            $data = fread($conn, $this->_config['readBuffer']);
            if ($data !== false && $data !== '') {
                $this->_buffersSize[$id] += strlen($data);
                $this->_buffers[$id]     .= $data;
            }
        }
    }







}
