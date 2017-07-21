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
      '#title' => t('Upload as a CSV file'),
      '#description' => t('Add matrix entries from a CSV file. Columns should be in the following order: threshold, type, value, minimum, maximum. No header row should be present.') . '<br /><strong>' . t('All current entries will be removed and any updates via the form table below (available for existing price matrices) will not have any effect.') . '</strong><br /><br />',
      '#weight' => 2,
    ];

    // Don't render the matrix table if it hasn't been defined yet.
    // In the future we could render the table for adding/updating matrix
    // entries, but at the moment there is no point in doing so because adding
    // new entries is not supported.
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

    // Read-only table for displaying current values.
    $form['price_matrix']['display'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $this->configuration['price_matrix']['values'],
      '#weight' => 0,
    ];

    $form['price_matrix']['help'] = [
      '#markup' => '<br /><p>' . t('You can update the Price Matrix by uploading a CSV file or by directly editing the entries in the table below. Note that the table only allows you to edit existing entries at the moment - you cannot add new entries or remove existing ones. This will be fixed in future releases.') . '</p>',
      '#weight' => 1,
    ];

    // Table for updating current values.
    $form['price_matrix']['entries'] = [
      '#type' => 'table',
      '#header' => $header,
      '#weight' => 3,
    ];
    foreach ($this->configuration['price_matrix']['values'] as $key => $value) {
      $form['price_matrix']['entries'][$key]['threshold'] = [
        '#type' => 'textfield',
        '#default_value' => $value['threshold'],
        '#required' => TRUE,
      ];
      $form['price_matrix']['entries'][$key]['type'] = [
        '#type' => 'textfield',
        '#default_value' => $value['type'],
        '#required' => TRUE,
      ];
      $form['price_matrix']['entries'][$key]['value'] = [
        '#type' => 'textfield',
        '#default_value' => $value['value'],
        '#required' => TRUE,
      ];
      if (!empty($value['min'])) {
        $form['price_matrix']['entries'][$key]['min'] = [
          '#type' => 'textfield',
          '#default_value' => $value['min'],
        ];
      }
      if (!empty($value['max'])) {
        $form['price_matrix']['entries'][$key]['max'] = [
          '#type' => 'textfield',
          '#default_value' => $value['max'],
        ];
      }
    }

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

    // Nothing to do if there was no file uploaded.
    if (empty($all_files[$form_field_name])) {
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

    // Make the final matrix in the form's storage so that it can saved by the
    // submit handler
    // @todo: Currency code configuration.
    $form_state->set(
      'commerce_shipping_price_matrix__updated',
      [
        'currency_code' => 'USD',
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
    $this->configuration['rate_label'] = $values['rate_label'];

    // Check if we have entries uploaded via a CSV file. They are saved in the
    // FormState's storage by the validation handler. If we do, save them as the
    // new price matrix.
    $price_matrix = $form_state->get('commerce_shipping_price_matrix__updated');
    if ($price_matrix) {
      $this->configuration['price_matrix'] = $price_matrix;
    }
    // Otherwise, we must have entries from the form table.
    elseif (!empty($values['price_matrix']['entries'])) {
      $this->configuration['price_matrix'] = [
        'currency_code' => NULL,
        'values' => $values['price_matrix']['entries'],
      ];
    }
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
