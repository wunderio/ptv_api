<?php

namespace Drupal\ptv_api\Form;

use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\Exception\KeyValueNotRetrievedException;
use Drupal\ptv_api\Service\PtvClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for debugging PTV API queries.
 */
class PtvApiDebugForm extends FormBase {

  /**
   * Search types that use paginated endpoints.
   */
  protected const PAGINATED_SEARCH_TYPES = ['services', 'channels', 'connection'];

  /**
   * PTV client service.
   */
  protected PtvClient $ptvClient;

  /**
   * Module logger.
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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ptv_api_debug_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $prefill = []): array {
    // Apply URL-provided prefill only on the initial build (not on rebuild
    // after a submit), so user edits are never overwritten.
    if (!$form_state->isRebuilding() && !empty($prefill)) {
      $auto_run = !empty($prefill['auto_run']);
      unset($prefill['auto_run']);

      foreach (['search_type', 'service_ids', 'channel_ids', 'content_id'] as $key) {
        if (isset($prefill[$key])) {
          $form_state->setValue($key, (string) $prefill[$key]);
        }
      }
      foreach (['page_size', 'max_pages'] as $key) {
        if (isset($prefill[$key])) {
          $form_state->setValue($key, (int) $prefill[$key]);
        }
      }

      if ($auto_run && !empty($prefill['search_type'])) {
        if ($prefill['search_type'] !== 'service' || !empty($prefill['content_id'])) {
          $this->executeQuery($form_state);
        }
      }
    }

    $form['search_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Search type'),
      '#options' => [
        'service' => $this->t('Single service (by Content ID)'),
        'services' => $this->t('Services'),
        'channels' => $this->t('Service channels'),
        'connection' => $this->t('Connections'),
      ],
      '#default_value' => $form_state->getValue('search_type') ?? 'services',
      '#required' => TRUE,
    ];

    $form['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Page size'),
      '#default_value' => $form_state->getValue('page_size') ?: 100,
      '#min' => 1,
      '#max' => 1000,
      '#states' => [
        'visible' => [
          ':input[name="search_type"]' => [
            ['value' => 'services'],
            ['value' => 'channels'],
            ['value' => 'connection'],
          ],
        ],
      ],
    ];

    $form['max_pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Max pages'),
      '#description' => $this->t('Maximum number of pages to fetch. Keep this small for empty/broad queries; increase only when you know the result set is bounded.'),
      '#default_value' => $form_state->getValue('max_pages') ?: 1,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="search_type"]' => [
            ['value' => 'services'],
            ['value' => 'channels'],
            ['value' => 'connection'],
          ],
        ],
      ],
    ];

    $form['content_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service content ID'),
      '#description' => $this->t('Required UUID for GET /api/v12/service/{contentId}, e.g. 123e4567-e89b-12d3-a456-426614174000.'),
      '#default_value' => $form_state->getValue('content_id') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="search_type"]' => ['value' => 'service'],
        ],
      ],
    ];

    $form['service_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Service content IDs'),
      '#description' => $this->t('Comma-separated UUIDs for connection search (max 20, unique). Use either this OR Channel content IDs.'),
      '#default_value' => $form_state->getValue('service_ids') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="search_type"]' => ['value' => 'connection'],
        ],
      ],
    ];

    $form['channel_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Channel content IDs'),
      '#description' => $this->t('Comma-separated UUIDs for connection search (max 20, unique). Use either this OR Service content IDs.'),
      '#default_value' => $form_state->getValue('channel_ids') ?? '',
      '#states' => [
        'visible' => [
          ':input[name="search_type"]' => ['value' => 'connection'],
        ],
      ],
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run query'),
      '#button_type' => 'primary',
    ];

    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear results'),
      '#submit' => ['::clearResults'],
      '#limit_validation_errors' => [],
    ];

    $results = $form_state->get('results');
    $meta = $form_state->get('meta');

    if (is_array($meta)) {
      $items = [
        $this->t('Search type: @v', ['@v' => $meta['search_type'] ?? '-']),
        $this->t('Result count: @v', ['@v' => (string) ($meta['count'] ?? 0)]),
        $this->t('Response time: @v ms', ['@v' => (string) ($meta['ms'] ?? 0)]),
      ];

      if (($meta['max_pages'] ?? NULL) !== NULL) {
        $items[] = $this->t('Max pages: @v', ['@v' => (string) $meta['max_pages']]);
      }
      if (!empty($meta['content_id'])) {
        $items[] = $this->t('Endpoint contentId: @v', ['@v' => $meta['content_id']]);
      }

      $form['meta'] = [
        '#type' => 'details',
        '#title' => $this->t('Query metadata'),
        '#open' => TRUE,
      ];

      $form['meta']['items'] = [
        '#theme' => 'item_list',
        '#items' => $items,
      ];
    }

    if (is_array($results)) {
      $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

      $form['raw_json'] = [
        '#type' => 'details',
        '#title' => $this->t('Raw JSON'),
        '#open' => TRUE,
      ];
      $form['raw_json']['content'] = [
        '#markup' => '<pre>' . Html::escape($json ?: '[]') . '</pre>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $search_type = (string) $form_state->getValue('search_type');

    if ($this->isPaginatedSearchType($search_type)) {
      $page_size = (int) $form_state->getValue('page_size');
      if ($page_size < 1 || $page_size > 1000) {
        $form_state->setErrorByName('page_size', $this->t('Page size must be between 1 and 1000.'));
      }

      $max_pages = (int) $form_state->getValue('max_pages');
      if ($max_pages < 1 || $max_pages > 100) {
        $form_state->setErrorByName('max_pages', $this->t('Max pages must be between 1 and 100.'));
      }
    }

    if ($search_type === 'service') {
      $content_id = trim((string) $form_state->getValue('content_id'));
      if ($content_id === '') {
        $form_state->setErrorByName('content_id', $this->t('Service content ID is required.'));
      }
      elseif (!Uuid::isValid($content_id)) {
        $form_state->setErrorByName('content_id', $this->t('Service content ID must be a valid UUID.'));
      }
    }

    if ($search_type === 'connection') {
      $service_ids = $this->splitCsv((string) $form_state->getValue('service_ids'));
      $channel_ids = $this->splitCsv((string) $form_state->getValue('channel_ids'));

      if (empty($service_ids) && empty($channel_ids)) {
        $form_state->setErrorByName('service_ids', $this->t('Provide either Service content IDs or Channel content IDs for connection search.'));
      }

      if (!empty($service_ids) && !empty($channel_ids)) {
        $message = $this->t('Provide either Service content IDs or Channel content IDs, not both.');
        $form_state->setErrorByName('service_ids', $message);
        $form_state->setErrorByName('channel_ids', $message);
      }

      $this->validateConnectionContentIds($form_state, $service_ids, 'service_ids');
      $this->validateConnectionContentIds($form_state, $channel_ids, 'channel_ids');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->executeQuery($form_state);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Executes the configured PTV search and stores results on the form state.
   *
   * Shared by submitForm() and the URL-triggered auto-run path in buildForm().
   */
  protected function executeQuery(FormStateInterface $form_state): void {
    $search_type = (string) $form_state->getValue('search_type');
    $is_paginated = $this->isPaginatedSearchType($search_type);
    $params = $is_paginated ? $this->buildParams($form_state, $search_type) : [];
    $max_pages = $is_paginated ? max(1, (int) ($form_state->getValue('max_pages') ?: 1)) : 1;
    $content_id = trim((string) $form_state->getValue('content_id'));

    try {
      $start = microtime(TRUE);

      $results = match ($search_type) {
        'service' => $this->ptvClient->getService($content_id),
        'services' => $this->ptvClient->serviceSearch($params, $max_pages),
        'channels' => $this->ptvClient->serviceChannelSearch($params, $max_pages),
        'connection' => $this->ptvClient->connectionSearch($params, $max_pages),
        default => throw new \InvalidArgumentException('Invalid search type.'),
      };

      $ms = round((microtime(TRUE) - $start) * 1000, 2);
      $result_count = $this->countResults($search_type, $results);

      $form_state->set('results', $results);
      $form_state->set('meta', [
        'search_type' => $search_type,
        'count' => $result_count,
        'ms' => $ms,
        'max_pages' => $is_paginated ? $max_pages : NULL,
        'content_id' => $search_type === 'service' ? $content_id : NULL,
      ]);

      $this->logger->info('PTV debug query executed: type={type}, count={count}, max_pages={max_pages}, content_id={content_id}', [
        'type' => $search_type,
        'count' => $result_count,
        'max_pages' => $is_paginated ? $max_pages : '-',
        'content_id' => $search_type === 'service' ? $content_id : '-',
      ]);
    }
    catch (KeyValueNotRetrievedException) {
      $this->messenger()->addError($this->t('PTV API key is not configured. Configure it at /admin/config/services/ptv-api.'));
      $form_state->set('results', []);
      $form_state->set('meta', NULL);
    }
    catch (\Throwable $e) {
      $this->logger->error('PTV debug query failed: @message', ['@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('PTV query failed: @message', ['@message' => $e->getMessage()]));
      $form_state->set('results', []);
      $form_state->set('meta', NULL);
    }
  }

  /**
   * Clears in-form results.
   */
  public function clearResults(array &$form, FormStateInterface $form_state): void {
    $form_state->set('results', NULL);
    $form_state->set('meta', NULL);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Build PTV query params from form values.
   */
  protected function buildParams(FormStateInterface $form_state, string $search_type): array {
    $params = [
      'pageSize' => max(1, (int) ($form_state->getValue('page_size') ?: 100)),
    ];

    $language = trim((string) $form_state->getValue('language'));
    if ($language !== '') {
      $params['language'] = $language;
    }

    if ($search_type === 'connection') {
      $service_ids = $this->splitCsv((string) $form_state->getValue('service_ids'));
      $channel_ids = $this->splitCsv((string) $form_state->getValue('channel_ids'));

      // API filter logic is OR: use either serviceContentIds or channelContentIds.
      if (!empty($service_ids)) {
        $params['serviceContentIds'] = $service_ids;
      }
      elseif (!empty($channel_ids)) {
        $params['channelContentIds'] = $channel_ids;
      }
    }

    return $params;
  }

  /**
   * Check if the selected search type uses paginated API endpoint.
   */
  protected function isPaginatedSearchType(string $search_type): bool {
    return in_array($search_type, self::PAGINATED_SEARCH_TYPES, TRUE);
  }

  /**
   * Count logical results for metadata display.
   */
  protected function countResults(string $search_type, array $results): int {
    if ($search_type === 'service') {
      return empty($results) ? 0 : 1;
    }
    return count($results);
  }

  /**
   * Validate connection search content ID lists.
   */
  protected function validateConnectionContentIds(FormStateInterface $form_state, array $ids, string $field_name): void {
    if (empty($ids)) {
      return;
    }

    if (count($ids) > 20) {
      $form_state->setErrorByName($field_name, $this->t('You can provide at most 20 IDs.'));
    }

    $normalized = array_map(static fn(string $id): string => strtolower($id), $ids);
    if (count($normalized) !== count(array_unique($normalized))) {
      $form_state->setErrorByName($field_name, $this->t('IDs must be unique.'));
    }

    foreach ($ids as $id) {
      if (!Uuid::isValid($id)) {
        $form_state->setErrorByName($field_name, $this->t('All IDs must be valid UUIDs.'));
        break;
      }
    }
  }

  /**
   * Split comma-separated values.
   */
  protected function splitCsv(string $value): array {
    $parts = array_map('trim', explode(',', $value));
    $parts = array_filter($parts, static fn(string $v): bool => $v !== '');
    return array_values($parts);
  }

}
