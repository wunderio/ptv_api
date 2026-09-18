<?php

namespace Drupal\ptv_api\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\ptv_api\Service\PtvClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * ptv_get_city_name.
 *
 * Example usage with configuration:
 * @code
 * process:
 *   service_data:
 *     plugin: ptv_get_city_name
 *     source: id
 * @endcode
 */
#[MigrateProcess(
  id: 'ptv_get_city_name',
)]
class PtvHelperGetCityName extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a PtvServiceConnection plugin.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\ptv_api\Service\PtvClient $ptvClient
   *   The PTV client service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly PtvClient $ptvClient,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ptv_api.client'),
    );
  }


  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if (empty($value)) {
      return NULL;
    }

    $r = $this->ptvClient->getPostalCodeInfo($value);

    if (empty($r)) {
      return NULL;
    }

    $r = reset($r);
    if (!isset($r['name'])) {
      return NULL;
    }

    foreach ($r['name'] as &$city) {
      $city = mb_convert_case($city, MB_CASE_TITLE, "UTF-8");
    }

    return $r['name'];
  }
}
