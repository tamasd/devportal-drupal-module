<?php

namespace Drupal\Tests\devportal_api_reference\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\DrupalSelenium2Driver;
use Drupal\FunctionalJavascriptTests\JavascriptTestBase;
use Drupal\Tests\file\Functional\FileFieldCreationTrait;
use Drupal\Tests\TestFileCreationTrait;

/**
 * @group devportal
 * @group api_reference
 */
class OpenApiUploadTest extends JavascriptTestBase {

  use FileFieldCreationTrait;
  use TestFileCreationTrait;

  protected const TITLE_NAME = 'title[0][value]';
  protected const VERSION_NAME = 'field_version[0][value]';
  protected const FILEFIELD_NAME = 'files[field_source_file_0]';

  protected static $modules = [
    'devportal_api_reference',
    'block',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct($name = NULL, array $data = [], string $dataName = '') {
    $this->minkDefaultDriverClass = DrupalSelenium2Driver::class;
    parent::__construct($name, $data, $dataName);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->drupalPlaceBlock('page_title_block');
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * {@inheritdoc}
   */
  public function createScreenshot($filename_prefix = '', $set_background_color = TRUE) {
    $log_dir = getenv('BROWSERTEST_OUTPUT_DIRECTORY') ?: $this->container
      ->get('file_system')
      ->realpath('public://');

    $screenshots_dir = "{$log_dir}/screenshots";
    if (!is_dir($screenshots_dir)) {
      mkdir($screenshots_dir, 0777, TRUE);
    }

    /** @var \Drupal\Core\Database\Connection $database */
    $database = $this->container->get('database');
    $test_id = str_replace('test', '', $database->tablePrefix());

    $filename = file_create_filename("{$filename_prefix}-{$test_id}.png", $screenshots_dir);
    $this->container
      ->get('logger.factory')
      ->get('devportal')
      ->debug("Creating new screenshot: {$filename}.");
    parent::createScreenshot($filename, $set_background_color);
  }

  /**
   * A simple file upload.
   */
  public function testPetstoreUpload() {
    $this->drupalLogin($this->rootUser);

    $this->drupalGet('node/add/api_reference');
    $this->createScreenshot(__FUNCTION__);

    $this->uploadFile('petstore-openapi.yaml');

    $this->submitForm([], 'Save');
    $this->createScreenshot(__FUNCTION__ . '_after_submit');
    $this->assertSession()->pageTextContains('Swagger Petstore');

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this
      ->container
      ->get('entity_type.manager')
      ->getStorage('node')
      ->load(1);
    $version = $node->get('field_version')->getValue()[0]['value'];
    $this->assertEquals('1.0.0', $version);
  }

  /**
   * Tests the propose mode.
   */
  public function testPropose() {
    $this->drupalLogin($this->rootUser);
    $session = $this->getSession();

    $this->drupalGet('node/add/api_reference');
    $this->createScreenshot(__FUNCTION__);

    $name = $this->randomMachineName();
    $version = '0.1';

    $page = $session->getPage();
    $this->selectManualMode();
    $page->fillField(static::TITLE_NAME, $name);
    $page->fillField(static::VERSION_NAME, $version);
    $this->createScreenshot(__FUNCTION__ . '_before_save');
    $this->clickSubmit();
    $this->createScreenshot(__FUNCTION__ . '_after_save');

    $this->assertSession()->pageTextContains($name);

    $this->clickLink('Edit');

    $new_name = $this->randomMachineName();
    $new_version = '0.2';
    $this->selectManualMode();
    $page = $session->getPage();
    $page->fillField(static::TITLE_NAME, $new_name);
    $page->fillField(static::VERSION_NAME, $new_version);
    $this->clickSubmit();

    $this->assertSession()->pageTextContains($new_name);
    $this->assertSession()->pageTextContains($new_version);
    $this->assertSession()->pageTextNotContains($name);
    $this->assertSession()->pageTextNotContains($version);

    $this->clickLink('Edit');
    $this->selectManualMode();
    $this->selectUploadMode();
    $this->uploadFile('petstore-openapi.yaml');
    $this->createScreenshot(__FUNCTION__ . '_before_upload');
    $this->clickSubmit();

    $this->createScreenshot(__FUNCTION__ . '_after_upload');
    $this->assertSession()->pageTextContains('Swagger Petstore');
  }

  /**
   * Tests revision handling.
   */
  public function testRevisions() {
    $this->drupalLogin($this->rootUser);

    $this->drupalGet('node/add/api_reference');

    $this->uploadFile('petstore-openapi.yaml');
    $this->clickSubmit();

    $this->assertSession()->pageTextContains('Swagger Petstore');
    $this->assertSession()->pageTextContains('1.0.0');

    $this->clickLink('Edit');

    $this->uploadFile('petstore-openapi2.yaml');
    $this->clickSubmit();

    $this->assertSession()->pageTextContains('Swagger Petstore');
    $this->assertSession()->pageTextContains('1.0.1');

    $this->clickLink('Edit');
    $this->assertSession()->waitForButton('Save');
    $this->createScreenshot(__FUNCTION__ . '_edit_page');
    $this->assertSession()->pageTextContains('petstore-openapi.yaml (1.0.0)');
    $this->assertSession()->pageTextContains('petstore-openapi2.yaml (1.0.1)');

    $this->clickLink('Revisions');
    $this->assertSession()->waitForLink('Revert');
    $this->clickLink('Revert');
    $this->assertSession()->waitForLink('Cancel');
    $this->getSession()->getPage()->findButton('Revert')->click();
    $this->assertSession()->waitForLink('View');

    $this->drupalGet('node/1');

    $this->assertSession()->pageTextContains('1.0.0');

    $this->drupalGet('node/1/revisions/2/delete');
    $this->getSession()->getPage()->findButton('Delete')->click();
    $this->assertSession()->waitForLink('Revert');
    $this->createScreenshot(__FUNCTION__ . '_after_revision_delete');

    $this->drupalGet('node/1/edit');

    $this->assertSession()->pageTextContains('petstore-openapi.yaml (1.0.0)');
    $this->assertSession()->pageTextNotContains('petstore-openapi2.yaml (1.0.1)');
  }

  /**
   * Tests the 'allow_version_duplication' setting.
   */
  public function testVersionDuplication() {
    $this->drupalLogin($this->rootUser);
    $this
      ->config('devportal_api_reference.settings')
      ->set('allow_version_duplication', TRUE)
      ->save();

    $this->drupalGet('node/add/api_reference');
    $this->uploadFile('petstore-openapi.yaml');
    $this->clickSubmit();

    $this->assertSession()->pageTextContains('Swagger Petstore');
    $this->assertSession()->pageTextContains('1.0.0');

    $this->clickLink('Edit');

    $this->uploadFile('petstore-openapi-duplicate.yaml');
    $this->clickSubmit();

    $this->assertSession()->pageTextContains('Swagger Petstore');
    $this->assertSession()->pageTextContains('1.0.0');
    $this->assertSession()->pageTextContains('petstore-openapi-duplicate.yaml');
  }

  /**
   * Uploads a file on the node edit form.
   *
   * @param string $filename
   *   Name of the file relative to the fixture directory.
   */
  protected function uploadFile(string $filename) {
    $this
      ->getSession()
      ->getPage()
      ->attachFileToField(static::FILEFIELD_NAME, $this->getFixture($filename));

    $this
      ->assertSession()
      ->waitForLink($filename);
  }

  /**
   * Clicks on the submit button.
   */
  protected function clickSubmit() {
    $this->click('input[name="op"][value="Save"]');
  }

  /**
   * Selects the 'manual' mode on the api_reference node form.
   */
  protected function selectManualMode() {
    $this->selectMode('manual', ['id_or_name', static::TITLE_NAME]);
  }

  /**
   * Selects the 'upload' mode on the api_reference node form.
   */
  protected function selectUploadMode() {
    $this->selectMode('upload', ['id_or_name', static::FILEFIELD_NAME]);
  }

  /**
   * Selects a mode on the api_reference node form.
   *
   * @param string $mode
   *   The mode to select.
   * @param array $locator
   *   An element locator to verify that the mode switch AJAX is finished and
   *   successful.
   */
  protected function selectMode(string $mode, array $locator) {
    $this
      ->getSession()
      ->getPage()
      ->find('css', "input[name=\"mode_selector\"][value=\"{$mode}\"]")
      ->click();

    $this
      ->assertSession()
      ->waitForElement('named', $locator);
  }

  /**
   * Returns the absolute path to a fixture.
   *
   * @param string $filename
   *   File name inside the fixtures directory.
   *
   * @return string
   *   Path.
   */
  protected function getFixture(string $filename): string {
    return DRUPAL_ROOT . '/' . drupal_get_path('module', 'devportal_api_reference') . "/tests/fixtures/{$filename}";
  }

}