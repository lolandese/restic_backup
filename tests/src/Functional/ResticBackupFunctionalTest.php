<?php

namespace Drupal\Tests\restic_backup\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for Restic Backup module.
 *
 * @group restic_backup
 */
class ResticBackupFunctionalTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['restic_backup'];

  /**
   * Test admin page access.
   *
   * @test
   */
  public function testAdminPageAccess() {
    $this->markTestIncomplete(
      'This test needs to be implemented to test admin page access'
    );
  }

  /**
   * Test setup form functionality.
   *
   * @test
   */
  public function testSetupForm() {
    $this->markTestIncomplete(
      'This test needs to be implemented to test setup form'
    );
  }

}
