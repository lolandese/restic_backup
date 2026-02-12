<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\restic_backup\Service\ResticManager;
use Drupal\restic_backup\Service\BackupLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin Dashboard Form for Restic Backup Status and Actions.
 *
 * @ingroup restic_backup
 */
class DashboardForm extends FormBase {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Backup logger service.
   */
  protected BackupLogger $backupLogger;

  /**
   * Constructs a DashboardForm object.
   */
  public function __construct(
    ResticManager $restic_manager,
    BackupLogger $backup_logger
  ) {
    $this->resticManager = $restic_manager;
    $this->backupLogger = $backup_logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('restic_backup.manager'),
      $container->get('restic_backup.backup_logger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'restic_backup_dashboard_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // Status Section
    $form['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Repository Status'),
      '#weight' => -10,
    ];

    // Get repository statistics
    $stats = $this->resticManager->getRepositoryStats();
    $snapshots = $this->resticManager->listSnapshots();

    if (!empty($stats)) {
      $status_items = [
        $this->t('Total Snapshots: @count', ['@count' => $stats['snapshot_count']]),
        $this->t('Total Size: @size', ['@size' => $this->formatBytes($stats['total_size'])]),
        $this->t('Total Files: @count', ['@count' => number_format($stats['total_file_count'])]),
      ];

      // Get latest snapshot info
      if (!empty($snapshots)) {
        $latest = end($snapshots);
        $latest_time_ago = $this->formatTimeAgo(strtotime($latest['time']));
        $status_items[] = $this->t('Latest Backup: @time', ['@time' => $latest_time_ago]);
      }
      else {
        $status_items[] = $this->t('No backups yet');
      }

      $form['status']['info'] = [
        '#theme' => 'item_list',
        '#items' => $status_items,
      ];
    }
    else {
      $form['status']['info'] = [
        '#markup' => '<p>' . $this->t('Repository not configured or not accessible. Please complete the setup wizard first.') . '</p>',
      ];
    }

    // Configuration Summary Section
    $form['config_summary'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Configuration'),
      '#weight' => -5,
    ];

    $repo_path = $config->get('repo_path');
    $encryption_enabled = $config->get('encryption_enabled');
    $retention = $config->get('retention_policy') ?: [];

    $config_items = [
      $this->t('Repository: @path', ['@path' => $repo_path ?: $this->t('Not configured')]),
      $this->t('Encryption: @status', ['@status' => $encryption_enabled ? $this->t('Enabled') : $this->t('Disabled')]),
    ];

    if (!empty($retention)) {
      $config_items[] = $this->t('Retention: @daily daily, @weekly weekly, @monthly monthly, @yearly yearly', [
        '@daily' => $retention['keep_daily'] ?? 7,
        '@weekly' => $retention['keep_weekly'] ?? 4,
        '@monthly' => $retention['keep_monthly'] ?? 12,
        '@yearly' => $retention['keep_yearly'] ?? 3,
      ]);
    }

    $form['config_summary']['info'] = [
      '#theme' => 'item_list',
      '#items' => $config_items,
    ];

    // Quick Actions Section
    $form['actions_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Quick Actions'),
      '#weight' => 0,
    ];

    $form['actions_section']['backup_now'] = [
      '#type' => 'submit',
      '#value' => $this->t('Backup Now'),
      '#name' => 'backup_now',
      '#submit' => ['::backupNowSubmit'],
      '#button_type' => 'primary',
    ];

    $form['actions_section']['check_repo'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check Repository Integrity'),
      '#name' => 'check_repo',
      '#submit' => ['::checkRepositorySubmit'],
    ];

    $form['actions_section']['view_snapshots'] = [
      '#type' => 'link',
      '#title' => $this->t('View All Snapshots'),
      '#url' => \Drupal\Core\Url::fromRoute('restic_backup.snapshots_list'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['actions_section']['configure'] = [
      '#type' => 'link',
      '#title' => $this->t('Configure Settings'),
      '#url' => \Drupal\Core\Url::fromRoute('restic_backup.admin_setup'),
      '#attributes' => ['class' => ['button']],
    ];

    // Recent Snapshots Section
    if (!empty($snapshots)) {
      $form['recent_snapshots'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Recent Snapshots (Last 5)'),
        '#weight' => 5,
      ];

      // Get last 5 snapshots
      $recent = array_slice(array_reverse($snapshots), 0, 5);
      $rows = [];

      foreach ($recent as $snapshot) {
        $rows[] = [
          substr($snapshot['id'], 0, 8),
          $this->formatTimeAgo(strtotime($snapshot['time'])),
          $snapshot['hostname'] ?? 'N/A',
          isset($snapshot['paths']) ? implode(', ', array_slice($snapshot['paths'], 0, 2)) : 'N/A',
        ];
      }

      $form['recent_snapshots']['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Age'),
          $this->t('Hostname'),
          $this->t('Paths'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No snapshots available'),
      ];
    }

    return $form;
  }

  /**
   * Submit handler for Backup Now button.
   */
  public function backupNowSubmit(array &$form, FormStateInterface $form_state) {
    try {
      $config = $this->config('restic_backup.settings');
      $paths = $config->get('included_paths') ?: [];

      if (empty($paths)) {
        $this->messenger()->addWarning($this->t('No paths configured for backup. Please select files first.'));
        $form_state->setRedirect('restic_backup.admin_files');
        return;
      }

      $this->messenger()->addStatus($this->t('Starting backup operation...'));

      // Execute backup
      $result = $this->resticManager->backup($paths);

      if ($result['success']) {
        $this->messenger()->addStatus($this->t('Backup completed successfully: @message', [
          '@message' => $result['message'],
        ]));

        // Log the backup
        $this->backupLogger->logOperation('backup', 'info', [
          'snapshot_id' => $result['snapshot_id'] ?? 'unknown',
          'files_count' => $result['files_count'] ?? 0,
          'backup_size' => $result['backup_size'] ?? 0,
        ]);
      }
      else {
        $this->messenger()->addError($this->t('Backup failed: @message', [
          '@message' => $result['message'],
        ]));
      }

      // Rebuild form to show updated stats
      $form_state->setRebuild(TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Exception during backup: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Submit handler for Check Repository button.
   */
  public function checkRepositorySubmit(array &$form, FormStateInterface $form_state) {
    try {
      $this->messenger()->addStatus($this->t('Checking repository integrity... This may take several minutes.'));

      $result = $this->resticManager->check();

      if ($result['success']) {
        $this->messenger()->addStatus($this->t('✓ Repository check passed: @message', [
          '@message' => $result['message'],
        ]));
      }
      else {
        $this->messenger()->addError($this->t('✗ Repository check failed: @message', [
          '@message' => $result['message'],
        ]));
      }

      $form_state->setRebuild(TRUE);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Exception during repository check: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Default submit handler (not used, buttons have their own handlers)
  }

  /**
   * Format bytes for human-readable display.
   *
   * @param int $bytes
   *   Number of bytes.
   *
   * @return string
   *   Formatted string.
   */
  protected function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, 2) . ' ' . $units[$pow];
  }

  /**
   * Format timestamp as time ago with granularity of 2 (integers only).
   *
   * @param int $timestamp
   *   Unix timestamp.
   *
   * @return string
   *   Formatted time ago string (e.g., "2 hours 30 minutes ago").
   */
  protected function formatTimeAgo(int $timestamp): string {
    $now = time();
    $diff = $now - $timestamp;

    if ($diff < 0) {
      return $this->t('in the future')->render();
    }

    $units = [
      'year' => 31536000,    // 365 * 24 * 60 * 60
      'month' => 2592000,    // 30 * 24 * 60 * 60
      'week' => 604800,      // 7 * 24 * 60 * 60
      'day' => 86400,        // 24 * 60 * 60
      'hour' => 3600,        // 60 * 60
      'minute' => 60,
      'second' => 1,
    ];

    $parts = [];
    $granularity = 0;

    foreach ($units as $unit => $seconds) {
      if ($granularity >= 2) {
        break;
      }

      $value = floor($diff / $seconds);
      if ($value > 0) {
        // Use integer value (no decimals)
        $parts[] = $this->formatPlural($value, '1 ' . $unit, '@count ' . $unit . 's')->render();
        $diff -= $value * $seconds;
        $granularity++;
      }
    }

    if (empty($parts)) {
      return $this->t('just now')->render();
    }

    return implode(' ', $parts) . ' ' . $this->t('ago')->render();
  }

}
