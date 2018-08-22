<?php

namespace Drupal\media_bulk_upload;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\media\MediaTypeInterface;
use Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class MediaSubFormManager.
 *
 * @package Drupal\media_bulk_upload
 */
class MediaSubFormManager implements ContainerInjectionInterface {

  /**
   * Default max file size.
   *
   * @var string
   */
  protected $defaultMaxFileSize = '32MB';

  /**
   * Media Type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaTypeStorage;

  /**
   * Entity Form Display storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $entityFormDisplayStorage;

  /**
   * Media entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaStorage;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * BulkMediaUploadForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity field manager.
   * @param \Drupal\Core\Utility\Token $token
   *   Token service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    Token $token
  ) {
    $this->mediaTypeStorage = $entityTypeManager->getStorage('media_type');
    $this->mediaStorage = $entityTypeManager->getStorage('media');
    $this->entityFormDisplayStorage = $entityTypeManager->getStorage('entity_form_display');
    $this->entityFieldManager = $entityFieldManager;
    $this->token = $token;
    $this->defaultMaxFileSize = format_size(file_upload_max_size())->render();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('token')
    );
  }

  /**
   * Get the target field settings for the media type.
   *
   * @param array $targetFieldSettings
   *   The target field settings for a media type.
   *
   * @return string
   *   The directory location to store the files.
   */
  public function getTargetFieldDirectory(array $targetFieldSettings) {
    $fileDirectory = trim($targetFieldSettings['file_directory'], '/');
    $fileDirectory = PlainTextOutput::renderFromHtml($this->token->replace($fileDirectory));
    $targetDirectory = $targetFieldSettings['uri_scheme'] . '://' . $fileDirectory;
    file_prepare_directory($targetDirectory, FILE_CREATE_DIRECTORY);
    return $targetDirectory;
  }

  /**
   * Get media entity form fields that are available in all given $mediaForms.
   *
   * @param array $form
   *   Render array containing the form elements.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface $mediaBulkConfig
   *   The media bulk config entity.
   * @param array $mediaFormFieldComponents
   *   List of field components keyed by media type id.
   */
  public function buildMediaSubForm(array &$form, FormStateInterface $form_state, MediaBulkConfigInterface $mediaBulkConfig, array $mediaFormFieldComponents) {
    $baseFieldNames = reset($mediaFormFieldComponents);
    $mediaTypeId = key($mediaFormFieldComponents);
    $sharedFormFieldNames = $this->getSharedFormFieldComponents($mediaFormFieldComponents, $baseFieldNames);

    /** @var \Drupal\media\MediaTypeInterface $mediaType */
    $mediaType = $this->mediaTypeStorage->load($mediaTypeId);

    /** @var \Drupal\media\MediaInterface $dummyMedia */
    $dummyMedia = $this->mediaStorage->create(['bundle' => $mediaType->id()]);
    $mediaFormDisplay = $this->getMediaFormDisplay($mediaBulkConfig, $mediaType);
    $mediaFormDisplay->buildForm($dummyMedia, $form['fields']['shared'], $form_state);

    $storage = $form_state->getStorage();
    $field_storage = $storage['field_storage']['#parents'];
    $storage['field_storage']['#parents'] = [
      'fields' => [
        'shared' => $field_storage,
      ],
    ];
    $form_state->setStorage($storage);

    $targetFieldName = $this->getTargetFieldName($mediaType);
    unset($form['fields']['shared'][$targetFieldName]);
    unset($form['fields']['shared']['#parents']);
    $this->configureSharedFields($form['fields']['shared'], $sharedFormFieldNames);
  }

  /**
   * Get the form field components shared between the media types.
   *
   * @param array $mediaFormFieldComponents
   *   List of field components keyed by media type id.
   * @param array $baseFieldNames
   *   List of field components from one of the media types.
   *
   * @return array
   *   The list of field names shared between the media types.
   */
  public function getSharedFormFieldComponents(array $mediaFormFieldComponents, array $baseFieldNames) {
    $sharedFormFieldNames = [];
    foreach ($baseFieldNames as $fieldName) {
      if (!$this->validateSharedMediaFieldComponent($mediaFormFieldComponents, $fieldName)) {
        continue;
      }
      $sharedFormFieldNames[$fieldName] = $fieldName;
    }
    return $sharedFormFieldNames;
  }

  /**
   * Validate if the given element key exists in all given media forms.
   *
   * @param array $mediaFormFieldComponents
   *   List of field components keyed by media type id.
   * @param string $fieldName
   *   The name of the field to check upon.
   *
   * @return bool
   *   If validation passes TRUE, otherwise FALSE.
   */
  public function validateSharedMediaFieldComponent(array $mediaFormFieldComponents, $fieldName) {
    foreach ($mediaFormFieldComponents as $formFieldComponents) {
      if (!in_array($fieldName, $formFieldComponents)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Get the media form display for the given media type.
   *
   * @param \Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface $mediaBulkConfig
   *   Media bulk config entity.
   * @param \Drupal\media\MediaTypeInterface $mediaType
   *   The media type.
   *
   * @return \Drupal\Core\Entity\Display\EntityFormDisplayInterface
   *   The media form display to get the field widgets from.
   */
  public function getMediaFormDisplay(MediaBulkConfigInterface $mediaBulkConfig, MediaTypeInterface $mediaType) {
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $mediaFormDisplay */
    $mediaFormDisplay = $this->entityFormDisplayStorage->load('media.' . $mediaType->id() . '.' . $mediaBulkConfig->get('form_mode'));
    if (is_null($mediaFormDisplay)) {
      $mediaFormDisplay = $this->entityFormDisplayStorage->load('media.' . $mediaType->id() . '.default');
    }
    return $mediaFormDisplay;
  }

  /**
   * Return the media type target field.
   *
   * @param \Drupal\media\MediaTypeInterface $mediaType
   *   Media Type ID.
   *
   * @return string
   *   The name of the target field.
   */
  public function getTargetFieldName(MediaTypeInterface $mediaType) {
    return $mediaType->getSource()->getConfiguration()['source_field'];
  }

  /**
   * Configure all the shared fields.
   *
   * Will set all the correct parents and make all the fields optional.
   *
   * @param array $elements
   *   Form elements from the media type form.
   * @param array $allowedFields
   *   Fields that are allowed to be shown in the media bulk upload form.
   */
  public function configureSharedFields(array &$elements, array $allowedFields) {
    $children = Element::children($elements);
    foreach ($children as $child) {
      if (!in_array($child, $allowedFields)) {
        unset($elements[$child]);
        continue;
      }
      unset($elements[$child]['#parents']);
      $widget_parents = array_merge([
        'fields',
        'shared',
      ], $elements[$child]['widget']['#parents']);
      $elements[$child]['widget']['#parents'] = $widget_parents;

      $this->forceFieldsAsOptional($elements[$child]);
    }
  }

  /**
   * Make sure the fields are optional, instead of required.
   *
   * @param array $elements
   *   The form elements to check the required settings on.
   */
  public function forceFieldsAsOptional(array &$elements) {
    if (isset($elements['#required'])) {
      $elements['#required'] = FALSE;
    }
    $children = Element::children($elements);
    foreach ($children as $child) {
      $this->forceFieldsAsOptional($elements[$child]);
    }
  }

  /**
   * Check if the media form fields should be used in the upload form.
   *
   * @param \Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface $mediaBulkConfig
   *   The media bulk configuration entity.
   *
   * @return bool
   *   True if the media form fields should be used.
   */
  public function validateMediaFormDisplayUse(MediaBulkConfigInterface $mediaBulkConfig) {
    $formMode = $mediaBulkConfig->get('form_mode');
    if (!empty($formMode)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Get the target field settings for the media type.
   *
   * @param \Drupal\media\MediaTypeInterface $mediaType
   *   Media Type ID.
   *
   * @return array
   *   The field settings.
   */
  public function getTargetFieldSettings(MediaTypeInterface $mediaType) {
    $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions('media', $mediaType->id());
    $targetFieldName = $this->getTargetFieldName($mediaType);

    /** @var \Drupal\field\Entity\FieldConfig $targetField */
    $targetField = $fieldDefinitions[$targetFieldName];
    return $targetField->getSettings();
  }

  /**
   * Return the media type target field.
   *
   * @param array $targetFieldSettings
   *   Target field settings.
   *
   * @return array
   *   The allowed file extensions.
   */
  public function getTargetFieldExtensions(array $targetFieldSettings) {
    $extensions = explode(' ', $targetFieldSettings['file_extensions']);
    return array_map('trim', $extensions);
  }

  /**
   * Get the target maximum upload size.
   *
   * Gets the maximum upload size for a file compared to the current
   * $maxFileSize, from the target field settings.
   *
   * @param array $targetFieldSettings
   *   Target field settings for a media type.
   *
   * @return string
   *   Returns the max filesize as a string..
   */
  public function getTargetFieldMaxSize(array $targetFieldSettings) {
    $maxFileSize = $targetFieldSettings['max_filesize'];
    if(empty($maxFileSize)) {
      $maxFileSize = $this->defaultMaxFileSize;
    }
    return $maxFileSize;
  }

  /**
   * Get the field components for the given media type.
   *
   * @param \Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface $mediaBulkConfig
   *   The media bulk config entity.
   * @param \Drupal\media\MediaTypeInterface $mediaType
   *   The media type.
   *
   * @return array
   *   List of field components.
   */
  public function getMediaEntityFieldComponents(MediaBulkConfigInterface $mediaBulkConfig, MediaTypeInterface $mediaType) {
    $mediaFormDisplay = $this->getMediaFormDisplay($mediaBulkConfig, $mediaType);
    $fieldComponents = $mediaFormDisplay->getComponents();
    return array_keys($fieldComponents);
  }
}
