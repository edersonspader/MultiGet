<?php
/*

$mget = new MultiGet(3);
$url = '...';
$userVar = '...';
$mget->request($url, $postFields, $curlOptions)
->on('success', function ($content, $url, $handle) use ($userVar) {

})
->on('error', function ($url, $handle) {

})
->on('complete', function ($content, $url, $handle) {

});

$mget.go();

*/

class MultiGetRequest {// similar to EventEmitter !?

    public $url;
    public $postData;
    public $curlOptions;
    public $handle = null;
    public $multiHandle = null;

    public function __construct($url, $postData = null, $curlOptions = null) {
        $this->url = $url;
        $this->postData = $postData;
        $this->curlOptions = $curlOptions;
    }

    private $listeners = array();

    public function on($eventType, $callback) {
        $this->listeners[] = array('eventType' => $eventType, 'callback' => $callback);
        return $this;
    }

    public function emit($eventType/*, $arg1, $arg2, ... */) {
        $args = array_slice(func_get_args(), 1);
        $candidates = array_slice($this->listeners, 0);
        for ($i = 0; $i < count($candidates); $i++) {
          if ($candidates[$i]['eventType'] == $eventType) {
            call_user_func_array($candidates[$i]['callback'], $args);
          }
        }
        return $this;
    }
}

class MultiGet {

    public $maxRequests;
    public $curlOptions;

    private $requests;
    private $queue = array();// order of loading is not garanted
    

    private $timeouts = array();

    public function __construct($maxRequests = 4, $curlOptions = null) {
        $this->maxRequests = $maxRequests;
        $this->requests = array();
        $this->curlOptions = $curlOptions;
    }

    private function _request($x) {
        if (count($this->requests) < $this->maxRequests) {
            $multiHandle = curl_multi_init();
            $handle = curl_init();
           
            curl_setopt($handle, CURLOPT_URL, $x->url);
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
            if ($x->postData !== null) {
                curl_setopt($handle, CURLOPT_POST, TRUE);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $x->postData);
            }

            curl_setopt($handle, CURLOPT_HEADER, TRUE);
            curl_setopt($handle, CURLOPT_TIMEOUT, 45);//!!!?
            
            if ($this->curlOptions) {
                curl_setopt_array($handle, $this->curlOptions);
            }
            if ($x->curlOptions) {
                curl_setopt_array($handle, $x->curlOptions);
            }

            curl_multi_add_handle($multiHandle, $handle);

            $x->multiHandle = $multiHandle;
            $x->handle = $handle;
            $this->requests[] = $x;
        } else {
            $this->queue[] = $x;
        }
    }

    public function request($url, $postFields = null, $curlOptions = null) {
        $postData = null;
        if (isset($postFields) && is_array($postFields)) {
            $postData = '';
            foreach ($postFields as $k => $v) {
                $postData .= ($postData !== '' ? '&' : '');
                $postData .= urlencode($k) . '=' . urlencode($v);
            }
        } else {
          $postData = $postFields;
        }
        $x = new MultiGetRequest($url, $postData, $curlOptions);
        $this->_request($x);
        return $x;
    }
    
    public function setTimeout($callback, $delay) {
        $this->timeouts[] = array(
            'callback' => $callback,
            'delay' => $delay,
            'from' => microtime(true)
        );
    }

    public function go() {
        while (count($this->requests) > 0 || count($this->timeouts) > 0) {
        
       //echo count($this->requests) . ' / ' . count($this->timeouts) . " - r/t \n";
            // check timeouts...
            $i = 0;
            $ts = microtime(true);
            while ($i < count($this->timeouts)) {
              $t = $this->timeouts[$i];
              if ($t['from'] + $t['delay'] < $ts) {
                $t = $this->timeouts[$i];
                array_splice($this->timeouts, $i, 1);

                $args = array();//?
                call_user_func_array($t['callback'], $args);
                
                usleep(1000);
                continue 2; //!
              }
              $i++;
            }
            //...

            $active = 1;
            while ($active > 0) {
                $i = count($this->requests);
                while ($i > 0 && $active > 0) {
                    $i--;
                    $mrc = curl_multi_exec($this->requests[$i]->multiHandle, $active);
                }
                if ($active > 0) {
                    usleep(1000);
                    continue 2; //!
                }
            }

            $completed = $this->requests[$i];
            $content = curl_multi_getcontent($completed->handle);
            
            // http://stackoverflow.com/questions/4017911/curl-and-redirects-returning-multiple-headers
            $curlInfo = curl_getinfo($completed->handle);
            $headerSize = $curlInfo["header_size"];
            $responseHeaders = substr($content, 0, $headerSize);
            $content = substr($content, $headerSize);
            //?

            curl_multi_remove_handle($completed->multiHandle, $completed->handle);
            curl_multi_close($completed->multiHandle);

            $this->requests[$i] = $this->requests[count($this->requests) - 1];
            array_pop($this->requests);

            if ((count($this->requests) < $this->maxRequests) && (count($this->queue) > 0)) {
                $x = array_pop($this->queue);
                $this->_request($x);      
            }

            if (curl_error($completed->handle)) {
                $completed->emit('error', curl_error($completed->handle), $completed->url, $completed->handle);
            } else {
                $completed->emit('success', $content, $completed->url, $completed->handle);          
            }
            $completed->emit('complete', $content, $completed->url, $completed->handle, $responseHeaders);

            curl_close($completed->handle);
        }
    }
}

?>
