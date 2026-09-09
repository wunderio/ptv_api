<?php

namespace Drupal\ptv_api\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenOffCanvasDialogCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\ptv_api\Form\PtvApiDebugForm;
use Drupal\ptv_api\Service\PtvClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for PTV API debugging utilities.
 */
class PtvDebugController extends ControllerBase {

  /**
   * Default max pages for debug/AJAX callers to avoid runaway pagination.
   */
  const DEBUG_DEFAULT_MAX_PAGES = 1;

  /**
   * Absolute cap on max_pages allowed via the AJAX endpoint.
   */
  const DEBUG_MAX_PAGES_CAP = 20;

  /**
   * The PTV client service.
   */
  protected PtvClient $ptvClient;

  /**
   * The logger channel.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->ptvClient = $container->get('ptv_api.client');
    $instance->logger = $container->get('logger.channel.ptv_api');
    return $instance;
  }

  /**
   * Debug page callback.
   */
  public function debugPage(Request $request): array {
    $query = $request->query;

    $prefill = [
      'search_type' => $query->get('search_type'),
      'content_id' => $query->get('content_id'),
      'language' => $query->get('language'),
      'page_size' => $query->get('page_size'),
      'max_pages' => $query->get('max_pages'),
      'service_ids' => $query->get('service_ids'),
      'channel_ids' => $query->get('channel_ids'),
      'auto_run' => filter_var($query->get('auto_run'), FILTER_VALIDATE_BOOLEAN),
    ];

    // Drop null/empty entries so the form only overrides what was provided.
    $prefill = array_filter(
      $prefill,
      static fn($v) => $v !== NULL && $v !== '',
    );

    return [
      'intro' => [
        '#type' => 'markup',
        '#markup' => '<p>Run live PTV API debug queries without migrations.</p>',
      ],
      'form' => $this->formBuilder()->getForm(PtvApiDebugForm::class, $prefill),
    ];
  }

  /**
   * AJAX endpoint for debug search.
   */
  public function debugAjax(Request $request) {
    $search_type = (string) $request->query->get('search_type', '');
    $params = $request->query->all();

    $max_pages = self::DEBUG_DEFAULT_MAX_PAGES;
    if ($this->isPaginatedSearchType($search_type) && $request->query->has('max_pages')) {
      $max_pages = max(1, min(self::DEBUG_MAX_PAGES_CAP, (int) $request->query->get('max_pages')));
    }

    unset(
      $params['search_type'],
      $params['max_pages'],
      $params['_wrapper_format'],
      $params['_drupal_ajax'],
      $params['ajax_form'],
      $params['ajax_page_state'],
      $params['dialogType'],
      $params['dialogRenderer'],
      $params['dialogOptions']
    );

    try {
      $start_time = microtime(TRUE);
      $results = $this->executeSearch($search_type, $params, $max_pages);
      $elapsed = microtime(TRUE) - $start_time;

      $payload = [
        'status' => 'success',
        'data' => $results,
        'metadata' => [
          'item_count' => $this->countResults($search_type, $results),
          'response_time_ms' => round($elapsed * 1000, 2),
          'max_pages' => $this->isPaginatedSearchType($search_type) ? $max_pages : NULL,
        ],
      ];
    }
    catch (\Throwable $e) {
      $payload = [
        'status' => 'error',
        'message' => $e->getMessage(),
      ];
    }

    $formatted = json_encode(
      $payload,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ptv-debug-off-canvas']],
      'output' => [
        '#markup' => '<pre>' . Html::escape($formatted) . '</pre>',
      ],
    ];

    $response = new AjaxResponse();
    $response->addCommand(new OpenOffCanvasDialogCommand(
      (string) $this->t('PTV API Debug'),
      $content,
      ['width' => 520]
    ));

    return $response;
  }

  /**
   * Executes the appropriate search based on search type.
   */
  protected function executeSearch(string $search_type, array $params, int $max_pages = self::DEBUG_DEFAULT_MAX_PAGES): array {
    $params = $this->sanitizeParams($params);

    return match ($search_type) {
      'service' => $this->executeSingleServiceLookup($params),
      'services' => $this->ptvClient->serviceSearch($params, $max_pages),
      'channel' => $this->ptvClient->getServiceChannel($params['contentId']),
      'channels' => $this->ptvClient->serviceChannelSearch($params, $max_pages),
      'connection' => $this->ptvClient->connectionSearch($params, $max_pages),
      default => throw new \InvalidArgumentException('Invalid search type: ' . $search_type),
    };
  }

  /**
   * Execute GET /service/{contentId} with validation.
   */
  protected function executeSingleServiceLookup(array $params): array {
    $content_id = trim((string) ($params['contentId'] ?? ''));

    if ($content_id === '') {
      throw new \InvalidArgumentException('contentId is required for service_single search.');
    }
    if (!Uuid::isValid($content_id)) {
      throw new \InvalidArgumentException('contentId must be a valid UUID.');
    }

    return $this->ptvClient->getService($content_id);
  }

  /**
   * Counts logical results for metadata display.
   */
  protected function countResults(string $search_type, array $results): int {
    if ($search_type === 'service') {
      return empty($results) ? 0 : 1;
    }
    return count($results);
  }

  /**
   * Whether a search type uses paginated API endpoints.
   */
  protected function isPaginatedSearchType(string $search_type): bool {
    return in_array($search_type, ['services', 'channels', 'connection'], TRUE);
  }

  /**
   * Extracts params from request.
   */
  protected function extractParams(Request $request, string $search_type): array {
    $params = [];

    $page_size = $request->request->get('page_size');
    if ($page_size !== NULL && $page_size !== '') {
      $params['pageSize'] = (int) $page_size;
    }

    switch ($search_type) {
      case 'connection':
        $service_ids = $request->request->get('service_ids');
        if (is_string($service_ids) && $service_ids !== '') {
          $params['serviceContentIds'] = $service_ids;
        }

        $channel_ids = $request->request->get('channel_ids');
        if (is_string($channel_ids) && $channel_ids !== '') {
          $params['channelContentIds'] = $channel_ids;
        }
        break;

      case self::SEARCH_TYPE_SERVICE_SINGLE:
        $content_id = $request->request->get('content_id');
        if (is_string($content_id) && $content_id !== '') {
          $params['content_id'] = $content_id;
        }
        break;
    }

    return $params;
  }

  /**
   * Sanitizes parameter keys/values.
   */
  protected function sanitizeParams(array $params): array {
    $sanitized = [];

    foreach ($params as $key => $value) {
      if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
        continue;
      }

      if (is_string($value)) {
        $sanitized[$key] = trim($value);
      }
      elseif (is_int($value) || is_bool($value) || is_float($value)) {
        $sanitized[$key] = $value;
      }
      elseif (is_array($value)) {
        $sanitized[$key] = $this->sanitizeParams($value);
      }
    }

    return $sanitized;
  }

}
