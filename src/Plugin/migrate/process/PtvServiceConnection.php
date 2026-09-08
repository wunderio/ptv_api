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
 * ptv_service_connection.
 *
 * Example usage with configuration:
 * @code
 * process:
 *   service_data:
 *     plugin: ptv_service_connection
 *     source: id
 * @endcode
 */
#[MigrateProcess(
  id: 'ptv_service_connection',
)]
class PtvServiceConnection extends ProcessPluginBase implements ContainerFactoryPluginInterface {

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
    $r = [];
    switch($this->configuration['content']  ?? '') {
      case 'channel':
        $connections = $this->ptvClient->connectionSearch([
          'serviceContentIds' => $value
        ]);
        foreach ($connections as $connection) {
          $r[] = ['channel_content_id' => $connection['channelContentId']];
        }
        break;
      case 'service':
        $connections = $this->ptvClient->connectionSearch([
          'channelContentIds' => $value
        ]);
        foreach ($connections as $connection) {
          $r[] = ['service_content_id' => $connection['serviceContentId']];
        }
        break;
      default:
        return NULL;
        break;;
    }

    return $r;
  }
}
