<?php

namespace Framework\Cache\Driver {

    use Framework\Cache as Cache;
    use Framework\Cache\Exception as Exception;

    /**
     * Makes use of the inherited accessor support. It defaults the $_host and $_port properties to common values, and calls the parent::__construct($options) method.
     * Connections to the Memcached server are made via the connect() public method from within the __construct() method.
     * 
     * The driver also has a protected _isValidService() method that is used ensure that the value of the $_service is a valid Memcached instance. Let us look at the connect()/disconnect() methods
     *
     * Requirements ------------> php-memcached, "apt install memcached"
     */
    class Memcached extends Cache\Driver {

        /**
         * Instance of PHP’s Memcached class
         * @var type 
         */
        protected $_service;

        /**
         * @readwrite
         */
        protected $_host = "127.0.0.1";

        /**
         * @readwrite
         */
        protected $_namespace = "webapp_";

        /**
         * @readwrite
         */
        protected $_port = "11211";

        /**
         * @readwrite
         */
        protected $_isConnected = false;

        protected function _isValidService() {
            $isEmpty = empty($this->_service);
            $isInstance = $this->_service instanceof \Memcached;
            if ($this->isConnected && $isInstance && !$isEmpty) {
                return true;
            }
            return false;
        }

        /**
         * Attempts to connect to the Memcached server at the specified host/port. If it connects, 
         * @return \Framework\Cache\Driver\Memcached
         * @throws Exception\Service
         */
        public function connect() {
            try {
                $this->_service = new \Memcached();
                $this->_service->addServer($this->host, $this->port);
                $this->isConnected = true;
            } catch (\Exception $e) {
                throw new Exception\Service("Unable to connect to service");
            }

            return $this;
        }

        /**
         * Attempts to disconnect the $_service instance from the Memcached service. It will only do so if the _isValidService() method returns true.
         * @return \Framework\Cache\Driver\Memcached
         */
        public function disconnect() {
            if ($this->_isValidService()) {
                $this->_service->close();
                $this->isConnected = false;
            }

            return $this;
        }

        public function makeKey($key) {
        	return $this->_namespace . $key;
        }

        /**
         * Get cached values
         * @param type $key
         * @param type $default allows for a default value to be supplied
         * @return type returned in the event no cached value is found at the corresponding key
         * @throws Exception\Service
         */
        public function get($key, $default = null) {
            if (!$this->_isValidService()) {
                throw new Exception\Service("Not connected to a valid memcached service");
            }

            $value = $this->_service->get($this->makeKey($key));
            if ($value === false) {
                return $default;
            }
            return $value;
        }

        /**
         * Set values to keys
         * @param type $key
         * @param type $value
         * @param type $duration duration for which the data should be cached
         * @return boolean Return status of save
         * @throws Exception\Service
         */
        public function set($key, $value, $duration = 300) {
            if (!$this->_isValidService()) {
                throw new Exception\Service("Not connected to a valid  memcached service");
            }

            return $this->_service->set($this->makeKey($key), $value, $duration);
        }

        /**
         * Erase value of key
         * @param type $key
         * @return \Framework\Cache\Driver\Memcached
         * @throws Exception\Service
         */
        public function erase($key) {
            if (!$this->_isValidService()) {
                throw new Exception\Service("Not connected to a valid memcached service");
            }

            $this->_service->delete($this->makeKey($key));
            return $this;
        }

        /**
         * Erase data from the cache
         */
        public function flush() {
            if (!$this->_isValidService()) {
                return false;
            }
            return $this->_service->flush();
        }
    }
}
