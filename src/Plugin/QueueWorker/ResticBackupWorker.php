<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\restic_backup\Service\ResticManager;
use Drupal\restic_backup\Service\BackupLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes asynchronous backups.
 *
 * @QueueWorker(
 *   id = "restic_backup_jobs",
 *   title = @Translation("Restic Backup Jobs"),
 *   cron = {"time" = 60}
 * )
 *
 * @ingroup restic_backup
 */
class ResticBackupWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Backup logger service.
   */
  protected BackupLogger $backupLogger;

  /**
   * Logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->resticManager = $container->get('restic_backup.manager');
    $instance->backupLogger = $container->get('restic_backup.backup_logger');
    $instance->logger = $container->get('logger.channel.restic_backup');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // TODO: Implement backup queue processing
    // Handle different operation types: backup, restore, prune
    // Log progress and results
    // Handle errors appropriately

    $this->logger->info('Processing backup job: @data', [
      '@data' => json_encode($data),
    ]);
  }

}
