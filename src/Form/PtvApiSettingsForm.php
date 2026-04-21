<?php

namespace Drupal\ptv_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\ptv_api\Service\PtvClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for PTV API settings.
 */
class PtvApiSettingsForm extends ConfigFormBase {

  /**
   * The key repository service.
   */
  protected KeyRepositoryInterface $keyRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->keyRepository = $container->get('key.repository');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['ptv_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ptv_api_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ptv_api.settings');

    // Build options from available keys.
    $keys = $this->keyRepository->getKeys();
    $options = ['' => $this->t('- Select a key -')];
    foreach ($keys as $key) {
      $options[$key->id()] = $key->label();
    }

    $form['base_uri'] = [
      '#type' => 'select',
      '#title' => $this->t('PTV Base URI'),
      '#description' => $this->t('Select the PTV API environment to use.'),
      '#options' => PtvClient::BASE_URI_OPTIONS,
      '#default_value' => $config->get('base_uri') ?? PtvClient::PTV_BASE_URI_PRODUCTION,
      '#required' => TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'select',
      '#title' => $this->t('PTV API Key'),
      '#description' => $this->t('Select the key from the Key repository to use for authenticating with the PTV API.'),
      '#options' => $options,
      '#default_value' => $config->get('api_key') ?? '',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('ptv_api.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('base_uri', $form_state->getValue('base_uri'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
