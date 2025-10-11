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
  private $_access_token;

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
    $_SESSION['atproto.did'] = $this->_did;
    $_SESSION['atproto.handle'] = $this->_handle;
    $_SESSION['atproto.pds'] = $this->_pds;
    $_SESSION['atproto.dpop_jwk'] = $this->_jwk->jsonSerialize();
    $_SESSION['atproto.dpop_jkt'] = $this->_jwk->thumbprint('sha256');
    $_SESSION['atproto.dpop_nonce'] = $this->_dpop_nonce;
    $_SESSION['atproto.as_metadata'] = json_encode($this->_as_metadata);
  }

  public function restore_state() {
    $this->_did = $_SESSION['atproto.did'];
    $this->_handle = $_SESSION['atproto.handle'];
    $this->_pds = $_SESSION['atproto.pds'];
    $this->_dpop_nonce = $_SESSION['atproto.dpop_nonce'];
    $this->_jwk = new JWK($_SESSION['atproto.dpop_jwk']);
    $this->_as_metadata = json_decode($_SESSION['atproto.as_metadata'], true);
  }

  public function clear_state() {
    unset($_SESSION['atproto.did']);
    unset($_SESSION['atproto.handle']);
    unset($_SESSION['atproto.pds']);
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

    if(count($dns_record) >= 1) {

      $dns_record = $dns_record[0];

      if(preg_match('/did=(did:plc:.+)/', $dns_record['txt'], $match)) {
        return $match[1];
      }

    } else {

      // Fallback to HTTP lookup for bsky.social subdomains
      if(preg_match('/\.bsky.social/', $handle)) {
        $http = http_client();
        $response = $http->get('https://'.$handle.'/.well-known/atproto-did');
        $body = trim($response['body']);
        if(is_string($body) && preg_match('/^did:plc:.+$/', $body)) {
          return $body;
        }
      }

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

    if(empty($this->_as_metadata)) {
      throw new ATProtoException('Could not fetch ATProto OAuth config for handle '.$this->_handle);
    }

    $url = $this->_as_metadata['pushed_authorization_request_endpoint'];
    $state = generate_state('atproto');
    $code_verifier = generate_pkce_code_verifier();
    $params = [
      'response_type' => 'code',
      'client_id' => getenv('ATPROTO_CLIENT_ID'),
      'redirect_uri' => getenv('ATPROTO_REDIRECT_URI'),
      'scope' => 'atproto rpc:app.bsky.actor.getProfile?aud=did:web:api.bsky.app%23bsky_appview',
      'state' => $state,
      'code_challenge' => pkce_code_challenge($code_verifier),
      'code_challenge_method' => 'S256',
      #'login_hint' => $this->_handle,
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

      if(empty($body['request_uri'])) {
        $userlog->error('Did not receive request_uri from server', [
          'body' => $body,
        ]);
        throw new ATProtoException('Invalid response from ATProto OAuth server');
      }

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

    // Verify iss
    if(!isset($query['iss']) || $query['iss'] != $this->_as_metadata['issuer']) {
      $userlog->warning('Received invalid iss in the response', [
        'query' => $query,
        'expected' => $this->_as_metadata['issuer'] ?? null,
      ]);
      throw new ATProtoException('Received invalid iss in the response');
    }


    $url = $this->_as_metadata['token_endpoint'];
    $params = [
      'grant_type' => 'authorization_code',
      'client_id' => getenv('ATPROTO_CLIENT_ID'),
      'redirect_uri' => getenv('ATPROTO_REDIRECT_URI'),
      'code_verifier' => $_SESSION['code_verifier'],
      'code' => $query['code'],
    ];
    $headers = [
      'DPoP: '.$this->create_dpop_proof('POST', $url, ['nonce' => $this->_dpop_nonce]),
    ];

    $userlog->info('Sending token request: '.json_encode($params));

    $result = $this->_http()->post($url, http_build_query($params), $headers);
    if(!empty($result['headers']['Dpop-Nonce'])) {
      $this->_dpop_nonce = $result['headers']['Dpop-Nonce'];
    }

    $body = json_decode($result['body'], true);

    if(empty($body['sub'])) {
      $userlog->warning('Received invalid ATProto token response', [
        'query' => $query,
        'body' => $body,
      ]);
      throw new ATProtoException('Received invalid response from ATProto server');
    }

    // Check that the sub matches the expected value
    // https://docs.bsky.app/docs/advanced-guides/oauth-client#callback-and-access-token-request

    if($body['sub'] != $this->_did) {
      $userlog->warning('Expected sub did not match returned sub', [
        'query' => $query,
        'body' => $body,
        'expected' => $this->_did,
      ]);
      throw new ATProtoException('Error verifying the login attempt. The user ID returned ('.$body['sub'].') did not match the expected user ID ('.$this->_did.')');
    }

    $userlog->info('Successful ATProto login', [
      'response' => $body,
    ]);

    $this->_access_token = $body['access_token'];

    return $body['sub'];
  }

  public function fetch_profile() {
    if(!$this->_access_token)
      return null;

    $url = $this->_pds . '/xrpc/app.bsky.actor.getProfile?'.http_build_query([
      'actor' => $this->_did,
    ]);
    $headers = [
      'Authorization: DPoP '.$this->_access_token,
      'DPoP: '.$this->create_dpop_proof('GET', $url, ['access_token' => $this->_access_token]),
    ];
    $result = $this->_http()->get($url, $headers);

    if(!empty($result['headers']['Dpop-Nonce'])) {
      $this->_dpop_nonce = $result['headers']['Dpop-Nonce'];
    }

    $headers = [
      'Authorization: DPoP '.$this->_access_token,
      'DPoP: '.$this->create_dpop_proof('GET', $url, ['nonce' => $this->_dpop_nonce, 'access_token' => $this->_access_token]),
    ];
    $result = $this->_http()->get($url, $headers);
    return json_decode($result['body'], true);
  }

}


class ATProtoException extends Exception {

}

