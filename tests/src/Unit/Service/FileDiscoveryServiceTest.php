<?php

namespace Drupal\Tests\restic_backup\Unit\Service;

use Drupal\Tests\UnitTestCase;
use Drupal\restic_backup\Service\FileDiscoveryService;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for FileDiscoveryService.
 *
 * @group restic_backup
 * @covers \Drupal\restic_backup\Service\FileDiscoveryService
 */
class FileDiscoveryServiceTest extends UnitTestCase {

  /**
   * File discovery service instance.
   */
  protected FileDiscoveryService $fileDiscoveryService;

  /**
   * Mock file system service.
   */
  protected MockObject $mockFileSystem;

  /**
   * Mock logger.
   */
  protected MockObject $mockLogger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->mockFileSystem = $this->createMock('Drupal\Core\File\FileSystemInterface');
    $this->mockLogger = $this->createMock('Psr\Log\LoggerInterface');

    // TODO: Create actual service instance or use factory
    // $this->fileDiscoveryService = new FileDiscoveryService(
    //   $this->mockFileSystem,
    //   $this->mockLogger
    // );
  }

  /**
   * Test that gitignore parsing works correctly.
   *
   * @test
   */
  public function testGitignoreParsing() {
    $this->markTestIncomplete(
      'This test needs to be implemented to parse .gitignore files'
    );
  }

  /**
   * Test detection of dependency directories.
   *
   * @test
   */
  public function testDependencyDetection() {
    $this->markTestIncomplete(
      'This test needs to be implemented for dependency detection'
    );
  }

  /**
   * Test detection of regenerable files.
   *
   * @test
   */
  public function testRegenerableFileDetection() {
    $this->markTestIncomplete(
      'This test needs to be implemented for regenerable file detection'
    );
  }

}
