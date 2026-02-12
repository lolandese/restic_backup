<?php

declare(strict_types = 1);

namespace Drupal\restic_backup\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\restic_backup\Service\FileDiscoveryService;
use Drupal\restic_backup\Service\EncryptionValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * File Selection Form for Restic Backup.
 *
 * Allows users to choose which files and directories to back up.
 *
 * @ingroup restic_backup
 */
class FileSelectionForm extends ConfigFormBase {

  /**
   * File discovery service.
   */
  protected FileDiscoveryService $fileDiscoveryService;

  /**
   * Encryption validator service.
   */
  protected EncryptionValidator $encryptionValidator;

  /**
   * Constructs a FileSelectionForm object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    FileDiscoveryService $file_discovery_service,
    EncryptionValidator $encryption_validator
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->fileDiscoveryService = $file_discovery_service;
    $this->encryptionValidator = $encryption_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('restic_backup.file_discovery'),
      $container->get('restic_backup.encryption_validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['restic_backup.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'restic_backup_file_selection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // Load previously saved selections.
    $savedPaths = $config->get('included_paths') ?? [];

    // Scan project to get file categories (uses cache by default).
    try {
      $fileCategories = $this->fileDiscoveryService->scanProject();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Error scanning project files: @error', [
        '@error' => $e->getMessage(),
      ]));
      return parent::buildForm($form, $form_state);
    }

    $encryptionStatus = $this->encryptionValidator->validateRepository(
      $config->get('repo_path') ?? ''
    );
    $encryptionEnabled = $encryptionStatus['can_backup_sensitive'];

    // Wrapper for AJAX updates
    $form['#prefix'] = '<div id="file-selection-wrapper">';
    $form['#suffix'] = '</div>';

    // ========== STATUS SECTION ==========
    $form['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Backup Status'),
      '#weight' => -20,
    ];

    // Show last scan time and rescan button
    $last_scan = $this->fileDiscoveryService->getLastScanTime();
    $scan_info = '';
    if ($last_scan) {
      $time_ago = $this->formatTimeAgo($last_scan);
      $scan_info = '<div class="messages messages--status">' . $this->t('Last file scan: @time_ago', ['@time_ago' => $time_ago]) . '</div>';
    }
    else {
      $scan_info = '<div class="messages messages--warning">' . $this->t('No file scan has been performed yet. Click "Rescan Files Now" below.') . '</div>';
    }

    $form['status']['scan_info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $scan_info,
    ];

    $form['status']['rescan_button'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rescan Files Now'),
      '#submit' => ['::rescanFiles'],
      '#ajax' => [
        'callback' => '::rescanAjaxCallback',
        'wrapper' => 'file-selection-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Scanning files...'),
        ],
      ],
      '#attributes' => ['class' => ['button', 'button--small']],
    ];

    $form['status']['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['backup-status-summary']],
      '#value' => $this->buildStatusMarkup($fileCategories),
    ];

    // ========== PUBLIC FILES SECTION ==========
    $form['public_files'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('📁 Public Files (User Uploads)'),
      '#weight' => -10,
      '#collapsible' => FALSE,
    ];

    $public_count = $fileCategories['public_files']['count'] ?? 0;
    $total_size = $fileCategories['metadata']['total_size_bytes'] ?? 0;

    $form['public_files']['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $this->t('@count files, @size total', [
        '@count' => $public_count,
        '@size' => $this->formatBytes($total_size),
      ]),
    ];

    // Add checkboxes for each public file (limit to first 50 for performance).
    $public_files = array_slice($fileCategories['public_files']['included'] ?? [], 0, 50);
    foreach ($public_files as $file) {
      $filePath = $file['path'];
      // Default: checked if in saved paths, or TRUE if no saved paths yet (first time).
      $defaultChecked = empty($savedPaths) ? TRUE : in_array($filePath, $savedPaths);

      // Add NEW badge if file is newly discovered
      $title = $filePath . ' (' . $file['human'] . ')';
      if (!empty($file['is_new'])) {
        $title .= ' <span style="color: #d32f2f; font-weight: bold; margin-left: 8px;">NEW</span>';
      }

      $fieldName = 'file_' . str_replace(['/', '.'], '_', $filePath);
      $form['public_files'][$fieldName] = [
        '#type' => 'checkbox',
        '#title' => Markup::create($title),
        '#default_value' => $defaultChecked,
        '#return_value' => $filePath,
      ];
    }

    if (count($fileCategories['public_files']['included'] ?? []) > 50) {
      $form['public_files']['more'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('... and @more more files', [
          '@more' => count($fileCategories['public_files']['included']) - 50,
        ]),
      ];
    }

    // Regenerable files (collapsed, informational).
    if (!empty($fileCategories['regenerable']['informational'])) {
      $form['public_files']['regenerable'] = [
        '#type' => 'details',
        '#title' => $this->t('Regenerable (Auto-Excluded - Safe to Skip)'),
        '#open' => FALSE,
      ];

      foreach ($fileCategories['regenerable']['informational'] as $regen) {
        $form['public_files']['regenerable']['regen_' . str_replace(['/', '.'], '_', $regen['path'])] = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => ['class' => ['regenerable-item']],
          '#value' => $this->t('<strong>@path</strong> - @reason', [
            '@path' => $regen['path'],
            '@reason' => $regen['reason'],
          ]),
        ];
      }
    }

    // ========== PRIVATE FILES SECTION (ENCRYPTION-GATED) ==========
    $form['private_files'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('🔒 Private Files (Requires Encryption)'),
      '#weight' => 0,
    ];

    if (!$encryptionEnabled) {
      $form['private_files']['warning'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $this->t('<strong>⚠️ Encryption Not Enabled</strong> - Private files will be excluded. <a href="@link">Enable encryption</a> to include private files in backups.', [
          '@link' => '/admin/config/system/restic-backup/setup',
        ]),
      ];
    }

    foreach ($fileCategories['private_files']['paths'] ?? [] as $file) {
      $filePath = $file['path'];
      $defaultChecked = $encryptionEnabled && (empty($savedPaths) || in_array($filePath, $savedPaths));

      // Add NEW badge if file is newly discovered
      $title = $filePath . ' (' . $this->formatBytes($file['size']) . ')';
      if (!empty($file['is_new'])) {
        $title .= ' <span style="color: #d32f2f; font-weight: bold; margin-left: 8px;">NEW</span>';
      }

      $fieldName = 'file_' . str_replace(['/', '.'], '_', $filePath);
      $form['private_files'][$fieldName] = [
        '#type' => 'checkbox',
        '#title' => Markup::create($title),
        '#default_value' => $defaultChecked,
        '#disabled' => !$encryptionEnabled,
        '#return_value' => $filePath,
      ];
    }

    // ========== SENSITIVE CONFIG SECTION ==========
    $form['sensitive_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('🔒 Sensitive Configuration (Requires Encryption)'),
      '#weight' => 5,
    ];

    if (!$encryptionEnabled) {
      $sensitive_paths = array_column($fileCategories['sensitive_config']['paths'] ?? [], 'path');
      $form['sensitive_config']['excluded_notice'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['messages', 'messages--warning']],
        '#value' => $this->t('These files will be <strong>excluded</strong> because encryption is not enabled: @files', [
          '@files' => implode(', ', $sensitive_paths),
        ]),
      ];
    }

    foreach ($fileCategories['sensitive_config']['paths'] ?? [] as $file) {
      $filePath = $file['path'];
      $defaultChecked = $encryptionEnabled && (empty($savedPaths) || in_array($filePath, $savedPaths));

      // Add NEW badge if file is newly discovered
      $type_label = $this->sensitiveFileTypeLabel($file['type']);
      $title = $this->t('@path (@type)', [
        '@path' => $filePath,
        '@type' => $type_label,
      ]);
      if (!empty($file['is_new'])) {
        $title .= ' <span style="color: #d32f2f; font-weight: bold; margin-left: 8px;">NEW</span>';
      }

      $fieldName = 'file_' . str_replace(['/', '.'], '_', $filePath);
      $form['sensitive_config'][$fieldName] = [
        '#type' => 'checkbox',
        '#title' => Markup::create($title),
        '#default_value' => $defaultChecked,
        '#disabled' => !$encryptionEnabled,
        '#return_value' => $filePath,
      ];
    }

    // ========== DEPENDENCIES SECTION (INFORMATIONAL) ==========
    if (!empty($fileCategories['dependencies'])) {
      $form['dependencies'] = [
        '#type' => 'details',
        '#title' => $this->t('Dependencies (Auto-Excluded - Managed by Composer/NPM)'),
        '#weight' => 10,
        '#open' => FALSE,
      ];

      $form['dependencies']['info'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $this->t('These directories are excluded by default: @dirs', [
          '@dirs' => implode(', ', array_slice($fileCategories['dependencies'], 0, 10)),
        ]),
      ];
    }

    // ========== EXCLUSIONS SECTION ==========
    $form['exclusions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('⚙️ File Exclusions'),
      '#description' => $this->t('Specify directories and files to exclude from backups. Some cache directories are automatically excluded as they regenerate automatically.'),
      '#weight' => 15,
    ];

    // Get saved exclusions.
    $savedExclusions = $config->get('excluded_paths') ?? [];

    // Define cache directories that should always be excluded (greyed out).
    $autoExcludedCache = [
      'web/sites/default/files/php' => $this->t('Twig template cache - regenerated automatically by Drupal'),
      'web/sites/default/files/styles' => $this->t('Image style cache - regenerated on demand'),
      'web/sites/default/files/css' => $this->t('CSS aggregation cache - regenerated automatically'),
      'web/sites/default/files/js' => $this->t('JavaScript aggregation cache - regenerated automatically'),
      'private/restic-repo' => $this->t('Restic repository itself - must not backup into itself'),
      'private/db-backups' => $this->t('Database backup directory - avoid nested backups'),
    ];

    $form['exclusions']['auto_excluded'] = [
      '#type' => 'details',
      '#title' => $this->t('Auto-Excluded (Recommended - Do Not Change)'),
      '#open' => TRUE,
    ];

    $form['exclusions']['auto_excluded']['info'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => ['class' => ['messages', 'messages--status']],
      '#value' => $this->t('<strong>These directories are automatically excluded</strong> to prevent backup issues and reduce backup size. They contain files that regenerate automatically.'),
    ];

    foreach ($autoExcludedCache as $path => $reason) {
      $form['exclusions']['auto_excluded']['exclude_' . str_replace(['/', '.'], '_', $path)] = [
        '#type' => 'checkbox',
        '#title' => '<strong>' . $path . '</strong>',
        '#description' => $reason,
        '#default_value' => FALSE,
        '#disabled' => TRUE,
        '#attributes' => ['class' => ['auto-excluded-item']],
      ];
    }

    // Custom exclusions (user can add via textarea).
    $customExclusions = array_diff($savedExclusions, array_keys($autoExcludedCache));
    $form['exclusions']['custom_exclusions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional Exclusions (Optional)'),
      '#description' => $this->t('Enter additional paths to exclude, one per line. Paths are relative to project root (e.g., "tmp/cache" or "web/sites/default/files/custom-cache").'),
      '#default_value' => implode("\n", $customExclusions),
      '#rows' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('restic_backup.settings');

    // Collect all selected file paths from form.
    $selectedPaths = [];
    $allValues = $form_state->getValues();

    foreach ($allValues as $key => $value) {
      // Find checkbox fields that start with 'file_' and are checked.
      // The value is now the actual path (from #return_value), not 1
      if (strpos($key, 'file_') === 0 && !empty($value) && is_string($value)) {
        $selectedPaths[] = $value;
      }
    }

    // Process exclusions.
    $autoExcludedCache = [
      'web/sites/default/files/php',
      'web/sites/default/files/styles',
      'web/sites/default/files/css',
      'web/sites/default/files/js',
      'private/restic-repo',
      'private/db-backups',
    ];

    // Parse custom exclusions from textarea.
    $customExclusionsText = $form_state->getValue('custom_exclusions') ?? '';
    $customExclusions = array_filter(
      array_map('trim', explode("\n", $customExclusionsText)),
      function ($line) {
        return !empty($line);
      }
    );

    // Combine auto-excluded and custom exclusions.
    $allExclusions = array_unique(array_merge($autoExcludedCache, $customExclusions));

    // Save exclusions to config.
    $config->set('excluded_paths', $allExclusions);

    // Validate selections with EncryptionValidator.
    try {
      $repoPath = $config->get('repo_path') ?? '';
      $encryptionStatus = $this->encryptionValidator->validateRepository($repoPath);
      $encryptionEnabled = $encryptionStatus['can_backup_sensitive'] ?? FALSE;

      $validation = $this->encryptionValidator->validateSelection(
        $selectedPaths,
        $encryptionEnabled
      );

      // Show warnings for excluded sensitive files.
      if (!empty($validation['excluded_files'])) {
        foreach ($validation['warnings'] as $warning) {
          $this->messenger()->addWarning($warning);
        }

        $this->messenger()->addWarning($this->t(
          'Excluded @count sensitive file(s) because encryption is not enabled. <a href="@link">Enable encryption</a> to include these files.',
          [
            '@count' => count($validation['excluded_files']),
            '@link' => '/admin/config/system/restic-backup/setup',
          ]
        ));
      }

      // Save validated paths to config.
      $config->set('included_paths', $validation['safe_files'])->save();

      $this->messenger()->addStatus($this->t(
        'Saved file selection: @count file(s) selected for backup, @excluded path(s) excluded.',
        [
          '@count' => count($validation['safe_files']),
          '@excluded' => count($allExclusions),
        ]
      ));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t(
        'Error validating file selections: @error',
        ['@error' => $e->getMessage()]
      ));
      return;
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Build status markup HTML for the status section.
   *
   * @param array $categories
   *   File categories from FileDiscoveryService.
   *
   * @return string
   *   HTML markup.
   */
  private function buildStatusMarkup(array $categories): string {
    $total_size = $categories['metadata']['total_size_bytes'] ?? 0;
    $total_files = $categories['metadata']['total_files'] ?? 0;
    $public_count = count($categories['public_files']['included'] ?? []);
    $private_count = count($categories['private_files']['paths'] ?? []);

    $markup = '<div class="restic-status-grid">';
    $markup .= sprintf(
      '<div class="status-item"><strong>Backup Size:</strong> %s</div>',
      $this->formatBytes($total_size)
    );
    $markup .= sprintf(
      '<div class="status-item"><strong>Total Files:</strong> %s</div>',
      $total_files
    );
    $markup .= sprintf(
      '<div class="status-item"><strong>Public Files:</strong> %d</div>',
      $public_count
    );
    $markup .= sprintf(
      '<div class="status-item"><strong>Private Files:</strong> %d</div>',
      $private_count
    );
    $markup .= '</div>';

    return $markup;
  }

  /**
   * Format bytes into human-readable format.
   *
   * @param int $bytes
   *   Number of bytes.
   *
   * @return string
   *   Formatted string (e.g., "1.5 MB").
   */
  private function formatBytes(int $bytes): string {
    if ($bytes < 1024) {
      return $bytes . ' B';
    }
    elseif ($bytes < 1048576) {
      return round($bytes / 1024, 2) . ' KB';
    }
    elseif ($bytes < 1073741824) {
      return round($bytes / 1048576, 2) . ' MB';
    }
    else {
      return round($bytes / 1073741824, 2) . ' GB';
    }
  }

  /**
   * Get human-readable label for sensitive file type.
   *
   * @param string $type
   *   Sensitive file type from EncryptionValidator.
   *
   * @return string
   *   Human-readable label.
   */
  private function sensitiveFileTypeLabel(string $type): string {
    $labels = [
      'drupal_config' => 'Drupal Settings',
      'drupal_services' => 'Service Configuration',
      'environment_vars' => 'Environment Variables',
      'crypto_key' => 'Private Key',
      'private_files' => 'Private Files',
      'other_sensitive' => 'Sensitive',
    ];

    return $labels[$type] ?? 'Sensitive';
  }

  /**
   * Submit handler for "Rescan Files Now" button.
   */
  public function rescanFiles(array &$form, FormStateInterface $form_state): void {
    try {
      $this->fileDiscoveryService->scanAndCache();
      $this->messenger()->addStatus($this->t('File scan completed successfully. New files will be marked with a red "NEW" badge.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('File scan failed: @error', [
        '@error' => $e->getMessage(),
      ]));
    }

    // Rebuild form to show new results
    $form_state->setRebuild(TRUE);
  }

  /**
   * AJAX callback for "Rescan Files Now" button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The updated form.
   */
  public function rescanAjaxCallback(array &$form, FormStateInterface $form_state): array {
    return $form;
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
