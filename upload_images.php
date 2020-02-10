<?php

namespace macropage\ebaysdk\trading\upload;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class upload_images {

	private $config;
	private $client;
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
		$config['comp-level']      = array_key_exists('comp-level', $config) && $config['comp-level'] ? $config['comp-level'] : 1113;
		$config['ExtensionInDays'] = array_key_exists('ExtensionInDays', $config) && $config['ExtensionInDays'] ? $config['ExtensionInDays'] : 30;
		$config['rewrite-index']   = array_key_exists('rewrite-index', $config) && $config['rewrite-index'] ? $config['rewrite-index'] : true;
		$config['timeout']         = array_key_exists('timeout', $config) && $config['timeout'] ? $config['timeout'] : 60;
		$config['max-retry']       = array_key_exists('max-retry', $config) && $config['max-retry'] ? $config['max-retry'] : 10;
		$config['random-wait']     = array_key_exists('random-wait', $config) && $config['random-wait'] ? $config['random-wait'] : 0;
		$this->config              = $config;
		$this->client              = new Client([
													'base_uri' => $api_uri,
													'debug'    => $this->debug,
													'verify'   => false
												]
		);
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
	 * @return array
	 * @throws RuntimeException
	 */
	public function upload(array $images) {
		$responses = $this->uploadImages($images);
		$this->parseResponses($responses);

		if (!count($responses)) {
			throw new RuntimeException('unable to get any responses');
		}

		return $this->parseResponses($responses);
	}

	/**
	 * @param array $images
	 *
	 * @return array
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	public function uploadImages(array $images) {
		$responses = [];
		foreach ($images as $index => $imageData) {
			$try       = 1;
			while ($try<$this->config['max-retry']) {
				try {
					$response                         = $this->doRequest($index, $imageData);
					$responses[$index]['response']    = $response;
					$bodyContents                     = $response->getBody()->getContents();
					$parsedResponse                   = simplexml_load_string($bodyContents);
					$responses[$index]['parsed_body'] = json_decode(json_encode((array)$parsedResponse), TRUE);
					$responses[$index]['try']         = $try;
					$try                              = $this->config['max-retry'];
					unset($responses[$index]['reason']);
				} catch (RequestException $reason) {
					$try++;
					$responses[$index]['reason'] = $reason;
					$responses[$index]['try']    = $try;
				}
				if ($this->config['random-wait']) {
					sleep(mt_rand(3, $this->config['random-wait']));
				}
			}
		}

		return $responses;
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
				$responses_parsed[$index] = $this->returnFalse($response['reason'], $global_state, $response['try']);
				continue;
			}
			if (!array_key_exists('response', $response)) {
				if ($this->debug) {
					d($response);
				}
				$responses_parsed[$index] = $this->returnFalse('missing response', $global_state, $response['try']);
				continue;
			}
			if (
				!array_key_exists('parsed_body', $response)
				||
				!array_key_exists('Ack', $response['parsed_body'])
			) {
				if ($this->debug) {
					d($response);
				}
				$responses_parsed[$index] = $this->returnFalse('missing parsed_body: unable to read xml?', $global_state, $response['try']);
				continue;
			}
			if (in_array($response['parsed_body']['Ack'], ['Success', 'Warning'])) {

				$responses_parsed[$index]          = $response['parsed_body']['SiteHostedPictureDetails'];
				$responses_parsed[$index]['state'] = true;
				$responses_parsed[$index]['try']   = $response['try'];

				if ($response['parsed_body']['Ack'] === 'Warning') {
					$responses_parsed[$index]['Warning'] = $response['parsed_body']['Errors'];
				}
				continue;
			}
			if ($response['parsed_body']['Ack'] === 'Failure') {
				$responses_parsed[$index] = $this->returnFalse($response['parsed_body']['Errors'], $global_state, $response['try']);
				continue;
			}
			/** @var $reponse_original \GuzzleHttp\Psr7\Response */
			$reponse_original = $response['response'];
			if ($this->debug) {
				d($response);
				/** @noinspection NullPointerExceptionInspection */
				d($reponse_original->getBody()->getContents());
				d($reponse_original->getHeaders());
			}
			throw new RuntimeException('unknown response: ' . print_r($response, 1));
		}

		return ['state' => $global_state, 'responses' => $responses_parsed];
	}

	/**
	 * @param $error
	 * @param $global_state
	 *
	 * @return array
	 */
	private function returnFalse($error, &$global_state, $try) {
		$global_state = false;

		return ['state' => false, 'error' => $error, 'try' => $try];
	}

	/**
	 * @param $index
	 * @param $imageData
	 *
	 * @return mixed|\Psr\Http\Message\ResponseInterface
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 */
	private function doRequest($index, $imageData) {
		return $this->client->request('POST', '/ws/api.dll', [
			'timeout' => $this->config['timeout'],
			'verify'  => false,
			'version' => 1.0,

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
					'contents' => $imageData,
				]
			]
		]);
	}

}
