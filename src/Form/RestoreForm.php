<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\restic_backup\Service\ResticManager;
use Drupal\restic_backup\Service\BackupLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for Restoring Files from a Snapshot.
 *
 * @ingroup restic_backup
 */
class RestoreForm extends FormBase {

  /**
   * Restic manager service.
   */
  protected ResticManager $resticManager;

  /**
   * Backup logger service.
   */
  protected BackupLogger $backupLogger;

  /**
   * Snapshot ID to restore from.
   */
  protected ?string $snapshotId = NULL;

  /**
   * Constructs a RestoreForm object.
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
    return 'restic_backup_restore_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get all available snapshots first to determine default
    $snapshots = $this->resticManager->listSnapshots();

    if (empty($snapshots)) {
      $form['no_snapshots'] = [
        '#markup' => '<p>' . $this->t('No snapshots available to restore. Please create a backup first.') . '</p>',
      ];
      return $form;
    }

    // Default to latest snapshot (last in array) if not yet selected
    $latest_snapshot_id = end($snapshots)['id'];
    $selected_snapshot = $form_state->getValue('snapshot_id') ?? $latest_snapshot_id;
    $this->snapshotId = $selected_snapshot;

    // ========== SNAPSHOT SELECTION SECTION ==========
    $form['snapshot_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('📸 Select Snapshot'),
      '#weight' => -10,
    ];

    // Build snapshot options
    $snapshot_options = [];
    $snapshot_details = [];

    foreach ($snapshots as $snapshot) {
      $id = $snapshot['id'];
      $short_id = substr($id, 0, 8);
      $time = date('Y-m-d H:i:s', strtotime($snapshot['time']));
      $time_ago = $this->formatTimeAgo(strtotime($snapshot['time']));
      $hostname = $snapshot['hostname'] ?? 'unknown';
      $paths = isset($snapshot['paths']) ? implode(', ', $snapshot['paths']) : 'N/A';

      $snapshot_options[$id] = $this->t('@id - @time_ago (@hostname)', [
        '@id' => $short_id,
        '@time_ago' => $time_ago,
        '@hostname' => $hostname,
      ]);

      $snapshot_details[$id] = [
        'full_id' => $id,
        'short_id' => $short_id,
        'time' => $time,
        'hostname' => $hostname,
        'paths' => $paths,
      ];
    }

    $form['snapshot_section']['snapshot_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Snapshot'),
      '#description' => $this->t('Select a snapshot to restore from. <strong>Latest snapshot is pre-selected.</strong>'),
      '#options' => array_reverse($snapshot_options, TRUE),
      '#default_value' => $selected_snapshot,
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::snapshotAjaxCallback',
        'wrapper' => 'snapshot-info-wrapper',
        'event' => 'change',
      ],
    ];

    // ========== SNAPSHOT DETAILS SECTION ==========
    $form['snapshot_info'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'snapshot-info-wrapper'],
      '#weight' => -5,
    ];

    if ($selected_snapshot && isset($snapshot_details[$selected_snapshot])) {
      $details = $snapshot_details[$selected_snapshot];

      $form['snapshot_info']['details'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Snapshot Details'),
      ];

      $form['snapshot_info']['details']['info'] = [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('<strong>Full ID:</strong> @id', ['@id' => $details['full_id']]),
          $this->t('<strong>Created:</strong> @time', ['@time' => $details['time']]),
          $this->t('<strong>Hostname:</strong> @hostname', ['@hostname' => $details['hostname']]),
          $this->t('<strong>Backed up paths:</strong> @paths', ['@paths' => $details['paths']]),
        ],
      ];
    }

    // ========== RESTORE OPTIONS SECTION ==========
    $form['restore_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('⚙️ Restore Options'),
      '#weight' => 0,
    ];

    $project_root = dirname(\Drupal::root());

    $form['restore_section']['target_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Target Restore Path'),
      '#description' => $this->t('Full path where files will be restored. Leave empty to restore to original locations (recommended). Example: @root/restore-YYYYMMDD', [
        '@root' => $project_root,
      ]),
      '#default_value' => '',
      '#placeholder' => $this->t('Restore to original locations (default)'),
    ];

    $form['restore_section']['restore_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Restore Mode'),
      '#options' => [
        'full' => $this->t('Full Snapshot - Restore all files from this snapshot'),
        'specific' => $this->t('Specific Files - Enter specific file paths to restore (advanced)'),
      ],
      '#default_value' => 'full',
    ];

    $form['restore_section']['specific_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specific File Paths'),
      '#description' => $this->t('Enter specific file paths to restore, one per line. Paths should match those in the snapshot (e.g., "web/sites/default/files/image.jpg").'),
      '#rows' => 5,
      '#states' => [
        'visible' => [
          ':input[name="restore_mode"]' => ['value' => 'specific'],
        ],
      ],
    ];

    // ========== WARNING SECTION ==========
    $form['warning'] = [
      '#type' => 'container',
      '#weight' => 5,
      '#attributes' => ['class' => ['messages', 'messages--warning']],
    ];

    $form['warning']['message'] = [
      '#markup' => '<strong>⚠️ Warning:</strong> Restoring files will <strong>overwrite</strong> existing files with the same names. Make sure you have a current backup before proceeding, or specify a different target path.',
    ];

    // ========== CONFIRMATION CHECKBOX ==========
    $form['confirm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that this will overwrite existing files and I have verified the snapshot selection.'),
      '#required' => TRUE,
      '#weight' => 10,
    ];

    // ========== SUBMIT BUTTON ==========
    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 15,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Restore Files'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => \Drupal\Core\Url::fromRoute('restic_backup.admin_page'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * AJAX callback for snapshot selection.
   */
  public function snapshotAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['snapshot_info'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $target_path = $form_state->getValue('target_path');

    // If target path is specified, validate it
    if (!empty($target_path)) {
      // Ensure it's an absolute path
      if ($target_path[0] !== '/') {
        $form_state->setErrorByName('target_path',
          $this->t('Target path must be an absolute path (starting with /).')
        );
      }

      // Check if it's writable (if it exists)
      if (file_exists($target_path) && !is_writable($target_path)) {
        $form_state->setErrorByName('target_path',
          $this->t('Target path exists but is not writable. Please check permissions.')
        );
      }

      // Warn if target path doesn't exist (restic will create it)
      if (!file_exists($target_path)) {
        $this->messenger()->addWarning($this->t(
          'Target path does not exist. Restic will create it during restore.'
        ));
      }
    }

    // Validate specific paths if restore mode is 'specific'
    $restore_mode = $form_state->getValue('restore_mode');
    if ($restore_mode === 'specific') {
      $specific_paths = $form_state->getValue('specific_paths');

      if (empty(trim($specific_paths))) {
        $form_state->setErrorByName('specific_paths',
          $this->t('Please specify at least one file path to restore, or switch to "Full Snapshot" mode.')
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      $snapshot_id = $form_state->getValue('snapshot_id');
      $target_path = $form_state->getValue('target_path');
      $restore_mode = $form_state->getValue('restore_mode');

      // Parse specific paths if provided
      $include_paths = [];
      if ($restore_mode === 'specific') {
        $specific_paths_text = $form_state->getValue('specific_paths');
        $include_paths = array_filter(
          array_map('trim', explode("\n", $specific_paths_text)),
          function ($line) {
            return !empty($line);
          }
        );
      }

      $this->messenger()->addStatus($this->t('Starting restore operation from snapshot @id...', [
        '@id' => substr($snapshot_id, 0, 8),
      ]));

      // Execute restore
      $result = $this->resticManager->restore($snapshot_id, $target_path, $include_paths);

      if ($result['success']) {
        $this->messenger()->addStatus($this->t('✓ Restore completed successfully!'));

        if (!empty($result['message'])) {
          $this->messenger()->addStatus($result['message']);
        }

        if (!empty($result['files_restored'])) {
          $this->messenger()->addStatus($this->t('Files restored: @count', [
            '@count' => $result['files_restored'],
          ]));
        }

        // Log the restore operation
        $this->backupLogger->logOperation('restore', 'info', [
          'snapshot_id' => $snapshot_id,
          'target_path' => $target_path ?: 'original locations',
          'restore_mode' => $restore_mode,
          'files_restored' => $result['files_restored'] ?? 0,
        ]);

        // Redirect to dashboard
        $form_state->setRedirect('restic_backup.admin_page');
      }
      else {
        $this->messenger()->addError($this->t('✗ Restore failed: @message', [
          '@message' => $result['message'] ?? $this->t('Unknown error'),
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Exception during restore: @error', [
        '@error' => $e->getMessage(),
      ]));

      $this->backupLogger->logOperation('restore', 'error', [
        'snapshot_id' => $snapshot_id ?? 'unknown',
        'error' => $e->getMessage(),
      ]);
    }
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
      'year' => 31536000,
      'month' => 2592000,
      'week' => 604800,
      'day' => 86400,
      'hour' => 3600,
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
