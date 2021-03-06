<?php

/**
* OAuth consumer using PHP cURL
* @author Ben Tadiar <ben@handcraftedbyben.co.uk>
* @link https://github.com/benthedesigner/dropbox
* @package Dropbox\OAuth
* @subpackage Consumer
*/
namespace Dropbox\OAuth\Consumer;
use Dropbox\API as API;
use Dropbox\OAuth\Storage\StorageInterface as StorageInterface;

class Curl extends ConsumerAbstract
{	
	/**
	 * Default cURL options
	 * @var array
	 */
	private $options = array(
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_VERBOSE        => true,
		CURLINFO_HEADER_OUT    => false,
		CURLOPT_FOLLOWLOCATION => true,
	);
	
	/**
	 * Set properties and begin authentication
	 * @param string $key
	 * @param string $secret
	 * @param StorageInterface $storage
	 * @param string $callback
	 */
	public function __construct($key, $secret, StorageInterface $storage, $callback = null)
	{
		// Check the cURL extension is loaded
		if(!extension_loaded('curl')){
			throw new \Dropbox\Exception('The cURL OAuth consumer requires the cURL extension');
		}
		
		$this->consumerKey = $key;
		$this->consumerSecret = $secret;
		$this->storage = $storage;
		$this->callback = $callback;
		$this->authenticate();
	}

	/**
	 * Execute an API call
	 * @todo Improve error handling
	 * @param string $method The HTTP method
	 * @param string $url The API endpoint
	 * @param string $call The API method to call
	 * @param array $params Additional parameters
	 * @param resource $fileHandle optional valid  & open file handle to store the file instead of returning data in memory. You must close it
	 * @return string|object stdClass
	 */
	public function fetch($method, $url, $call, array $additional = array(), $fileHandle = null)
	{
		// Get the signed request URL
		$request = $this->getSignedRequest($method, $url, $call, $additional);
		
		// Initialise and execute a cURL request
		$handle = curl_init($request['url']);
		curl_setopt_array($handle, $this->options);
		
		// POST request specific
		if($method == 'POST'){
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_POSTFIELDS, $request['postfields']);
		}

		if( !empty( $fileHandle ) ) {
			curl_setopt($handle, CURLOPT_FILE, $fileHandle);   
			curl_setopt($handle, CURLOPT_BINARYTRANSFER, true);
		} else {
			curl_setopt($handle, CURLOPT_HEADER, true);
			curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		}

		// Execute and parse the response
		if($raw = curl_exec($handle)) {
			$response = $this->parse($raw);
		}
		curl_close($handle);
		
		// Check if an error occurred and throw an Exception
		if(!empty($response['body']->error)){
			$message = $response['body']->error . ' (Status Code: ' . $response['code'] . ')';
			throw new \Dropbox\Exception($message);
		}
		
		return $response;
	}
	
	/**
	 * Parse a cURL response
	 * @param string $response 
	 * @return array
	 */
	private function parse($response)
	{
		// Explode the response into headers and body parts (separated by double EOL)
		list($headers, $response) = explode("\r\n\r\n", $response, 2);
		
		// Explode response headers
		$lines = explode("\r\n", $headers);
		
		// If the status code is 100, the API server must send a final response
		// We need to explode the response again to get the actual response
		if(preg_match('#^HTTP/1.1 100#', $lines[0])){
			list($headers, $response) = explode("\r\n\r\n", $response, 2);
			$lines = explode("\r\n", $headers);
		}
		
		// Get the HTTP response code from the first line
		$first = array_shift($lines);
		$pattern = '#^HTTP/1.1 ([0-9]{3})#';
		preg_match($pattern, $first, $matches);
		$code = $matches[1];
        
		// Parse the remaining headers into an associative array
		// Note: Headers are not returned at present, but may be useful
		$headers = array();
		foreach ($lines as $line){
			list($k, $v) = explode(': ', $line, 2);
			$headers[strtolower($k)] = $v;
		}
		
		// If the response body is not a JSON encoded string
		// we'll return the entire response body
		if(!$body = json_decode($response)){
			$body = $response;
		}
		
		return array('code' => $code, 'body' => $body, 'headers' => $headers);
	}
}
