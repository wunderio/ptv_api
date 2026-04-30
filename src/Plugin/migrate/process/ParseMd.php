<?php

namespace Drupal\ptv_api\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * parse_md.
 *
 * Example usage with configuration:
 * @code
 * process:
 *   service_data:
 *     plugin: parse_md
 *     source: id
 * @endcode
 */
#[MigrateProcess(
  id: 'parse_md',
)]
class ParseMd extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    return (new \Parsedown())->text($value);
  }
}
