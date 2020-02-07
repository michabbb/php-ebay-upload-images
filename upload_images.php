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
			try {
				$response         = $this->client->request('POST', '/ws/api.dll', [
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

				$responses[$index]['response']     = $response;
				$bodyContents                     = $response->getBody()->getContents();
				$parsedResponse                   = simplexml_load_string($bodyContents);
				$responses[$index]['parsed_body'] = json_decode(json_encode((array)$parsedResponse), TRUE);

			} catch (RequestException $reason) {
				$responses[$index]['reason'] = $reason;
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

				$responses_parsed[$index]          = $response['parsed_body']['SiteHostedPictureDetails'];
				$responses_parsed[$index]['state'] = true;

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
				/** @noinspection NullPointerExceptionInspection */
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
