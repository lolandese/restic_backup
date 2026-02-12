<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\restic_backup\Service\BackupLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Log Viewer Controller.
 *
 * @ingroup restic_backup
 */
class LogController extends ControllerBase {

  /**
   * Backup logger service.
   */
  protected BackupLogger $backupLogger;

  /**
   * Constructs a LogController object.
   */
  public function __construct(BackupLogger $backup_logger) {
    $this->backupLogger = $backup_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('restic_backup.backup_logger')
    );
  }

  /**
   * View backup operation logs.
   *
   * Displays watchdog logs filtered by restic_backup channel.
   */
  public function viewLogs() {
    $build = [];

    $build['description'] = [
      '#markup' => '<p>' . $this->t('Recent backup operations and events logged by the Restic Backup module.') . '</p>',
    ];

    // Query watchdog logs for restic_backup messages
    $connection = \Drupal::database();

    try {
      $query = $connection->select('watchdog', 'w')
        ->fields('w', ['wid', 'type', 'message', 'variables', 'severity', 'timestamp'])
        ->condition('type', 'restic_backup')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 100);

      $results = $query->execute();

      $rows = [];
      foreach ($results as $log) {
        // Decode variables
        $variables = $log->variables ? unserialize($log->variables) : [];

        // Format message
        $message = $this->t($log->message, $variables)->render();

        // Format severity
        $severity_labels = [
          0 => $this->t('Emergency'),
          1 => $this->t('Alert'),
          2 => $this->t('Critical'),
          3 => $this->t('Error'),
          4 => $this->t('Warning'),
          5 => $this->t('Notice'),
          6 => $this->t('Info'),
          7 => $this->t('Debug'),
        ];
        $severity = $severity_labels[$log->severity] ?? $this->t('Unknown');

        // Format timestamp with time ago
        $timestamp = (int) $log->timestamp;
        $time = date('Y-m-d H:i:s', $timestamp);
        $time_ago = $this->formatTimeAgo($timestamp);
        $time_display = $this->t('@time<br><small>(@time_ago)</small>', [
          '@time' => $time,
          '@time_ago' => $time_ago,
        ]);

        $rows[] = [
          ['data' => ['#markup' => $time_display]],
          $severity,
          ['data' => ['#markup' => $message]],
        ];
      }

      if (empty($rows)) {
        $build['no_logs'] = [
          '#markup' => '<p>' . $this->t('No logs found. Backup operations will be logged here.') . '</p>',
        ];
      }
      else {
        $build['logs_table'] = [
          '#type' => 'table',
          '#header' => [
            ['data' => $this->t('Time'), 'style' => 'width: 200px; min-width: 200px;'],
            ['data' => $this->t('Severity'), 'style' => 'width: 120px;'],
            $this->t('Message'),
          ],
          '#rows' => $rows,
          '#attributes' => ['class' => ['restic-logs-table']],
        ];
      }
    }
    catch (\Exception $e) {
      $build['error'] = [
        '#markup' => '<p>' . $this->t('Error loading logs: @error', ['@error' => $e->getMessage()]) . '</p>',
      ];
    }

    $build['full_logs_link'] = [
      '#markup' => '<p>' . $this->t('View <a href="@url">all system logs</a>.', [
        '@url' => '/admin/reports/dblog',
      ]) . '</p>',
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

}
