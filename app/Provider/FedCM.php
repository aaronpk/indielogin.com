<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Config;

trait FedCM {

  public function fedcm_start(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();

    $params = $request->getParsedBody();

    $code_verifier = generate_pkce_code_verifier();
    $hashed = hash('sha256', $code_verifier, true);
    $code_challenge = rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');

    $login_request = [
      'client_id' => $params['client_id'],
      'redirect_uri' => $params['redirect_uri'],
      'state' => $params['state'],
    ];
    $_SESSION['login_request'] = $login_request;

    $response->getBody()->write(json_encode([
      'code_challenge' => $code_challenge,
      'client_id' => getenv('BASE_URL').'id',
    ]));
    return $response->withHeader('Content-type', 'application/json');
  }

  public function fedcm_login(ServerRequestInterface $request, ResponseInterface $response) {
    session_start();
    
    $userlog = make_logger('user');

    $http = http_client();
    $params = $request->getParsedBody();

    // Fetch the IndieAuth metadata
    $res = $http->get($params['metadata_endpoint']);
    $metadata = json_decode($res['body'], true);

    if(!$metadata || !isset($metadata['token_endpoint'])) {
      $response->getBody()->write(json_encode([
        'error' => 'invalid_indieauth_response',
      ]));
      return $response->withHeader('Content-type', 'application/json')->withStatus(400);      
    }

    $res = $http->post($metadata['token_endpoint'], [
      'grant_type' => 'authorization_code',
      'client_id' => getenv('BASE_URL').'id',
      'code' => $params['code'],
      'code_verifier' => $_SESSION['code_verifier'],
    ]);
    $userinfo = json_decode($res['body'], true);
    
    if(!$userinfo || !isset($userinfo['me'])) {
      $userlog->warning('IndieAuth code exchange failed', ['metadata_endpoint' => $params['metadata_endpoint']]);
      $response->getBody()->write(json_encode([
        'error' => 'invalid_indieauth_response',
      ]));
      return $response->withHeader('Content-type', 'application/json')->withStatus(400);
    }

    $verified = false;

    // If the hostname of 'me' matches hostname of the metadata endpoint, we're done
    if(parse_url($userinfo['me'], PHP_URL_HOST) == parse_url($params['metadata_endpoint'], PHP_URL_HOST)) {
      $userlog->info('FedCM verified with matching hostname');
      $verified = true;
    }
    
    // Fetch the user's profile URL and confirm that this metadata endpoint is linked to from their HTTP headers
    if(!$verified) {
      $res = $http->get($userinfo['me']);
  
      if(isset($res['rels']['indieauth-metadata']) && in_array($params['metadata_endpoint'], $res['rels']['indieauth-metadata'])) {
        $userlog->info('FedCM verified by finding metadata endpoint in HTTP headers');
        $verified = true;
      }
    }
    
    // Fetch the user's profile URL and confirm that this metadata endpoint is linked to from their web page
    if(!$verified) {
      $mf2 = \mf2\Parse($res['body'], $res['url']);
      if(isset($mf2['rels']) && isset($mf2['rels']['indieauth-metadata'])) {
        if(in_array($params['metadata_endpoint'], $mf2['rels']['indieauth-metadata'])) {
          $userlog->info('FedCM verified by finding metadata endpoint in HTML rels');
          $verified = true;
        }
      }
    }
    
    if(!$verified) {
      $userlog->warning('IndieAuth verification failed', ['me' => $userinfo['me'], 'metadata_endpoint' => $params['metadata_endpoint']]);
      $response->getBody()->write(json_encode([
        'error' => 'verification_failed',
      ]));
      return $response->withHeader('Content-type', 'application/json')->withStatus(400);
    }
    
    $_SESSION['expected_me'] = $userinfo['me'];
    
    return $this->_finishAuthenticateJSON($response);
  }
  
}
