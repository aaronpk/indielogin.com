<?php
namespace App\Provider;

use danielburger1337\OAuth2\DPoP\Encoder\WebTokenFrameworkDPoPTokenEncoder;
use danielburger1337\OAuth2\DPoP\DPoPProofFactory;
use danielburger1337\OAuth2\DPoP\NonceStorage\CacheNonceStorage;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Request;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use Config;

trait ATProto {

  private function resolve_handle($handle) {
    $resolveHandle = 'https://api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle='.urlencode($handle);
    $response = http_client()->get($resolveHandle, []);
    $body = json_decode($response['body']);
    return $body->did ?? null;
  }

  private function resolve_did_plc($did) {
    $didDocument = 'https://' . getenv('PLC_HOST') . '/' . $did;
    $response = http_client()->get($didDocument, []);
    $body = json_decode($response['body']);
    return $body;
  }

  private function resolve_did_web($did) {
    $path = substr($did, strlen('did:web:'));
    if (strpos($path, ':') === false) {
      // No colon, did document is under .well-known
      $path = $path . '/.well-known/did.json';
    } else {
      // If there are colons, replace them with slashes
      // e.g. did:web:example.com:user -> example.com/user
      $path = str_replace(':', '/', $path) . '/did.json';
    }

    $response = http_client()->get($path, []);
    $body = json_decode($response['body']);
    return $body;
  }

  private function resolve_did($did) {
    if (preg_match('/^did:plc:/', $did)) {
      return $this->resolve_did_plc($did);
    } elseif (preg_match('/^did:web:/', $did)) {
      return $this->resolve_did_web($did);
    } else {
      // idk enough PHP to deal with error handling
      return null;
    }
  }

  private function pds_from_did($did) {
    $userlog = make_logger('pds_from_did');
    $didDocument = $this->resolve_did($did);
    $userlog->info('Resolved DID Document', ['didDocument' => $didDocument]);
    if (!$didDocument || !isset($didDocument->service)) {
      return null;
    }

    foreach ($didDocument->service as $service) {
      if (isset($service->id) && $service->id === '#atproto_pds') {
        if (isset($service->serviceEndpoint)) {
          return $service->serviceEndpoint;
        }
      }
    }

    return null;
  }

  private function _start_atproto($login_request, $details) {
    $userlog = make_logger('user');

    $handle = $details['handle'] ?? null;
    
    $userlog->info('Beginning atproto login', ['provider' => $details, 'login' => $login_request]);
    if (!$handle) {
      return new HtmlResponse('Missing account handle', 400);
    }

    // if (preg_match('/^did:/', $identifier)) {
    //   $did = $identifier;
    // } else {
      $did = $this->resolve_handle($handle);
      if (!$did) {
        return new HtmlResponse('Could not resolve handle to DID', 400);
      }
    // }
  
    $userlog->info('Resolved handle to DID', ['did' => $did]);

    // Get PDS endpoint from DID
    $pds = $this->pds_from_did($did);
    if (!$pds) {
      return new HtmlResponse('Could not resolve PDS from DID', 400);
    }

    $userlog->info('Resolved PDS from DID', ['pds' => $pds]);

    // Fetch PDS resource server metadata
    $resource_meta_url = rtrim($pds, '/') . '/.well-known/oauth-protected-resource';
    $resource_meta_resp = http_client()->get($resource_meta_url, []);
    $resource_meta = json_decode($resource_meta_resp['body'] ?? '', true);
    if (!isset($resource_meta['authorization_servers'][0])) {
      return new HtmlResponse('Could not find authorization server', 400);
    }
    $auth_server = $resource_meta['authorization_servers'][0];

    // Fetch Authorization Server metadata
    $auth_meta_url = rtrim($auth_server, '/') . '/.well-known/oauth-authorization-server';
    $auth_meta_resp = http_client()->get($auth_meta_url, []);
    $auth_meta = json_decode($auth_meta_resp['body'] ?? '', true);
    if (!isset($auth_meta['authorization_endpoint']) || !isset($auth_meta['pushed_authorization_request_endpoint'])) {
      return new HtmlResponse('Invalid authorization server metadata', 400);
    }
    $authorization_endpoint = $auth_meta['authorization_endpoint'];
    $par_endpoint = $auth_meta['pushed_authorization_request_endpoint'];

    // Prepare PKCE (S256)
    $code_verifier = bin2hex(random_bytes(32));
    $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

    // Generate EC keypair for DPoP
    $dpop_jwk = JWKFactory::createECKey('P-256');
    $_SESSION['atproto.dpop_jwk'] = $dpop_jwk->jsonSerialize();
    $_SESSION['atproto.dpop_jkt'] = $dpop_jwk->thumbprint('sha256');

    $dpopEncoder = new WebTokenFrameworkDPoPTokenEncoder($dpop_jwk, new AlgorithmManager([new ES256()]));
    $dpopFactory = new DPoPProofFactory(
      new \Symfony\Component\Clock\Clock(),
      $dpopEncoder,
      new CacheNonceStorage(
        $cache = new ArrayAdapter(
          $defaultLifetime = 0,
          $storeSerialized = true,
          $maxLifetime = 0,
          $maxItems = 0,
        ),
      ),
    );

    // Prepare OAuth params
    $client_id = getenv('BASE_URL') . 'id/atproto';
    $redirect_uri = getenv('BASE_URL') . 'redirect/atproto';
    $scope = 'atproto transition:generic';
    $state = generate_state();
    $_SESSION['atproto.code_verifier'] = $code_verifier;
    $_SESSION['atproto.did'] = $did;
    $_SESSION['atproto.auth_server'] = $auth_server;

    // Proof generation
    $psrRequest = new Request($par_endpoint, 'POST');
    $dpopProof = $dpopFactory->createProofFromRequest($psrRequest, ['ES256'], $_SESSION['atproto.dpop_jkt']);

    // Make PAR request
    $par_params = [
      'client_id' => "http://localhost/?scope=atproto transition:generic&redirect_uri=$redirect_uri",
      'redirect_uri' => $redirect_uri,
      'response_type' => 'code',
      'scope' => $scope,
      'state' => $state,
      'code_challenge' => $code_challenge,
      'code_challenge_method' => 'S256',
      'login_hint' => $handle,
    ];
    $par_headers = [
      'Content-Type: application/x-www-form-urlencoded',
      'DPoP: ' . $dpopProof->proof,
    ];
    $par_resp = http_client()->post($par_endpoint, http_build_query($par_params), $par_headers);
    $par_body = json_decode($par_resp['body'] ?? '', true);
    $userlog->info('PAR response', ['response' => $par_body]);
    if (!isset($par_body['request_uri'])) {
      return new HtmlResponse('Failed to initiate authorization (PAR)', 400);
    }
    $request_uri = $par_body['request_uri'];
    $userlog->info('PAR successful', ['request_uri' => $request_uri]);

    // Redirect to authorization endpoint with request_uri
    $authorize_url = $authorization_endpoint . '?' . http_build_query([
      'client_id' => "http://localhost/?scope=atproto transition:generic&redirect_uri=$redirect_uri",
      'request_uri' => $request_uri,
    ]);
    return redirect_response($authorize_url, 302);
  }

  public function redirect_atproto(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $userlog = make_logger('user');
    $query = $request->getQueryParams();
    $code = $query['code'] ?? null;
    $state = $query['state'] ?? null;
    $issuer = $query['iss'] ?? null;

    $userlog->info('ATProto redirect handler called', ['query' => $query, 'session' => $_SESSION]);

    // Validate state
    if (!$code || !$state || $state !== ($_SESSION['state'] ?? null)) {
      return new HtmlResponse('Invalid or missing code/state', 400);
    }

    // Get session vars
    $code_verifier = $_SESSION['atproto.code_verifier'] ?? null;
    $redirect_uri = getenv('BASE_URL') . 'redirect/atproto';
    $client_id = "http://localhost/?scope=atproto transition:generic&redirect_uri=$redirect_uri";
    $auth_server = $_SESSION['atproto.auth_server'] ?? null;
    if (!$code_verifier || !$auth_server) {
      return new HtmlResponse('Session expired or missing', 400);
    }

    // Fetch Authorization Server metadata
    $auth_meta_url = rtrim($auth_server, '/') . '/.well-known/oauth-authorization-server';
    $auth_meta_resp = http_client()->get($auth_meta_url, []);
    $auth_meta = json_decode($auth_meta_resp['body'] ?? '', true);
    if (!isset($auth_meta['token_endpoint'])) {
      return new HtmlResponse('Invalid authorization server metadata', 400);
    }
    $token_endpoint = $auth_meta['token_endpoint'];

    // Prepare token request
    $token_params = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $redirect_uri,
      'client_id' => $client_id,
      'code_verifier' => $code_verifier,
    ];
    $token_headers = [
      'Content-Type: application/x-www-form-urlencoded',
      // TODO: Add DPoP header (DPoP JWT) here
    ];
    $token_resp = http_client()->post($token_endpoint, http_build_query($token_params), $token_headers);
    $token_body = json_decode($token_resp['body'] ?? '', true);
    $userlog->info('Token response', ['response' => $token_body]);

    // Validate token response
    if (!isset($token_body['access_token'], $token_body['sub'], $token_body['scope'])) {
      return new HtmlResponse('Invalid token response', 400);
    }
    if (strpos($token_body['scope'], 'atproto') === false) {
      return new HtmlResponse('Missing atproto scope in token', 400);
    }

    // Verify DID matches session
    $expected_did = $_SESSION['atproto.did'] ?? null;
    if ($expected_did && $token_body['sub'] !== $expected_did) {
      return new HtmlResponse('DID mismatch', 400);
    }

    // Optionally, verify issuer matches auth_server
    if ($issuer && strpos($auth_server, $issuer) === false) {
      return new HtmlResponse('Issuer mismatch', 400);
    }

    // Success: return or store tokens, show user info, etc.
    // For demo, just show the DID and access token
    return new HtmlResponse('<h1>ATProto Login Successful</h1>' .
      '<p>DID: ' . htmlspecialchars($token_body['sub']) . '</p>' .
      '<p>Access Token: ' . htmlspecialchars($token_body['access_token']) . '</p>', 200);
  }

}
