<?php

namespace Drupal\media_bulk_upload\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Entity\Display\EntityFormDisplayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;
use Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface;
use Drupal\media_bulk_upload\MediaSubFormManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class BulkMediaUploadForm.
 *
 * @package Drupal\media_upload\Form
 */
class MediaBulkUploadForm extends FormBase {

  /**
   * Media Type storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaTypeStorage;

  /**
   * Media Bulk Config storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaBulkConfigStorage;

  /**
   * Media entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $mediaStorage;

  /**
   * Media SubForm Manager.
   *
   * @var \Drupal\media_bulk_upload\MediaSubFormManager
   */
  protected $mediaSubFormManager;

  /**
   * The max file size for the media bulk form.
   *
   * @var string
   */
  protected $maxFileSizeForm;

  /**
   * The allowed extensions for the media bulk form.
   *
   * @var array
   */
  protected $allowed_extensions = [];

  /**
   * BulkMediaUploadForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\media_bulk_upload\MediaSubFormManager $mediaSubFormManager
   *   Media Sub Form Manager.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    MediaSubFormManager $mediaSubFormManager
  ) {
    $this->mediaTypeStorage = $entityTypeManager->getStorage('media_type');
    $this->mediaBulkConfigStorage = $entityTypeManager->getStorage('media_bulk_config');
    $this->mediaStorage = $entityTypeManager->getStorage('media');
    $this->maxFileSizeForm = ini_get("upload_max_filesize");
    $this->mediaSubFormManager = $mediaSubFormManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('media_bulk_upload.subform_manager')
    );
  }

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'media_bulk_upload_form';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface|null $media_bulk_config
   *   The media bulk configuration entity.
   *
   * @return array
   *   The form structure.
   *
   * @throws \Exception
   */
  public function buildForm(array $form, FormStateInterface $form_state, MediaBulkConfigInterface $media_bulk_config = NULL) {
    $mediaBulkConfig = $media_bulk_config;
    $mediaTypeIds = $mediaBulkConfig->get('media_types');

    /** @var \Drupal\media\MediaTypeInterface[] $mediaTypes */
    $mediaTypes = $this->mediaTypeStorage->loadMultiple($mediaTypeIds);
    $mediaFormFieldComponents = [];
    $items = [];

    foreach ($mediaTypes as $mediaType) {
      $targetFieldSettings = $this->mediaSubFormManager->getTargetFieldSettings($mediaType);
      $extensions = $this->mediaSubFormManager->getTargetFieldExtensions($targetFieldSettings);
      natsort($extensions);
      $items[] = $mediaType->label() . ' (max ' . $this->mediaSubFormManager->getTargetFieldMaxSize($targetFieldSettings) . '): ' . implode(', ', $extensions);
      $this->addAllowedExtensions($extensions);
      $mediaFormFieldComponents[$mediaType->id()] = $this->mediaSubFormManager->getMediaEntityFieldComponents($mediaBulkConfig, $mediaType);
      if (!$this->isMaxFileSizeLarger($this->mediaSubFormManager->getTargetFieldMaxSize($targetFieldSettings))) {
        continue;
      }

      $this->setMaxFileSizeForm($this->mediaSubFormManager->getTargetFieldMaxSize($targetFieldSettings));
    }

    return $this->setupForm($form, $form_state, $mediaBulkConfig, $mediaFormFieldComponents, $items);
  }

  /**
   * Setup the form fields for the media bulk configuration entity.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\media_bulk_upload\Entity\MediaBulkConfigInterface $mediaBulkConfig
   *   The media bulk configuration entity.
   * @param array $mediaFormFieldComponents
   *   List of field components keyed by media type id.
   *
   * @return array
   *   Render array containing the form fields.
   */
  private function setupForm(array $form, FormStateInterface $form_state, MediaBulkConfigInterface $mediaBulkConfig, array $mediaFormFieldComponents, array $items) {
    $form['#tree'] = TRUE;
    $form['information_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => [
          'media-bulk-upload-information-wrapper',
        ],
      ],
    ];
    $form['information_wrapper']['information_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#value' => $this->t('Information'),
      '#attributes' => [
        'class' => [
          'form-control-label',
        ],
        'for' => 'media_bulk_upload_information',
      ],
    ];

    $form['information_wrapper']['information'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Media Types:'),
      '#items' => $items,
    ];

    $form['information_wrapper']['warning'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#id' => 'media_bulk_upload_information',
      '#name' => 'media_bulk_upload_information',
      '#value' => '<p>Please be 
        aware that if file extensions overlap between the media types that are 
        available in this upload form, that the media entity will be assigned 
        automatically to one of these types.</p>',
    ];

    $form['dropzonejs'] = [
      '#type' => 'dropzonejs',
      '#title' => $this->t('Dropzone'),
      '#required' => TRUE,
      '#dropzone_description' => $this->t('Click or drop your files here'),
      '#max_filesize' => $this->maxFileSizeForm,
      '#extensions' => implode(' ', $this->allowed_extensions),
    ];


    if ($this->mediaSubFormManager->validateMediaFormDisplayUse($mediaBulkConfig)) {
      $form['fields'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Fields'),
        'shared' => [],
      ];
      $this->mediaSubFormManager->buildMediaSubForm($form, $form_state, $mediaBulkConfig, $mediaFormFieldComponents);
    }

    $form['media_bundle_config'] = [
      '#type' => 'value',
      '#value' => $mediaBulkConfig->id(),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * Submit handler to create the file entities and media entities.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $mediaBundleConfigId = $values['media_bundle_config'];

    /** @var MediaBulkConfigInterface $mediaBulkConfig */
    $mediaBulkConfig = $this->mediaBulkConfigStorage->load($mediaBundleConfigId);
    $files = $values['dropzonejs']['uploaded_files'];
    $mediaTypeIds = $mediaBulkConfig->get('media_types');

    /** @var \Drupal\media\MediaTypeInterface[] $mediaTypes */
    $mediaTypes = $this->mediaTypeStorage->loadMultiple($mediaTypeIds);
    $mediaTypeTargetFieldSettings = [];
    $targetDirectories = [];

    foreach ($mediaTypes as $mediaType) {
      $mediaTypeTargetFieldSettings[$mediaType->id()] = $this->mediaSubFormManager->getTargetFieldSettings($mediaType);
      $targetDirectories[$mediaType->id()] = $this->mediaSubFormManager->getTargetFieldDirectory($mediaTypeTargetFieldSettings[$mediaType->id()]);
    }

    $mediaType = reset($mediaTypes);
    $mediaFormDisplay = $this->mediaSubFormManager->getMediaFormDisplay($mediaBulkConfig, $mediaType);

    $savedMediaItems = [];
    foreach ($files as $file) {
      try {
        $media = $this->processFile($mediaTypes, $file, $mediaTypeTargetFieldSettings, $targetDirectories);
        if (!$media) {
          continue;
        }
        if ($this->mediaSubFormManager->validateMediaFormDisplayUse($mediaBulkConfig)) {
          $this->copyFormValuesToEntity($media, $mediaFormDisplay, $form['fields']['shared'], $form_state);
        }
        $media->save();
        $savedMediaItems[] = $media;
      }
      catch (\Exception $e) {
        watchdog_exception('media_bulk_upload', $e);
      }
    }

    if (!empty($savedMediaItems)) {
      drupal_set_message($this->t('@count media item(s) are created.', ['@count' => count($savedMediaItems)]));
    }
  }

  /**
   * Process a file upload.
   *
   * Will create a file entity and prepare a media entity with data.
   *
   * @param array $mediaTypes
   *   List of media types.
   * @param array $file
   *   File upload data.
   * @param array $mediaTypeTargetFieldSettings
   *   List of target field settings keyed by media type id.
   * @param array $mediaTypeTargetDirectories
   *   List of target directories keyed by media type id.
   *
   * @return \Drupal\media\MediaInterface
   *   The unsaved media entity that is created.
   *
   * @throws \Exception
   */
  private function processFile(array $mediaTypes, array $file, array $mediaTypeTargetFieldSettings, array $mediaTypeTargetDirectories) {
    $fileInfo = pathinfo($file['filename']);
    $filename = $fileInfo['basename'];

    if (!$this->validateFilename($fileInfo)) {
      drupal_set_message($this->t('File :filename does not have a valid extension or filename.', array(':filename' => $filename)), 'error');
      throw new \Exception("File $filename does not have a valid extension or filename.");
    }

    $mediaTypeId = $this->getMediaTypeIdByExtension($fileInfo, $mediaTypeTargetFieldSettings);
    $targetFieldSettings = $mediaTypeTargetFieldSettings[$mediaTypeId];
    if (!$this->validateFileSize($file['path'], $targetFieldSettings)) {
      $fileSizeSetting = $this->mediaSubFormManager->getTargetFieldMaxSize($targetFieldSettings);
      $mediaTypeLabel = $mediaTypes[$mediaTypeId]->label();
      drupal_set_message($this->t('File :filename exceeds the maximum file size of :file_size for media type :media_type exceeded.', array(':filename' => $filename, ':file_size' => $fileSizeSetting, ':media_type' => $mediaTypeLabel)), 'error');
      throw new \Exception("File $filename exceeds the maximum file size of $fileSizeSetting for media type $mediaTypeLabel exceeded.");
    }

    $destination = $mediaTypeTargetDirectories[$mediaTypeId] . '/' . $file['filename'];
    $data = file_get_contents($file['path']);
    $fileEntity = file_save_data($data, $destination);

    if (!$fileEntity) {
      drupal_set_message($this->t('File :filename could not be created.', array(':filename' => $filename)), 'error');
      throw new \Exception('File entity could not be created.');
    }

    $values = $this->getNewMediaValues($mediaTypes[$mediaTypeId], $fileInfo, $fileEntity);
    /** @var \Drupal\media\MediaInterface $media */
    $media = $this->mediaStorage->create($values);
    return $media;
  }

  /**
   * Validate if the filename and extension are valid in the provided file info.
   *
   * @param array $fileInfo
   *   File info.
   *
   * @return bool
   *   If the file info validates, returns true.
   */
  private function validateFilename(array $fileInfo) {
    if (empty($fileInfo['filename']) || empty($fileInfo['extension'])) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Check the size of a file.
   *
   * @param string $filePath
   *   File path.
   * @param array $targetFieldSettings
   *   Bundle type target file field settings
   *
   * @return bool
   *   True if max size for a given file do not exceeds max size for its type.
   */
  private function validateFileSize($filePath, $targetFieldSettings) {
    $fileSizeSetting = $this->mediaSubFormManager->getTargetFieldMaxSize($targetFieldSettings);
    $fileSize = filesize($filePath);
    $maxFileSize = Bytes::toInt($fileSizeSetting);
    return $fileSize <= $maxFileSize;
  }

  /**
   * Get the media type id belonging to the given file extension.
   *
   * @param array $fileInfo
   *   File info.
   * @param array $targetFieldSettings
   *   Targeted file upload settings.
   *
   * @return string
   *   The machine name of the media type id.
   * @throws \Exception
   */
  private function getMediaTypeIdByExtension(array $fileInfo, array $targetFieldSettings) {
    foreach ($targetFieldSettings as $mediaTypeId => $settings) {
      $extensions = $this->mediaSubFormManager->getTargetFieldExtensions($targetFieldSettings[$mediaTypeId]);
      if (!in_array($fileInfo['extension'], $extensions)) {
        continue;
      }

      return $mediaTypeId;
    }
    throw new \Exception('No matching media type id for the given file.');
  }

  /**
   * Builds the array of all necessary info for the new media entity.
   *
   * @param \Drupal\media\MediaTypeInterface $mediaType
   *   Media Type ID.
   * @param array $fileInfo
   *   File info.
   * @param \Drupal\file\FileInterface $file
   *   File entity.
   *
   * @return array
   *   Return an array describing the new media entity.
   */
  private function getNewMediaValues(MediaTypeInterface $mediaType, array $fileInfo, FileInterface $file) {
    $targetFieldName = $this->mediaSubFormManager->getTargetFieldName($mediaType);
    return [
      'bundle' => $mediaType->id(),
      'name' => $fileInfo['filename'],
      $targetFieldName => [
        'target_id' => $file->id(),
        'title' => $fileInfo['filename'],
      ],
    ];
  }

  /**
   * Copy the submitted values for the media subform to the media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   Media Entity.
   * @param \Drupal\Core\Entity\Display\EntityFormDisplayInterface $mediaFormDisplay
   *   Media Form Display.
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form State.
   */
  private function copyFormValuesToEntity(MediaInterface $media, EntityFormDisplayInterface $mediaFormDisplay, array $form, FormStateInterface $form_state) {
    $extracted = $mediaFormDisplay->extractFormValues($media, $form, $form_state);
    foreach ($form_state->getValues() as $name => $values) {
      if (!$media->hasField($name) || isset($extracted[$name])) {
        continue;
      }
      $media->set($name, $values);
    }
  }

  /**
   * Validate if a max file size is bigger then the current max file size.
   *
   * @param string $MaxFileSize
   *
   * @return bool
   */
  private function isMaxFileSizeLarger($MaxFileSize) {
    $size = Bytes::toInt($MaxFileSize);
    $currentSize = Bytes::toInt($this->maxFileSizeForm);

    return ($size > $currentSize);
  }

  /**
   * Set the max File size for the form.
   *
   * @param string $newMaxFileSize
   */
  private function setMaxFileSizeForm($newMaxFileSize) {
    $this->maxFileSizeForm = $newMaxFileSize;

  }

  /**
   * @param array $extensions
   */
  private function addAllowedExtensions($extensions) {
    $this->allowed_extensions = array_unique(array_merge($this->allowed_extensions, $extensions));
  }
}
