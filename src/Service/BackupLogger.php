<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Service;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Service for logging backup operations to Drupal watchdog.
 *
 * Provides structured logging of backup events with metadata tracking.
 */
class BackupLogger {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Logger instance.
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a new BackupLogger instance.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    Connection $database,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->logger = $logger;
  }

  /**
   * Log a backup operation.
   *
   * @param string $operation
   *   Operation type: 'backup', 'restore', 'prune', 'init', etc.
   * @param string $level
   *   Log level: 'debug', 'info', 'warning', 'error'.
   * @param array $data
   *   Operation data to log (snapshot_id, file_count, size, etc.).
   */
  public function logOperation(string $operation, string $level, array $data): void {
    $message = sprintf(
      '%s operation: %s',
      ucfirst($operation),
      json_encode($data)
    );

    // Log to Drupal watchdog
    $this->logger->log(
      $this->getLevelSeverity($level),
      $message,
      ['@operation' => $operation, '@data' => json_encode($data)]
    );

    // Also store in database for UI display
    $this->database->insert('watchdog')
      ->fields([
        'uid' => 0,
        'type' => 'restic_backup',
        'message' => $message,
        'variables' => json_encode(['@operation' => $operation, '@data' => $data]),
        'severity' => $this->getLevelSeverity($level),
        'link' => 'admin/config/system/restic-backup/logs',
        'timestamp' => \Drupal::time()->getCurrentTime(),
      ])
      ->execute();
  }

  /**
   * Get recent backup logs.
   *
   * @param int $limit
   *   Number of recent logs to return.
   *
   * @return array
   *   Array of recent log entries.
   */
  public function getRecentLogs(int $limit = 50): array {
    $query = $this->database->select('watchdog', 'w')
      ->fields('w')
      ->condition('w.type', 'restic_backup')
      ->orderBy('w.timestamp', 'DESC')
      ->range(0, $limit);

    return $query->execute()->fetchAllAssoc('wid');
  }

  /**
   * Get backup logs with optional filtering.
   *
   * @param array $filter
   *   Filter criteria (operation, severity, date_start, date_end).
   *
   * @return array
   *   Array of matching log entries.
   */
  public function getLogs(array $filter = []): array {
    $query = $this->database->select('watchdog', 'w')
      ->fields('w')
      ->condition('w.type', 'restic_backup');

    if (isset($filter['severity'])) {
      $query->condition('w.severity', $filter['severity']);
    }

    if (isset($filter['date_start'])) {
      $query->condition('w.timestamp', $filter['date_start'], '>=');
    }

    if (isset($filter['date_end'])) {
      $query->condition('w.timestamp', $filter['date_end'], '<=');
    }

    $query->orderBy('w.timestamp', 'DESC');

    return $query->execute()->fetchAllAssoc('wid');
  }

  /**
   * Convert log level string to watchdog severity constant.
   *
   * @param string $level
   *   Level name: debug, info, warning, error.
   *
   * @return int
   *   Watchdog severity constant.
   */
  private function getLevelSeverity(string $level): int {
    $levels = [
      'debug' => \Drupal\Core\Logger\RfcLogLevel::DEBUG,
      'info' => \Drupal\Core\Logger\RfcLogLevel::INFO,
      'notice' => \Drupal\Core\Logger\RfcLogLevel::NOTICE,
      'warning' => \Drupal\Core\Logger\RfcLogLevel::WARNING,
      'error' => \Drupal\Core\Logger\RfcLogLevel::ERROR,
      'critical' => \Drupal\Core\Logger\RfcLogLevel::CRITICAL,
    ];

    return $levels[$level] ?? \Drupal\Core\Logger\RfcLogLevel::INFO;
  }

}
