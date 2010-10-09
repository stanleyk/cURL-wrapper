<?php

namespace Curl;

use Nette;
use Nette\String;


/**
 * Parses the response from a cURL request into an object containing
 * the response body and an associative array of headers
 *
 * @package cURL
 * @author Sean Huber <shuber@huberry.com>
 * @author Filip Procházka <hosiplan@kdyby.org>
 */
class Response extends Nette\Object
{

	/**#@+ regexp's for parsing */
	const HEADER_REGEXP = "#(.*?)\:\s(.*)#";
	const HEADERS_REGEXP = "#HTTP/\d\.\d.*?$.*?\r\n\r\n#ims";
	const VERSION_AND_STATUS = "#HTTP/(\d\.\d)\s(\d\d\d)\s(.*)#";
	const FILE_CONTENT_START = "\r\n\r\n";
	/**#@- */

	/**
	 * The body of the response without the headers block
	 *
	 * @var string
	 */
	var $body = '';


	/**
	 * An associative array containing the response's headers
	 *
	 * @var array
	 */
	var $headers = array();


	/**
	 * Contains reference for Request
	 *
	 * @var Curl
	 * @access protected
	 */
	protected $Request;


	/**
	 * Contains resource for last downloaded file
	 *
	 * @var resource
	 * @access protected
	 */
	protected $downloadedFile;


	/**
	 * Accepts the result of a curl request as a string
	 *
	 * <code>
	 * $response = new Curl\Response(curl_exec($curl_handle));
	 * echo $response->body;
	 * echo $response->headers['Status'];
	 * </code>
	 *
	 * @param string $response
	 */
	public function __construct($response, Request $request = Null)
	{
		$this->Request = $request;

		if( $this->Request->getMethod() === Request::DOWNLOAD ){
			$this->parseFile();

		} else {
			# Extract headers from response
			$matches = String::matchAll($response, self::HEADERS_REGEXP);
			$headers_string = array_pop($matches[0]);
			$headers = explode("\r\n", str_replace("\r\n\r\n", '', $headers_string));

			# Remove headers from the response body
			$this->body = str_replace($headers_string, '', $response);

			$this->parseHeaders($headers);
		}
	}


	/**
	 * Parses headers from given list
	 *
	 * @param array $headers
	 */
	private function parseHeaders($headers)
	{
		# Extract the version and status from the first header
		$version_and_status = array_shift($headers);
		$matches = String::match($version_and_status, self::VERSION_AND_STATUS);
		if( count($matches) > 0 ){
			$this->headers['Http-Version'] = $matches[1];
			$this->headers['Status-Code'] = $matches[2];
			$this->headers['Status'] = $matches[2].' '.$matches[3];
		}

		# Convert headers into an associative array
		foreach ($headers as $header) {
			$matches = String::match($header, self::HEADER_REGEXP);
			$this->headers[$matches[1]] = $matches[2];
		}
	}


	/**
	 * Fix downloaded file
	 *
	 * @return CurlResponse  provides a fluent interface
	 */
	public function parseFile()
	{
		if( $this->Request->getMethod() === Curl::DOWNLOAD ){
			$path_p = $this->Request->getDownloadPath();
			@fclose($this->Request->getOption('FILE'));

			if( ($fp = fopen($this->Request->getFileProtocol() . '://' . $path_p, "rb")) === False ){
				throw new CurlException("Fopen error for file '{$path_p}'");
			}

			$rows = array();
			do{
				if( feof($fp) ){
					break;
				}
				$rows[] = fgets($fp);

				$matches = String::matchAll(implode($rows), self::HEADERS_REGEXP);

			} while( count($matches[0])==0 );

			if( isset($matches[0][0]) ){
				$headers_string = array_pop($matches[0]);
				$headers = explode("\r\n", str_replace("\r\n\r\n", '', $headers_string));
				$this->parseHeaders($headers);

				fseek($fp, strlen($headers_string));
// 				$this->Request->getFileProtocol();

				$path_t = $this->Request->getDownloadPath() . '.tmp';

				if( ($ft = fopen($this->Request->getFileProtocol() . '://' . $path_t, "wb")) === False ){
					throw new CurlException("Write error for file '{$path_t}' ");
				}

				while( !feof($fp) ){
					$row = fgets($fp, 4096);
					fwrite($ft, $row);
				}

				fclose($fp);
				fclose($ft);

				if( !@unlink($this->Request->getFileProtocol() . '://' . $path_p) ){
					throw new CurlException("Error while deleting file {$path_p} ");
				}

				if( !@rename($this->Request->getFileProtocol() . '://' . $path_t, $this->Request->getFileProtocol() . '://' . $path_p) ){
					throw new CurlException("Error while renaming file '{$path_t}' to '".basename($path_p)."'. ");
				}

				@chmod($path_p, 0755);

			}
		}

		return $this;
	}

	/**
	 * Returns the response body
	 *
	 * <code>
	 * $curl = new Curl;
	 * $response = $curl->get('google.com');
	 * echo $response;  # => echo $response->body;
	 * </code>
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->body;
	}


	/**
	 * Returns the response body
	 */
	public function getBody()
	{
		return $this->body;
	}


	/**
	 * Alias for getBody
	 */
	public function getResponse()
	{
		return $this->body;
	}


	/**
	 * @return \phpQuery\phpQuery
	 */
	public function getQuery()
	{
		$contentType = NULL;
		if (isset($this->headers['Content-Type'])) {
			$contentType = $this->headers['Content-Type'];
		}

		return \phpQuery\phpQuery::newDocument($this->body, $contentType);
	}


	/**
	 * @param string $charset
	 * @return CurlResponse 
	 */
	public function convert($to = "UTF-8", $from = NULL)
	{
		if ($from === NULL) {
			$charset = $this->query['head > meta[http-equiv=Content-Type]']->attr('content');
			$match = \Nette\String::match($charset, "~^(?P<type>[^;]+); charset=(?P<charset>.+)$~");

			$from = $match['charset'];
		}

		if ($body = @iconv($from, $to, $this->body)) {
			$this->body = $body;

		} else {
			throw new CurlException("Charset conversion from $from to $to failed");
		}

		return $this;
	}


	/**
	 * Returns the response headers
	 */
	public function getHeaders()
	{
		return $this->headers;
	}


	/**
	 * Returns specified header
	 */
	public function getHeader($header)
	{
		if( isset($this->headers[$header]) ){
			return $this->headers[$header];

		} else {
			return Null;
		}
	}


	/**
	 * Returns resource to downloaded file
	 *
	 * @return resource
	 */
	public function openFile()
	{
		$path = $this->Request->getDownloadPath();
		if( ($this->downloadedFile = fopen($this->Request->getFileProtocol() . '://' . $path, "r")) === False ){
			throw new CurlException("Read error for file '{$path}'");
		}

		return $this->downloadedFile;
	}


	/**
	 * Returns resource to downloaded file
	 *
	 * @return resource file stream
	 */
	public function closeFile()
	{
		return @fclose($this->downloadedFile);
	}


	/**
	 * Returns the Curl request object
	 *
	 * @return Curl
	 */
	public function getRequest()
	{
		return $this->Request;
	}


}
