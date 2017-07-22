<?php

namespace Drupal\commerce_shipping_price_matrix\Plugin\Commerce\ShippingMethod;

use Drupal\commerce\EntityHelper;
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
      'order_subtotal' => NULL,
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
      '#title' => $this->t('Rate label'),
      '#description' => $this->t('Shown to customers during checkout.'),
      '#default_value' => $this->configuration['rate_label'],
      '#required' => TRUE,
    ];

    // Configuration for how to calculate the order subtotal based on which we
    // will calculate the shipping costs.
    $form['order_subtotal'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Order Subtotal Calculation'),
    ];

    // Allow excluding certain product variation types from the order subtotal
    // that will be used to calculate the shipping costs.

    // Get all product variation types for the form.
    // @todo Ideally we should be getting the entity type manager with
    // dependency injection.
    $storage = \Drupal::service('entity_type.manager')->getStorage('commerce_product_variation_type');
    $product_variation_types = $storage->loadMultiple();

    // Currently excluded product variation types.
    $exclude_product_variations = [];
    if (!empty($this->configuration['order_subtotal']['exclude_product_variations'])) {
      $exclude_product_variations = $this->configuration['order_subtotal']['exclude_product_variations'];
    }

    $form['order_subtotal']['exclude_product_variations'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Exlclude product variations'),
      '#options' => EntityHelper::extractLabels($product_variation_types),
      '#default_value' => $exclude_product_variations,
      '#description' => $this->t('The product variations that will be excluded from the order subtotal that will be used for calculating the shipping costs. If none selected, all product variations will be included in the calculation.'),
    ];

    // Configuration related to the price matrix.
    $form['price_matrix'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Price matrix'),
      '#description' => $this->t('The Price Matrix defines how the shipping costs are calculated for a given order. Each entry is of one of two types: Fixed Amount or Percentage. Both types have a minimum of three elements.') . '<ol><li>' . $this->t('Threshold: orders with a price that is larger or equal to this value and smaller than the threshold of the next entry will use this entry for calculating the shiping costs.') . '</li><li>' . $this->t('Type: the type of the entry, Fixed Amount ("fixed_amount") or Percentage "percentage".') . '</li><li>' . $this->t('Value: For Fixed Amount entries, simply a price. For Percentage entries, the percentage of the order price to charge as the shipping costs.') . '</ol>' . $this->t('Entries of type Percentage can optionally have two additional elements.') . '<ol><li>' . $this->t('Minimum: The minimum shipping costs to charge, if the calculated percentage is lower than this value.') . '</li><li>' . $this->t('Maximum: The maximum shipping costs to charge, if the calculated percentage is higher than this value.') . '</li></ol>',
    ];

    $form['price_matrix']['csv_file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload as a CSV file'),
      '#description' => $this->t('Add matrix entries from a CSV file. Columns should be in the following order: threshold, type, value, minimum, maximum. No header row should be present.') . '<br /><strong>' . $this->t('All current entries will be removed and replaced by the entries defined in the uploaded file.') . '</strong>',
      '#weight' => 2,
    ];

    // The current price matrix for display purposes. It will either be coming
    // from the configuration when the form is first created, but when submitted
    // and rebuild after an error the configuration won't be available and the
    // price matrix is available via the hidden field - see below.
    $price_matrix = NULL;
    if (!empty($this->configuration['price_matrix']['values'])) {
      $price_matrix = $this->configuration['price_matrix']['values'];
    }
    else {
      $values = $form_state->getValue($form['#parents']);
      if (!empty($values['price_matrix']['current_entries'])) {
        $price_matrix = json_decode(
          $values['price_matrix']['current_entries'],
          TRUE
        );
      }
    }

    // Don't render the matrix table if it hasn't been defined yet.
    // In the future we should add a form table for adding/updating matrix
    // entries, but at the moment there is no point in doing so because adding
    // new entries is not supported.
    if (!$price_matrix) {
      return $form;
    }

    $header = [
      $this->t('Threshold'),
      $this->t('Type'),
      $this->t('Value'),
      $this->t('Minimum'),
      $this->t('Maximum'),
    ];

    // Read-only table for displaying current values.
    $form['price_matrix']['display'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $price_matrix,
      '#weight' => 0,
    ];

    // If we don't add the existing matrix as a form field, we won't have it
    // available in the submit handler where we need to save it in the shipping
    // method's configuration (if there's no CSV file upload). We therefor make
    // it available as a hidden field. This should probably be a temporary
    // solution until we implement a fully functioning form table that allows
    // editing the matrix entries from the UI.
    $form['price_matrix']['current_entries'] = [
      '#type' => 'hidden',
      '#default_value' => json_encode($price_matrix),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    parent::validateConfigurationForm($form, $form_state);

    // Get the matrix values from the uploaded CSV file, if provided.
    // The file is uploaded as 'plugin' even though the form field is defined as
    // 'csv_file'.
    $form_field_name = 'plugin';
    $all_files = \Drupal::request()->files->get('files', []);

    // There's no further validation to do if there was no file uploaded, apart
    // from guaranteeing that we do have an existing matrix since it is a
    // requirement.
    if (empty($all_files[$form_field_name])) {
      $values = $form_state->getValue($form['#parents']);
      if (empty($values['price_matrix']['current_entries'])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t('The Price Matrix cannot be empty. Please upload a CSV file with the matrix entries.')
        );
      }
      return;
    }

    $file_upload = $all_files[$form_field_name];
    if (!$file_upload->isValid()) {
      $form_state->setErrorByName(
        'csv_file',
        $this->t(
          'An error has occurred while uploading the CSV file, please try again. The error message was: @error_message',
          [
            '@error_message' => $file_upload->getErrorMessage(),
          ]
        )
      );
      return;
    }

    // UploadFile advises to get the MIME type from getMimeType() as
    // getClientMimeType() is not considered a safe value. However,
    // getMimeType() does not always get it right as it tries to guess it from
    // the file content, so we check for both.
    if ($file_upload->getMimeType() !== 'text/csv' && $file_upload->getClientMimeType() !== 'text/csv') {
      $form_state->setErrorByName(
        'csv_file',
        $this->t(
          'The uploaded file must be in CSV format. Expected MIME type is "text/csv", "@mime_type" given.',
          [
            '@mime_type' => $file_upload->getClientMimeType(),
          ]
        )
      );
      return;
    }

    // Read the values from the file.
    $file_realpath = $file_upload->getRealPath();
    $reader = Reader::createFromPath($file_realpath);
    $rows = $reader->fetch();

    // We'll be storing the final matrix values in the desired format here.
    $matrix_values = [];

    // We prefer a strict validation which means that we won't be keeping any
    // entry even if there is an error only in one row. Let's go through all
    // rows for errors but display only one error per row at a time.
    // Note: at the time of writing Drupal 8 does not support setting multiple
    // errors per form element. Only one error will therefore be displayed for
    // the CSV file element.
    foreach ($rows as $row_key => $row) {
      // We need at least 3 columns in each row otherwise the file is not valid.
      if (!isset($row[2])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'Row %row_number has only %num_columns columns, at least three are required.',
            [
              '%row_number' => $row_key+1,
              '%num_columns' => count($row),
            ]
          )
        );
        continue;
      }

      // Column 1: Price threshold, must be a numeric value.
      $current_column_key = 0;
      if (!is_numeric($row[$current_column_key])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'Row %row_number Column %column_number should hold a numeric value indicating the threshold price. "@column_value" given',
            [
              '%row_number' => $row_key+1,
              '%column_number' => $current_column_key+1,
              '@column_value' => $row[$current_column_key],
            ]
          )
        );
        continue;
      }
      // The price threshold for the first entry should always be 0.
      if ($row_key === 0 && (int) $row[$current_column_key] !== 0) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'The price threshold for the first entry (row %row_number, column %column_number) must be zero. "@column_value" given.',
            [
              '%row_number' => $row_key+1,
              '%column_number' => $current_column_key+1,
              '@column_value' => $row[$current_column_key],
            ]
          )
        );
        continue;
      }
      $matrix_values[$row_key]['threshold'] = $row[$current_column_key];

      // Column 2: Entry type, 'fixed_amount' and 'percentage' supported.
      $current_column_key = 1;
      if (!in_array($row[$current_column_key], ['fixed_amount', 'percentage'])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'Row %row_number Column %column_number should either be "fixed_amount" or "percentage", indicating the type of the entry. "@column_value" given.',
            [
              '%row_number' => $row_key+1,
              '%column_number' => $current_column_key+1,
              '@column_value' => $row[$current_column_key],
            ]
          )
        );
        continue;
      }
      $matrix_values[$row_key]['type'] = $row[$current_column_key];

      // Column 3: Entry value, either a price value or a percentage
      // i.e. numeric.
      $current_column_key = 2;
      if (!is_numeric($row[$current_column_key])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'Row %row_number Column %column_number should hold a numeric value, indicating either a fixed amount or a percentage. "@column_value" given.',
            [
              '%row_number' => $row_key+1,
              '%column_number' => $current_column_key+1,
              '@column_value' => $row[$current_column_key],
            ]
          )
        );
        continue;
      }
      // Additionally, percentages must be given as values between 0 and 1.
      if ($row[1] === 'percentage' && ($row[$current_column_key] < 0 || $row[$current_column_key] > 1)) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'Row %row_number Column %column_number should hold a numeric value between 0 and 1, indicating a percentage. "@column_value" given.',
            [
              '%row_number' => $row_key+1,
              '%column_number' => $current_column_key+1,
              '@column_value' => $row[$current_column_key],
            ]
          )
        );
        continue;
      }
      $matrix_values[$row_key]['value'] = $row[$current_column_key];

      // If the entry type is 'fixed_amount' there should be no more columns.
      if ($row[1] === 'fixed_amount' && isset($row[3])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t(
            'Row %row_number that is of "fixed_amount" type should only have %num_columns columns.',
            [
              '%row_number' => $row_key+1,
              '%num_columns' => count($row),
            ]
          )
        );
        continue;
      }

      // Column 4: Minimum value, optional, must be a numeric value.
      $current_column_key = 3;
      if (isset($row[$current_column_key])) {
        if (!is_numeric($row[$current_column_key])) {
          $form_state->setErrorByName(
            'csv_file',
            $this->t(
              'Row %row_number Column %column_number should hold a numeric value, indicating a minimum cost. "@column_value" given.',
              [
                '%row_number' => $row_key+1,
                '%column_number' => $current_column_key+1,
                '@column_value' => $row[$current_column_key],
              ]
            )
          );
          continue;
        }
        $matrix_values[$row_key]['min'] = $row[$current_column_key];
      }

      // Column 5: Maximum value, optional, must be a numeric value.
      $current_column_key = 4;
      if (isset($row[$current_column_key])) {
        if (!is_numeric($row[$current_column_key])) {
          $form_state->setErrorByName(
            'csv_file',
            $this->t(
              'Row %row_number Column %column_number should hold a numeric value, indicating a maximum cost. "@column_value" given.',
              [
                '%row_number' => $row_key+1,
                '%column_number' => $current_column_key+1,
                '@column_value' => $row[$current_column_key],
              ]
            )
          );
          continue;
        }
        $matrix_values[$row_key]['max'] = $row[$current_column_key];
      }
    }

    // To avoid possible accidental errors in the entries, we don't sort the
    // entries ourselves. Instead, we detect whether they are in the correct
    // order (lower threshold to higher threshold) and we raise an error if
    // not.
    foreach ($matrix_values as $key => $value) {
      // The threshold should always be smaller than that of the next entry.
      $next_key = $key+1;
      if (!empty($matrix_values[$next_key]['threshold']) && !($value['threshold'] < $matrix_values[$next_key]['threshold'])) {
        $form_state->setErrorByName(
          'csv_file',
          $this->t('The rows provided in the CSV file are not in the correct order. The threshold of each row must be lower than that of the next row.')
        );
      }
    }

    // Make the final matrix in the form's storage so that it can saved by the
    // submit handler
    // @todo: Currency code configuration.
    $form_state->set(
      'commerce_shipping_price_matrix__updated',
      [
        'currency_code' => NULL,
        'values' => $matrix_values,
      ]
    );

    // We are not storing the file, delete it.
    unlink($file_realpath);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ) {
    if ($form_state->getErrors()) {
      return;
    }

    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    // Rate label.
    $this->configuration['rate_label'] = $values['rate_label'];

    // Excluded product variation types.
    $exclude_product_variations = array_filter($values['order_subtotal']['exclude_product_variations']);
    if ($exclude_product_variations) {
      $this->configuration['order_subtotal'] = [
        'exclude_product_variations' => $exclude_product_variations,
      ];
    }

    // Check if we have entries uploaded via a CSV file. They are saved in the
    // FormState's storage by the validation handler. If we do, save them as the
    // new price matrix.
    $price_matrix = $form_state->get('commerce_shipping_price_matrix__updated');
    if ($price_matrix) {
      $this->configuration['price_matrix'] = $price_matrix;
    }
    // Otherwise, we must have the existing entries from the hidden field.
    elseif (!empty($values['price_matrix']['current_entries'])) {
      $this->configuration['price_matrix'] = [
        'currency_code' => NULL,
        'values' => json_decode(
          $values['price_matrix']['current_entries'],
          TRUE
        ),
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function calculateRates(ShipmentInterface $shipment) {
    // Get the order subtotal which will be used as the price for calculating
    // the shipping costs.
    $order = $shipment->getOrder();
    $order_subtotal = $order->getSubtotalPrice();

    // If we have any product variation types configured to be excluded from the
    // order subtotal, go through all order items and subtract the total price
    // of all items that match the specified product variation types.
    if (!empty($this->configuration['order_subtotal']['exclude_product_variations'])) {
      $order_items = $order->getItems();
      foreach ($order_items as $order_item) {
        $purchased_entity = $order_item->getPurchasedEntity();
        $excluded_type = $purchased_entity->getEntityTypeId() === 'commerce_product_variation';
        $excluded_bundle = in_array(
          $purchased_entity->bundle(),
          $this->configuration['order_subtotal']['exclude_product_variations']
        );
        if ($excluded_type && $excluded_bundle) {
          $order_subtotal = $order_subtotal->subtract($order_item->getTotalPrice());
        }
      }
    }

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
    // We currently disable the check until we have added the currency code in
    // the configuration form.
    // if ($matrix['currency_code'] !== $price_currency_code) {
    //   throw new \Exception('The shipping price matrix must be at the same currency as the order total for calculating the shipping costs.');
    // }

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
