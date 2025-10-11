<?php
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Core\JWK;


class ATProto {

  private $_http;
  private $_jwk;
  private $_handle;
  private $_did;
  private $_pds;
  private $_as;
  private $_as_metadata;
  private $_dpop_nonce;

  public function __construct($newKey=true) {
    if($newKey)
      $this->_jwk = JWKFactory::createECKey('P-256');
  }

  public static function restore_from_session() {
    $at = new ATProto(false);
    $at->restore_state();
    return $at;
  }

  public function save_state() {
    $_SESSION['atproto.dpop_jwk'] = $this->_jwk->jsonSerialize();
    $_SESSION['atproto.dpop_jkt'] = $this->_jwk->thumbprint('sha256');
    $_SESSION['atproto.dpop_nonce'] = $this->_dpop_nonce;
    $_SESSION['atproto.as_metadata'] = json_encode($this->_as_metadata);
  }

  public function restore_state() {
    $this->_dpop_nonce = $_SESSION['atproto.dpop_nonce'];
    $this->_jwk = new JWK($_SESSION['atproto.dpop_jwk']);
    $this->_as_metadata = json_decode($_SESSION['atproto.as_metadata'], true);
  }

  public function clear_state() {
    unset($_SESSION['atproto.dpop_jwk']);
    unset($_SESSION['atproto.dpop_jkt']);
    unset($_SESSION['atproto.dpop_nonce']);
    unset($_SESSION['atproto.as_metadata']);
  }



  private function _http() {
    if($this->_http) return $this->_http;
    $this->_http = http_client();
    return $this->_http;
  }

  public static function handle_to_did($handle) {
    $dns_record = dns_get_record('_atproto.'.$handle, DNS_TXT);

    if(count($dns_record) != 1) {
      return null;
    }

    $dns_record = $dns_record[0];

    if(preg_match('/did=(did:plc:.+)/', $dns_record['txt'], $match)) {
      return $match[1];
    }

    return null;
  }

  public function did_to_pds($did, $handle) {
    $result = $this->_http()->get('https://plc.directory/'.$did);

    if(!empty($result['body'])) {
      $data = json_decode($result['body'], true);
      if($data) {
        $aka = $data['alsoKnownAs'][0];
        // Bidirectionally verify that the DID document links back to the handle
        if($aka == 'at://'.$handle) {
          return $data['service'][0]['serviceEndpoint'] ?? null;
        }
      }
    }

    return null;
  }

  public function pds_to_authorization_server($pds) {
    $result = $this->_http()->get($pds.'/.well-known/oauth-protected-resource');

    if(!empty($result['body'])) {
      $data = json_decode($result['body'], true);
      if($data) {
        return $data['authorization_servers'][0] ?? null;
      }
    }

    return null;
  }

  public function as_metadata($as) {
    $result = $this->_http()->get($as.'/.well-known/oauth-authorization-server');

    if(!empty($result['body'])) {
      $data = json_decode($result['body'], true);
      return $data;
    }

    return null;
  }

  public function create_dpop_proof($method, $url, $opts=[]) {
    $claims = [
      'htm' => $method,
      'htu' => $url,
      'iat' => time(),
      'jti' => generate_state(),
    ];
    if(!empty($opts['nonce'])) {
      $claims['nonce'] = $opts['nonce'];
    }
    if(!empty($opts['access_token'])) {
      $claims['ath'] = base64_urlencode(hash('sha256', $opts['access_token'], true));
    }

    $algorithmManager = new AlgorithmManager([
      new ES256(),
    ]);
    $jwsBuilder = new JWSBuilder($algorithmManager);
    $jws = $jwsBuilder
      ->create()
      ->withPayload(json_encode($claims))
      ->addSignature($this->_jwk, [
        'typ' => 'dpop+jwt',
        'alg' => 'ES256',
        'jwk' => $this->_jwk->toPublic(),
      ])
      ->build();
    $serializer = new CompactSerializer();
    return $serializer->serialize($jws, 0);
  }

  public function initialize($handle, $did=false) {
    $this->_handle = $handle;
    if($did) {
      $this->_did = $did;
    } else {
      $this->_did = self::handle_to_did($handle);
    }
    $this->_pds = $this->did_to_pds($this->_did, $handle);
    $this->_as = $this->pds_to_authorization_server($this->_pds);
    $this->_as_metadata = $this->as_metadata($this->_as);
  }

  public function start_oauth() {
    $userlog = make_logger('user');

    $url = $this->_as_metadata['pushed_authorization_request_endpoint'];
    $state = generate_state('atproto');
    $code_verifier = generate_pkce_code_verifier();
    $params = [
      'response_type' => 'code',
      'client_id' => getenv('ATPROTO_CLIENT_ID'),
      'redirect_uri' => getenv('ATPROTO_REDIRECT_URI'),
      'scope' => 'atproto',
      'state' => $state,
      'code_challenge' => pkce_code_challenge($code_verifier),
      'code_challenge_method' => 'S256',
      'login_hint' => $this->_handle,
    ];
    $headers = [
      'DPoP: '.$this->create_dpop_proof('POST', $url),
    ];

    $userlog->info('Sending PAR request: '.json_encode($params));

    $result = $this->_http()->post($url, http_build_query($params), $headers);
    $body = json_decode($result['body'], true);

    // We expect an error first where it returns a DPoP nonce in the header
    if(isset($body['error']) && $body['error'] == 'use_dpop_nonce') {
      $nonce = $result['headers']['Dpop-Nonce'];
      $this->_dpop_nonce = $nonce;

      $headers = [
        'DPoP: '.$this->create_dpop_proof('POST', $url, ['nonce' => $nonce]),
      ];
      $result = $this->_http()->post($url, http_build_query($params), $headers);
      $body = json_decode($result['body'], true);

      return $this->_as_metadata['authorization_endpoint'].'?'.http_build_query([
        'client_id' => getenv('ATPROTO_CLIENT_ID'),
        'request_uri' => $body['request_uri'],
      ]);
    }

    return null;
  }

  public function finish_oauth($query) {
    $userlog = make_logger('user');

    // Verify the state parameter
    if(!isset($_SESSION['atproto.state']) || $_SESSION['atproto.state'] != $query['state']) {
      $userlog->warning('ATProto server returned an invalid state parameter', [
        'query' => $query,
        'expected' => $_SESSION['atproto.state'] ?? null,
      ]);
      throw new ATProtoException('Your ATProto server did not return a valid state parameter');
    }




    $url = $this->_as_metadata['token_endpoint'];


  }

}


class ATProtoException extends Exception {

}

