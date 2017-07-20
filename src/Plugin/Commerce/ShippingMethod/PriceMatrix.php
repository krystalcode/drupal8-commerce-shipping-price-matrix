<?php

namespace Drupal\commerce_shipping_price_matrix\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\commerce_shipping\ShippingService;
use Drupal\Core\Form\FormStateInterface;

use League\Csv\Reader;

/**
 * Provides the Price Matrix shipping method.
 *
 * @CommerceShippingMethod(
 *   id = "price_matrix",
 *   label = @Translation("Price matrix"),
 * )
 */
class PriceMatrix extends ShippingMethodBase {

  /**
   * Constructs a new PriceMatrix object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $package_type_manager
   *   The package type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    PackageTypeManagerInterface $package_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $package_type_manager);

    $this->services['default'] = new ShippingService(
      'default',
      $this->configuration['rate_label']
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'price_matrix' => NULL,
      'rate_label' => NULL,
      'services' => ['default'],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['default_package_type']['#access'] = FALSE;

    $form['rate_label'] = [
      '#type' => 'textfield',
      '#title' => t('Rate label'),
      '#description' => t('Shown to customers during checkout.'),
      '#default_value' => $this->configuration['rate_label'],
      '#required' => TRUE,
    ];

    $form['price_matrix'] = [
      '#type' => 'fieldset',
      '#title' => t('Price matrix'),
      '#description' => t(''),
    ];

    $form['price_matrix']['csv_file'] = [
      '#type' => 'file',
      '#title' => t('Price matrix csv file'),
    ];

    if (empty($this->configuration['price_matrix'])) {
      return $form;
    }

    $header = [
      t('Threshold'),
      t('Type'),
      t('Value'),
      t('Minimum'),
      t('Maximum'),
    ];
    $rows = $this->configuration['price_matrix']['values'];
    $table = array(
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    );
    $markup = drupal_render($table);
    $form['price_matrix']['current_values'] = [
      '#markup' => $markup,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if ($form_state->getErrors()) {
      return;
    }

    $values = $form_state->getValue($form['#parents']);
    $this->configuration['rate_label'] = $values['rate_label'];

    $form_field_name = 'plugin';
    $all_files = \Drupal::request()->files->get('files', []);
    // Make sure there's an upload to process.
    if (empty($all_files[$form_field_name])) {
      return NULL;
    }
    $file_upload = $all_files[$form_field_name];

    // @todo: Validation.

    $file_realpath = $file_upload->getRealPath();
    $reader = Reader::createFromPath($file_realpath);
    $results = $reader->fetch();
    $r = [];
    $matrix_values = [];
    foreach ($results as $key => $row) {
      $r[] = $row;
      if (!isset($row[2])) {
        // Error
      }

      if (!is_numeric($row[0])) {
        // Error
      }
      $matrix_values[$key]['threshold'] = $row[0];

      if (!in_array($row[1], ['fixed_amount', 'percentage'])) {
        // Error
      }
      $matrix_values[$key]['type'] = $row[1];

      if (!is_numeric($row[2])) {
        // Error
      }
      $matrix_values[$key]['value'] = $row[2];

      if ($row[1] === 'fixed_amount' && isset($row[3])) {
        // Error
      }

      if (isset($row[3])) {
        if (!is_numeric($row[3])) {
          // Error
        }
        $matrix_values[$key]['min'] = $row[3];
      }

      if (isset($row[4])) {
        if (!is_numeric($row[4])) {
          // Error
        }
        $matrix_values[$key]['max'] = $row[4];
      }
    }

    // @todo: Currency code configuration.
    $this->configuration['price_matrix'] = [
      'currency_code' => 'USD',
      'values' => $matrix_values,
    ];

    unlink($file_realpath);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    $order = $shipment->getOrder();
    // @todo configuration on which product types/variations to include/exclude
    //       in the price calculation
    $order_subtotal = $order->getSubtotalPrice();
    $amount = new Price(
      $this->resolveMatrix($this->configuration['price_matrix'], $order_subtotal),
      $order_subtotal->getCurrencyCode()
    );
    // Rate IDs aren't relevant in our scenario.
    $rate_id = 0;
    $rates = [];
    $rates[] = new ShippingRate($rate_id, $this->services['default'], $amount);

    return $rates;
  }

  protected function resolveMatrix(array $matrix, $price) {
    $price_currency_code = $price->getCurrencyCode();
    $price_number = $price->getNumber();
    if ($matrix['currency_code'] !== $price_currency_code) {
      throw new \Exception('The shipping price matrix must be at the same currency as the order total for calculating the shipping costs.');
    }

    foreach ($matrix['values'] as $key => $value) {
      $bigger_than_current = Calculator::compare($price_number, $value['threshold']) !== -1;

      if (isset($matrix['values'][$key+1])) {
        $smaller_than_next = Calculator::compare($price_number, $matrix['values'][$key+1]['threshold']) === -1;
      }
      else {
        $smaller_than_next = TRUE;
      }

      if (!($bigger_than_current && $smaller_than_next)) {
        continue;
      }

      if ($value['type'] === 'fixed_amount') {
        return $value['value'];
      }

      if ($value['type'] !== 'percentage') {
        throw new \Exception(
          sprintf('Unsupported price matrix item "%s", \'fixed_amount\' or \'percentage\' expected.'),
          $value['type']
        );
      }

      $cost = Calculator::multiply($price_number, $value['value']);

      if (!empty($value['min']) && Calculator::compare($cost, $value['min']) === -1) {
        $cost = $value['min'];
      }
      elseif (!empty($value['max']) && Calculator::compare($cost, $value['max']) === 1){
        $cost = $value['max'];
      }

      return $cost;
    }
  }

}
