<?php

namespace Drupal\ptv_api\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * PTV client to handle requests to API and authentication.
 */
class PtvClient {

  const PTV_BASE_URI_PRODUCTION = 'https://api-gw.palvelutietovaranto.suomi.fi/api/v12/';
  const PTV_BASE_URI_TRAINING = 'https://api-gw.palvelutietovaranto.trn.suomi.fi/api/v12/';
  const GEO_STAT_WFS_BASE_URI = 'https://geo.stat.fi/geoserver/wfs';

  /**
   * Available base URI options keyed by URI.
   */
  const BASE_URI_OPTIONS = [
    self::PTV_BASE_URI_PRODUCTION => 'Production (palvelutietovaranto.suomi.fi)',
    self::PTV_BASE_URI_TRAINING => 'Training (palvelutietovaranto.trn.suomi.fi)',
  ];

  /**
   * Constructs the PtvClient service.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend for ptv_bin.
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   The key repository service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly LoggerInterface $logger,
    protected readonly CacheBackendInterface $cache,
    protected readonly KeyRepositoryInterface $keyRepository,
    protected readonly TimeInterface $time,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the configured base URI, falling back to training.
   *
   * @return string
   *   The base URI for the PTV API.
   */
  protected function getBaseUri(): string {
    $uri = $this->configFactory->get('ptv_api.settings')->get('base_uri');
    if ($uri && isset(self::BASE_URI_OPTIONS[$uri])) {
      return $uri;
    }
    return self::PTV_BASE_URI_TRAINING;
  }

  /**
   * Fetches postal code area info from the Finnish national geo WFS service.
   *
   * @param string $postalCode
   *   The postal code to look up (e.g. '99100').
   *
   * @return array
   *   The decoded GeoJSON feature collection, or an empty array on failure.
   */
  public function getPostalCodeInfo(string $postalCode): array {
    $options = [
      'query' => [
        'service' => 'WFS',
        'version' => '2.0.0',
        'request' => 'GetFeature',
        'typeName' => 'postialue:pno',
        'outputFormat' => 'application/json',
        'cql_filter' => "posti_alue='" . $postalCode . "'",
      ],
      'headers' => [
        'Accept' => 'application/json',
      ],
    ];

    return $this->cachedRequest('GET', self::GEO_STAT_WFS_BASE_URI, $options);
  }

  /**
   * Searches for services using the PTV API with paginated results.
   *
   * @param array $params
   *   The query parameters for the service search.
   *
   * @return array
   *   A flat array of all service items across all pages.
   */
  public function serviceSearch(array $params): array {
    return $this->paginatedCalls('service/search', $params);
  }

  /**
   * Searches for service channels using the PTV API with paginated results.
   *
   * @param array $params
   *   The query parameters for the service channel search.
   *
   * @return array
   *   A flat array of all service channel items across all pages.
   */
  public function serviceChannelSearch(array $params): array {
    return $this->paginatedCalls('service-channel/search', $params);
  }

  /**
   * Searches for connections using the PTV API with paginated results.
   *
   * @param array $params
   *   The query parameters for the connection search.
   *
   * @return array
   *   A flat array of all connection items across all pages.
   */
  public function connectionSearch(array $params): array {
    return $this->paginatedCalls('connection/search', $params);
  }

  /**
   * Fetches a single service channel by its content ID.
   *
   * @param string $content_id
   *   The PTV content ID of the service channel.
   *
   * @return array
   *   The decoded JSON response for the service channel, or an empty array on failure.
   */
  public function getServiceChannel(string $content_id): array {
    return $this->cachedPtvRequest('GET', "service-channel/" . $content_id, []);
  }

  /**
   * Handles paginated API calls, fetching all pages and merging results.
   *
   * @param string $url
   *   The PTV endpoint path (relative to the base URI).
   * @param array $params
   *   The query parameters. 'page' and 'pageSize' can be overridden.
   *
   * @return array
   *   A flat array of all items across all paginated responses.
   */
  protected function paginatedCalls(string $url, array $params): array {
    $params['page'] = $params['page'] ?? 0;
    $params['pageSize'] = $params['pageSize'] ?? 100;
    $options['query'] = $params;
    $total_pages = 1;
    $items = [];

    while ($options['query']['page'] < $total_pages) {
      $options['query']['page']++;
      $call = $this->cachedPtvRequest('GET', $url, $options);
      $items = [...$items, ...($call['items'] ?? [])];
      $total_pages = $call['totalPages'] ?? 1;
    }

    return $items;
  }

  /**
   * Builds PTV options with auth and delegates to the generic cached request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $uri
   *   The PTV endpoint path (appended to the base URI).
   * @param array $options
   *   Guzzle request options.
   *
   * @return array
   *   The decoded JSON response, or an empty array on failure.
   */
  protected function cachedPtvRequest(string $method, string $uri, array $options): array {
    $keyId = $this->configFactory->get('ptv_api.settings')->get('api_key');
    $key = $keyId ? $this->keyRepository->getKey($keyId) : NULL;

    if (!$key) {
      $this->logger->error('PTV API key is not configured. Visit /admin/config/services/ptv-api to set it.');
      return [];
    }

    $options['headers']['Accept'] = 'application/json';
    $options['headers']['x-api-key'] = $key->getKeyValue();

    return $this->cachedRequest($method, $this->getBaseUri() . $uri, $options);
  }

  /**
   * Generic cached HTTP request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $url
   *   The full URL to request.
   * @param array $options
   *   Guzzle request options.
   * @param int $ttl
   *   Cache lifetime in seconds. Defaults to 4 hours.
   *
   * @return array
   *   The decoded JSON response, or an empty array on failure.
   */
  protected function cachedRequest(string $method, string $url, array $options, int $ttl = 60 * 60 * 4): array {
    $hash = md5($method . '_' . $url . '/' . Json::encode($options));
    $cached = $this->cache->get($hash);

    if ($cached !== FALSE) {
      return $cached->data;
    }

    $data = [];

    try {
      $response = $this->httpClient->request($method, $url, $options);
      $data = Json::decode($response->getBody(), TRUE) ?? [];
    }
    catch (RequestException $e) {
      $this->logger->error($e);
    }

    $expire = $this->time->getCurrentTime() + $ttl;
    $this->cache->set($hash, $data, $expire, ['ptv_bin']);

    return $data;
  }

}
