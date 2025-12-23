<?php
if (!defined('ABSPATH')) exit;

class Yoda_Kako_Client {
  private $base;
  private $appId;
  private $appKey;
  private $timeout = 20;

  public function __construct($base, $appId, $appKey){
    $fallbackBase = defined('KAKO_API_BASE') && KAKO_API_BASE ? KAKO_API_BASE : 'https://api-test.kako.live';
    $this->base   = rtrim($base ?: $fallbackBase, '/');
    $this->appId  = $appId ?: (defined('KAKO_APP_ID') ? KAKO_APP_ID : '');
    $this->appKey = $appKey ?: (defined('KAKO_APP_KEY') ? KAKO_APP_KEY : '');
    if (defined('YODA_HTTP_TIMEOUT') && (int)YODA_HTTP_TIMEOUT > 0){
      $this->timeout = (int) YODA_HTTP_TIMEOUT;
    }
  }

  /** Gera string JSON do corpo; envia '{}' quando vazio */
  private function make_json_body(array $bodyArr){
    if (empty($bodyArr)) return '{}';
    return wp_json_encode($bodyArr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  }

  /** Assina a STRING JSON do corpo (não o array) */
  private function sign($path, $bodyJson){
    $ts = (string) time();
    $elements = [
      'appId='.$this->appId,
      'body='.$bodyJson,
      'path='.$path,
      'timestamp='.$ts,
    ];
    sort($elements, SORT_STRING);
    $signContent = implode('&', $elements);
    $sig = hash_hmac('sha256', $signContent, $this->appKey);
    return [$ts, $sig];
  }

  private function post($path, array $bodyArr){
    $bodyJson = $this->make_json_body($bodyArr);
    list($ts, $sig) = $this->sign($path, $bodyJson);

    $url = $this->base.$path;

    $args = [
      'headers' => [
        'Content-Type' => 'application/json',
        'X-App-Id'     => $this->appId,
        'X-Timestamp'  => $ts,
        'X-Sign'       => $sig,
        'Expect'       => '',
        'User-Agent'   => 'YodaKako-WP/'.(get_bloginfo('name')?:'site'),
      ],
      'timeout'    => $this->timeout,
      'body'       => $bodyJson,
      'blocking'   => true,
      'httpversion'=> '1.1',
    ];

    $args = apply_filters('yoda_kako_http_args', $args, $url, $path, $bodyArr);
    $res  = wp_remote_post($url, $args);

    if (is_wp_error($res)) {
      $msg = $res->get_error_message();
      if (stripos($msg, 'cURL error 28') !== false) {
        $args['timeout'] = max($this->timeout, 20);
        $res = $this->with_streams_transport(function() use ($url, $args){
          return wp_remote_post($url, $args);
        });
      }

      if (class_exists('Yoda_Logger')) {
        Yoda_Logger::api(
          $path,
          [
            'url'     => $url,
            'headers' => $args['headers'],
            'body'    => json_decode($bodyJson, true),
          ],
          [ 'error' => is_wp_error($res) ? $res->get_error_message() : null ],
          null,
          null
        );
      }
      return $res;
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);

    if (class_exists('Yoda_Logger')) {
      Yoda_Logger::api(
        $path,
        [
          'url'     => $url,
          'headers' => $args['headers'],
          'body'    => json_decode($bodyJson, true),
        ],
        [ 'http' => $code, 'json' => $json ],
        $code,
        null
      );
    }

    return ['http'=>$code, 'json'=>$json];
  }

  private function with_streams_transport($cb){
    $filter = function($transports){ return ['streams']; };
    add_filter('http_api_transports', $filter);
    try { return $cb(); } finally { remove_filter('http_api_transports', $filter); }
  }

  public function balance(){
    return $this->post('/api/v1/seller/api/balance', []);
  }

  public function userinfo($kakoId){
    return $this->post('/api/v1/seller/api/userinfo', ['userId'=>(string)$kakoId]);
  }

  public function transout($openId, $amount, $orderId){
    return $this->post('/api/v1/seller/api/transout', [
      'openId'  => (string) $openId,
      'amount'  => (int)    $amount,
      'orderId' => (string) $orderId,
    ]);
  }

  public function transqry($orderId){
    return $this->post('/api/v1/seller/api/transqry', [
      'orderId' => (string) $orderId,
    ]);
  }
}

