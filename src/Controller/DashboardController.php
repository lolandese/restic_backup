<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\restic_backup\Service\ResticManager;
use Drupal\Core\Messenger\Messenger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin Dashboard Controller for Restic Backup.
 *
 * @ingroup restic_backup
 */
class DashboardController extends ControllerBase {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Queue factory service.
   */
  protected QueueFactory $queueFactory;

  /**
   * Constructs a DashboardController object.
   */
  public function __construct(ResticManager $restic_manager, QueueFactory $queue_factory) {
    $this->resticManager = $restic_manager;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('restic_backup.manager'),
      $container->get('queue')
    );
  }

  /**
   * Dashboard page callback.
   *
   * TODO: Implement dashboard rendering with status and stats.
   */
  public function dashboard() {
    return [
      '#markup' => 'Dashboard - TODO: Complete implementation',
    ];
  }

  /**
   * AJAX endpoint to start backup immediately.
   *
   * Triggers a manual backup operation (immediate or queued based on config).
   */
  public function backupNow(Request $request) {
    // Validate request method
    if ($request->getMethod() !== 'POST') {
      return new JsonResponse(['error' => 'Invalid request method'], 400);
    }

    try {
      // Get configured paths
      $config = $this->config('restic_backup.settings');
      $paths = $config->get('included_paths') ?? [];

      if (empty($paths)) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $this->t('No paths configured for backup.'),
        ], 400);
      }

      // Check if queue is enabled
      $use_queue = $config->get('use_queue') ?? FALSE;

      if ($use_queue) {
        // Add to queue for background processing
        $queue = $this->queueFactory->get('restic_backup_queue');
        $queue->createItem([
          'operation' => 'backup',
          'paths' => $paths,
        ]);

        return new JsonResponse([
          'success' => TRUE,
          'message' => $this->t('Backup has been queued for processing. Check the Logs tab for progress.'),
          'queued' => TRUE,
        ]);
      }
      else {
        // Run backup immediately (synchronous)
        $result = $this->resticManager->backup($paths);

        if ($result['success']) {
          return new JsonResponse([
            'success' => TRUE,
            'message' => $this->t('Backup completed successfully.'),
            'snapshot_id' => $result['snapshot_id'] ?? NULL,
          ]);
        }
        else {
          return new JsonResponse([
            'success' => FALSE,
            'message' => $result['message'] ?? $this->t('Backup failed.'),
          ], 500);
        }
      }
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => $this->t('Exception: @error', ['@error' => $e->getMessage()]),
      ], 500);
    }
  }

}
