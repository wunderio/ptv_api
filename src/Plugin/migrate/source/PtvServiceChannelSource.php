<?php

namespace Drupal\ptv_api\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;

/**
 * Basic custom migrate source plugin.
 */
#[MigrateSource(
  id: 'ptv_service_channels_source'
)]
class PtvServiceChannelSource extends PtvSourceBase {

  /**
   * {@inheritdoc}
   */
  public function initializeIterator() {
    $rows = $this->ptvClient->serviceChannelSearch($this->params);

    $rows = $this->injectLangcodeToArray($rows);

    // Return an \Iterator over your source data.
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'PTV API Service channel Source';
  }

}
