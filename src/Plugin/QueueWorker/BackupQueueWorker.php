<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\restic_backup\Service\ResticManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes backup jobs in the background.
 *
 * @QueueWorker(
 *   id = "restic_backup_queue",
 *   title = @Translation("Restic Backup Queue Worker"),
 *   cron = {"time" = 300}
 * )
 */
class BackupQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Logger service.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a BackupQueueWorker object.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\restic_backup\Service\ResticManager $restic_manager
   *   Restic manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ResticManager $restic_manager,
    LoggerInterface $logger
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->resticManager = $restic_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('restic_backup.manager'),
      $container->get('logger.factory')->get('restic_backup')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Validate item data
    if (!isset($data['operation'])) {
      $this->logger->error('Queue item missing operation type');
      return;
    }

    $operation = $data['operation'];

    try {
      switch ($operation) {
        case 'backup':
          $this->processBackup($data);
          break;

        case 'prune':
          $this->processPrune($data);
          break;

        case 'check':
          $this->processCheck($data);
          break;

        default:
          $this->logger->warning('Unknown queue operation: @operation', [
            '@operation' => $operation,
          ]);
          break;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Queue worker error: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Process a backup operation.
   *
   * @param array $data
   *   Queue item data with 'paths' key.
   */
  protected function processBackup(array $data): void {
    if (!isset($data['paths']) || empty($data['paths'])) {
      $this->logger->error('Backup queue item missing paths');
      return;
    }

    $paths = $data['paths'];
    $this->logger->info('Starting queued backup for @count path(s)', [
      '@count' => count($paths),
    ]);

    $result = $this->resticManager->backup($paths);

    if ($result['success']) {
      $this->logger->info('Queued backup completed successfully. Snapshot: @id', [
        '@id' => $result['snapshot_id'] ?? 'unknown',
      ]);
    }
    else {
      $this->logger->error('Queued backup failed: @message', [
        '@message' => $result['message'] ?? 'Unknown error',
      ]);
    }
  }

  /**
   * Process a prune operation.
   *
   * @param array $data
   *   Queue item data (no additional data needed).
   */
  protected function processPrune(array $data): void {
    $this->logger->info('Starting queued prune operation');

    $result = $this->resticManager->prune();

    if ($result['success']) {
      $this->logger->info('Queued prune completed successfully');
    }
    else {
      $this->logger->error('Queued prune failed: @message', [
        '@message' => $result['message'] ?? 'Unknown error',
      ]);
    }
  }

  /**
   * Process a check operation.
   *
   * @param array $data
   *   Queue item data (no additional data needed).
   */
  protected function processCheck(array $data): void {
    $this->logger->info('Starting queued repository check');

    $result = $this->resticManager->check();

    if ($result['success']) {
      $this->logger->info('Queued repository check completed successfully');
    }
    else {
      $this->logger->error('Queued repository check failed: @message', [
        '@message' => $result['message'] ?? 'Unknown error',
      ]);
    }
  }

}
