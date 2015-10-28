<?php

/**
 * @file
 * Contains \Drupal\filefield_sources\Plugin\FilefieldSource\Reference.
 */

namespace Drupal\filefield_sources\Plugin\FilefieldSource;

use Drupal\Core\Form\FormStateInterface;
use Drupal\filefield_sources\FilefieldSourceInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Field\WidgetInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;

/**
 * A FileField source plugin to allow referencing of existing files.
 *
 * @FilefieldSource(
 *   id = "reference",
 *   name = @Translation("Autocomplete reference textfield"),
 *   label = @Translation("Reference existing"),
 *   description = @Translation("Reuse an existing file by entering its file name."),
 *   weight = 1
 * )
 */
class Reference implements FilefieldSourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function value(array &$element, &$input, FormStateInterface $form_state) {
    if (isset($input['filefield_reference']['autocomplete']) && strlen($input['filefield_reference']['autocomplete']) > 0 && $input['filefield_reference']['autocomplete'] != FILEFIELD_SOURCE_REFERENCE_HINT_TEXT) {
      $matches = array();
      if (preg_match('/\[fid:(\d+)\]/', $input['filefield_reference']['autocomplete'], $matches)) {
        $fid = $matches[1];
        if ($file = file_load($fid)) {

          // Remove file size restrictions, since the file already exists on
          // disk.
          if (isset($element['#upload_validators']['file_validate_size'])) {
            unset($element['#upload_validators']['file_validate_size']);
          }

          // Check that the user has access to this file through
          // hook_download().
          if (!$file->access('download')) {
            $form_state->setError($element, t('You do not have permission to use the selected file.'));
          }
          elseif (filefield_sources_element_validate($element, (object) $file, $form_state)) {
            if (!in_array($file->id(), $input['fids'])) {
              $input['fids'][] = $file->id();
            }
          }
        }
        else {
          $form_state->setError($element, t('The referenced file could not be used because the file does not exist in the database.'));
        }
      }
      // No matter what happens, clear the value from the autocomplete.
      $input['filefield_reference']['autocomplete'] = '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function process(array &$element, FormStateInterface $form_state, array &$complete_form) {

    $element['filefield_reference'] = array(
      '#weight' => 100.5,
      '#theme' => 'filefield_sources_element',
      '#source_id' => 'reference',
      // Required for proper theming.
      '#filefield_source' => TRUE,
      '#filefield_sources_hint_text' => FILEFIELD_SOURCE_REFERENCE_HINT_TEXT,
    );

    $autocomplete_route_parameters = array(
      'entity_type' => $element['#entity_type'],
      'bundle_name' => $element['#bundle'],
      'field_name' => $element['#field_name'],
    );

    $element['filefield_reference']['autocomplete'] = array(
      '#type' => 'textfield',
      '#autocomplete_route_name' => 'filefield_sources.autocomplete',
      '#autocomplete_route_parameters' => $autocomplete_route_parameters,
      '#description' => filefield_sources_element_validation_help($element['#upload_validators']),
    );

    $ajax_settings = [
      'url' => Url::fromRoute('file.ajax_upload'),
      'options' => [
        'query' => [
          'element_parents' => implode('/', $element['#array_parents']),
          'form_build_id' => $complete_form['form_build_id']['#value'],
        ],
      ],
      'wrapper' => $element['#id'] . '-ajax-wrapper',
      'effect' => 'fade',
    ];

    $element['filefield_reference']['select'] = [
      '#name' => implode('_', $element['#parents']) . '_autocomplete_select',
      '#type' => 'submit',
      '#value' => t('Select'),
      '#validate' => [],
      '#submit' => ['filefield_sources_field_submit'],
      '#limit_validation_errors' => [$element['#parents']],
      '#ajax' => $ajax_settings,
    ];

    return $element;
  }

  /**
   * Theme the output of the reference element.
   */
  public static function element($variables) {
    $element = $variables['element'];

    $element['autocomplete']['#field_suffix'] = drupal_render($element['select']);
    return '<div class="filefield-source filefield-source-reference clear-block">' . drupal_render($element['autocomplete']) . '</div>';
  }

  /**
   * Menu callback; autocomplete.js callback to return a list of files.
   */
  public static function autocomplete(Request $request, $entity_type, $bundle_name, $field_name) {
    $matches = array();
    $string = Unicode::strtolower($request->query->get('q'));

    $field_definition = entity_load('field_config', $entity_type . '.' . $bundle_name . '.' . $field_name);
    $handler = \Drupal::getContainer()->get('plugin.manager.entity_reference_selection')->getSelectionHandler($field_definition);

    if (isset($string)) {
      // Get an array of matching entities.
      $widget = entity_get_form_display($entity_type, $bundle_name, 'default')->getComponent($field_name);
      $autocomplete_type = $widget['third_party_settings']['filefield_sources']['filefield_sources']['source_reference']['autocomplete'];
      $match_operator = !empty($autocomplete_type) ? $autocomplete_type : FILEFIELD_SOURCE_REFERENCE_CONTAINS_AUTOCOMPLETE_TYPE;
      $entity_labels = $handler->getReferenceableEntities($string, $match_operator, 10);

      // Loop through the entities and convert them into autocomplete output.
      foreach ($entity_labels as $values) {
        foreach ($values as $entity_id => $label) {
          $key = "$label [fid:$entity_id]";
          // Strip things like starting/trailing white spaces, line breaks and
          // tags.
          $key = preg_replace('/\s\s+/', ' ', str_replace("\n", '', trim(Html::decodeEntities(strip_tags($key)))));
          // Names containing commas or quotes must be wrapped in quotes.
          $matches[] = array('value' => $key, 'label' => $label);
        }
      }
    }

    return new JsonResponse($matches);
  }

  /**
   * Define routes for Reference source.
   *
   * @return array
   *   Array of routes.
   */
  public static function routes() {
    $routes = array();

    $routes['filefield_sources.autocomplete'] = new Route(
      '/file/reference/{entity_type}/{bundle_name}/{field_name}',
      array(
        '_controller' => get_called_class() . '::autocomplete',
      ),
      array(
        '_access_filefield_sources_field' => 'TRUE',
      )
    );

    return $routes;
  }

  /**
   * Implements hook_filefield_source_settings().
   */
  public static function settings(WidgetInterface $plugin) {
    $settings = $plugin->getThirdPartySetting('filefield_sources', 'filefield_sources', array(
      'source_reference' => array(
        'autocomplete' => FILEFIELD_SOURCE_REFERENCE_STARTS_WITH_AUTOCOMPLETE_TYPE,
      ),
    ));

    $return['source_reference'] = array(
      '#title' => t('Autocomplete reference options'),
      '#type' => 'details',
    );

    $return['source_reference']['autocomplete'] = array(
      '#title' => t('Match file name'),
      '#options' => array(
        FILEFIELD_SOURCE_REFERENCE_STARTS_WITH_AUTOCOMPLETE_TYPE => t('Starts with'),
        FILEFIELD_SOURCE_REFERENCE_CONTAINS_AUTOCOMPLETE_TYPE => t('Contains'),
      ),
      '#type' => 'radios',
      '#default_value' => isset($settings['source_reference']['autocomplete']) ? $settings['source_reference']['autocomplete'] : FILEFIELD_SOURCE_REFERENCE_STARTS_WITH_AUTOCOMPLETE_TYPE,
    );

    return $return;
  }

}
