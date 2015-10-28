<?php

/**
 * @file
 * Definition of Drupal\filefield_sources\Tests\AttachSourceTest.
 */

namespace Drupal\filefield_sources\Tests;

/**
 * Tests the attach source.
 *
 * @group filefield_sources
 */
class AttachSourceTest extends FileFieldSourcesTestBase {

  /**
   * Check to see if a option is present.
   *
   * @param string $uri
   *   The option to check.
   *
   * @return bool
   *   TRUE if the option is present, FALSE otherwise.
   */
  public function isOptionPresent($uri) {
    $options = $this->xpath('//select[@name=:name]/option[@value=:option]', array(
      ':name' => $this->fieldName . '[0][filefield_attach][filename]',
      ':option' => $uri,
    ));
    return isset($options[0]);
  }

  /**
   * Check to see if can attach file.
   *
   * @param object $file
   *   File to attach.
   */
  public function assertCanAttachFile($file) {
    // Ensure option is present.
    $this->assertTrue($this->isOptionPresent($file->uri), 'File option is present.');

    // Ensure empty message is not present.
    $this->assertNoText('There currently are no files to attach.', "Empty message is not present.");

    // Attach button is always present.
    $this->assertFieldByXpath('//input[@type="submit"]', t('Attach'), 'Attach button is present.');
  }

  /**
   * Check to see if can attach file.
   *
   * @param object $file
   *   File to attach.
   */
  public function assertCanNotAttachFile($file) {
    // Ensure option is not present.
    $this->assertFalse($this->isOptionPresent($file->uri), 'File option is not present.');

    // Ensure empty message is present.
    $this->assertText('There currently are no files to attach.', "Empty message is present.");

    // Attach button is always present.
    $this->assertFieldByXpath('//input[@type="submit"]', t('Attach'), 'Attach button is present.');
  }

  /**
   * Tests move file from relative path.
   *
   * Default settings: Move file from 'public://file_attach' to 'public://'.
   */
  public function testMoveFileFromRelativePath() {
    // Create test file.
    $path = file_default_scheme() . '://' . FILEFIELD_SOURCE_ATTACH_DEFAULT_PATH;
    $file = $this->createTemporaryFile($path);
    $dest_uri = file_default_scheme() . '://' . $file->filename;

    $this->enableSources(array(
      'attach' => TRUE,
    ));

    $this->assertCanAttachFile($file);

    // Upload a file.
    $this->uploadFileByAttachSource($file->uri, $file->filename, 0);

    // We can only attach one file on single value field.
    $this->assertNoFieldByXPath('//input[@type="submit"]', t('Attach'), 'After uploading a file, "Attach" button is no longer displayed.');

    // Ensure file is moved.
    $this->assertFalse(is_file($file->uri), 'Source file has been removed.');
    $this->assertTrue(is_file($dest_uri), 'Destination file has been created.');

    $this->removeFile($file->filename, 0);

    $this->assertCanNotAttachFile($file);
  }

  /**
   * Calculate custom absolute path.
   */
  public function getCustomAttachPath() {
    $path = drupal_realpath(file_default_scheme() . '://');
    $path = str_replace(realpath('./'), '', $path);
    $path = ltrim($path, '/');
    $path = $path . '/custom_file_attach';
    return $path;
  }

  /**
   * Tests copy file from absolute path.
   *
   * Copy file from 'sites/default/files/custom_file_attach' to 'public://'.
   */
  public function testCopyFileFromAbsolutePath() {
    $path = $this->getCustomAttachPath();

    // Create test file.
    $file = $this->createTemporaryFile($path);
    $dest_uri = file_default_scheme() . '://' . $file->filename;

    // Change settings.
    $this->updateFilefieldSourcesSettings('source_attach', 'path', $path);
    $this->updateFilefieldSourcesSettings('source_attach', 'absolute', FILEFIELD_SOURCE_ATTACH_ABSOLUTE);
    $this->updateFilefieldSourcesSettings('source_attach', 'attach_mode', FILEFIELD_SOURCE_ATTACH_MODE_COPY);

    $this->enableSources(array(
      'attach' => TRUE,
    ));

    $this->assertCanAttachFile($file);

    // Upload a file.
    $this->uploadFileByAttachSource($file->uri, $file->filename, 0);

    // We can only attach one file on single value field.
    $this->assertNoFieldByXPath('//input[@type="submit"]', t('Attach'), 'After uploading a file, "Attach" button is no longer displayed.');

    // Ensure file is copied.
    $this->assertTrue(is_file($file->uri), 'Source file still exists.');
    $this->assertTrue(is_file($dest_uri), 'Destination file has been created.');

    $this->removeFile($file->filename, 0);

    $this->assertCanAttachFile($file);
  }

}
