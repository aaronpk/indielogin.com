<?php
namespace App\Provider;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response;
use ATProto, ATProtoException;

use Config;

trait ATProtoProvider {

  private function _start_atproto($login_request, $details) {
    $userlog = make_logger('user');

    $at = new ATProto();
    $at->initialize($details['atproto']['handle'], $details['atproto']['did']);
    try {
      $authorize = $at->start_oauth();
    } catch(ATProtoException $e) {
      return $this->_userError($e->getMessage());
    }

    $at->save_state();

    $userlog->info('Beginning ATProto login', ['provider' => $details, 'login' => $login_request]);

    return redirect_response($authorize, 302);
  }

  public function redirect_atproto(ServerRequestInterface $request): ResponseInterface {
    session_start();

    $userlog = make_logger('user');

    $query = $request->getQueryParams();

    if(isset($query['error'])) {
      return $this->_userError($query['error_description']);
    }

    $at = ATProto::restore_from_session();

    try {
      $did = $at->finish_oauth($query);
    } catch(ATProtoException $e) {
      return $this->_userError($e->getMessage());
    }

    // If no exception was thrown, the OAuth flow completed successfully and the user ID (`did`) was returned and matches the starting `did`

    // If they entered a website that linked to an ATProto URL with rel=me, verify the profile links back to their website.
    // Check for this condition by checking if the hostname of the entered URL matches the hostname of their ATProto handle.
    if(!same_host($_SESSION['expected_me'], 'https://'.$_SESSION['atproto.handle'])) {
      $profile = $at->fetch_profile();
      if(empty($profile)) {
        return $this->_userError('Error fetching BlueSky profile');
      }
      $userlog->debug('Got different handle than entered', [
        'expected' => $_SESSION['expected_me'],
        'atproto' => $_SESSION['atproto.handle'],
        'profile' => $profile,
      ]);
      if(empty($profile['description']) || !string_contains_url($profile['description'], $_SESSION['expected_me'])) {
        return $this->_userError('Ensure your BlueSky profile contains a link to your website');
      }
      $_SESSION['login_request']['provider'] = 'atproto_rel'; // Just for logging purposes at this point, everything else is done
    }

    $_SESSION['login_request']['profile'] = json_encode([
      'did' => $did,
      'atproto_handle' => $_SESSION['atproto.handle'],
    ]);

    $at->clear_state();

    return $this->_finishAuthenticate();
  }

}
