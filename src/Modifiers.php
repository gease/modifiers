<?php

namespace Drupal\modifiers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Render\Markup;

/**
 * Provides utility functions for modifiers.
 */
class Modifiers {

  /**
   * The field holding modifiers.
   */
  const FIELD = 'field_modifiers';

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The modifier plugin manager.
   *
   * @var \Drupal\modifiers\ModifierPluginManager
   */
  protected $modifierPluginManager;

  /**
   * Constructs a new Modifiers service.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\modifiers\ModifierPluginManager $modifier_plugin_manager
   *   The modifier plugin manager service.
   */
  public function __construct(ModuleHandlerInterface $module_handler, ModifierPluginManager $modifier_plugin_manager) {
    $this->moduleHandler = $module_handler;
    $this->modifierPluginManager = $modifier_plugin_manager;
  }

  /**
   * Applies modification to specific element.
   *
   * @param array $modifications
   *   The modification objects to be applied.
   * @param array $build
   *   The element where modification is attached.
   * @param string $build_id
   *   The specific ID unique within the current request.
   */
  public function apply(array $modifications, array &$build, $build_id) {

    // Initialize empty CSS string.
    $style = '';
    // Initialize empty attributes array.
    $build_attributes = [];

    // Process all modifications.
    foreach ($modifications as $modification) {

      // Get current modification demands.
      $css = $modification->getCss();
      $libraries = $modification->getLibraries();
      $settings = $modification->getSettings();
      $attributes = $modification->getAttributes();
      $links = $modification->getLinks();

      // Render CSS into single string and append to existing.
      if (!empty($css)) {
        $style .= $this->renderCss($css);
      }

      // Attach all required libraries.
      foreach ($libraries as $library) {
        $build['#attached']['library'][] = $library;
      }

      // Attach settings for JS libraries.
      if (!empty($settings)) {
        $build['#attached']['drupalSettings']['modifiers']['settings'][$build_id][] = $settings;
      }

      // Attach attributes for JS processing.
      if (!empty($attributes)) {
        $this->mergeAttributes($build_attributes, $attributes);
      }

      // Attach all links between head elements.
      foreach ($links as $link) {
        $build['#attached']['html_head'][] = [
          [
            '#type' => 'html_tag',
            '#tag' => 'link',
            '#attributes' => $link,
            '#weight' => 10,
          ],
          'modifications_links_' . md5(serialize($link)),
        ];
      }
    }

    // Attach CSS from all current modifications together.
    if (!empty($style)) {
      $build['#attached']['html_head'][] = [
        [
          '#type' => 'html_tag',
          '#tag' => 'style',
          '#attributes' => [
            'media' => 'all',
            'data-modifiers' => $build_id,
          ],
          '#value' => Markup::create($style),
          '#weight' => 10,
        ],
        'modifications_css_' . $build_id,
      ];
    }

    // Attach attributes from all current modifications together.
    if (!empty($build_attributes)) {
      $build['#attached']['drupalSettings']['modifiers']['attributes'][$build_id] = $build_attributes;
    }
  }

  /**
   * Renders provided styles into single string.
   *
   * @param array $styles
   *   The styles to be rendered.
   *
   * @return string
   *   The concatenated version of styles.
   */
  private function renderCss(array $styles) {

    $css = '';
    foreach ($styles as $media => $rules) {
      // Use media query only when specified.
      if ($media !== 'all') {
        $css .= '@media' . ($media[0] !== '(' ? ' ' : '') . $media . '{';
      }
      // Append all CSS rules to existing styles.
      foreach ($rules as $selector => $properties) {
        $css .= $selector . '{' . implode(';', $properties) . '}';
      }
      // Close media query only when used.
      if ($media !== 'all') {
        $css .= '}';
      }
    }
    return $css;
  }

  /**
   * Merges provided attributes into existing array.
   *
   * @param array $build_attributes
   *   The existing attributes for merging.
   * @param array $attributes
   *   The attributes to be merged.
   */
  private function mergeAttributes(array &$build_attributes, array $attributes) {

    // Process all attributes by media queries and selectors.
    foreach ($attributes as $media => $selectors) {
      foreach ($selectors as $selector => $attributes_set) {
        foreach ($attributes_set as $attribute_key => $attribute) {
          // Initialize current target attribute.
          $target = &$build_attributes[$media][$selector][$attribute_key];
          // Only when attribute is set of values.
          if (is_array($attribute)) {
            // Merge when target attribute is already set.
            $target = is_array($target) ? array_merge($target, $attribute) : $attribute;
            // Filter only unique values.
            $target = array_unique($target);
          }
          // Other attributes have single value.
          else {
            // Only when attribute is not already set.
            if (empty($target)) {
              $target = $attribute;
            }
          }
        }
      }
    }
  }

  /**
   * Extracts configuration from provided entity.
   *
   * The resulting array is suitable for consumption by modifiers and is much
   * simpler than the standard Drupal entity structure.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity containing configuration fields.
   * @param string $field_name
   *   The name of field referencing sets of fields.
   * @param array $config
   *   The array where configuration is added.
   *
   * @return array
   *   The array with added configuration.
   */
  public function extractEntityConfig(EntityInterface $entity, $field_name, array $config = []) {

    // Skip entities without specified field.
    if (!($entity instanceof FieldableEntityInterface) || !$entity->hasField($field_name)) {
      return $config;
    }
    // Get field definition and short name.
    $field = $entity->get($field_name);
    $field_short = $this->getShortField($field_name);

    // Process simple value fields other than references.
    if (!($field instanceof EntityReferenceFieldItemListInterface)) {

      // Only if field is not already processed.
      if (!isset($config[$field_short])) {
        /** @var \Drupal\Core\Field\FieldStorageDefinitionInterface $field_storage */
        $field_storage = $field->getFieldDefinition()
          ->getFieldStorageDefinition();
        $config[$field_short] = $this->getSimpleValue($field, $field_storage);
      }
      return $config;
    }

    // Get all already processed bundles.
    $processed_bundles = !empty($config[$field_short]) ? array_keys($config[$field_short]) : [];
    // Get all entities referenced by field.
    $referenced_entities = $field->referencedEntities();

    // Process all referenced entities.
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $referenced_entity */
    foreach ($referenced_entities as $referenced_entity) {
      $referenced_entity_bundle = $referenced_entity->bundle();

      // Only if entity bundle is not already processed.
      if (!in_array($referenced_entity_bundle, $processed_bundles)) {
        $referenced_entity_config = [];

        // Process all fields attached to referenced entity.
        foreach ($referenced_entity->getFields() as $referenced_entity_field) {
          $this->extractFieldConfig($referenced_entity_field, $referenced_entity_config);
        }
        // Fill referenced entity values into config array.
        $config[$field_short][$referenced_entity_bundle][] = $referenced_entity_config;
      }
    }
    return $config;
  }

  /**
   * Extracts configuration from provided entity field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field object containing values.
   * @param array $config
   *   The array where configuration is added.
   */
  public function extractFieldConfig(FieldItemListInterface $field, array &$config) {

    // Skip entity base fields.
    $field_storage = $field->getFieldDefinition()->getFieldStorageDefinition();
    if (!$field_storage->isBaseField()) {

      // Get simple value from field with referenced entity.
      if ($field instanceof EntityReferenceFieldItemListInterface) {
        $value = $this->getReferencedValue($field, $field_storage);
      }
      else {
        // Otherwise get value from simple field.
        $value = $this->getSimpleValue($field, $field_storage);
      }

      // Fill field value into referenced entity config array.
      $field_short = $this->getShortField($field->getName());
      $config[$field_short] = $value;
    }
  }

  /**
   * Gets value from complex field with referenced entities.
   *
   * @param \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field
   *   The field object referencing other entities.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $field_storage
   *   The field storage definition object.
   *
   * @return array|mixed|null
   *   The array of values, single value or null if empty.
   */
  public function getReferencedValue(EntityReferenceFieldItemListInterface $field, FieldStorageDefinitionInterface $field_storage) {

    // Only if some value exists.
    if (!$field->isEmpty()) {
      // Define mappings from entity type and bundle to field names.
      $mappings = [
        'media' => [
          'audio' => ['field_media_audio_file'],
          'file' => ['field_media_file'],
          'image' => ['field_media_image', 'image', 'field_file'],
          'remote_video' => ['field_media_oembed_video'],
          'video' => [
            'field_media_video_file',
            'field_media_video_embed_field',
            'field_file',
          ],
          'video_embed' => ['field_media_video_embed_field', 'field_file'],
        ],
        'taxonomy_term' => [
          'modifiers_color' => ['field_mod_color'],
        ],
      ];
      // Allow other modules to alter these mappings.
      $this->moduleHandler->alter('modifiers_mappings', $mappings);

      $values = [];
      // Process all referenced entities.
      foreach ($field->referencedEntities() as $entity) {
        $type = $entity->getEntityTypeId();
        $bundle = $entity->bundle();

        // Only if this type has some mappings.
        if ($entity instanceof FieldableEntityInterface && isset($mappings[$type][$bundle])) {

          // Find first existing field by name.
          foreach ($mappings[$type][$bundle] as $field_name) {
            if ($entity->hasField($field_name)) {
              // Only if some value exists.
              if (!$entity->get($field_name)->isEmpty()) {
                /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $entity_field */
                $entity_field = $entity->get($field_name);
                break;
              }
            }
          }

          // Only for entities with mapped field.
          if (!empty($entity_field)) {
            $entity_field_storage = $entity_field->getFieldDefinition()
              ->getFieldStorageDefinition();

            // Get value by different field types.
            switch ($entity_field_storage->getType()) {

              case 'color_field_type':
                foreach ($entity_field->getValue() as $item) {
                  $values[] = $this->getColorValue($item['color'], $item['opacity']);
                }
                break;

              case 'file':
              case 'image':
                /** @var \Drupal\file\Entity\File $file */
                foreach ($entity_field->referencedEntities() as $file) {
                  $values[] = $file->createFileUrl();
                }
                break;

              default:
                foreach ($entity_field->getValue() as $item) {
                  $values[] = $item[$entity_field_storage->getMainPropertyName()];
                }
                break;
            }
          }
        }
      }
      // Return simple value or array of values.
      if (!empty($values)) {
        return $field_storage->isMultiple() ? $values : $values[0];
      }
    }
    // Return null if there is no value.
    return NULL;
  }

  /**
   * Gets value from simple field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field object containing values.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $field_storage
   *   The field storage definition object.
   *
   * @return array|mixed|null
   *   The array of values, single value or null if empty.
   */
  public function getSimpleValue(FieldItemListInterface $field, FieldStorageDefinitionInterface $field_storage) {

    // Only if some value exists.
    if (!$field->isEmpty()) {
      $field_type = $field_storage->getType();
      $property_name = $field_storage->getMainPropertyName();
      $values = [];

      // Process all field values.
      foreach ($field->getValue() as $item) {

        // Specific handling for color field.
        if ($field_type === 'color_field_type') {
          $values[] = $this->getColorValue($item['color'], $item['opacity']);
        }
        else {
          // Get value by main property.
          $values[] = $item[$property_name];
        }
      }
      // Return simple value or array of values.
      return $field_storage->isMultiple() ? $values : $values[0];
    }
    // Return null if there is no value.
    return NULL;
  }

  /**
   * Gets RGBA color string from color and opacity.
   *
   * @param string $color
   *   The hexadecimal color value.
   * @param string $opacity
   *   The decimal opacity value.
   *
   * @return string
   *   The RGBA value.
   */
  public function getColorValue($color, $opacity) {

    // Clean provided values.
    $hex = trim($color);
    $opacity = floatval($opacity);

    // Validate values to match hexadecimal format.
    if (substr($hex, 0, 1) === '#') {
      $hex = substr($hex, 1);
    }
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/[0-9A-F]{6}/i', $hex)) {
      return '';
    }
    // Convert hexadecimal string to decimal.
    [$red, $green, $blue] = sscanf($hex, "%02x%02x%02x");

    // Join provided values into single RGBA string.
    return 'rgba(' . $red . ',' . $green . ',' . $blue . ',' . $opacity . ')';
  }

  /**
   * Gets short name of provided field.
   *
   * @param string $name
   *   The full field name.
   *
   * @return string
   *   The short name without field prefix.
   */
  public function getShortField($name) {

    // Remove "field_mod_" prefix from field machine name.
    if (substr($name, 0, 10) === 'field_mod_') {
      return substr($name, 10);
    }
    // Remove at least "field_" prefix.
    elseif (substr($name, 0, 6) === 'field_') {
      return substr($name, 6);
    }
    // Return whole string if not prefixed.
    return $name;
  }

  /**
   * Fills all modifications based on provided configuration.
   *
   * @param array|\Drupal\modifiers\Modification[] $modifications
   *   The existing modifications set.
   * @param array $modifiers
   *   The modifiers configuration.
   * @param string $selector
   *   The modification selector.
   */
  public function process(array &$modifications, array $modifiers, $selector) {

    foreach ($modifiers as $type => $configs) {

      if ($this->modifierPluginManager->hasDefinition($type) && !empty($configs)) {
        /** @var \Drupal\modifiers\ModifierInterface $plugin */
        $plugin = $this->modifierPluginManager->createInstance($type);

        // Process all current plugin modifications.
        foreach ($configs as $config) {
          $modification = $plugin::modification($selector, $config);

          if (!empty($modification)) {
            $modifications[] = $modification;
          }
        }
      }
    }
  }

}
