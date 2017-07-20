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

    // We don't need packages in our case - disable access to related
    // configuration. It is still kept internally because it seems to be
    // required for all shipping methods.
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

    // Don't render the matrix table if it hasn't been defined yet.
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

    // Get the matrix values from the uploaded CSV file, if provided.
    // The file is uploaded as 'plugin' even though the form field is defined as
    // 'csv_file'.
    $form_field_name = 'plugin';
    $all_files = \Drupal::request()->files->get('files', []);

    // Nothing to do if there was no file uploaded.
    if (empty($all_files[$form_field_name])) {
      return;
    }

    $file_upload = $all_files[$form_field_name];
    $file_realpath = $file_upload->getRealPath();

    // @todo: Validation.
    // $file_upload->isValid()
    // $file_upload->getErrorMessage()
    // $file_upload->getClientMimeType();
    // 'text/csv'

    // Read the values from the file.
    $reader = Reader::createFromPath($file_realpath);
    $results = $reader->fetch();

    // We'll be storing the final matrix values in the desired format here.
    $matrix_values = [];

    // We prefer a strict validation which means that we'll be returning an
    // error immediately when we detect one in any row. We won't be keeping any
    // entry even if there is an error only in one row.
    // Users can correct the CSV file and try again.
    foreach ($results as $key => $row) {
      // We need at least 3 columns in each row otherwise the file is not valid.
      if (!isset($row[2])) {
        // Error
      }

      // Column 1: Price threshold, must be a numeric value.
      if (!is_numeric($row[0])) {
        // Error
      }
      $matrix_values[$key]['threshold'] = $row[0];

      // Column 2: Entry type, 'fixed_amount' and 'percentage' supported.
      if (!in_array($row[1], ['fixed_amount', 'percentage'])) {
        // Error
      }
      $matrix_values[$key]['type'] = $row[1];

      // Column 3: Entry value, either a price value or a percentage
      // i.e. numeric.
      // @todo validate that the percentage is a number between 0 and 1
      if (!is_numeric($row[2])) {
        // Error
      }
      $matrix_values[$key]['value'] = $row[2];

      // If the entry type is 'fixed_amount' there should be no more columns.
      if ($row[1] === 'fixed_amount' && isset($row[3])) {
        // Error
      }

      // Column 4: Minimum value, must be a numeric value.
      if (isset($row[3])) {
        if (!is_numeric($row[3])) {
          // Error
        }
        $matrix_values[$key]['min'] = $row[3];
      }

      // Column 5: Maximum value, must be a numeric value.
      if (isset($row[4])) {
        if (!is_numeric($row[4])) {
          // Error
        }
        $matrix_values[$key]['max'] = $row[4];
      }
    }

    // Save the final matrix in the method's configuration.
    // @todo: Currency code configuration.
    $this->configuration['price_matrix'] = [
      'currency_code' => 'USD',
      'values' => $matrix_values,
    ];

    // We are not storing the file, delete it.
    unlink($file_realpath);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    // Get the order subtotal which will be used as the price for calculating
    // the shipping costs.
    // @todo configuration on which product types/variations to include/exclude
    //       in the price calculation
    $order = $shipment->getOrder();
    $order_subtotal = $order->getSubtotalPrice();

    // Calculate the shipping costs.
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

  /**
   * Calculates the costs for the given price based on the given price matrix.
   *
   * @param array $matrix
   *   The price matrix that will be used for calculating the shipping costs.
   * @param \Drupal\commerce_price\Price
   *   The price based on which the shipping costs will be calculated.
   */
  protected function resolveMatrix(array $matrix, $price) {
    $price_currency_code = $price->getCurrencyCode();
    $price_number = $price->getNumber();

    // The price matrix must be in the same currency as the order.
    if ($matrix['currency_code'] !== $price_currency_code) {
      throw new \Exception('The shipping price matrix must be at the same currency as the order total for calculating the shipping costs.');
    }

    // We detect which matrix entry the price falls under. It should be larger
    // or equal than the entry's threshold and smaller than the next entry's
    // threshold. Only larger or equal then the entry's threshold in the case of
    // the last entry.
    foreach ($matrix['values'] as $key => $value) {
      $bigger_than_current = Calculator::compare($price_number, $value['threshold']) !== -1;

      if (isset($matrix['values'][$key+1])) {
        $smaller_than_next = Calculator::compare($price_number, $matrix['values'][$key+1]['threshold']) === -1;
      }
      else {
        $smaller_than_next = TRUE;
      }

      // Doesn't match the current entry, move on to the next one.
      if (!($bigger_than_current && $smaller_than_next)) {
        continue;
      }

      // If the type of the matched entry is 'fixed_amount', the cost is fixed
      // and it equals the entry's value.
      if ($value['type'] === 'fixed_amount') {
        return $value['value'];
      }

      // Throw an exception if the type is neither 'fixed_amount' nor
      // 'percentage'.
      if ($value['type'] !== 'percentage') {
        throw new \Exception(
          sprintf('Unsupported price matrix item "%s", \'fixed_amount\' or \'percentage\' expected.'),
          $value['type']
        );
      }

      // If the type of the matched entry is 'percentage', the cost is the given
      // price multiplied by the percentage factor i.e. the entry's value.
      $cost = Calculator::multiply($price_number, $value['value']);

      // Check minimum and maximum constraints.
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
