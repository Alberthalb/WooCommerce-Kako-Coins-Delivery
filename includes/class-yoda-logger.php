<?php
if (!defined('ABSPATH')) exit;

/**
 * Yoda_Logger
 * - Escreve JSON Lines em /uploads/yoda-logs/yoda-YYYY-MM-DD.log
 * - Usa constante YODA_LOGS=true para ativar
 * - Oculta dados sensíveis automaticamente
 */
class Yoda_Logger {
  const DIRNAME   = 'yoda-logs';
  const FILENAME  = 'yoda-%s.log'; // %s => Y-m-d

  public static function hooks(){
    // limpeza diária
    add_action('yoda_logs_prune', [__CLASS__, 'prune_old']);
    if (!wp_next_scheduled('yoda_logs_prune')){
      wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'yoda_logs_prune');
    }
  }

  /** log genérico */
  public static function log($event, array $data = [], $order_id = null, $level = 'info'){
    if (!self::enabled()) return;

    // enrich
    $row = [
      'ts'       => current_time('mysql', true), // UTC
      'level'    => $level,
      'event'    => $event,
      'order_id' => $order_id ? (int)$order_id : null,
      'site'     => home_url(),
      'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
      'data'     => self::redact($data),
    ];
    self::write_line($row);
  }

  /** log específico de API */
  public static function api($endpoint, array $req, array $res, $http_code = null, $order_id = null){
    if (!self::enabled()) return;
    $payload = [
      'endpoint'  => $endpoint,
      'http'      => $http_code,
      'request'   => self::redact($req),
      'response'  => self::redact($res),
    ];
    self::log('api_call', $payload, $order_id);
  }

  /** habilitado? */
  private static function enabled(){
    return defined('YODA_LOGS') && YODA_LOGS;
  }

  /** mascara dados sensíveis */
  private static function redact(array $arr){
    $json = wp_json_encode($arr);
    // chaves comuns
    $json = preg_replace('/("appKey"\s*:\s*")([^"]+)(")/i', '$1***redacted***$3', $json);
    $json = preg_replace('/("X-Sign"\s*:\s*")([^"]+)(")/i',  '$1***redacted***$3', $json);
    $json = preg_replace('/("X-App-Id"\s*:\s*")([^"]+)(")/i','$1***redacted***$3', $json);
    return json_decode($json, true) ?: $arr;
  }

  /** escreve linha */
  private static function write_line(array $row){
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) return;

    $dir = trailingslashit($upload['basedir']).self::DIRNAME;
    if (!wp_mkdir_p($dir)) return;

    $file = trailingslashit($dir).sprintf(self::FILENAME, gmdate('Y-m-d'));
    $line = wp_json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    // write atomically
    $fh = @fopen($file, 'ab');
    if ($fh){
      @fwrite($fh, $line.PHP_EOL);
      @fclose($fh);
    }
  }

  /** apaga arquivos antigos */
  public static function prune_old(){
    $days = defined('YODA_LOGS_RETENTION_DAYS') ? (int)YODA_LOGS_RETENTION_DAYS : 14;
    $upload = wp_upload_dir();
    if (!empty($upload['error'])) return;

    $dir = trailingslashit($upload['basedir']).self::DIRNAME;
    if (!is_dir($dir)) return;

    foreach (glob($dir.'/yoda-*.log') as $file){
      if (@filemtime($file) < time() - $days*DAY_IN_SECONDS){
        @unlink($file);
      }
    }
  }
}
