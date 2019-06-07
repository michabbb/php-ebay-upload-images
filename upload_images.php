<?php

namespace macropage\ebaysdk\trading\upload;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

class upload_images {

	private $config;
	private $client;
	private $requests;
	private $debug;

	/**
	 * uploadImages constructor.
	 *
	 * @param      $config
	 * @param bool $live
	 */
	public function __construct(array $config, $live = true) {
		foreach (['app-name', 'cert-name', 'dev-name', 'siteid', 'auth-token'] as $key) {
			if (!array_key_exists($key, $config)) {
				throw new RuntimeException('missing config key: ' . $config);
			}
			if ($key === 'siteid' && !preg_match('/^\d+$/', $config[$key])) {
				throw new RuntimeException('for siteid please use numbers, not letters!');
			}
		}
		$api_uri                   = $live ? 'https://api.ebay.com/' : 'https://api.sandbox.ebay.com/';
		$this->debug               = (array_key_exists('debug', $config) && $config['debug']);
		$config['concurrency']     = array_key_exists('concurrency', $config) && $config['concurrency'] ? $config['concurrency'] : 10;
		$config['comp-level']      = array_key_exists('comp-level', $config) && $config['comp-level'] ? $config['comp-level'] : 1113;
		$config['ExtensionInDays'] = array_key_exists('ExtensionInDays', $config) && $config['ExtensionInDays'] ? $config['ExtensionInDays'] : 30;
		$config['rewrite-index']   = array_key_exists('rewrite-index', $config) && $config['rewrite-index'] ? $config['rewrite-index'] : true;
		$this->config              = $config;
		$this->client              = new Client(['base_uri' => $api_uri, 'debug' => $this->debug]);
	}

	/**
	 * @param Client $client
	 */
	public function setClient(Client $client) {
		$this->client = $client;
	}

	/**
	 * @param array $images
	 *
	 * @throws RuntimeException
	 * @return array
	 */
	public function upload(array $images) {
		$this->prepareRequest($images);
		$prepared_requests = $this->requests;

		$requests = static function () use ($prepared_requests) {
			foreach ($prepared_requests as $index => $request) {
				/** @var Promise $request */
				yield static function () use ($request, $index) {
					return $request->then(static function (Response $response) use ($index) {
						/** @noinspection PhpUndefinedFieldInspection */
						$response->_index = $index;
						return $response;
					});
				};
			}
		};

		$responses = [];

		$pool = new Pool($this->client, $requests(), [
			'concurrency' => $this->config['concurrency'],
			'fulfilled'   => static function (Response $response) use (&$responses) {
				/** @noinspection PhpUndefinedFieldInspection */
				$index                            = $response->_index;
				$responses[$index]['response']    = $response;
				$bodyContents = $response->getBody()->getContents();
				$parsedResponse                   = simplexml_load_string($bodyContents);
				$responses[$index]['parsed_body'] = json_decode(json_encode((array)$parsedResponse), TRUE);
			},
			'rejected'    => static function (RequestException $reason) {
				$index                       = $reason->getRequest()->getHeaders()['X-MY-INDEX'][0];
				$responses[$index]['reason'] = $reason;
			},
		]);
		// Initiate the transfers and create a promise
		$promise = $pool->promise();
		// Force the pool of requests to complete
		$promise->wait();

		if (!count($responses)) {
			throw new RuntimeException('unable to get any responses');
		}

		return $this->parseResponses($responses);
	}

	/**
	 * @param $images
	 */
	public function prepareRequest(array $images) {
		foreach ($images as $index => $imageData) {
			$this->requests[$index] = $this->client->requestAsync('POST', '/ws/api.dll', [
				'headers'   => [
					'X-MY-INDEX'                     => $index,
					'X-EBAY-API-APP-NAME'            => $this->config['app-name'],
					'X-EBAY-API-CERT-NAME'           => $this->config['cert-name'],
					'X-EBAY-API-DEV-NAME'            => $this->config['dev-name'],
					'X-EBAY-API-CALL-NAME'           => 'UploadSiteHostedPictures',
					'X-EBAY-API-COMPATIBILITY-LEVEL' => $this->config['comp-level'],
					'X-EBAY-API-SITEID'              => $this->config['siteid']
				],
				'multipart' => [
					[
						'name'     => 'xml payload',
						'contents' => '<?xml version="1.0" encoding="utf-8"?>
<UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
    <RequesterCredentials>
        <ebl:eBayAuthToken xmlns:ebl="urn:ebay:apis:eBLBaseComponents">' . $this->config['auth-token'] . '</ebl:eBayAuthToken>
    </RequesterCredentials>
    <PictureName>' . $index . '</PictureName>
    <PictureSet>Standard</PictureSet>
    <ExtensionInDays>' . $this->config['ExtensionInDays'] . '</ExtensionInDays>
    <MessageID>' . $index . '</MessageID>
</UploadSiteHostedPicturesRequest>',
					],
					[
						'name'     => 'image data',
						'filename' => 'doesntmatter',
						'contents' => $imageData,
					]
				]
			]);
		}
	}

	/**
	 * @param array $responses
	 *
	 * @return array
	 */
	private function parseResponses(array $responses) {
		$responses_parsed = [];
		$global_state     = true;

		foreach ($responses as $index => $response) {
			if (array_key_exists('reason', $response)) {
				$responses_parsed[$index] = $this->returnFalse($response['reason'], $global_state);
				continue;
			}
			if (!array_key_exists('response', $response)) {
				$responses_parsed[$index] = $this->returnFalse('missing response', $global_state);
				continue;
			}
			if (
				!array_key_exists('parsed_body', $response)
				||
				!array_key_exists('Ack',$response['parsed_body'])
			) {
				$responses_parsed[$index] = $this->returnFalse('missing parsed_body: unable to read xml?', $global_state);
				continue;
			}
			if (in_array($response['parsed_body']['Ack'],['Success','Warning'])) {
                $responses_parsed[$index] = $response['parsed_body']['SiteHostedPictureDetails'];
			    if ($response['parsed_body']['Ack']==='Warning') {
                    $responses_parsed[$index]['Warning'] = $response['parsed_body']['Errors'];
                }
				continue;
			}
			if ($response['parsed_body']['Ack']==='Failure') {
                $responses_parsed[$index] = $this->returnFalse( $response['parsed_body']['Errors'], $global_state);
                continue;
            }
			/** @var $reponse_original \GuzzleHttp\Psr7\Response */
			$reponse_original = $response['response'];
			if ($this->debug) {
				d($reponse_original->getBody()->getContents());
				d($reponse_original->getHeaders());
			}
			throw new RuntimeException('unknown response: '.print_r($response,1));
		}

		return ['state' => $global_state, 'responses' => $responses_parsed];
	}

	/**
	 * @param $error
	 * @param $global_state
	 *
	 * @return array
	 */
	private function returnFalse($error, &$global_state) {
		$global_state = false;

		return ['state' => false, 'error' => $error];
	}

}
