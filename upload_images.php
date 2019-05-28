<?php

namespace macropage\ebaysdk\trading\upload;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Response;
use RuntimeException;

class upload_images {

	private $config;
	private $client;

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
		}
		$api_uri                   = $live ? 'https://api.ebay.com/' : 'https://api.sandbox.ebay.com/';
		$debug                     = (array_key_exists('debug', $config) && $config['debug']);
		$config['concurrency']     = array_key_exists('concurrency', $config) && $config['concurrency'] ? $config['concurrency'] : 10;
		$config['comp-level']      = array_key_exists('comp-level', $config) && $config['comp-level'] ? $config['comp-level'] : 1113;
		$config['ExtensionInDays'] = array_key_exists('ExtensionInDays', $config) && $config['ExtensionInDays'] ? $config['ExtensionInDays'] : 30;
		$this->config              = $config;
		$this->client              = new Client(['base_uri' => $api_uri, 'debug' => $debug]);
	}

	/**
	 * @param Client $client
	 */
	public function setClient(Client $client) {
		$this->client = $client;
	}

	public function upload(array $images) {
		$client   = $this->client;
		$config   = $this->config;
		$requests = static function ($images) use ($client, $config) {
			foreach ($images as $imageData) {
				yield static function () use ($client, $imageData, $config) {
					return $client->postAsync('/ws/api.dll', [
						'headers'   => [
							'X-EBAY-API-APP-NAME'            => $config['app-name'],
							'X-EBAY-API-CERT-NAME'           => $config['cert-name'],
							'X-EBAY-API-DEV-NAME'            => $config['dev-name'],
							'X-EBAY-API-CALL-NAME'           => 'UploadSiteHostedPictures',
							'X-EBAY-API-COMPATIBILITY-LEVEL' => $config['comp-level'],
							'X-EBAY-API-SITEID'              => $config['siteid']
						],
						'multipart' => [
							[
								'name'     => 'xml payload',
								'contents' => '<?xml version="1.0" encoding="utf-8"?>
<UploadSiteHostedPicturesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
    <RequesterCredentials>
        <ebl:eBayAuthToken xmlns:ebl="urn:ebay:apis:eBLBaseComponents">' . $config['auth-token'] . '</ebl:eBayAuthToken>
    </RequesterCredentials>
    <PictureName>something</PictureName>
    <PictureSet>Standard</PictureSet>
    <ExtensionInDays>' . $config['ExtensionInDays'] . '</ExtensionInDays>
</UploadSiteHostedPicturesRequest>',
							],
							[
								'name'     => 'image data',
								'filename' => 'doesntmatter',
								'contents' => $imageData,
							]
						]
					]);
				};
			}
		};

		$responses = [];

		$pool = new Pool($client, $requests($images), [
			'concurrency' => $this->config['concurrency'],
			'fulfilled'   => static function (Response $response, $index) use (&$responses) {
				$responses[$index]['response']    = $response;
				$parsedResponse                   = simplexml_load_string($response->getBody()->getContents());
				$responses[$index]['parsed_body'] = json_decode(json_encode((array)$parsedResponse), TRUE);
			},
			'rejected'    => static function ($reason, $index) {
				$responses[$index]['reason'] = $reason;
			},
		]);
		// Initiate the transfers and create a promise
		$promise = $pool->promise();
		// Force the pool of requests to complete
		$promise->wait();

		return $this->parseResponses($responses);
	}

	private function parseResponses(array $responses) {
		$responses_parsed = [];
		foreach ($responses as $index => $response) {
			d($response);
			if (array_key_exists('reason', $response)) {
				return ['state' => false, 'index' => $index, 'error' => $response['reason']];
			}
			if (!array_key_exists('response', $response)) {
				return ['state' => false, 'index' => $index, 'error' => 'missing response'];
			}
			if (!array_key_exists('parsed_body', $response)) {
				return ['state' => false, 'index' => $index, 'error' => 'missing parsed_body: unable to read xml?'];
			}
			if ($response['parsed_body']['Ack'] !== 'Success') {
				return ['state' => false, 'index' => $index, 'error' => $response['parsed_body']->Errors];
			}
			$responses_parsed[$index] = $response['parsed_body']['SiteHostedPictureDetails'];
		}

		return ['state' => true, 'responses' => $responses_parsed];
	}

}
