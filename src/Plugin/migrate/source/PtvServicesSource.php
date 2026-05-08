<?php

namespace Drupal\ptv_api\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;

/**
 * Basic custom migrate source plugin.
 */
#[MigrateSource(
  id: 'ptv_services_source'
)]
class PtvServicesSource extends PtvSourceBase {

  /**
   * {@inheritdoc}
   */
  public function initializeIterator() {
    $rows = $this->ptvClient->serviceSearch($this->params);

    $rows = $this->injectLangcodeToArray($rows);

    // Return an \Iterator over your source data.
    return new \ArrayIterator($rows);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'PTV API Services Source';
  }

}
