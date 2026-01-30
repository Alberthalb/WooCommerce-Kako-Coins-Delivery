<?php
if (!defined('ABSPATH')) exit;

class Yoda_Ledger {
  const TABLE = 'yoda_ledger';
  // Statuses padronizados
  const STATUS_PENDING   = 'pending';
  const STATUS_AVAILABLE = 'available';
  const STATUS_PAID      = 'paid';
  const STATUS_REVERSED  = 'reversed';
  const STATUS_BLOCKED   = 'blocked';

  public static function hooks(){
    self::maybe_create_table();
  }

  public static function maybe_create_table(){
    global $wpdb;
    $table = self::table_name();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      created_at DATETIME NOT NULL,
      type VARCHAR(32) NOT NULL,
      ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      amount DECIMAL(18,4) NOT NULL DEFAULT 0,
      status VARCHAR(32) NOT NULL,
      meta LONGTEXT NULL,
      PRIMARY KEY  (id),
      KEY type_idx (type),
      KEY ref_idx (ref_id),
      KEY user_idx (user_id),
      KEY status_idx (status),
      KEY type_status_created_idx (type, status, created_at),
      KEY ref_user_idx (ref_id, user_id)
    ) $charset;";
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  public static function log($type, $ref_id, $user_id, $amount, $status, array $meta = []){
    global $wpdb;
    $table = self::table_name();
    $row = [
      'created_at' => current_time('mysql'),
      'type'       => substr((string)$type, 0, 32),
      'ref_id'     => (int)$ref_id,
      'user_id'    => (int)$user_id,
      'amount'     => (float)$amount,
      'status'     => substr((string)$status, 0, 32),
      'meta'       => $meta ? wp_json_encode($meta) : null,
    ];
    $wpdb->insert($table, $row, [
      '%s','%s','%d','%d','%f','%s','%s'
    ]);

    // Audit trail via Yoda_Logger, se disponível
    if (class_exists('Yoda_Logger')) {
      try {
        Yoda_Logger::log('ledger', [
          'type'    => $row['type'],
          'ref_id'  => $row['ref_id'],
          'user_id' => $row['user_id'],
          'amount'  => $row['amount'],
          'status'  => $row['status'],
          'meta'    => $meta,
        ]);
      } catch (\Throwable $e) {
        // falha de log não pode quebrar fluxo
      }
    }
  }

  private static function table_name(){
    global $wpdb;
    return $wpdb->prefix . self::TABLE;
  }
}
