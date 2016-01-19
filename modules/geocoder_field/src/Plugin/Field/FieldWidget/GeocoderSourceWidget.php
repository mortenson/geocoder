<?php

/**
 * @file
 * Contains \Drupal\geocoder_field\Plugin\Field\FieldWidget\GeocoderSourceWidget.
 */

namespace Drupal\geocoder_field\Plugin\Field\FieldWidget;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\geocoder\Geocoder;

/**
 * Plugin implementation of the 'geocoder_source_widget' widget.
 *
 * @FieldWidget(
 *   id = "geocoder_source_widget",
 *   label = @Translation("Geocode from source"),
 *   field_types = {
 *     "geofield"
 *   },
 * )
 */
class GeocoderSourceWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return array(
      'source_field' => '',
      'geocoder_plugins' => array(),
      'dumper_plugin' => 'wkt',
      'show_coordinates' => TRUE,
    ) + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager */
    $entity_field_manager = \Drupal::service('entity_field.manager');
    $entity_field_definitions = $entity_field_manager->getFieldDefinitions($this->fieldDefinition->getTargetEntityTypeId(), $this->fieldDefinition->getTargetBundle());

    $options = array();
    foreach ($entity_field_definitions as $id => $definition) {
      if ($definition->getType() == 'string') {
        $options[$id] = $definition->getLabel();
      }
    }

    $elements['source_field'] = array(
      '#type' => 'select',
      '#title' => $this->t('Source Field'),
      '#default_value' => $this->getSetting('source_field'),
      '#required' => TRUE,
      '#options' => $options,
    );

    $enabled_plugins = array();
    $i = 0;
    foreach($this->getSetting('geocoder_plugins') as $plugin_id => $plugin) {
      if ($plugin['checked']) {
        $plugin['weight'] = intval($i++);
        $enabled_plugins[$plugin_id] = $plugin;
      }
    }

    $elements['geocoder_plugins_title'] = array(
      '#type' => 'item',
      '#title' => t('Geocoder plugin(s)'),
      '#description' => t('Select the Geocoder plugins to use, you can reorder them. The first one to return a valid value will be used.'),
    );

    $elements['geocoder_plugins'] = array(
      '#type' => 'table',
      '#header' => array(
        array('data' => $this->t('Enabled')),
        array('data' => $this->t('Weight')),
        array('data' => $this->t('Name')),
      ),
      '#tabledrag' => array(
        array(
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'geocoder_plugins-order-weight',
        ),
      ),
    );

    $rows = array();
    $count = count($enabled_plugins);
    foreach (Geocoder::getPlugins('Provider') as $plugin_id => $plugin_name) {
      if (isset($enabled_plugins[$plugin_id])) {
        $weight = $enabled_plugins[$plugin_id]['weight'];
      } else {
        $weight = $count++;
      }

      $rows[$plugin_id] = array(
        '#attributes' => array(
          'class' => array('draggable'),
        ),
        '#weight' => $weight,
        'checked' => array(
          '#type' => 'checkbox',
          '#default_value' => isset($enabled_plugins[$plugin_id]) ? 1 : 0,
        ),
        'weight' => array(
          '#type' => 'weight',
          '#title' => t('Weight for @title', array('@title' => $plugin_id)),
          '#title_display' => 'invisible',
          '#default_value' => $weight,
          '#attributes' => array('class' => array('geocoder_plugins-order-weight')),
        ),
        'name' => array(
          '#plain_text' => $plugin_name,
        ),
      );
    }

    uasort($rows, function($a, $b) {
      return strcmp($a['#weight'], $b['#weight']);
    });

    foreach($rows as $plugin_id => $row) {
      $elements['geocoder_plugins'][$plugin_id] = $row;
    }

    $elements['dumper_plugin'] = array(
      '#type' => 'select',
      '#title' => 'Output format',
      '#default_value' => $this->getSetting('dumper_plugin'),
      '#options' => Geocoder::getPlugins('dumper'),
      '#description' => t('Set the output format of the value. Ex, for a geofield, the format must be set to WKT.')
    );

    $elements['show_coordinates'] = array(
      '#type' => 'checkbox',
      '#title' => t('Show Coordinates'),
      '#default_value' => $this->getSetting('show_coordinates'),
      '#description' => t('Whether or not the current coordinates should be shown in the form.')
    );

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = array();
    $dumper_plugin = $this->getSetting('dumper_plugin');

    $summary[] = $this->t('Source Field: @source', array('@source' => $this->getSetting('source_field')));

    $geocoder_plugins = Geocoder::getPlugins('Provider');
    $dumper_plugins = Geocoder::getPlugins('Dumper');

    // Find the enabled geocoder plugins.
    $geocoder_plugin_ids = array();
    foreach($this->getSetting('geocoder_plugins') as $plugin_id => $plugin) {
      if ($plugin['checked']) {
        $geocoder_plugin_ids[] = $geocoder_plugins[$plugin_id];
      }
    }

    if (!empty($geocoder_plugin_ids)) {
      $summary[] = t('Geocoder plugin(s): @plugin_ids', array('@plugin_ids' => implode(', ', $geocoder_plugin_ids)));
    }
    if (!empty($dumper_plugin)) {
      $summary[] = t('Output format plugin: @format', array('@format' => $dumper_plugins[$dumper_plugin]));
    }

    $coordinates = $this->getSetting('show_coordinates') ? 'shown' : 'hidden';
    $summary[] = $this->t('Coordinates are ' . $coordinates);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // The user has the option of hiding the current coordinates from view.
    if ($this->getSetting('show_coordinates')) {
      $element += array(
        '#type' => 'textfield',
        '#disabled' => TRUE,
        '#placeholder' => t('Latitude: @lat, Longitude: @lon', array('@lat' => $items[$delta]->lat, '@lon' => $items[$delta]->lon)),
        '#suffix' => t('These values are set dynamically from the @field field.', array('@field' => $this->getSetting('source_field'))),
      );
    }
    else {
      // We set this field dynamically, no need to have more than the minimum.
      $element += array(
        '#type' => 'hidden',
        '#value' => ''
      );
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $source_field = $this->getSetting('source_field');
    $dumper_plugin = $this->getSetting('dumper_plugin');
    $geocoder_plugins = $this->getSetting('geocoder_plugins');

    // Check that the source field has been set.
    if (!empty($source_field)) {
      // Find the enabled geocoder plugins.
      $geocoder_plugins = array_filter($geocoder_plugins, function($v) {
        return (bool) $v['checked'];
      });
      $geocoder_plugins = array_keys($geocoder_plugins);

      // Get the value of our source field.
      $field_value = $form_state->getValue($source_field, array());

      // For each value, geocode the address and set our coordinates.
      foreach ($field_value as $delta => $value) {
        // Set the geo data in WKT to the field defined in the widget configuration.
        if ($address_collection = Geocoder::geocode($geocoder_plugins, $value['value'])) {
          $dumper = Geocoder::getPlugin('dumper', $dumper_plugin);
          $values[$delta]['value'] = $dumper->dump($address_collection->first());
        }
      }
    }

    return $values;
  }

}
