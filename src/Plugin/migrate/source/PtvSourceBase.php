<?php

namespace Drupal\ptv_api\Plugin\migrate\source;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\ptv_api\Service\PtvClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Basic custom migrate source plugin.
 */
abstract class PtvSourceBase extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * Information on the source fields to be extracted from the data.
   *
   * @var array[]
   *   Array of field information keyed by field names. A 'label' subkey
   *   describes the field for migration tools; a 'path' subkey provides the
   *   source-specific path for obtaining the value.
   */
  protected $fields = [];

  /**
   * Description of the unique ID fields for this source.
   *
   * @var array[]
   *   Each array member is keyed by a field name, with a value that is an
   *   array with a single member with key 'type' and value a column type such
   *   as 'integer'.
   */
  protected $ids = [];

  /**
   * Params to filter calls.
   */
  protected $params = [];

  /**
   * Langcode for migrated content.
   */
  protected $langcode = NULL;

  /**
   * Should we skip missing translations.
   *
   * @var bool
   */
  protected $skipMissingTranslations;

  /**
   * Constructs the plugin.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param Drupal\ptv_api\Service\PtvClient $ptvClient
   *   The PTV API client service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    protected readonly PtvClient $ptvClient,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->fields = $configuration['fields'] ?? [];
    $this->ids = $configuration['ids'] ?? [];
    $this->params = $configuration['parameters'];
    $this->skipMissingTranslations =
      $configuration['skip_missing_translations'] ?? FALSE;
    $this->langcode = $configuration['constants']['langcode'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('ptv_api.client'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Internal helper to inject settings to source data.
   */
  protected function injectLangcodeToArray(array $rows): array {
    if ($this->langcode) {
      $rows = array_map(function (array $row): array {
        $row['langcode'] = $this->langcode;
        return $row;
      }, $rows);
    }

    return $rows;
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator() {
    $rows = $this->ptvClient->serviceSearch($this->params);

    if ($this->langcode) {
      $rows = array_map(function (array $row): array {
        $row['langcode'] = $this->langcode;
        return $row;
      }, $rows);
    }

    // Return an \Iterator over your source data.
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): array {
    $fields = [
      'contentId' => $this->t('Content ID'),
      'languageVersions' => $this->t('Language versions'),
    ];
    foreach ($this->fields as $field_info) {
      $fields[$field_info['name']] = $field_info['label'] ?? $field_info['name'];
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds(): array {
    // Add PTV fields automatically to source.
    $this->ids['contentId'] = [
      'type' => 'string',
    ];

    // If we use langcodes on migration lets add it also as unqiue key to
    // prevent collisions.
    if ($this->langcode) {
      $this->ids['langcode'] = [
        'type' => 'string',
      ];
    }

    return $this->ids;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString(): string {
    return 'PTV API Source';
  }

  /**
   * Pre-process a row before it moves to the process stage.
   */
  public function prepareRow(Row $row): bool {
    if ($this->langcode) {
      if ($this->skipMissingTranslations) {
        $language_versions = $row->getSourceProperty('languageVersions');
        // Skip non translated items.
        if (!isset($language_versions[$this->langcode])) {
          return FALSE;
        }

        $row->setSourceProperty('languageVersions', $language_versions[$this->langcode]);
      }
    }
    return parent::prepareRow($row);
  }

}
