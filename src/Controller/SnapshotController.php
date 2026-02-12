<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\restic_backup\Service\ResticManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Snapshot Management Controller.
 *
 * @ingroup restic_backup
 */
class SnapshotController extends ControllerBase {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Constructs a SnapshotController object.
   */
  public function __construct(ResticManager $restic_manager) {
    $this->resticManager = $restic_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('restic_backup.manager')
    );
  }

  /**
   * List all snapshots.
   *
   * Displays a table of all available snapshots with details and restore links.
   */
  public function listSnapshots() {
    $snapshots = $this->resticManager->listSnapshots();

    if (empty($snapshots)) {
      return [
        '#markup' => '<p>' . $this->t('No snapshots found. Please create a backup first.') . '</p>',
        '#attached' => [
          'library' => ['restic_backup/admin'],
        ],
      ];
    }

    // Build table rows
    $rows = [];
    foreach (array_reverse($snapshots) as $snapshot) {
      $snapshot_id = $snapshot['id'];
      $short_id = substr($snapshot_id, 0, 8);
      $time_ago = isset($snapshot['time']) ? $this->formatTimeAgo(strtotime($snapshot['time'])) : 'N/A';
      $hostname = $snapshot['hostname'] ?? 'N/A';
      $paths = isset($snapshot['paths']) ? implode('<br>', array_slice($snapshot['paths'], 0, 3)) : 'N/A';

      $rows[] = [
        $short_id,
        $time_ago,
        $hostname,
        ['data' => ['#markup' => $paths]],
      ];
    }

    $build = [];

    $build['description'] = [
      '#markup' => '<p>' . $this->t('All available backup snapshots. Use the "Restore" tab above to restore files from a specific snapshot.') . '</p>',
    ];

    $build['snapshots_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Snapshot ID'),
        $this->t('Age'),
        $this->t('Hostname'),
        $this->t('Backed up paths'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No snapshots available.'),
      '#attributes' => ['class' => ['restic-snapshots-table']],
    ];

    $build['#attached']['library'][] = 'restic_backup/admin';

    return $build;
  }

  /**
   * Format a timestamp as a time-ago string with two units.
   *
   * @param int $timestamp
   *   The Unix timestamp to format.
   *
   * @return string
   *   Formatted time-ago string like "2 hours 30 minutes ago".
   */
  protected function formatTimeAgo(int $timestamp): string {
    $diff = time() - $timestamp;

    if ($diff < 0) {
      return (string) $this->t('in the future');
    }

    $units = [
      'year' => 31536000,
      'month' => 2592000,
      'week' => 604800,
      'day' => 86400,
      'hour' => 3600,
      'minute' => 60,
      'second' => 1,
    ];

    $parts = [];
    foreach ($units as $unit => $seconds) {
      if ($diff >= $seconds) {
        $value = (int) floor($diff / $seconds);
        $diff %= $seconds;

        $parts[] = (string) $this->formatPlural($value, '1 ' . $unit, '@count ' . $unit . 's');

        if (count($parts) >= 2) {
          break;
        }
      }
    }

    if (empty($parts)) {
      return (string) $this->t('just now');
    }

    return implode(' ', $parts) . ' ' . (string) $this->t('ago');
  }

  /**
   * View snapshot details.
   *
   * TODO: Implement snapshot detail view.
   */
  public function viewSnapshot($snapshot_id) {
    return [
      '#markup' => 'Snapshot details - TODO: Complete implementation',
    ];
  }

}
